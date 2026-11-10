<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Справочник кодов неисправностей (админ): список, добавление, правка, загрузка из Excel/CSV.
 * Коды выпадают в поле «Код неисправности» во всех вкладках учёта.
 */
class FaultCodeController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $codes = FaultCode::query()->with('section:id,name')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                ->orWhere('description', 'like', '%' . addcslashes($q, '%_\\') . '%')))
            ->orderBy('code')
            ->paginate(100)->withQueryString();

        return view('fault-codes.index', [
            'codes'    => $codes,
            'q'        => $q,
            'sections' => Section::ordered()->get(['id', 'name']),
            'total'    => FaultCode::count(),
        ]);
    }

    public function store(Request $r)
    {
        $data = $this->validated($r);
        $code = $this->upsert($data['code'], $data['description'] ?? null, $data['section_id'] ?? null);
        AuditLog::write('fault_codes', 'created', "Код неисправности {$code->code}", ['subject_type' => 'fault_code', 'subject_id' => $code->id, 'section_id' => null]);

        return back()->with('ok', "Код {$code->code} сохранён");
    }

    public function update(Request $r, FaultCode $faultCode)
    {
        $data = $this->validated($r, $faultCode);
        $before = $faultCode->only(['code', 'description', 'is_active']);
        $faultCode->update($data + ['is_active' => $r->boolean('is_active')]);
        AuditLog::write('fault_codes', 'updated', "Код неисправности {$faultCode->code}", ['subject_type' => 'fault_code', 'subject_id' => $faultCode->id, 'section_id' => null,
            'changes' => collect($faultCode->only(['code', 'description', 'is_active']))->map(fn ($v, $k) => [$before[$k], $v])->filter(fn ($p) => $p[0] != $p[1])->all()]);

        return back()->with('ok', "Код {$faultCode->code} изменён");
    }

    public function destroy(FaultCode $faultCode)
    {
        AuditLog::write('fault_codes', 'deleted', "Код неисправности {$faultCode->code} — {$faultCode->description}", ['subject_type' => 'fault_code', 'subject_id' => $faultCode->id, 'section_id' => null]);
        $faultCode->delete();

        return back()->with('ok', 'Код удалён. В уже сохранённых записях он останется.');
    }

    /** Загрузка из Excel (.xlsx, .xls) или CSV: колонки «Код» и «Описание». */
    public function import(Request $r)
    {
        $r->validate([
            'file'       => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ], ['file.mimes' => 'Нужен файл Excel (.xlsx, .xls) или CSV.']);

        $rows = $this->readRows($r->file('file'));
        [$codeCol, $descCol, $start] = $this->detectColumns($rows);

        $added = $updated = $skipped = 0;
        $seen = [];
        foreach (array_slice($rows, $start) as $row) {
            $code = mb_substr(trim((string) ($row[$codeCol] ?? '')), 0, 100);
            $desc = $descCol !== null ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($row[$descCol] ?? ''))), 0, 255) : '';
            if ($code === '' || isset($seen[mb_strtolower($code)])) { $skipped++; continue; }
            $seen[mb_strtolower($code)] = true;

            $existed = $this->find($code, $r->input('section_id'));
            $this->upsert($code, $desc !== '' ? $desc : null, $r->input('section_id'));
            $existed ? $updated++ : $added++;
        }

        AuditLog::write('fault_codes', 'imported', "Загрузка кодов неисправностей: добавлено {$added}, обновлено {$updated}", ['section_id' => null]);

        if ($added + $updated === 0) {
            return back()->withErrors(['file' => 'В файле не найдено ни одного кода. Нужны колонки «Код» и «Описание» (или код в первой колонке).']);
        }

        return back()->with('ok', "Загружено: добавлено {$added}, обновлено {$updated}" . ($skipped ? ", пропущено пустых и повторов: {$skipped}" : ''));
    }

    /** Шаблон для заполнения (CSV, открывается в Excel). */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM — чтобы Excel правильно показал русские буквы
            fputcsv($out, ['Код', 'Описание'], ';');
            fputcsv($out, ['E01', 'Нет связи с модулем'], ';');
            fputcsv($out, ['E02', 'Не определяется камера'], ';');
            fclose($out);
        }, 'коды-неисправностей-шаблон.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ================================================================ служебное

    private function validated(Request $r, ?FaultCode $existing = null): array
    {
        $data = $r->validate([
            'code'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'section_id'  => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ]);
        $data['code'] = trim($data['code']);
        $data['section_id'] = $data['section_id'] ?? null;

        $dup = $this->find($data['code'], $data['section_id']);
        abort_if($dup && $existing && $dup->id !== $existing->id, 422, 'Такой код уже есть');

        return $data;
    }

    private function find(string $code, $sectionId): ?FaultCode
    {
        return FaultCode::where('code', $code)
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId), fn ($q) => $q->whereNull('section_id'))
            ->first();
    }

    private function upsert(string $code, ?string $description, $sectionId): FaultCode
    {
        $row = $this->find($code, $sectionId) ?? new FaultCode(['code' => $code, 'section_id' => $sectionId ?: null]);
        if ($description !== null) $row->description = $description;
        $row->is_active = true;
        $row->save();

        return $row;
    }

    /** Строки файла: Excel — через PhpSpreadsheet, CSV — сами (кодировка и разделитель как у русского Excel). */
    private function readRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $sheets = Excel::toArray(new class implements ToArray { public function array(array $array): void {} }, $file);
            return $sheets[0] ?? [];
        }

        $text = (string) file_get_contents($file->getRealPath());
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\R/u', trim($text));
        $first = $lines[0] ?? '';
        $delimiter = collect([';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')])
            ->sortDesc()->keys()->first();
        if (substr_count($first, $delimiter) === 0) $delimiter = ';';

        return array_map(fn ($l) => str_getcsv($l, $delimiter), array_filter($lines, fn ($l) => trim($l) !== ''));
    }

    /** Колонки по заголовку («Код», «Описание»…); без заголовка — 1-я и 2-я колонки. */
    private function detectColumns(array $rows): array
    {
        foreach (array_slice($rows, 0, 5, true) as $i => $row) {
            $codeCol = $descCol = null;
            foreach ($row as $c => $cell) {
                $h = mb_strtolower(trim((string) $cell));
                if ($codeCol === null && preg_match('/^(код|code|шифр)/u', $h)) $codeCol = $c;
                elseif ($descCol === null && preg_match('/(описан|расшифр|наимен|неисправ|причин|description|name)/u', $h)) $descCol = $c;
            }
            if ($codeCol !== null) return [$codeCol, $descCol, $i + 1];
        }

        return [0, 1, 0];
    }
}
