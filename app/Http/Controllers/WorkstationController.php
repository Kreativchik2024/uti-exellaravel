<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkstationController extends Controller
{
    public function show(Request $r, StatsService $s)
    {
        $workers  = $s->listWorkers();
        $selected = session('workstation_workers', []);
        $intended = $r->query('intended', route('dashboard'));

        $employeesList = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        $posts       = range(1, 30);
        $peripherals = range(1, 50);

        $workplaceType      = session('workstation_type');
        $selectedPost       = session('workstation_post');
        $selectedPeripheral = session('workstation_peripheral');

        return view('workstation.select', compact(
            'workers', 'selected', 'intended', 'employeesList',
            'posts', 'peripherals', 'workplaceType', 'selectedPost', 'selectedPeripheral'
        ));
    }

    public function store(Request $r)
    {
        $allowed = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        $validated = $r->validate([
            'workers'    => ['array', 'max:5'],
            'workers.*'  => ['nullable', 'string', Rule::in($allowed)],
            'type'       => ['nullable', Rule::in(['post', 'peripheral'])],
            'post'       => ['nullable', 'integer', 'min:1', 'max:30'],
            'peripheral' => ['nullable', 'integer', 'min:1', 'max:50'],
            'intended'   => ['nullable', 'string'],
        ], [
            'workers.*.in' => 'Выберите исполнителя из списка.',
            'post.integer' => 'Пост должен быть числом.',
            'post.min'     => 'Пост от 1 до 30.',
            'post.max'     => 'Пост от 1 до 30.',
            'peripheral.integer' => 'Периферия должна быть числом.',
            'peripheral.min'     => 'Периферия от 1 до 50.',
            'peripheral.max'     => 'Периферия от 1 до 50.',
        ]);

        $workers = array_values(array_filter(
            $validated['workers'] ?? [],
            fn ($w) => $w !== null && $w !== ''
        ));

        session(['workstation_workers' => $workers]);

        $type = $validated['type'] ?? null;

        // ВАЖНО: сохраняем ТОЛЬКО ЧИСЛО, без префикса «Пост» / «Периферия»
        if ($type === 'post' && !empty($validated['post'])) {
            session([
                'workstation_type' => 'post',
                'workstation_post' => (int) $validated['post'],   // <-- int
            ]);
            session()->forget('workstation_peripheral');
        } elseif ($type === 'peripheral' && !empty($validated['peripheral'])) {
            session([
                'workstation_type'       => 'peripheral',
                'workstation_peripheral' => (int) $validated['peripheral'], // <-- int
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