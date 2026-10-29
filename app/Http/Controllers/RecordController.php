<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class RecordController extends Controller
{
    private function cfg(string $sheet): array
    {
        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg, 404, "Вкладка {$sheet} не найдена");
        return $cfg;
    }

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

        $defaults = $this->workerDefaults();
        foreach ($defaults as $field => $value) {
            if (!$r->exists($field) && $value !== '') {
                $r->merge([$field => $value]);
            }
        }

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $data['created_by'] = auth()->id();

        // Превентивная проверка уникальности до вставки
        if ($dup = $this->findDuplicate($cfg['model'], $data)) {
            return back()
                ->withInput()
                ->withErrors(['general' => $this->duplicateMessage($dup)]);
        }

        try {
            $cfg['model']::create($data);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return back()
                    ->withInput()
                    ->withErrors(['general' => 'Такая запись уже существует. Измените дату или исполнителя.']);
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
        $data['updated_by'] = auth()->id();

        // Превентивная проверка — исключая саму редактируемую запись
        if ($dup = $this->findDuplicate($cfg['model'], $data, $id)) {
            return back()
                ->withInput()
                ->withErrors(['general' => $this->duplicateMessage($dup)]);
        }

        try {
            $record->update($data);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return back()
                    ->withInput()
                    ->withErrors(['general' => 'Такая запись уже существует. Измените дату или исполнителя.']);
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
        return back()->with('ok', 'Запись удалена');
    }

    /**
     * Ищет в таблице модели уникальный индекс, который уже занят переданными $data.
     *
     * @return array{name:string, columns:string[], values:array<string,mixed>}|null
     */
    private function findDuplicate(string $modelClass, array $data, ?int $excludeId = null): ?array
    {
        $model = new $modelClass;
        $table = $model->getTable();

        $indexes = collect(Schema::getIndexes($table))->where('unique', true);

        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                continue; // автоинкрементный id не считаем
            }

            $columns = $index['columns'];

            // Все колонки индекса должны присутствовать в $data
            foreach ($columns as $col) {
                if (!array_key_exists($col, $data)) {
                    continue 2;
                }
            }

            $query = $modelClass::query();
            foreach ($columns as $col) {
                $query->where($col, $data[$col]);
            }

            if ($excludeId !== null) {
                $query->where($model->getKeyName(), '!=', $excludeId);
            }

            if ($query->exists()) {
                return [
                    'name'    => $index['name'],
                    'columns' => $columns,
                    'values'  => array_intersect_key($data, array_flip($columns)),
                ];
            }
        }

        return null;
    }

    /**
     * Человеческое сообщение по составу уникального индекса.
     */
    private function duplicateMessage(array $dup): string
    {
        $labels = [
            'record_date' => 'дата',
            'worker1'     => 'исполнитель',
            'worker2'     => 'исполнитель 2',
            'worker3'     => 'исполнитель 3',
            'worker4'     => 'исполнитель 4',
            'worker5'     => 'исполнитель 5',
        ];

        $pairs = [];
        foreach ($dup['values'] as $col => $val) {
            $label = $labels[$col] ?? $col;
            $pairs[] = "{$label}: {$val}";
        }

        return 'Такая запись уже существует (' . implode(', ', $pairs) . '). Измените дату или исполнителя.';
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), '1062')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}