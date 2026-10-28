<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\StatsService;
use Illuminate\Http\Request;

class WorkstationController extends Controller
{
    public function show(Request $r, StatsService $s)
    {
        $workers  = $s->listWorkers();
        $selected = session('workstation_workers', []);
        $intended = $r->query('intended', route('dashboard'));

        // Активные сотрудники из справочника для datalist-подсказок
        $employeesList = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        return view('workstation.select', compact('workers', 'selected', 'intended', 'employeesList'));
    }

    public function store(Request $r)
    {
        $validated = $r->validate([
            'workers'   => ['array', 'max:5'],
            'workers.*' => ['nullable', 'string', 'max:255'],
            'intended'  => ['nullable', 'string'],
        ]);

        $workers = array_values(array_filter(
            $validated['workers'] ?? [],
            fn($w) => $w !== null && $w !== ''
        ));

        session(['workstation_workers' => $workers]);

        return redirect($validated['intended'] ?? route('dashboard'));
    }

    public function skip(Request $r)
    {
        session(['workstation_workers' => []]);
        return redirect($r->input('intended', route('dashboard')));
    }

    public function clear()
    {
        session()->forget('workstation_workers');
        return redirect()->route('workstation.show');
    }
}