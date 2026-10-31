<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordController extends Controller
{
    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    private function cfg(string $sheet): array
    {
        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg, 404, "Вкладка {$sheet} не найдена");
        return $cfg;
    }

    private function workerDefaults(): array
    {
        $workers = session('workstation_workers', []);
        $allowed = $this->employeesList();

        $defaults = [
            'worker1' => $workers[0] ?? '',
            'worker2' => $workers[1] ?? '',
            'worker3' => $workers[2] ?? '',
            'worker4' => $workers[3] ?? '',
            'worker5' => $workers[4] ?? '',
        ];

        return array_map(
            fn ($v) => in_array($v, $allowed, true) ? $v : '',
            $defaults
        );
    }

    private function defaults(string $sheet, array $cfg): array
    {
        $defaults = $this->workerDefaults();

        if (array_key_exists('post_number', $cfg['fields'])) {
            $post = session('workstation_post');
            if ($post) {
                $defaults['post_number'] = (int) $post;
            }
        }

        if (array_key_exists('peripheral_number', $cfg['fields'])) {
            $peripheral = session('workstation_peripheral');
            if ($peripheral) {
                $defaults['peripheral_number'] = (int) $peripheral;
            }
        }

        return $defaults;
    }

    private function employeesList(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    private function validateWorkers(array $data): void
    {
        $allowed = $this->employeesList();
        $errors  = [];

        foreach (self::WORKER_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === null || $value === '') {
                continue;
            }

            if (!in_array($value, $allowed, true)) {
                $errors[$field] = 'Выберите исполнителя из списка.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function collectWorkers($record): string
    {
        $names = [];
        foreach (self::WORKER_FIELDS as $field) {
            $val = $record->{$field} ?? null;
            if (!empty($val)) {
                $names[] = $val;
            }
        }
        return implode(', ', $names);
    }

    private function computeHealth($record, array $fields): string
    {
        foreach ($fields as $field) {
            $val = $record->{$field} ?? null;

            if ($val === null || $val === '' || $val !== 'Работает') {
                return 'yellow';
            }
        }

        return 'green';
    }

    public function index(Request $request, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        $query = $cfg['model']::query()->with('creator');

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

        // === Формирование колонок ===
        $listColumns = $cfg['list_columns'];

        // 1. worker1 — всегда, если есть в fields
        if (array_key_exists('worker1', $cfg['fields']) && !in_array('worker1', $listColumns)) {
            $listColumns[] = 'worker1';
        }

        // 2. created_by — всегда
        if (!in_array('created_by', $listColumns)) {
            $listColumns[] = 'created_by';
        }

        // 3. fault_code — если в fields есть, но в list_columns ещё нет
        //    (например, camera_bracket: убран из конфига, добавляем перед health_indicator)
        if (array_key_exists('fault_code', $cfg['fields']) && !in_array('fault_code', $listColumns)) {
            $listColumns[] = 'fault_code';
        }

        // 4. health_indicator — всегда последним
        //    Сначала убираем, если случайно есть в config (защита от дубля)
        $listColumns = array_values(array_filter($listColumns, fn($col) => $col !== 'health_indicator'));

        if (!empty($cfg['health_fields'])) {
            $listColumns[] = 'health_indicator';
        }

        // Обогащение записей
        foreach ($records as $record) {
            $record->workers_list = $this->collectWorkers($record);

            if (!empty($cfg['health_fields'])) {
                $record->health = $this->computeHealth($record, $cfg['health_fields']);
            }
        }

        return view('records.index', compact('sheet', 'cfg', 'records', 'q', 'listColumns'));
    }

    public function show(string $sheet, int $id)
    {
        $cfg = $this->cfg($sheet);
        $record = $cfg['model']::with('creator')->findOrFail($id);
        Gate::authorize('view', $record);

        return view('records.show', compact('sheet', 'cfg', 'record'));
    }

    public function create(string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults      = $this->defaults($sheet, $cfg);
        $employeesList = $this->employeesList();

        return view('records.create', compact('sheet','cfg','defaults','employeesList'));
    }

    public function store(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults = $this->defaults($sheet, $cfg);
        foreach ($defaults as $field => $value) {
            if (!$r->exists($field) && $value !== '' && $value !== null) {
                $r->merge([$field => $value]);
            }
        }

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $this->validateWorkers($data);

        $data['created_by'] = auth()->id();

        try {
            $cfg['model']::create($data);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return back()->withInput()->withErrors(['general' => 'Такая запись уже существует.']);
            }
            throw $e;
        }

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
        $this->validateWorkers($data);

        $data['updated_by'] = auth()->id();

        try {
            $record->update($data);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return back()->withInput()->withErrors(['general' => 'Такая запись уже существует.']);
            }
            throw $e;
        }

        return redirect()->route('records.index', $sheet)->with('ok', 'Запись обновлена');
    }

    public function destroy(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('delete', $record);
        $record->delete();
        return redirect()->route('records.index', $sheet)->with('ok', 'Запись удалена');
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), '1062')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}