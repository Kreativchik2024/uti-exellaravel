<?php

namespace App\Http\Controllers;

use App\Services\CommProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/** Администратор: профиль прошивки модулей связи — значения инструкции и бэкапы .msconf. */
class CommProfileController extends Controller
{
    public function __construct(private CommProfile $profile) {}

    public function index()
    {
        return view('comm-profile.index', [
            'groups'   => CommProfile::PARAMS,
            'settings' => $this->profile->settings(),
            'msconfs'  => $this->profile->msconfs(),
        ]);
    }

    public function saveParams(Request $r)
    {
        $rules = [];
        foreach (CommProfile::flatParams() as $k => $p) {
            $rules[$k] = match ($p['type']) {
                'bool' => ['nullable', 'boolean'],
                'int'  => ['required', 'integer', 'min:0', 'max:100000'],
                'num'  => ['required', 'numeric', 'min:0', 'max:100000'],
                default => ['nullable', 'string', 'max:200'],
            };
        }
        $rules['factory_ip'] = ['required', 'ip'];
        $rules['net_prefix'] = ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/'];
        $rules['nw_mask'] = ['required', 'ip'];
        $rules['blocked'] = ['nullable', 'regex:/^[\d\s,;-]*$/'];
        $data = $r->validate($rules, ['net_prefix.regex' => 'Сеть — три числа через точку, например 192.168.1',
                                      'blocked.regex' => 'Запрещённые номера: числа и диапазоны через запятую']);
        $this->profile->saveParams($data, Auth::user()?->name);

        return back()->with('status', 'Параметры сохранены — флешеры возьмут их при следующем запуске потока');
    }

    public function uploadMsconf(Request $r)
    {
        $r->validate(['msconf' => ['required', 'file', 'max:2048', 'extensions:msconf']],
                     ['msconf.extensions' => 'Нужен файл .msconf (бэкап настроек модуля)']);
        try {
            $name = $this->profile->storeMsconf($r->file('msconf'), $r->boolean('make_active'), Auth::user()?->name);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['msconf' => $e->getMessage()]);
        }

        return back()->with('status', "Файл {$name} загружен");
    }

    public function activate(string $name)
    {
        $this->profile->activate($name, Auth::user()?->name);

        return back()->with('status', "Рабочий бэкап: {$name}");
    }

    public function download(string $name)
    {
        $path = $this->profile->msconfPath($name);
        abort_unless($path, 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    public function destroy(string $name)
    {
        $this->profile->deleteMsconf($name, Auth::user()?->name);

        return back()->with('status', "Файл {$name} удалён");
    }
}
