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

    private ?array $workersIndex = null;

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
        $map = config('sheets.map', []);
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

            // Один запрос вместо двух: сумма по created_by даёт и total, и разбивку
            $byUser = (clone $q)
                ->select('created_by', $hasQty ? DB::raw('SUM(qty) as c') : DB::raw('COUNT(*) as c'))
                ->groupBy('created_by')->pluck('c', 'created_by')->toArray();

            $total = array_sum(array_map('intval', $byUser));

            $users = User::whereIn('id', array_keys($byUser))->pluck('name', 'id')->toArray();

            $perUser = [];
            foreach ($byUser as $uid => $count) {
                $perUser[] = ['name' => $users[$uid] ?? 'Удалён', 'count' => (int) $count];
            }
            usort($perUser, fn($a, $b) => $b['count'] <=> $a['count']);

            $result[$slug] = [
                'title'   => $cfg['title'],
                'total'   => (int) $total,
                'unit'    => $hasQty ? 'шт.' : 'записей',
                'perUser' => $perUser,
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
     * Детализация по постам — считается в SQL (GROUP BY), без выгрузки всех записей в память.
     */
    public function byPosts(?Carbon $from, ?Carbon $to, ?User $user): array
    {
        $cfg = config('sheets.map.pgp');
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
        foreach (config('sheets.map', []) as $slug => $cfg) {
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
        $cfg = config("sheets.map.$slug");
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
        $parts = preg_split('/[,;\/\n\r]+|\s+и\s+/u', $value) ?: [];
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn($p) => $p !== '');
        return array_values($parts);
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace(["\xC2\xA0", "\t", "\n", "\r"], ' ', $name);
        $name = trim($name);
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = str_replace('ё', 'е', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        return trim(rtrim($name, '.,;:'));
    }

    /**
     * Индекс написаний ФИО по всем таблицам — тяжёлый, поэтому кешируется.
     */
    private function workersIndex(): array
    {
        if ($this->workersIndex !== null) return $this->workersIndex;

        return $this->workersIndex = Cache::remember('stats.workers_index.v1', 600, function () {
            $index = [];

            foreach (config('sheets.map', []) as $cfg) {
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
