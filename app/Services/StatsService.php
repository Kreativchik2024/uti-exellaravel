<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatsService
{
    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    private ?array $workersIndex = null;

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
            $model = $cfg['model'];
            $hasQty = isset($cfg['fields']['qty']);
            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';

            $q = $model::query();
            if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
            if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());

            if ($user && $user->isExecutor()) {
                $q->where('created_by', $user->id);
            }

            if (!empty($workerVariants)) {
                $workerFields = array_values(array_filter(
                    self::WORKER_FIELDS,
                    fn($f) => Schema::hasColumn($table, $f)
                ));
                if (empty($workerFields)) continue;

                $q->where(function ($w) use ($workerFields, $workerVariants) {
                    foreach ($workerFields as $field) {
                        $w->orWhereIn($field, $workerVariants);
                    }
                });
            }

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();

            $byUser = (clone $q)
                ->select('created_by', $hasQty ? DB::raw('SUM(qty) as c') : DB::raw('COUNT(*) as c'))
                ->groupBy('created_by')->pluck('c', 'created_by')->toArray();

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

    public function byPosts(?Carbon $from, ?Carbon $to, ?User $user): array
    {
        $pgpTable = (new \App\Models\PgpRecord)->getTable();
        $dateField = Schema::hasColumn($pgpTable, 'record_date') ? 'record_date' : 'created_at';

        $q = \App\Models\PgpRecord::query();
        if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
        if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());
        if ($user && $user->isExecutor()) $q->where('created_by', $user->id);

        $records = $q->get();
        $result = [];
        foreach ($records as $record) {
            $post = $record->post_number ?? 'Без поста';
            if (!isset($result[$post])) $result[$post] = ['total' => 0, 'delivered' => 0];
            $result[$post]['total']++;
            if (($record->status ?? null) === 'Сдан') $result[$post]['delivered']++;
        }
        uasort($result, function ($a, $b) {
            if ($b['delivered'] !== $a['delivered']) return $b['delivered'] <=> $a['delivered'];
            return $b['total'] <=> $a['total'];
        });
        return $result;
    }

    public function workerSummary(string $name, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $key = $this->normalizeName($name);
        if ($key === '') return [];

        $index = $this->workersIndex();
        if (!isset($index[$key])) return [];

        $variants = $index[$key]['variants'];
        if (empty($variants)) return [];

        $map = config('sheets.map', []);
        $out = [];

        foreach ($map as $slug => $cfg) {
            $model = $cfg['model'];
            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';
            $hasQty = isset($cfg['fields']['qty']);

            $workerFields = array_values(array_filter(self::WORKER_FIELDS, fn($f) => Schema::hasColumn($table, $f)));
            if (empty($workerFields)) continue;

            $q = $model::query();
            if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
            if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());
            $q->where(function ($w) use ($workerFields, $variants) {
                foreach ($workerFields as $field) $w->orWhereIn($field, $variants);
            });

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();
            if ($total > 0) {
                $out[$slug] = ['title' => $cfg['title'], 'total' => (int) $total, 'unit' => $hasQty ? 'шт.' : 'записей'];
            }
        }
        return $out;
    }

    /**
     * Разбивка по категориям (листам) для конкретного исполнителя.
     * Возвращает [slug => ['title','total','unit']] только по тем листам,
     * где у исполнителя есть хотя бы одна запись в периоде.
     */
    public function workerBySheet(string $worker, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $key = $this->normalizeName($worker);
        if ($key === '') return [];

        $index = $this->workersIndex();
        if (!isset($index[$key])) return [];

        $variants = $index[$key]['variants'];
        if (empty($variants)) return [];

        $out = [];

        foreach (config('sheets.map', []) as $slug => $cfg) {
            if (empty($cfg['model']) || !class_exists($cfg['model'])) continue;

            $model = $cfg['model'];
            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';
            $hasQty = isset($cfg['fields']['qty']);

            $workerFields = array_values(array_filter(self::WORKER_FIELDS, fn($f) => Schema::hasColumn($table, $f)));
            if (empty($workerFields)) continue;

            $q = $model::query();
            if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
            if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());
            $q->where(function ($w) use ($workerFields, $variants) {
                foreach ($workerFields as $field) $w->orWhereIn($field, $variants);
            });

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();
            if ($total > 0) {
                $out[$slug] = [
                    'title' => $cfg['title'] ?? $slug,
                    'total' => (int) $total,
                    'unit'  => $hasQty ? 'шт.' : 'записей',
                ];
            }
        }

        return $out;
    }

    /**
     * Конкретные записи исполнителя в выбранной категории (листе).
     * Возвращает универсальный набор полей (только те, что реально есть в таблице).
     */
    public function workerRecords(
        string $worker,
        string $slug,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 500
    ): array {
        $cfg = config("sheets.map.$slug");
        if (!$cfg || empty($cfg['model']) || !class_exists($cfg['model'])) return [];

        $key = $this->normalizeName($worker);
        if ($key === '') return [];

        $index = $this->workersIndex();
        if (!isset($index[$key])) return [];

        $variants = $index[$key]['variants'];
        if (empty($variants)) return [];

        $model = $cfg['model'];
        $table = (new $model)->getTable();
        $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';

        $workerFields = array_values(array_filter(self::WORKER_FIELDS, fn($f) => Schema::hasColumn($table, $f)));
        if (empty($workerFields)) return [];

        $q = $model::query();
        if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
        if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());
        $q->where(function ($w) use ($workerFields, $variants) {
            foreach ($workerFields as $field) $w->orWhereIn($field, $variants);
        });

        $hasPost   = Schema::hasColumn($table, 'post_number');
        $hasStatus = Schema::hasColumn($table, 'status');
        $hasQty    = Schema::hasColumn($table, 'qty');
        $hasFault  = Schema::hasColumn($table, 'fault_code');

        $rows = $q->orderByDesc($dateField)->limit($limit)->get();

        $out = [];
        foreach ($rows as $row) {
            $matchedFields = [];
            foreach ($workerFields as $f) {
                $val = trim((string) ($row->$f ?? ''));
                if ($val === '') continue;
                foreach ($this->parseWorkers($val) as $part) {
                    if ($this->normalizeName($part) === $key) {
                        $matchedFields[] = $f;
                        break;
                    }
                }
            }

            $dateVal = $row->{$dateField} ?? null;
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
                'post'         => $hasPost   ? ($row->post_number ?? null) : null,
                'status'       => $hasStatus ? ($row->status ?? null)      : null,
                'qty'          => $hasQty    ? ($row->qty ?? null)         : null,
                'fault_code'   => $hasFault  ? ($row->fault_code ?? null)  : null,
                'worker_field' => implode(',', $matchedFields),
            ];
        }

        return $out;
    }

    /**
     * Список для селекта «Исполнитель» — ТОЛЬКО активные сотрудники из employees.
     */
    public function listWorkers(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
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

    private function workersIndex(): array
    {
        if ($this->workersIndex !== null) return $this->workersIndex;

        $index = [];
        foreach (config('sheets.map', []) as $cfg) {
            $model = $cfg['model'];
            $table = (new $model)->getTable();
            $fields = array_values(array_filter(self::WORKER_FIELDS, fn($f) => Schema::hasColumn($table, $f)));
            if (empty($fields)) continue;

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
        return $this->workersIndex = $index;
    }
}