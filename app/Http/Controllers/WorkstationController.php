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

        return view('workstation.select', compact('workers', 'selected', 'intended', 'employeesList'));
    }

    public function store(Request $r)
    {
        $allowed = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        $validated = $r->validate([
            'workers'   => ['array', 'max:5'],
            'workers.*' => ['nullable', 'string', Rule::in($allowed)],
            'intended'  => ['nullable', 'string'],
        ], [
            'workers.*.in' => 'Выберите исполнителя из списка.',
        ]);

        $workers = array_values(array_filter(
            $validated['workers'] ?? [],
            fn ($w) => $w !== null && $w !== ''
        ));

        if (empty($workers)) {
            return back()
                ->withInput()
                ->withErrors(['workers' => 'Выберите хотя бы одного исполнителя из списка.']);
        }

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