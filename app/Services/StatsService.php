<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsService
{
    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    /** Значения select-полей, означающие брак. */
    public const DEFECT_VALUES = [
        'Не сдан', 'Неисправен', 'В ремонт',
        'Не работает', 'Требует ремонта', 'Требует замены',
    ];

    private ?array $workersIndex = null;

    /** Вкладки учёта текущего участка (статистика считается только по ним). */
    private function sheets(): array
    {
        return app(SectionContext::class)->sheets();
    }

    /**
     * Единое правило «что считать браком» для вкладки (используется в сводке,
     * в зоне «Брак по категориям» и её графике — цифры везде совпадают).
     *
     * defect — брак (не исправлен); fixed — был брак, но изделие сдано.
     * Каждый элемент: ['sql' => '(...)', 'bindings' => [...]] или null, если правил нет.
     *
     * @return array{defect: ?array{sql: string, bindings: array}, fixed: ?array{sql: string, bindings: array}}
     */
    public function defectSql(array $cfg): array
    {
        $fields    = $cfg['fields'] ?? [];
        $hasStatus = isset($fields['status']);

        $defect = []; $defectBindings = [];
        $fixed  = [];

        $notEmpty = function (string $field) use ($hasStatus, &$defect, &$fixed) {
            if ($hasStatus) {
                $defect[] = "(`$field` IS NOT NULL AND `$field` != '' AND (`status` IS NULL OR `status` != 'Сдан'))";
                $fixed[]  = "(`$field` IS NOT NULL AND `$field` != '' AND `status` = 'Сдан')";
            } else {
                $defect[] = "(`$field` IS NOT NULL AND `$field` != '')";
            }
        };

        if (isset($fields['fault_code'])) {
            $notEmpty('fault_code');
        }

        foreach ($fields as $name => $f) {
            $type = $f['type'] ?? '';

            if ($type === 'select' && !empty($f['options'])) {
                $matched = array_values(array_intersect($f['options'], self::DEFECT_VALUES));
                if ($matched) {
                    $defect[] = "`$name` IN (" . implode(',', array_fill(0, count($matched), '?')) . ')';
                    array_push($defectBindings, ...$matched);
                }
            } elseif ($type === 'textarea' && preg_match('/(defect|дефект|брак)/iu', $name)) {
                $notEmpty($name);
            }
        }

        return [
            'defect' => $defect ? ['sql' => '(' . implode(' OR ', $defect) . ')', 'bindings' => $defectBindings] : null,
            'fixed'  => $fixed  ? ['sql' => '(' . implode(' OR ', $fixed) . ')',  'bindings' => []] : null,
        ];
    }

    /**
     * Метаданные таблицы берём из config/sheets.php, а не из information_schema:
     * раньше каждый вызов Schema::hasColumn() был отдельным SQL-запросом
     * (до ~95 запросов на страницу статистики).
     */
    private function metaFor(array $cfg): array
    {
        $fields = $cfg['fields'] ?? [];

        $workerFields = [];
        foreach (self::WORKER_FIELDS as $field) {
            if (isset($fields[$field])) $workerFields[] = $field;
        }

        return [
            'dateField'    => isset($fields['record_date']) ? 'record_date' : 'created_at',
            'workerFields' => $workerFields,
            'hasQty'       => isset($fields['qty']),
            'hasPost'      => isset($fields['post_number']),
            'hasStatus'    => isset($fields['status']),
            'hasFault'     => isset($fields['fault_code']),
        ];
    }

    /**
     * Сравнение по «сырому» полю даты вместо DATE(column) — так работает индекс.
     */
    private function applyRange($query, string $dateField, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) $query->where($dateField, '>=', $from->copy()->startOfDay());
        if ($to !== null)   $query->where($dateField, '<=', $to->copy()->endOfDay());
    }

    private function applyWorkerFilter($query, array $workerFields, array $variants): void
    {
        $query->where(function ($w) use ($workerFields, $variants) {
            foreach ($workerFields as $field) {
                $w->orWhereIn($field, $variants);
            }
        });
    }

    public function forPeriod(?Carbon $from, ?Carbon $to = null, ?User $user = null, ?string $worker = null): array
    {
        $map = $this->sheets();
        $result = [];

        $workerVariants = [];
        if ($worker !== null && $worker !== '') {
            $key = $this->normalizeName($worker);
            $index = $this->workersIndex();
            $workerVariants = isset($index[$key]) ? $index[$key]['variants'] : [$worker];
        }

        foreach ($map as $slug => $cfg) {
            if (empty($cfg['model']) || !class_exists($cfg['model'])) continue;

            $model  = $cfg['model'];
            $meta   = $this->metaFor($cfg);
            $hasQty = $meta['hasQty'];

            $q = $model::query();
            $this->applyRange($q, $meta['dateField'], $from, $to);

            if ($user && $user->isExecutor()) {
                $this->applyUserScope($q, $cfg, $user);
            }

            if (!empty($workerVariants)) {
                if (empty($meta['workerFields'])) continue;
                $this->applyWorkerFilter($q, $meta['workerFields'], $workerVariants);
            }

            // Один запрос: по created_by сразу и выпуск, и брак (условная сумма)
            $amount = $hasQty ? 'COALESCE(qty, 0)' : '1';
            $rule   = $this->defectSql($cfg)['defect'];

            $q->select('created_by')
                ->selectRaw("SUM($amount) as c")
                ->groupBy('created_by');
            if ($rule) {
                $q->selectRaw("SUM(CASE WHEN {$rule['sql']} THEN $amount ELSE 0 END) as d", $rule['bindings']);
            }
            $rows = $q->get();

            $total   = (int) $rows->sum('c');
            $defects = $rule ? (int) $rows->sum('d') : 0;

            $users = User::whereIn('id', $rows->pluck('created_by')->filter())->pluck('name', 'id')->toArray();

            $perUser = [];
            foreach ($rows as $row) {
                $perUser[] = ['name' => $users[$row->created_by] ?? 'Удалён', 'count' => (int) $row->c];
            }
            usort($perUser, fn($a, $b) => $b['count'] <=> $a['count']);

            $result[$slug] = [
                'title'      => $cfg['title'],
                'total'      => $total,
                'good'       => $total - $defects,
                'defects'    => $defects,
                'hasDefects' => $rule !== null, // есть ли у вкладки критерий брака
                'unit'       => $hasQty ? 'шт.' : 'записей',
                'perUser'    => $perUser,
            ];
        }

        return $result;
    }

    public function totals(array $perSheet): array
    {
        $total = 0; $perUser = [];
        foreach ($perSheet as $data) {
            $total += $data['total'];
            foreach ($data['perUser'] as $u) {
                $perUser[$u['name']] = ($perUser[$u['name']] ?? 0) + $u['count'];
            }
        }
        arsort($perUser);

        return ['total' => $total, 'perUser' => $perUser];
    }

    /**
     * Борта ПГП за период: всего и по типам (УМН / МН / СОН / … из config/board_types.php).
     * null — если на участке нет ПГП. Фильтры те же, что у сводки (период, исполнитель, свои записи).
     *
     * @return array{total:int, delivered:int, types: array<string, array{label:string,total:int,delivered:int}>, none: array{total:int,delivered:int}}|null
     */
    public function boardTypes(?Carbon $from, ?Carbon $to, ?User $user, ?string $worker = null): ?array
    {
        $cfg = $this->sheets()['pgp'] ?? null;
        if (!$cfg || empty($cfg['model']) || !isset($cfg['fields']['board_type'])) return null;

        $model = $cfg['model'];
        $meta  = $this->metaFor($cfg);

        $q = $model::query();
        $this->applyRange($q, $meta['dateField'], $from, $to);
        if ($user && $user->isExecutor()) $this->applyUserScope($q, $cfg, $user);
        if ($worker !== null && $worker !== '' && $meta['workerFields']) {
            $index = $this->workersIndex();
            $key   = $this->normalizeName($worker);
            $this->applyWorkerFilter($q, $meta['workerFields'], isset($index[$key]) ? $index[$key]['variants'] : [$worker]);
        }

        $rows = $q->selectRaw("COALESCE(NULLIF(board_type, ''), '_none') as t")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'Сдан' THEN 1 ELSE 0 END) as delivered")
            ->groupBy('t')
            ->get()
            ->keyBy('t');

        $row = fn ($k) => ['total' => (int) ($rows[$k]->total ?? 0), 'delivered' => (int) ($rows[$k]->delivered ?? 0)];

        $types = [];
        foreach (\App\Support\BoardTypes::labels() as $code => $label) {
            $types[$code] = ['label' => $label] + $row($code);
        }
        // Тип, которого уже нет в справочнике, но он остался в записях, — тоже показываем
        foreach ($rows->keys() as $k) {
            if ($k !== '_none' && !isset($types[$k])) $types[$k] = ['label' => (string) $k] + $row($k);
        }

        return [
            'total'     => (int) $rows->sum('total'),
            'delivered' => (int) $rows->sum('delivered'),
            'types'     => $types,
            'none'      => $row('_none'),
        ];
    }

    /**
     * Детализация по постам — считается в SQL (GROUP BY), без выгрузки всех записей в память.
     */
    public function byPosts(?Carbon $from, ?Carbon $to, ?User $user): array
    {
        $cfg = $this->sheets()['pgp'] ?? null;   // детализация по постам — только где есть ПГП
        if (!$cfg || empty($cfg['model'])) return [];

        $model = $cfg['model'];
        $table = (new $model)->getTable();
        $meta  = $this->metaFor($cfg);

        $q = $model::query();
        $this->applyRange($q, $meta['dateField'], $from, $to);
        if ($user && $user->isExecutor()) $this->applyUserScope($q, $cfg, $user);

        $rows = $q->select(
            'post_number',
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN `status` = 'Сдан' THEN 1 ELSE 0 END) as delivered")
        )->groupBy('post_number')->get();

        $result = [];
        foreach ($rows as $row) {
            $post = $row->post_number ?? 'Без поста';
            $result[$post] = ['total' => (int) $row->total, 'delivered' => (int) $row->delivered];
        }

        uasort($result, function ($a, $b) {
            if ($b['delivered'] !== $a['delivered']) return $b['delivered'] <=> $a['delivered'];
            return $b['total'] <=> $a['total'];
        });

        return $result;
    }

    public function workerSummary(string $name, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $variants = $this->variantsForName($name);
        if (empty($variants)) return [];

        $out = [];
        foreach ($this->sheets() as $slug => $cfg) {
            if (empty($cfg['model']) || !class_exists($cfg['model'])) continue;

            $meta = $this->metaFor($cfg);
            if (empty($meta['workerFields'])) continue;

            $model = $cfg['model'];
            $hasQty = $meta['hasQty'];

            $q = $model::query();
            $this->applyRange($q, $meta['dateField'], $from, $to);
            $this->applyWorkerFilter($q, $meta['workerFields'], $variants);

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();
            if ($total > 0) {
                $out[$slug] = ['title' => $cfg['title'], 'total' => (int) $total, 'unit' => $hasQty ? 'шт.' : 'записей'];
            }
        }

        return $out;
    }

    public function workerBySheet(string $worker, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->workerSummary($worker, $from, $to);
    }

    public function workerRecords(
        string $worker,
        string $slug,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 500
    ): array {
        $cfg = $this->sheets()[$slug] ?? null;
        if (!$cfg || empty($cfg['model']) || !class_exists($cfg['model'])) return [];

        $variants = $this->variantsForName($worker);
        if (empty($variants)) return [];

        $meta = $this->metaFor($cfg);
        if (empty($meta['workerFields'])) return [];

        $model = $cfg['model'];
        $key   = $this->normalizeName($worker);

        $q = $model::query();
        $this->applyRange($q, $meta['dateField'], $from, $to);
        $this->applyWorkerFilter($q, $meta['workerFields'], $variants);

        $rows = $q->orderByDesc($meta['dateField'])->limit($limit)->get();

        $out = [];
        foreach ($rows as $row) {
            $matchedFields = [];
            foreach ($meta['workerFields'] as $f) {
                $val = trim((string) ($row->$f ?? ''));
                if ($val === '') continue;
                foreach ($this->parseWorkers($val) as $part) {
                    if ($this->normalizeName($part) === $key) {
                        $matchedFields[] = $f;
                        break;
                    }
                }
            }

            $dateVal = $row->{$meta['dateField']} ?? null;
            if ($dateVal instanceof \DateTimeInterface) {
                $dateStr = $dateVal->format('Y-m-d');
            } elseif ($dateVal) {
                $dateStr = substr((string) $dateVal, 0, 10);
            } else {
                $dateStr = null;
            }

            $out[] = [
                'id'           => $row->id,
                'date'         => $dateStr,
                'post'         => $meta['hasPost']   ? ($row->post_number ?? null) : null,
                'status'       => $meta['hasStatus'] ? ($row->status ?? null)      : null,
                'qty'          => $meta['hasQty']    ? ($row->qty ?? null)         : null,
                'fault_code'   => $meta['hasFault']  ? ($row->fault_code ?? null)  : null,
                'worker_field' => implode(',', $matchedFields),
            ];
        }

        return $out;
    }

    /**
     * Зона 3 «Сводка по исполнителю» — по ВСЕМ вкладкам участка (раньше считалась только ПГП).
     * Имена сравниваются так же, как в детализации (App\Support\WorkerNames): разные написания
     * одного ФИО и несколько ФИО в одном поле считаются правильно. В список попадают только
     * активные сотрудники справочника.
     *
     * @return array{rows: list<array{name: string, cnt: int}>, total: int, days: array<string,int>, workerDays: array<string, array<string,int>>}
     */
    public function executorsOverview(?Carbon $from, ?Carbon $to): array
    {
        $key = 'stats.executors.v1.' . app(SectionContext::class)->cacheKey() . '.' . ($from?->toDateString() ?? '-') . '_' . ($to?->toDateString() ?? '-');

        return Cache::remember($key, 120, function () use ($from, $to) {
            // ключ ФИО → имя сотрудника из справочника (только активные)
            $directory = [];
            foreach ($this->listWorkers() as $name) {
                $k = $this->normalizeName((string) $name);
                if ($k !== '') $directory[$k] = $name;
            }

            $counts = []; $workerDays = []; $days = []; $total = 0;
            foreach ($this->sheets() as $cfg) {
                if (empty($cfg['model']) || !class_exists($cfg['model'])) continue;
                $meta = $this->metaFor($cfg);
                if (empty($meta['workerFields'])) continue;

                $model = $cfg['model'];
                $dateField = $meta['dateField'];
                $base = function () use ($model, $dateField, $from, $to) {
                    $q = $model::query();
                    $this->applyRange($q, $dateField, $from, $to);
                    return $q;
                };

                // Записи с исполнителями (каждая — один раз) по дням
                $perDay = $base()->where(function ($w) use ($meta) {
                    foreach ($meta['workerFields'] as $f) $w->orWhere(fn ($x) => $x->whereNotNull($f)->where($f, '!=', ''));
                })->selectRaw("DATE($dateField) AS d, COUNT(*) AS c")->groupBy('d')->get();
                foreach ($perDay as $row) {
                    $d = substr((string) $row->d, 0, 10);
                    $days[$d] = ($days[$d] ?? 0) + (int) $row->c;
                    $total += (int) $row->c;
                }

                // Участие каждого исполнителя: группировка по значению поля, имена разбираются в PHP
                foreach ($meta['workerFields'] as $f) {
                    $groups = $base()->whereNotNull($f)->where($f, '!=', '')
                        ->selectRaw("DATE($dateField) AS d, $f AS v, COUNT(*) AS c")->groupBy('d', $f)->get();
                    foreach ($groups as $g) {
                        $d = substr((string) $g->d, 0, 10);
                        $seen = [];
                        foreach ($this->parseWorkers((string) $g->v) as $part) {
                            $k = $this->normalizeName($part);
                            if ($k === '' || isset($seen[$k]) || !isset($directory[$k])) continue;
                            $seen[$k] = true;
                            $name = $directory[$k];
                            $counts[$name] = ($counts[$name] ?? 0) + (int) $g->c;
                            $workerDays[$name][$d] = ($workerDays[$name][$d] ?? 0) + (int) $g->c;
                        }
                    }
                }
            }

            arsort($counts);
            $rows = [];
            foreach ($counts as $name => $cnt) $rows[] = ['name' => (string) $name, 'cnt' => $cnt];

            return ['rows' => $rows, 'total' => $total, 'days' => $days, 'workerDays' => $workerDays];
        });
    }

    public function listWorkers(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    /**
     * Варианты написания ФИО для фильтра. Для аккаунта сотрудника достаточно
     * точного short_name: записи валидируются по справочнику. Тяжёлый индекс
     * по всем таблицам здесь больше не строится.
     */
    public function workerVariantsForUser(?User $user): array
    {
        if (!$user) return [];

        $name = trim((string) (Employee::query()->where('user_id', $user->id)->value('short_name') ?? ''));
        return $name === '' ? [] : [$name];
    }

    /**
     * Ограничить выборку записями, где пользователь указан исполнителем
     * (worker1..5). Если привязки к сотруднику нет — откат на created_by.
     */
    public function applyUserScope($query, array $cfg, User $user): void
    {
        $variants = $this->workerVariantsForUser($user);
        $meta     = $this->metaFor($cfg);

        if (empty($variants) || empty($meta['workerFields'])) {
            $query->where('created_by', $user->id);
            return;
        }

        $this->applyWorkerFilter($query, $meta['workerFields'], $variants);
    }

    /**
     * SQL-фрагмент (с ведущим " AND ") для тех же условий в сырых запросах.
     *
     * @return array{sql: string, bindings: array}
     */
    public function workerScopeSql(array $cfg, ?User $user): array
    {
        if (!$user || !$user->isExecutor()) {
            return ['sql' => '', 'bindings' => []];
        }

        $variants = $this->workerVariantsForUser($user);
        $fields   = $this->metaFor($cfg)['workerFields'];

        if (empty($variants) || empty($fields)) {
            return ['sql' => ' AND `created_by` = ?', 'bindings' => [$user->id]];
        }

        $parts = [];
        $bindings = [];
        foreach ($fields as $field) {
            $parts[] = "`$field` IN (" . implode(',', array_fill(0, count($variants), '?')) . ')';
            foreach ($variants as $variant) {
                $bindings[] = $variant;
            }
        }

        return ['sql' => ' AND (' . implode(' OR ', $parts) . ')', 'bindings' => $bindings];
    }

    private function variantsForName(string $name): array
    {
        $key = $this->normalizeName($name);
        if ($key === '') return [];

        $index = $this->workersIndex();
        return $index[$key]['variants'] ?? [$name];
    }

    private function parseWorkers(string $value): array
    {
        return \App\Support\WorkerNames::split($value);
    }

    /** Одно ФИО в разных написаниях → один ключ (App\Support\WorkerNames). */
    private function normalizeName(string $name): string
    {
        return \App\Support\WorkerNames::key($name);
    }

    /**
     * Индекс написаний ФИО по всем таблицам — тяжёлый, поэтому кешируется.
     */
    private function workersIndex(): array
    {
        if ($this->workersIndex !== null) return $this->workersIndex;

        $key = 'stats.workers_index.v3.' . app(SectionContext::class)->cacheKey();
        return $this->workersIndex = Cache::remember($key, 600, function () {
            $index = [];

            foreach ($this->sheets() as $cfg) {
                if (empty($cfg['model']) || !class_exists($cfg['model'])) continue;

                $fields = $this->metaFor($cfg)['workerFields'];
                if (empty($fields)) continue;

                $model = $cfg['model'];
                foreach ($model::query()->select($fields)->cursor() as $row) {
                    foreach ($fields as $f) {
                        $fullValue = trim((string) ($row->$f ?? ''));
                        if ($fullValue === '') continue;
                        foreach ($this->parseWorkers($fullValue) as $part) {
                            $key = $this->normalizeName($part);
                            if ($key === '') continue;
                            if (!isset($index[$key])) $index[$key] = ['canonical' => $part, 'variants' => []];
                            elseif (mb_strlen($part) > mb_strlen($index[$key]['canonical'])) $index[$key]['canonical'] = $part;
                            $index[$key]['variants'][$fullValue] = true;
                        }
                    }
                }
            }

            foreach ($index as $key => $data) $index[$key]['variants'] = array_keys($data['variants']);
            return $index;
        });
    }
}
