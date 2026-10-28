<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
    private function cfg(string $sheet): array
    {
        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg, 404, "Вкладка {$sheet} не найдена");
        return $cfg;
    }

    /**
     * Значения по умолчанию для полей worker1..5 из сессии.
     */
    private function workerDefaults(): array
    {
        $workers = session('workstation_workers', []);

        return [
            'worker1' => $workers[0] ?? '',
            'worker2' => $workers[1] ?? '',
            'worker3' => $workers[2] ?? '',
            'worker4' => $workers[3] ?? '',
            'worker5' => $workers[4] ?? '',
        ];
    }

    /**
     * Список активных сотрудников для подсказок в полях worker1..5.
     *
     * @return string[]
     */
    private function employeesList(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    public function index(Request $request, string $sheet)
    {
        $cfg = $this->cfg($sheet);

        $query = $cfg['model']::query();

        $q = trim($request->input('q', ''));
        if ($q !== '') {
            $searchableFields = [];
            foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
                if (in_array($fieldCfg['type'], ['text', 'textarea', 'string'])) {
                    $searchableFields[] = $fieldName;
                }
            }

            if (!empty($searchableFields)) {
                $query->where(function ($w) use ($searchableFields, $q) {
                    foreach ($searchableFields as $field) {
                        $w->orWhere($field, 'like', "%{$q}%");
                    }
                });
            }
        }

        $hasRecordDate = array_key_exists('record_date', $cfg['fields']);

        if ($hasRecordDate) {
            $query->orderByDesc('record_date')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $records = $query->paginate(50)->withQueryString();

        return view('records.index', compact('sheet','cfg','records', 'q'));
    }

    public function create(string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults      = $this->workerDefaults();
        $employeesList = $this->employeesList();

        return view('records.create', compact('sheet','cfg','defaults','employeesList'));
    }

    public function store(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        // Автозаполнение исполнителей, если поле вообще не пришло в запросе
        $defaults = $this->workerDefaults();
        foreach ($defaults as $field => $value) {
            if (!$r->exists($field) && $value !== '') {
                $r->merge([$field => $value]);
            }
        }

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $data['created_by'] = auth()->id();

        $cfg['model']::create($data);

        return redirect()->route('records.index', $sheet)->with('ok', 'Запись добавлена');
    }

    public function edit(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);

        $employeesList = $this->employeesList();

        return view('records.edit', compact('sheet','cfg','record','employeesList'));
    }

    public function update(Request $r, string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $data['updated_by'] = auth()->id();

        $record->update($data);

        return redirect()->route('records.index', $sheet)->with('ok', 'Запись обновлена');
    }

    public function destroy(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('delete', $record);
        $record->delete();
        return back()->with('ok', 'Запись удалена');
    }
}