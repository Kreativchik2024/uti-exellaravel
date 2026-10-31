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

    private function computeHealth($record, array $fields, array $goodValues = ['Работает']): string
    {
        foreach ($fields as $field) {
            $val = $record->{$field} ?? null;

            if ($val === null || $val === '' || !in_array($val, $goodValues, true)) {
                return 'yellow';
            }
        }

        return 'green';
    }

    /**
     * Формирует health/status_text/worker1/record_date по связанной записи.
     */
    private function buildLookupResult(array $targetCfg, $found): array
    {
        $healthFields = $targetCfg['health_fields'] ?? [];
        $goodValues   = $targetCfg['health_good_values'] ?? ['Работает'];

        $health     = 'green';
        $statusText = 'Всё в порядке';

        if (!empty($healthFields)) {
            $bad = [];
            foreach ($healthFields as $f) {
                $val = $found->{$f} ?? null;
                if ($val === null || $val === '' || !in_array($val, $goodValues, true)) {
                    $label = $targetCfg['fields'][$f]['label'] ?? $f;
                    $bad[] = $label . ': ' . ($val ?: '—');
                }
            }
            if (!empty($bad)) {
                $health = 'red';
                $statusText = implode(', ', array_unique($bad));
            }
        } else {
            $fc = $found->fault_code ?? null;
            if (!empty($fc)) {
                $health = 'red';
                $statusText = 'Неисправность: ' . $fc;
            }
        }

        return [
            'worker1'     => $found->worker1 ?? null,
            'record_date' => $found->record_date?->format('d.m.Y'),
            'health'      => $health,
            'status_text' => $statusText,
        ];
    }

    /**
     * Записывает снимок lookup в $data для сохранения в pgp_records.
     * Вызывается в store() и update().
     */
    private function applyLookupSnapshots(array $cfg, array &$data): void
    {
        foreach ($cfg['fields'] as $name => $f) {
            if (empty($f['lookup'])) continue;

            $prefix = $f['lookup']['prefix'] ?? null;
            if (!$prefix) continue;

            $value = trim((string) ($data[$name] ?? ''));

            // По умолчанию обнуляем снимок
            $data[$prefix . '_worker']      = null;
            $data[$prefix . '_record_date'] = null;
            $data[$prefix . '_health']      = null;
            $data[$prefix . '_status_text'] = null;

            if ($value === '') continue;

            $targetCfg = config("sheets.map.{$f['lookup']['sheet']}");
            if (!$targetCfg) continue;

            $found = $targetCfg['model']::query()
                ->where($f['lookup']['match_by'], $value)
                ->orderByDesc('record_date')
                ->first();

            if (!$found) continue;

            $res = $this->buildLookupResult($targetCfg, $found);

            $data[$prefix . '_worker']      = $res['worker1'];
            $data[$prefix . '_record_date'] = $res['record_date']
                ? \Carbon\Carbon::createFromFormat('d.m.Y', $res['record_date'])->toDateString()
                : null;
            $data[$prefix . '_health']      = $res['health'];
            $data[$prefix . '_status_text'] = $res['status_text'];
        }
    }

    /**
     * Для show()/edit(): читаем снимок из записи, а не live.
     */
    private function buildLookupFromRecord(array $cfg, $record): array
    {
        $result = [];

        foreach ($cfg['fields'] as $name => $f) {
            if (empty($f['lookup'])) continue;

            $prefix = $f['lookup']['prefix'] ?? null;
            if (!$prefix) continue;

            $worker = $record->{$prefix . '_worker'} ?? null;
            $date   = $record->{$prefix . '_record_date'} ?? null;
            $health = $record->{$prefix . '_health'} ?? null;
            $text   = $record->{$prefix . '_status_text'} ?? null;

            if ($worker === null && $date === null && $health === null) {
                continue; // снимка нет — нечего показывать
            }

            $result[$name] = [
                'worker1'     => $worker,
                'record_date' => $date ? \Carbon\Carbon::parse($date)->format('d.m.Y') : null,
                'health'      => $health ?: 'green',
                'status_text' => $text ?: 'Всё в порядке',
            ];
        }

        return $result;
    }

    /**
     * AJAX-lookup: возвращает live-данные (для подсказки при вводе).
     */
    public function lookup(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        $field = (string) $r->query('field');
        $value = trim((string) $r->query('value'));

        if ($value === '' || empty($cfg['fields'][$field]['lookup'])) {
            return response()->json(['found' => false]);
        }

        $lookup = $cfg['fields'][$field]['lookup'];
        $targetCfg = config("sheets.map.{$lookup['sheet']}");
        if (!$targetCfg) {
            return response()->json(['found' => false]);
        }

        $found = $targetCfg['model']::query()
            ->where($lookup['match_by'], $value)
            ->orderByDesc('record_date')
            ->first();

        if (!$found) {
            return response()->json(['found' => false]);
        }

        return response()->json(array_merge(
            ['found' => true],
            $this->buildLookupResult($targetCfg, $found)
        ));
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

        if (array_key_exists('record_date', $cfg['fields'])) {
            $query->orderByDesc('record_date')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $records = $query->paginate(50)->withQueryString();

        $listColumns = $cfg['list_columns'];

        if (array_key_exists('worker1', $cfg['fields']) && !in_array('worker1', $listColumns)) {
            $listColumns[] = 'worker1';
        }
        if (!in_array('created_by', $listColumns)) {
            $listColumns[] = 'created_by';
        }
        if (array_key_exists('fault_code', $cfg['fields']) && !in_array('fault_code', $listColumns)) {
            $listColumns[] = 'fault_code';
        }

        $listColumns = array_values(array_filter($listColumns, fn($col) => $col !== 'health_indicator'));
        if (!empty($cfg['health_fields'])) {
            $listColumns[] = 'health_indicator';
        }

        $goodValues = $cfg['health_good_values'] ?? ['Работает'];

        foreach ($records as $record) {
            $record->workers_list = $this->collectWorkers($record);
            if (!empty($cfg['health_fields'])) {
                $record->health = $this->computeHealth($record, $cfg['health_fields'], $goodValues);
            }
        }

        return view('records.index', compact('sheet', 'cfg', 'records', 'q', 'listColumns'));
    }

    public function show(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::with('creator')->findOrFail($id);
        Gate::authorize('view', $record);

        $lookups = $this->buildLookupFromRecord($cfg, $record);

        return view('records.show', compact('sheet', 'cfg', 'record', 'lookups'));
    }

    public function create(string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults      = $this->defaults($sheet, $cfg);
        $employeesList = $this->employeesList();
        $lookups       = [];

        return view('records.create', compact('sheet','cfg','defaults','employeesList','lookups'));
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

        $this->applyLookupSnapshots($cfg, $data);

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
        $lookups       = $this->buildLookupFromRecord($cfg, $record);

        return view('records.edit', compact('sheet','cfg','record','employeesList','lookups'));
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

        $this->applyLookupSnapshots($cfg, $data);

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