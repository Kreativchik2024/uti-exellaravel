<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;

class WorkstationController extends Controller
{
    public function show(Request $request)
    {
        $intended = (string) $request->query('intended', route('dashboard'));
        $selected = session('workstation_workers', []);
        $workplaceType = session('workstation_type', 'post');
        $selectedPost = session('workstation_post');
        $selectedPeripheral = session('workstation_peripheral');
        $posts = range(1, 30);
        $peripherals = range(1, 50);

        $employeesList = Employee::query()
            ->active()
            ->whereHas('user', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->orderBy('short_name')
            ->pluck('short_name')
            ->map(fn($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // ПИН-коды, подтверждённые в сессии
        $verified = session('verified_workers', []);
        $now = now()->timestamp;
        $verifiedMap = [];
        foreach ($verified as $id => $expires) {
            if ((int) $expires > $now) {
                $emp = Employee::find($id);
                if ($emp && !empty($emp->short_name)) {
                    $verifiedMap[trim($emp->short_name)] = (int) $expires;
                }
            }
        }

        // Сотрудники без ПИН
        $employeesWithoutPin = Employee::query()->active()->with('user')->get()
            ->filter(fn($e) => !$e->user || !method_exists($e->user, 'hasPin') || !$e->user->hasPin())
            ->pluck('short_name')
            ->map(fn($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $canManageWorkers = auth()->check() && auth()->user()->canManageContent();

        // Имя текущего пользователя (владелец записи) — ВЫЧИСЛЯЕМ ДО fallback
        $currentEmployeeName = null;
        if (auth()->check()) {
            $user = auth()->user();
            if ($user->employee && !empty($user->employee->short_name)) {
                $currentEmployeeName = trim((string) $user->employee->short_name);
            } elseif (isset($user->employee_id)) {
                $emp = Employee::find($user->employee_id);
                if ($emp && !empty($emp->short_name)) {
                    $currentEmployeeName = trim((string) $emp->short_name);
                }
            }
        }

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

        return redirect((string) $request->input('intended', route('dashboard')));
    }

    public function skip(Request $request)
    {
        session([
            'workstation_workers' => [],
            'workstation_type' => 'post',
            'workstation_post' => null,
            'workstation_peripheral' => null,
        ]);

        return redirect((string) $request->input('intended', route('dashboard')));
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