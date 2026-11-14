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

    /**
     * Большой журнал на слабом сервере (Orange Pi) разбирается и пишется дольше стандартных 30 секунд
     * и не помещается в 128 МБ — лимиты поднимаются только для запросов загрузки, не для всего сайта.
     */
    private function longRequest(): void
    {
        @set_time_limit(600);
        $limit = (string) config('app.import_memory_limit', '384M');
        $current = ini_get('memory_limit');
        if ($current !== '-1' && $this->bytes($current) < $this->bytes($limit)) @ini_set('memory_limit', $limit);
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    /** Разобранные строки предпросмотра — файлом рядом с загруженным (в кеше десятки тысяч строк не держим). */
    private function rowsPath(array $state): string
    {
        return $state['path'] . '.rows';
    }

    public function upload(Request $r, string $sheet)
    {
        $this->longRequest();
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
        $this->longRequest();
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

        // Листы читаются по одному: для выбора листа — только первые строки каждого, целиком — только выбранный.
        $file = Storage::disk('local')->path($state['path']);
        try {
            $heads = [];
            foreach (TableReader::sheetNamesFromPath($file, $state['ext']) as $name) {
                $heads[(string) $name] = TableReader::sheetFromPath($file, $state['ext'], (string) $name, 15);
            }
            if (! $heads) return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'В файле нет листов.']);

            if ($sheetName === null || ! array_key_exists($sheetName, $heads)) $sheetName = $import->pickSheet($heads);
            $rows = TableReader::sheetFromPath($file, $state['ext'], $sheetName);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'Не удалось прочитать файл — проверьте, что это таблица .ods / .xlsx / .xls / .csv.']);
        }
        $result = $import->parse($rows);
        unset($rows);

        $state['sheet'] = $sheetName;
        $state['count'] = $result['error'] ? 0 : count($result['rows']);
        Storage::disk('local')->put($this->rowsPath($state), serialize($result['error'] ? [] : $result['rows']));
        Cache::put($this->cacheKey($sheet, $token), $state, now()->addHour());

        return $this->view($sheet, [
            'result'   => $result,
            'token'    => $token,
            'fileName' => $state['name'],
            'sheets'   => array_map(fn ($rows) => $import->hasHeader($rows), $heads),   // лист => есть ли колонки этой вкладки
            'sheet'    => $sheetName,
        ]);
    }

    public function save(Request $r, string $sheet)
    {
        $import = $this->importer($sheet);
        $r->validate(['token' => ['required', 'string', 'size:32']]);
        $this->longRequest();
        $state = Cache::pull($this->cacheKey($sheet, $r->input('token')));
        $disk = Storage::disk('local');
        $rows = $state && $disk->exists($this->rowsPath($state))
            ? unserialize($disk->get($this->rowsPath($state)), ['allowed_classes' => false]) : [];
        if (! $state || ! $rows) {
            return redirect()->route('records.import.form', $sheet)->withErrors(['file' => 'Предпросмотр устарел или уже загружен — выберите файл заново.']);
        }
        $disk->delete([$state['path'], $this->rowsPath($state)]);

        $res = $import->save($rows, auth()->id());

        return redirect()->route('records.index', $sheet)
            ->with('ok', "Загрузка из файла («{$state['name']}», лист «{$state['sheet']}»): добавлено {$res['created']}, пропущено {$res['skipped']} (дубли и строки с ошибками).");
    }
}
