<?php

namespace App\Http\Controllers;

use App\Services\CommLicenses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Администратор: лицензии модулей связи (партии .dat + .xlsx), которые скачивает MANET Flasher. */
class CommLicenseController extends Controller
{
    public function __construct(private CommLicenses $lic) {}

    public function index(Request $r)
    {
        $sn = trim((string) $r->input('sn'));
        $found = $sn !== '' ? $this->lic->findSerial($sn) : null;

        return view('comm-licenses.index', ['batches' => $this->lic->batches(), 'sn' => $sn, 'found' => $found]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'batch' => ['nullable', 'string', 'max:80'],
            'dat'   => ['nullable', 'file', 'max:10240', 'extensions:dat'],
            'xlsx'  => ['nullable', 'file', 'max:10240', 'extensions:xlsx'],
        ], [
            'dat.extensions'  => 'Файл лицензии — .dat',
            'xlsx.extensions' => 'Таблица серийников — .xlsx',
        ]);
        abort_unless($r->hasFile('dat') || $r->hasFile('xlsx'), 422, 'Выберите файл лицензии и/или таблицу');

        $name = $data['batch'] ?? null;
        if (!filled($name)) {   // имя партии — из имени таблицы (… Box NO.F1#~F20#) или лицензии
            $name = pathinfo(($r->file('xlsx') ?? $r->file('dat'))->getClientOriginalName(), PATHINFO_FILENAME);
        }
        $batch = $this->lic->store($name, $r->file('dat'), $r->file('xlsx'));

        return redirect()->route('comm-licenses.index')->with('status', "Партия «{$batch}» загружена");
    }

    public function download(string $batch, string $file)
    {
        $path = $this->lic->fileFor($batch . '/' . $file);
        abort_unless($path, 404);

        return Storage::disk('local')->download($path, $file);
    }

    public function destroy(string $batch)
    {
        $this->lic->delete($batch);

        return redirect()->route('comm-licenses.index')->with('status', "Партия «{$batch}» удалена");
    }
}
