<?php

namespace App\Http\Controllers;

use App\Services\PgpImport;
use App\Services\SectionContext;
use App\Support\TableReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Загрузка бортов ПГП из таблицы (xlsx / xls / ods / csv): файл → выбор листа и предпросмотр → запись (мастер и админ).
 * Файл хранится до загрузки (час), чтобы переключать лист без повторного выбора файла.
 */
class PgpImportController extends Controller
{
    private const DIR = 'pgp-import';
    private const EXT = ['xlsx', 'xls', 'ods', 'csv'];

    private function ensureSheet(): void
    {
        abort_unless(isset(app(SectionContext::class)->sheets()['pgp']), 404);
    }

    private function cacheKey(string $token): string
    {
        return 'pgp-import:' . auth()->id() . ':' . $token;
    }

    public function form()
    {
        $this->ensureSheet();
        return view('records.pgp-import', ['result' => null, 'token' => null]);
    }

    /** Загрузка файла: сохраняем его и показываем предпросмотр подходящего листа. */
    public function upload(Request $r, PgpImport $import)
    {
        $this->ensureSheet();
        $r->validate(['file' => ['required', 'file', 'max:20480']]);
        $file = $r->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::EXT, true)) {
            return back()->withErrors(['file' => 'Нужен файл .ods, .xlsx, .xls или .csv.']);
        }

        // старые файлы (брошенные предпросмотры) — убираем
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $old) {
            if ($disk->lastModified($old) < now()->subDay()->getTimestamp()) $disk->delete($old);
        }

        $token = Str::random(32);
        $path = $file->storeAs(self::DIR, auth()->id() . '-' . $token . '.' . $ext, 'local');
        Cache::put($this->cacheKey($token), ['path' => $path, 'ext' => $ext, 'name' => $file->getClientOriginalName()], now()->addHour());

        return $this->preview($import, $token, null);
    }

    /** Переключение листа в уже загруженном файле. */
    public function sheet(Request $r, PgpImport $import)
    {
        $this->ensureSheet();
        $r->validate(['token' => ['required', 'string', 'size:32'], 'sheet' => ['required', 'string', 'max:200']]);

        return $this->preview($import, $r->input('token'), $r->input('sheet'));
    }

    private function preview(PgpImport $import, string $token, ?string $sheetName)
    {
        $state = Cache::get($this->cacheKey($token));
        if (! $state || ! Storage::disk('local')->exists($state['path'])) {
            return redirect()->route('pgp.import.form')->withErrors(['file' => 'Предпросмотр устарел — выберите файл заново.']);
        }

        try {
            $sheets = TableReader::sheetsFromPath(Storage::disk('local')->path($state['path']), $state['ext']);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('pgp.import.form')->withErrors(['file' => 'Не удалось прочитать файл — проверьте, что это таблица .ods / .xlsx / .xls / .csv.']);
        }
        if (! $sheets) return redirect()->route('pgp.import.form')->withErrors(['file' => 'В файле нет листов.']);

        if ($sheetName === null || ! array_key_exists($sheetName, $sheets)) $sheetName = $import->pickSheet($sheets);
        $result = $import->parse($sheets[$sheetName] ?? []);

        $state['sheet'] = $sheetName;
        $state['rows'] = $result['error'] ? [] : $result['rows'];
        Cache::put($this->cacheKey($token), $state, now()->addHour());

        return view('records.pgp-import', [
            'result'   => $result,
            'token'    => $token,
            'fileName' => $state['name'],
            'sheets'   => array_map(fn ($rows) => $import->hasHeader($rows), $sheets),   // название => похож ли на журнал ПГП
            'sheet'    => $sheetName,
        ]);
    }

    public function save(Request $r, PgpImport $import)
    {
        $this->ensureSheet();
        $r->validate(['token' => ['required', 'string', 'size:32']]);
        $state = Cache::pull($this->cacheKey($r->input('token')));
        if (! $state || empty($state['rows'])) {
            return redirect()->route('pgp.import.form')->withErrors(['file' => 'Предпросмотр устарел или уже загружен — выберите файл заново.']);
        }
        Storage::disk('local')->delete($state['path']);

        $res = $import->save($state['rows'], auth()->id());

        return redirect()->route('records.index', 'pgp')
            ->with('ok', "Загрузка из файла («{$state['name']}», лист «{$state['sheet']}»): добавлено {$res['created']}, пропущено {$res['skipped']} (дубли и строки с ошибками).");
    }
}
