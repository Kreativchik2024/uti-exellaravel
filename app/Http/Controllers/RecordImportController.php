<?php

namespace App\Http\Controllers;

use App\Services\PgpImport;
use App\Services\RecordImport;
use App\Services\SectionContext;
use App\Support\TableReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Загрузка записей вкладки из таблицы (xlsx / xls / ods / csv): выбор вкладки и файла → выбор листа и предпросмотр → запись.
 * Только вкладки текущего участка; новые вкладки и поля не создаются. Мастер и админ.
 * Файл хранится до загрузки (час), чтобы переключать лист без повторного выбора файла.
 */
class RecordImportController extends Controller
{
    private const DIR = 'record-import';
    private const EXT = ['xlsx', 'xls', 'ods', 'csv'];

    /** Вкладки участка, в которые можно загружать: slug => название. */
    private function tabs(): array
    {
        return array_map(fn ($c) => $c['title'], array_filter(app(SectionContext::class)->sheets(), fn ($c) => ! empty($c['model'])));
    }

    /** @return PgpImport|RecordImport */
    private function importer(string $sheet)
    {
        $cfg = app(SectionContext::class)->sheets()[$sheet] ?? null;
        abort_unless($cfg && ! empty($cfg['model']), 404);

        return $sheet === 'pgp' ? app(PgpImport::class) : new RecordImport($sheet, $cfg);
    }

    private function cacheKey(string $sheet, string $token): string
    {
        return "record-import:{$sheet}:" . auth()->id() . ':' . $token;
    }

    private function view(string $sheet, array $extra = [])
    {
        return view('records.import', $extra + [
            'tab'     => $sheet,
            'tabs'    => $this->tabs(),
            'columns' => $this->importer($sheet)->previewColumns(),
            'result'  => null,
            'token'   => null,
        ]);
    }

    public function form(string $sheet)
    {
        $this->importer($sheet);
        return $this->view($sheet);
    }

    public function upload(Request $r, string $sheet)
    {
        $this->importer($sheet);
        $r->validate(['file' => ['required', 'file', 'max:20480']]);
        $file = $r->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::EXT, true)) {
            return back()->withErrors(['file' => 'Нужен файл .ods, .xlsx, .xls или .csv.']);
        }

        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $old) {   // брошенные предпросмотры
            if ($disk->lastModified($old) < now()->subDay()->getTimestamp()) $disk->delete($old);
        }

        $token = Str::random(32);
        $path = $file->storeAs(self::DIR, auth()->id() . '-' . $token . '.' . $ext, 'local');
        Cache::put($this->cacheKey($sheet, $token), ['path' => $path, 'ext' => $ext, 'name' => $file->getClientOriginalName()], now()->addHour());

        return $this->preview($sheet, $token, null);
    }

    public function sheet(Request $r, string $sheet)
    {
        $r->validate(['token' => ['required', 'string', 'size:32'], 'sheet' => ['required', 'string', 'max:200']]);

        return $this->preview($sheet, $r->input('token'), $r->input('sheet'));
    }

    private function preview(string $sheet, string $token, ?string $sheetName)
    {
        $import = $this->importer($sheet);
        $state = Cache::get($this->cacheKey($sheet, $token));
        if (! $state || ! Storage::disk('local')->exists($state['path'])) {
            return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'Предпросмотр устарел — выберите файл заново.']);
        }

        try {
            $sheets = TableReader::sheetsFromPath(Storage::disk('local')->path($state['path']), $state['ext']);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'Не удалось прочитать файл — проверьте, что это таблица .ods / .xlsx / .xls / .csv.']);
        }
        if (! $sheets) return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'В файле нет листов.']);

        if ($sheetName === null || ! array_key_exists($sheetName, $sheets)) $sheetName = $import->pickSheet($sheets);
        $result = $import->parse($sheets[$sheetName] ?? []);

        $state['sheet'] = $sheetName;
        $state['rows'] = $result['error'] ? [] : $result['rows'];
        Cache::put($this->cacheKey($sheet, $token), $state, now()->addHour());

        return $this->view($sheet, [
            'result'   => $result,
            'token'    => $token,
            'fileName' => $state['name'],
            'sheets'   => array_map(fn ($rows) => $import->hasHeader($rows), $sheets),   // лист => есть ли колонки этой вкладки
            'sheet'    => $sheetName,
        ]);
    }

    public function save(Request $r, string $sheet)
    {
        $import = $this->importer($sheet);
        $r->validate(['token' => ['required', 'string', 'size:32']]);
        $state = Cache::pull($this->cacheKey($sheet, $r->input('token')));
        if (! $state || empty($state['rows'])) {
            return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'Предпросмотр устарел или уже загружен — выберите файл заново.']);
        }
        Storage::disk('local')->delete($state['path']);

        $res = $import->save($state['rows'], auth()->id());

        return redirect()->route('records.index', $sheet)
            ->with('ok', "Загрузка из файла («{$state['name']}», лист «{$state['sheet']}»): добавлено {$res['created']}, пропущено {$res['skipped']} (дубли и строки с ошибками).");
    }
}
