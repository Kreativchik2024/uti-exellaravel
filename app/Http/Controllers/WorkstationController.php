<?php

namespace App\Http\Controllers;

use App\Services\EmployeeDirectory;
use Illuminate\Http\Request;

class WorkstationController extends Controller
{
    public function __construct(private EmployeeDirectory $employees) {}

    /**
     * Защита от открытого редиректа: разрешаем только адреса этого же сайта.
     */
    private function safeIntended(mixed $url): string
    {
        $fallback = route('dashboard');
        if (!is_string($url) || $url === '') return $fallback;

        // Относительный путь вида "/records/pgp" (но не "//evil.com" и не "/\evil.com")
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\')) {
            return $url;
        }

        $target = parse_url($url);
        $app    = parse_url(url('/'));
        if (
            $target !== false
            && in_array(strtolower($target['scheme'] ?? ''), ['http', 'https'], true)
            && strcasecmp($target['host'] ?? '', $app['host'] ?? '') === 0
            && ($target['port'] ?? null) === ($app['port'] ?? null)
        ) {
            return $url;
        }

        return $fallback;
    }

    public function show(Request $request)
    {
        $intended = $this->safeIntended($request->query('intended'));
        $selected = session('workstation_workers', []);
        $workplaceType = session('workstation_type', 'post');
        $selectedPost = session('workstation_post');
        $selectedPeripheral = session('workstation_peripheral');
        $posts = range(1, 30);
        $peripherals = range(1, 50);

        $employeesList = $this->employees->namesForSelect();

        // ПИН-коды, подтверждённые в сессии
        $verifiedMap = $this->employees->verifiedMap();

        // Сотрудники без ПИН
        [, $employeesWithoutPin] = $this->employees->splitByPin();

        $canManageWorkers = auth()->check() && auth()->user()->canManageContent();

        // Имя текущего пользователя (владелец записи)
        $currentEmployeeName = $this->employees->currentEmployeeName();

        // АВТО-ПОДСТАНОВКА: если сессия пуста, но есть текущий пользователь — ставим его первым
        $selected = array_values(array_filter((array) $selected, fn($v) => is_string($v) && trim($v) !== ''));
        $selected = array_map('trim', $selected);

        if (empty($selected) && $currentEmployeeName && in_array($currentEmployeeName, $employeesList, true)) {
            $selected = [$currentEmployeeName];
        }

        return view('workstation.select', compact(
            'intended', 'selected', 'workplaceType', 'selectedPost', 'selectedPeripheral',
            'posts', 'peripherals', 'employeesList',
            'verifiedMap', 'employeesWithoutPin', 'canManageWorkers', 'currentEmployeeName'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'workers' => 'array',
            'workers.*' => 'nullable|string|max:120',
            'type' => 'nullable|in:post,peripheral',
            'post' => 'nullable|integer|min:1|max:30',
            'peripheral' => 'nullable|integer|min:1|max:50',
        ]);

        $workers = array_values(array_filter(
            (array) $request->input('workers', []),
            fn($v) => is_string($v) && trim($v) !== ''
        ));
        $workers = array_map('trim', $workers);

        session([
            'workstation_workers' => $workers,
            'workstation_type' => $request->input('type', 'post'),
            'workstation_post' => $request->input('post'),
            'workstation_peripheral' => $request->input('peripheral'),
        ]);

        return redirect($this->safeIntended($request->input('intended')));
    }

    public function skip(Request $request)
    {
        session([
            'workstation_workers' => [],
            'workstation_type' => 'post',
            'workstation_post' => null,
            'workstation_peripheral' => null,
        ]);

        return redirect($this->safeIntended($request->input('intended')));
    }

    public function clear()
    {
        session()->forget([
            'workstation_workers',
            'workstation_type',
            'workstation_post',
            'workstation_peripheral',
        ]);

        return redirect()->route('workstation.show');
    }
}