<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeDirectory;
use App\Services\SectionContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecordController extends Controller
{
    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    /** Конфиг вкладки с проверкой доступа к её участку (см. SectionContext::sheet). */
    private function cfg(string $sheet): array
    {
        return app(SectionContext::class)->sheet($sheet);
    }

    private function isRecordLocked($record): bool
    {
        if (!$record || !$record->created_at) return false;
        $user = auth()->user();
        if ($user && $user->canManageContent()) return false;
        return $record->created_at->copy()->addHours(24)->isPast();
    }

    private function canManageWorkersWithoutPin(): bool
    {
        return auth()->check() && auth()->user()->canManageContent();
    }

    public function __construct(private EmployeeDirectory $employees) {}

    private function currentEmployeeName(): ?string
    {
        return $this->employees->currentEmployeeName();
    }

    private function verifiedWorkersMap(): array
    {
        return $this->employees->verifiedMap();
    }

    private function recordWorkerValues($record, array $cfg): array
    {
        $result = [];
        foreach (self::WORKER_FIELDS as $wf) {
            if (array_key_exists($wf, $cfg['fields'])) {
                $result[$wf] = $record->{$wf} ?? '';
            }
        }
        return $result;
    }

    private function lookupPrefix(string $fieldName, array $lookup): ?string
    {
        if (!empty($lookup['prefix'])) return $lookup['prefix'];
        if (preg_match('/^(.+?)_(number|serial|code|id)$/', $fieldName, $m)) return $m[1];
        return null;
    }

    /**
     * Поля type=ip_suffix: в форме вводится только последний октет,
     * здесь он склеивается с префиксом из конфига ('prefix' => '192.168.1.').
     */
    private function applyIpSuffix(array $cfg, Request $r): void
    {
        foreach ($cfg['fields'] as $name => $f) {
            if (($f['type'] ?? '') !== 'ip_suffix' || !$r->exists($name)) continue;

            $raw = trim((string) $r->input($name));
            if ($raw !== '' && ctype_digit($raw)) {
                $r->merge([$name => ($f['prefix'] ?? '') . ltrim($raw, '0')]);
            }
        }
    }

    /** Свои тексты ошибок из конфига: 'messages' => ['regex' => '...'] у поля. */
    private function fieldMessages(array $cfg): array
    {
        $out = [];
        foreach ($cfg['fields'] as $name => $f) {
            foreach ($f['messages'] ?? [] as $rule => $text) {
                $out["$name.$rule"] = $text;
            }
        }
        return $out;
    }

    /** Человеческие названия полей для сообщений валидации. */
    private function attributeNames(array $cfg): array
    {
        return collect($cfg['fields'])->map(fn ($f, $k) => mb_strtolower($f['label'] ?? $k))->all();
    }

    /**
     * Варианты для полей type=combo: ранее введённые значения (частые — выше)
     * плюс заданные в конфиге. В форме — выпадающий список с возможностью ввести своё.
     */
    private function comboOptions(array $cfg): array
    {
        $result = [];
        foreach ($cfg['fields'] as $name => $f) {
            if (($f['type'] ?? '') !== 'combo') continue;

            $used = $cfg['model']::query()
                ->whereNotNull($name)->where($name, '!=', '')
                ->groupBy($name)
                ->orderByRaw('COUNT(*) DESC')
                ->limit(200)
                ->pluck($name)
                ->map(fn ($v) => trim((string) $v))
                ->all();

            $result[$name] = array_values(array_unique(array_filter(
                array_merge($f['options'] ?? [], $used),
                fn ($v) => $v !== ''
            )));
        }
        return $result;
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
        return $this->employees->namesForSelect();
    }

    private function workerDefaults(): array
    {
        $workers = session('workstation_workers', []);
        if (empty($workers)) {
            $currentName = $this->currentEmployeeName();
            if ($currentName) {
                $workers = [$currentName];
            }
        }
        $allowed = $this->employeesForSelect();
        $defaults = [
            'worker1' => trim($workers[0] ?? ''),
            'worker2' => trim($workers[1] ?? ''),
            'worker3' => trim($workers[2] ?? ''),
            'worker4' => trim($workers[3] ?? ''),
            'worker5' => trim($workers[4] ?? ''),
        ];
        return array_map(fn ($v) => in_array($v, $allowed, true) ? $v : '', $defaults);
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

    private function validateWorkers(array $data, array $exemptValues = []): void
    {
        $allActive = $this->employeesForSelect();
        $errors    = [];
        $seen      = [];

        $verified  = session('verified_workers', []);
        $now       = now()->timestamp;
        $canManage = $this->canManageWorkersWithoutPin();
        $currentName = $this->currentEmployeeName();

        foreach (self::WORKER_FIELDS as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = trim($data[$field]);
            if ($value === '') continue;

            if (isset($seen[$value])) {
                $errors[$field] = "Этот исполнитель уже указан в поле «{$seen[$value]}».";
                continue;
            }
            $seen[$value] = $field;

            if (!in_array($value, $allActive, true)) {
                $errors[$field] = 'Сотрудник неактивен или не привязан к учётной записи (email).';
                continue;
            }

            if ($canManage) continue;
            if ($currentName && $value === $currentName) continue;
            if (isset($exemptValues[$field]) && trim($exemptValues[$field]) === $value) continue;

            $employee = $this->employees->findActiveByName($value);
            if (!$employee) continue;

            if (!$employee->user || !$employee->user->hasPin()) {
                $errors[$field] = "У сотрудника «{$value}» не установлен ПИН-код. Выберите другого.";
                continue;
            }

            if (!isset($verified[$employee->id]) || (int) $verified[$employee->id] < $now) {
                $errors[$field] = "Подтвердите ПИН сотрудника «{$value}».";
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
                if (in_array($fc['type'], ['text', 'textarea', 'string', 'combo', 'ip_suffix'])) $searchable[] = $fn;
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

        if (Gate::denies('view', $record)) {
            return redirect()->route('records.index', $sheet)
                ->withErrors(['general' => 'Просмотр этой записи недоступен.']);
        }

        $lookups = $this->buildLookupFromRecord($cfg, $record);
        $isTimeLocked = $this->isRecordLocked($record);

        // История изменений записи (журнал действий) — мастеру и админу
        $history = auth()->user()->canManageContent()
            ? \App\Models\AuditLog::where('area', 'records')->where('subject_type', $sheet)->where('subject_id', $record->id)
                ->orderByDesc('id')->limit(30)->get()
            : collect();
        $historyLabels = \App\Http\Controllers\AuditController::fieldLabels();

        return view('records.show', compact('sheet', 'cfg', 'record', 'lookups', 'isTimeLocked', 'history', 'historyLabels'));
    }

    public function create(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $defaults              = $this->defaults($sheet, $cfg);
        $employeesList         = $this->employeesForSelect();
        $lookups               = [];
        $canManageWorkers      = $this->canManageWorkersWithoutPin();
        $verifiedWorkersMap    = $this->verifiedWorkersMap();
        $currentEmployeeName   = $this->currentEmployeeName();
        [$employeesRequiringPin, $employeesWithoutPin] = $this->employees->splitByPin();
        $originalWorkers       = array_filter(
            array_intersect_key($defaults, array_flip(self::WORKER_FIELDS)),
            fn($v) => $v !== '' && $v !== null
        );
        $isTimeLocked = false;
        $serverNow    = now()->timestamp;
        $comboOptions = $this->comboOptions($cfg);

        return view('records.create', compact(
            'sheet', 'cfg', 'defaults', 'employeesList', 'lookups',
            'canManageWorkers', 'verifiedWorkersMap', 'currentEmployeeName',
            'employeesRequiringPin', 'employeesWithoutPin', 'originalWorkers',
            'isTimeLocked', 'serverNow', 'comboOptions'
        ));
    }

    public function store(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        // --- ИСПРАВЛЕНИЕ ДЛЯ ЛОГИЧЕСКОГО ПОЛЯ ---
        if ($r->has('is_repaired')) {
            $val = $r->input('is_repaired');
            if (in_array($val, ['Да', '1', 1, true, 'true'], true)) $r->merge(['is_repaired' => 1]);
            if (in_array($val, ['Нет', '0', 0, false, 'false'], true)) $r->merge(['is_repaired' => 0]);
        }

        $defaults = $this->defaults($sheet, $cfg);
        foreach ($defaults as $field => $value) {
            if (!$r->exists($field) && $value !== '' && $value !== null) {
                $r->merge([$field => $value]);
            }
        }

        $this->applyIpSuffix($cfg, $r);
        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $rules = $this->withSnapshotRules($cfg, $rules);

        $data = $r->validate($rules, $this->fieldMessages($cfg), $this->attributeNames($cfg));

        $exempt = array_filter(
            array_intersect_key($defaults, array_flip(self::WORKER_FIELDS)),
            fn($v) => $v !== '' && $v !== null
        );
        $this->validateWorkers($data, $exempt);

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

        if (Gate::denies('update', $record)) {
            return redirect()->route('records.index', $sheet)
                ->withErrors(['general' => 'У вас нет прав на редактирование этой записи.']);
        }

        $employeesList         = $this->employeesForSelect();
        $lookups               = $this->buildLookupFromRecord($cfg, $record);
        $canManageWorkers      = $this->canManageWorkersWithoutPin();
        $verifiedWorkersMap    = $this->verifiedWorkersMap();
        $currentEmployeeName   = $this->currentEmployeeName();
        [$employeesRequiringPin, $employeesWithoutPin] = $this->employees->splitByPin();
        $originalWorkers       = array_filter(
            $this->recordWorkerValues($record, $cfg),
            fn($v) => $v !== '' && $v !== null
        );
        $isTimeLocked = $this->isRecordLocked($record);
        $serverNow    = now()->timestamp;
        $comboOptions = $this->comboOptions($cfg);

        return view('records.edit', compact(
            'sheet', 'cfg', 'record', 'employeesList', 'lookups',
            'canManageWorkers', 'verifiedWorkersMap', 'currentEmployeeName',
            'employeesRequiringPin', 'employeesWithoutPin', 'originalWorkers',
            'isTimeLocked', 'serverNow', 'comboOptions'
        ));
    }

    public function update(Request $r, string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);

        if (Gate::denies('update', $record)) {
            return redirect()->route('records.index', $sheet)
                ->withErrors(['general' => 'У вас нет прав на редактирование этой записи.']);
        }

        if ($this->isRecordLocked($record)) {
            return back()->withErrors(['general' => 'Редактирование запрещено: прошло более 24 часов с момента создания записи.']);
        }

        // --- ИСПРАВЛЕНИЕ ДЛЯ ЛОГИЧЕСКОГО ПОЛЯ ---
        if ($r->has('is_repaired')) {
            $val = $r->input('is_repaired');
            if (in_array($val, ['Да', '1', 1, true, 'true'], true)) $r->merge(['is_repaired' => 1]);
            if (in_array($val, ['Нет', '0', 0, false, 'false'], true)) $r->merge(['is_repaired' => 0]);
        }

        $this->applyIpSuffix($cfg, $r);
        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $rules = $this->withSnapshotRules($cfg, $rules);

        $data = $r->validate($rules, $this->fieldMessages($cfg), $this->attributeNames($cfg));

        $existing = $this->recordWorkerValues($record, $cfg);
        $this->validateWorkers($data, $existing);

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

        if (Gate::denies('delete', $record)) {
            return redirect()->route('records.index', $sheet)
                ->withErrors(['general' => 'У вас нет прав на удаление этой записи.']);
        }

        if ($this->isRecordLocked($record)) {
            return redirect()->route('records.index', $sheet)
                ->withErrors(['general' => 'Удаление запрещено: прошло более 24 часов с момента создания записи.']);
        }

        $record->delete();
        return redirect()->route('records.index', $sheet)->with('ok', 'Запись удалена');
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), '1062')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }

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

        // Подбор ПИН конкретного сотрудника: после 5 ошибок подряд — пауза 15 минут,
        // с какой бы учётки и с какого компьютера ни вводили
        $lockKey = 'pin-employee:' . $employee->id;
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            $min = (int) ceil(RateLimiter::availableIn($lockKey) / 60);
            return response()->json(['ok' => false, 'error' => "Слишком много неверных попыток ПИН этого сотрудника. Подождите {$min} мин."], 429);
        }
        if (!$employee->user->checkPin($pin)) {
            RateLimiter::hit($lockKey, 900);
            return response()->json(['ok' => false, 'error' => 'Неверный ПИН.'], 422);
        }
        RateLimiter::clear($lockKey);

        $expiresAt = now()->addHours(3)->timestamp;

        $verified = $r->session()->get('verified_workers', []);
        $verified[$employee->id] = $expiresAt;
        $r->session()->put('verified_workers', $verified);

        return response()->json([
            'ok'            => true,
            'employee_id'   => $employee->id,
            'employee_name' => $employee->short_name,
            'expires_at'    => $expiresAt,
            'server_now'    => now()->timestamp,
        ]);
    }

    public function checkCurrentUserPin(Request $r)
    {
        return response()->json(['ok' => false, 'error' => 'Функция отключена.'], 410);
    }
}