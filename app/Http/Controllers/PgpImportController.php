<?php

namespace App\Http\Controllers;

use App\Services\PgpImport;
use App\Services\SectionContext;
use App\Support\TableReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Загрузка бортов ПГП из Excel-журнала: файл → предпросмотр → запись (мастер и админ). */
class PgpImportController extends Controller
{
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

    public function preview(Request $r, PgpImport $import)
    {
        $this->ensureSheet();
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt']]);

        try {
            $sheet = TableReader::rows($r->file('file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Не удалось прочитать файл: ' . $e->getMessage()]);
        }
        $result = $import->parse($sheet);
        if ($result['error']) return back()->withErrors(['file' => $result['error']]);

        $token = Str::random(32);
        Cache::put($this->cacheKey($token), $result['rows'], now()->addHour());

        return view('records.pgp-import', ['result' => $result, 'token' => $token,
                                           'fileName' => $r->file('file')->getClientOriginalName()]);
    }

    public function save(Request $r, PgpImport $import)
    {
        $this->ensureSheet();
        $r->validate(['token' => ['required', 'string', 'size:32']]);
        $rows = Cache::pull($this->cacheKey($r->input('token')));
        if (! $rows) {
            return redirect()->route('pgp.import.form')->withErrors(['file' => 'Предпросмотр устарел или уже загружен — выберите файл заново.']);
        }

        $res = $import->save($rows, auth()->id());

        return redirect()->route('records.index', 'pgp')
            ->with('ok', "Загрузка из Excel: добавлено {$res['created']}, пропущено {$res['skipped']} (дубли и строки с ошибками).");
    }
}
