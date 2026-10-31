<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkstationController extends Controller
{
    private function employeesForSelect(): array
    {
        return Employee::query()
            ->active()
            ->whereHas('user', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    public function show(Request $r, StatsService $s)
    {
        $workers       = $this->employeesForSelect();
        $selected      = session('workstation_workers', []);
        $intended      = $r->query('intended', route('dashboard'));
        $employeesList = $workers;

        $posts       = range(1, 30);
        $peripherals = range(1, 50);

        $workplaceType      = session('workstation_type');
        $selectedPost       = session('workstation_post');
        $selectedPeripheral = session('workstation_peripheral');

        $this->markVerified($selected);

        return view('workstation.select', compact(
            'workers', 'selected', 'intended', 'employeesList',
            'posts', 'peripherals', 'workplaceType', 'selectedPost', 'selectedPeripheral'
        ));
    }

    private function markVerified(array $shortNames): void
    {
        if (empty($shortNames)) return;

        $verified = session('verified_workers', []);
        $expires = now()->addHours(3)->timestamp;

        $employees = Employee::whereIn('short_name', $shortNames)->get(['id']);
        foreach ($employees as $e) {
            $verified[$e->id] = $expires;
        }

        session(['verified_workers' => $verified]);
    }

    private function validatePins(array $workerNames, Request $r): void
    {
        if (empty($workerNames)) return;

        $verified = $r->session()->get('verified_workers', []);
        $now = now()->timestamp;
        $errors = [];

        $employees = Employee::whereIn('short_name', $workerNames)->get(['id', 'short_name']);
        $byName = $employees->keyBy('short_name');

        foreach ($workerNames as $name) {
            $e = $byName->get($name);
            if (!$e) continue;
            $ts = $verified[$e->id] ?? null;
            if (!$ts || $ts < $now) {
                $errors[] = "Введите ПИН для «{$e->short_name}».";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['workers' => $errors]);
        }
    }

    public function store(Request $r)
    {
        $allowed = $this->employeesForSelect();

        $validated = $r->validate([
            'workers'    => ['array', 'max:5'],
            'workers.*'  => ['nullable', 'string', Rule::in($allowed)],
            'type'       => ['nullable', Rule::in(['post', 'peripheral'])],
            'post'       => ['nullable', 'integer', 'min:1', 'max:30'],
            'peripheral' => ['nullable', 'integer', 'min:1', 'max:50'],
            'intended'   => ['nullable', 'string'],
        ], [
            'workers.*.in' => 'Выберите исполнителя из списка (только привязанные к email).',
            'post.integer' => 'Пост должен быть числом.',
            'post.min'     => 'Пост от 1 до 30.',
            'post.max'     => 'Пост от 1 до 30.',
            'peripheral.integer' => 'Периферия должна быть числом.',
            'peripheral.min'     => 'Периферия от 1 до 50.',
            'peripheral.max'     => 'Периферия от 1 до 50.',
        ]);

        $rawWorkers = $validated['workers'] ?? [];
        $workers = array_values(array_filter(
            $rawWorkers,
            fn ($w) => $w !== null && $w !== ''
        ));

        // дубли
        $seen = [];
        $dupErrors = [];
        foreach ($rawWorkers as $i => $w) {
            if ($w === null || $w === '') continue;
            if (isset($seen[$w])) {
                $dupErrors["workers.$i"] = "«{$w}» уже выбран в поле Исполнитель " . ($seen[$w] + 1) . '.';
            } else {
                $seen[$w] = $i;
            }
        }
        if (!empty($dupErrors)) throw ValidationException::withMessages($dupErrors);

        // ПИН
        $this->validatePins($workers, $r);

        session(['workstation_workers' => $workers]);

        $type = $validated['type'] ?? null;

        if ($type === 'post' && !empty($validated['post'])) {
            session([
                'workstation_type' => 'post',
                'workstation_post' => (int) $validated['post'],
            ]);
            session()->forget('workstation_peripheral');
        } elseif ($type === 'peripheral' && !empty($validated['peripheral'])) {
            session([
                'workstation_type'       => 'peripheral',
                'workstation_peripheral' => (int) $validated['peripheral'],
            ]);
            session()->forget('workstation_post');
        } else {
            session()->forget(['workstation_type', 'workstation_post', 'workstation_peripheral']);
        }

        return redirect($validated['intended'] ?? route('dashboard'));
    }

    public function skip(Request $r)
    {
        session()->forget([
            'workstation_workers',
            'workstation_type',
            'workstation_post',
            'workstation_peripheral',
        ]);
        return redirect($r->input('intended', route('dashboard')));
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