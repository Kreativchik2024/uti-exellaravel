<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    private function canEditWorkers(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    private function lookupPrefix(string $fieldName, array $lookup): ?string
    {
        if (!empty($lookup['prefix'])) return $lookup['prefix'];
        if (preg_match('/^(.+?)_(number|serial|code|id)$/', $fieldName, $m)) return $m[1];
        return null;
    }

    private function withSnapshotRules(array $cfg, array $rules): array
    {
        foreach ($cfg['fields'] as $name => $f) {
            if (empty($f['lookup'])) continue;
            $prefix = $this->lookupPrefix($name, $f['lookup']);
            if (!$prefix) continue;
            $rules[$prefix.'_worker']      = 'nullable|string|max:500';
            $rules[$prefix.'_record_date'] = 'nullable|date';
            $rules[$prefix.'_health']      = 'nullable|string|max:20';
            $rules[$prefix.'_status_text'] = 'nullable|string|max:500';
        }
        return $rules;
    }

    private function employeesForSelect(): array
    {
        return Employee::query()
            ->active()
            ->whereHas('user', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    private function workerDefaults(): array
    {
        $workers = session('workstation_workers', []);
        $allowed = $this->employeesForSelect();

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
            if ($post) $defaults['post_number'] = (int) $post;
        }
        if (array_key_exists('peripheral_number', $cfg['fields'])) {
            $peripheral = session('workstation_peripheral');
            if ($peripheral) $defaults['peripheral_number'] = (int) $peripheral;
        }
        return $defaults;
    }

    private function validateWorkers(array $data): void
    {
        $allActive = $this->employeesForSelect();
        $errors    = [];
        $seen      = [];

        foreach (self::WORKER_FIELDS as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = $data[$field];
            if ($value === null || $value === '') continue;

            if (isset($seen[$value])) {
                $errors[$field] = "Этот исполнитель уже указан в поле «{$seen[$value]}».";
                continue;
            }
            $seen[$value] = $field;

            if (!in_array($value, $allActive, true)) {
                $errors[$field] = 'Сотрудник неактивен или не привязан к учётной записи (email).';
            }
        }

        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function collectWorkers($record): string
    {
        $names = [];
        foreach (self::WORKER_FIELDS as $field) {
            $val = $record->{$field} ?? null;
            if (!empty($val)) $names[] = $val;
        }
        return implode(', ', $names);
    }

    private function computeHealth($record, array $fields, array $goodValues = ['Работает']): string
    {
        foreach ($fields as $field) {
            $val = $record->{$field} ?? null;
            if ($val === null || $val === '' || !in_array($val, $goodValues, true)) return 'yellow';
        }
        return 'green';
    }

    private function buildLookupResult(array $targetCfg, $found): array
    {
        $healthFields = $targetCfg['health_fields'] ?? [];
        $goodValues   = $targetCfg['health_good_values'] ?? ['Работает'];

        $health = 'green'; $statusText = 'Всё в порядке';

        if (!empty($healthFields)) {
            $bad = [];
            foreach ($healthFields as $f) {
                $val = $found->{$f} ?? null;
                if ($val === null || $val === '' || !in_array($val, $goodValues, true)) {
                    $label = $targetCfg['fields'][$f]['label'] ?? $f;
                    $bad[] = $label . ': ' . ($val ?: '—');
                }
            }
            if (!empty($bad)) { $health = 'red'; $statusText = implode(', ', array_unique($bad)); }
        } else {
            $fc = $found->fault_code ?? null;
            if (!empty($fc)) { $health = 'red'; $statusText = 'Неисправность: ' . $fc; }
        }

        $workersAll = [];
        foreach (self::WORKER_FIELDS as $wf) {
            $v = $found->{$wf} ?? null;
            if (!empty($v)) $workersAll[] = $v;
        }

        $recordDateDisplay = null; $recordDateIso = null;
        if (!empty($found->record_date)) {
            try {
                $dt = $found->record_date instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($found->record_date)
                    : \Carbon\Carbon::parse($found->record_date);
                $recordDateDisplay = $dt->format('d.m.Y');
                $recordDateIso     = $dt->toDateString();
            } catch (\Throwable $e) {}
        }

        return [
            'worker1'         => $workersAll[0] ?? null,
            'workers'         => implode(', ', $workersAll),
            'record_date'     => $recordDateDisplay,
            'record_date_iso' => $recordDateIso,
            'health'          => $health,
            'status_text'     => $statusText,
        ];
    }

    private function applyLookupSnapshots(array $cfg, array &$data): void
    {
        foreach ($cfg['fields'] as $name => $f) {
            if (empty($f['lookup'])) continue;
            $prefix = $this->lookupPrefix($name, $f['lookup']);
            if (!$prefix) continue;

            $value = trim((string) ($data[$name] ?? ''));

            if ($value === '') {
                $data[$prefix.'_worker']      = null;
                $data[$prefix.'_record_date'] = null;
                $data[$prefix.'_health']      = null;
                $data[$prefix.'_status_text'] = null;
                continue;
            }

            $incomingWorker = $data[$prefix.'_worker']      ?? null;
            $incomingHealth = $data[$prefix.'_health']      ?? null;
            $incomingDate   = $data[$prefix.'_record_date'] ?? null;

            if (!empty($incomingWorker) && in_array($incomingHealth, ['green','red'], true)) {
                if (!empty($incomingDate)) {
                    try { $data[$prefix.'_record_date'] = \Carbon\Carbon::parse($incomingDate)->toDateString(); }
                    catch (\Throwable $e) { $data[$prefix.'_record_date'] = null; }
                }
                continue;
            }

            $targetCfg = config("sheets.map.{$f['lookup']['sheet']}");
            if (!$targetCfg) continue;

            $found = $targetCfg['model']::query()
                ->where($f['lookup']['match_by'], $value)
                ->orderByDesc('record_date')->first();

            Log::info('[lookup snapshot]', [
                'field' => $name, 'prefix' => $prefix, 'value' => $value,
                'found_id' => $found ? $found->getKey() : null,
            ]);

            if (!$found) continue;

            $res = $this->buildLookupResult($targetCfg, $found);
            $data[$prefix.'_worker']      = $res['workers'] ?: null;
            $data[$prefix.'_record_date'] = $res['record_date_iso'] ?? null;
            $data[$prefix.'_health']      = $res['health'];
            $data[$prefix.'_status_text'] = $res['status_text'];
        }
    }

    private function buildLookupFromRecord(array $cfg, $record): array
    {
        $result = [];
        foreach ($cfg['fields'] as $name => $f) {
            if (empty($f['lookup'])) continue;
            $prefix = $this->lookupPrefix($name, $f['lookup']);
            if (!$prefix) continue;

            $worker = $record->{$prefix . '_worker'} ?? null;
            $date   = $record->{$prefix . '_record_date'} ?? null;
            $health = $record->{$prefix . '_health'} ?? null;
            $text   = $record->{$prefix . '_status_text'} ?? null;

            if ($worker === null && $date === null && $health === null) continue;

            $result[$name] = [
                'worker1'     => $worker,
                'workers'     => $worker,
                'record_date' => $date ? \Carbon\Carbon::parse($date)->format('d.m.Y') : null,
                'health'      => $health ?: 'green',
                'status_text' => $text ?: 'Всё в порядке',
            ];
        }
        return $result;
    }

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
        if (!$targetCfg) return response()->json(['found' => false]);

        $found = $targetCfg['model']::query()
            ->where($lookup['match_by'], $value)->orderByDesc('record_date')->first();
        if (!$found) return response()->json(['found' => false]);

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
            $searchable = [];
            foreach ($cfg['fields'] as $fn => $fc) {
                if (in_array($fc['type'], ['text', 'textarea', 'string'])) $searchable[] = $fn;
            }
            if (!empty($searchable)) {
                $query->where(function ($w) use ($searchable, $q) {
                    foreach ($searchable as $f) $w->orWhere($f, 'like', "%{$q}%");
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
        if (array_key_exists('worker1', $cfg['fields']) && !in_array('worker1', $listColumns)) $listColumns[] = 'worker1';
        if (!in_array('created_by', $listColumns)) $listColumns[] = 'created_by';
        if (array_key_exists('fault_code', $cfg['fields']) && !in_array('fault_code', $listColumns)) $listColumns[] = 'fault_code';

        $listColumns = array_values(array_filter($listColumns, fn($col) => $col !== 'health_indicator'));
        if (!empty($cfg['health_fields'])) $listColumns[] = 'health_indicator';

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

    public function create(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults       = $this->defaults($sheet, $cfg);
        $employeesList  = $this->employeesForSelect();
        $lookups        = [];
        $canEditWorkers = $this->canEditWorkers();

        return view('records.create', compact(
            'sheet', 'cfg', 'defaults', 'employeesList', 'lookups', 'canEditWorkers'
        ));
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
        $rules = $this->withSnapshotRules($cfg, $rules);

        $data = $r->validate($rules);

        if (!$this->canEditWorkers()) {
            foreach (self::WORKER_FIELDS as $wf) {
                if (array_key_exists($wf, $cfg['fields'])) {
                    $data[$wf] = $defaults[$wf] ?? null;
                }
            }
        }

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

    public function edit(Request $r, string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);

        $employeesList  = $this->employeesForSelect();
        $lookups        = $this->buildLookupFromRecord($cfg, $record);
        $canEditWorkers = $this->canEditWorkers();

        return view('records.edit', compact(
            'sheet', 'cfg', 'record', 'employeesList', 'lookups', 'canEditWorkers'
        ));
    }

    public function update(Request $r, string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $rules = $this->withSnapshotRules($cfg, $rules);

        $data = $r->validate($rules);

        if (!$this->canEditWorkers()) {
            foreach (self::WORKER_FIELDS as $wf) {
                if (array_key_exists($wf, $cfg['fields'])) {
                    $data[$wf] = $record->{$wf};
                }
            }
        }

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

    /**
     * AJAX: проверка ПИН сотрудника (используется на /workstation/select).
     */
    public function checkEmployeePin(Request $r)
    {
        $shortName = trim((string) $r->input('short_name', ''));
        $pin       = trim((string) $r->input('pin', ''));

        if ($shortName === '' || $pin === '') {
            return response()->json(['ok' => false, 'error' => 'Укажите сотрудника и ПИН.'], 422);
        }
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return response()->json(['ok' => false, 'error' => 'ПИН — 4–6 цифр.'], 422);
        }

        $employee = Employee::query()
            ->where('short_name', $shortName)
            ->active()
            ->with('user')
            ->first();

        if (!$employee)                       return response()->json(['ok' => false, 'error' => 'Сотрудник не найден.'], 404);
        if (!$employee->user)                 return response()->json(['ok' => false, 'error' => 'Сотрудник не привязан к учётной записи.'], 422);
        if (!$employee->user->hasPin())       return response()->json(['ok' => false, 'error' => 'У сотрудника не установлен ПИН.'], 422);
        if (!$employee->user->checkPin($pin)) return response()->json(['ok' => false, 'error' => 'Неверный ПИН.'], 422);

        $verified = $r->session()->get('verified_workers', []);
        $verified[$employee->id] = now()->addHours(3)->timestamp;
        $r->session()->put('verified_workers', $verified);

        return response()->json([
            'ok'            => true,
            'employee_id'   => $employee->id,
            'employee_name' => $employee->short_name,
        ]);
    }

    /**
     * Отключено: разблокировки полей своим ПИНом больше нет.
     */
    public function checkCurrentUserPin(Request $r)
    {
        return response()->json(['ok' => false, 'error' => 'Функция отключена.'], 410);
    }
}