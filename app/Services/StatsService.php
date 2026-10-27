<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatsService
{
    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    /** Кэш индекса: normalized_key => ['canonical' => ..., 'variants' => [...]] */
    private ?array $workersIndex = null;

    public function forPeriod(?Carbon $from, ?User $user): array
    {
        $map = config('sheets.map', []);
        $result = [];

        foreach ($map as $slug => $cfg) {
            $model = $cfg['model'];
            $hasQty = isset($cfg['fields']['qty']);

            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';

            $q = $model::query();

            if ($from !== null) {
                $q->whereDate($dateField, '>=', $from->toDateString());
            }

            if ($user && $user->isExecutor()) {
                $q->where('created_by', $user->id);
            }

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();

            $byUser = (clone $q)
                ->select('created_by', $hasQty ? DB::raw('SUM(qty) as c') : DB::raw('COUNT(*) as c'))
                ->groupBy('created_by')
                ->pluck('c', 'created_by')
                ->toArray();

            $users = User::whereIn('id', array_keys($byUser))->pluck('name', 'id')->toArray();

            $perUser = [];
            foreach ($byUser as $uid => $count) {
                $perUser[] = ['name' => $users[$uid] ?? 'Удалён', 'count' => (int) $count];
            }
            usort($perUser, fn($a, $b) => $b['count'] <=> $a['count']);

            $result[$slug] = [
                'title'   => $cfg['title'],
                'total'   => $total,
                'unit'    => $hasQty ? 'шт.' : 'записей',
                'perUser' => $perUser,
            ];
        }

        return $result;
    }

    public function totals(array $perSheet): array
    {
        $total = 0;
        $perUser = [];

        foreach ($perSheet as $data) {
            $total += $data['total'];
            foreach ($data['perUser'] as $u) {
                $perUser[$u['name']] = ($perUser[$u['name']] ?? 0) + $u['count'];
            }
        }

        arsort($perUser);

        return ['total' => $total, 'perUser' => $perUser];
    }

    public function byPosts(?Carbon $from, ?User $user): array
    {
        $pgpTable = (new \App\Models\PgpRecord)->getTable();
        $dateField = Schema::hasColumn($pgpTable, 'record_date') ? 'record_date' : 'created_at';

        $q = \App\Models\PgpRecord::query();

        if ($from !== null) {
            $q->whereDate($dateField, '>=', $from->toDateString());
        }

        if ($user && $user->isExecutor()) {
            $q->where('created_by', $user->id);
        }

        $records = $q->get();
        $result = [];

        foreach ($records as $record) {
            $post = $record->post_number ?? 'Без поста';

            if (!isset($result[$post])) {
                $result[$post] = ['total' => 0, 'delivered' => 0];
            }

            $result[$post]['total']++;

            if (($record->status ?? null) === 'Сдан') {
                $result[$post]['delivered']++;
            }
        }

        uasort($result, function ($a, $b) {
            if ($b['delivered'] !== $a['delivered']) {
                return $b['delivered'] <=> $a['delivered'];
            }
            return $b['total'] <=> $a['total'];
        });

        return $result;
    }

    /**
     * Сводка по исполнителю: сколько он сделал по каждой категории за период.
     * Учитываются ВСЕ варианты написания (регистр, пробелы, ё/е, точки на конце,
     * а также записи, где его имя указано в одном поле с другими через запятую).
     * Если в одной записи несколько исполнителей — операция засчитывается КАЖДОМУ.
     */
    public function workerSummary(string $name, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $key = $this->normalizeName($name);
        if ($key === '') {
            return [];
        }

        $index = $this->workersIndex();
        if (!isset($index[$key])) {
            return [];
        }

        $variants = $index[$key]['variants']; // полные значения из полей БД
        if (empty($variants)) {
            return [];
        }

        $map = config('sheets.map', []);
        $out = [];

        foreach ($map as $slug => $cfg) {
            $model = $cfg['model'];
            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';
            $hasQty    = isset($cfg['fields']['qty']);

            $workerFields = array_values(array_filter(
                self::WORKER_FIELDS,
                fn($f) => Schema::hasColumn($table, $f)
            ));

            if (empty($workerFields)) {
                continue;
            }

            $q = $model::query();

            if ($from !== null) $q->whereDate($dateField, '>=', $from->toDateString());
            if ($to   !== null) $q->whereDate($dateField, '<=', $to->toDateString());

            // Запись учитывается, если ЛЮБОЕ из полей worker1..5 содержит ЛЮБОЙ из вариантов.
            // Одна запись находится один раз, даже если имя встречается в нескольких полях.
            $q->where(function ($w) use ($workerFields, $variants) {
                foreach ($workerFields as $field) {
                    $w->orWhereIn($field, $variants);
                }
            });

            $total = $hasQty ? (clone $q)->sum('qty') : (clone $q)->count();

            if ($total > 0) {
                $out[$slug] = [
                    'title' => $cfg['title'],
                    'total' => (int) $total,
                    'unit'  => $hasQty ? 'шт.' : 'записей',
                ];
            }
        }

        return $out;
    }

    /**
     * Уникальный список фамилий (без дублей по регистру/пробелам/ё-е).
     *
     * @return string[]
     */
    public function listWorkers(): array
    {
        $index = $this->workersIndex();
        $list  = array_map(fn($d) => $d['canonical'], $index);

        // Финальная страховка от дублей (на случай если canonical совпали)
        $list = array_values(array_unique($list));

        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * Разбить значение поля worker на отдельных людей.
     * Разделители: запятая, точка с запятой, слэш, перенос строки, " и ".
     * Дефис НЕ разделитель — двойные фамилии (Иванов-Петров) остаются целыми.
     *
     * @return string[]
     */
    private function parseWorkers(string $value): array
    {
        $parts = preg_split('/[,;\/\n\r]+|\s+и\s+/u', $value) ?: [];
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn($p) => $p !== '');

        return array_values($parts);
    }

    /**
     * Нормализация имени для сравнения:
     *  - NBSP/табы/переносы → обычный пробел
     *  - trim
     *  - lowercase (UTF-8)
     *  - ё → е
     *  - схлопывание пробелов
     *  - удаление . , ; : в конце
     */
    private function normalizeName(string $name): string
    {
        $name = str_replace(["\xC2\xA0", "\t", "\n", "\r"], ' ', $name);
        $name = trim($name);

        $name = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);

        $name = str_replace('ё', 'е', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = rtrim($name, '.,;:');
        $name = trim($name);

        return $name;
    }

    /**
     * Индекс всех исполнителей.
     *
     * normalized_key => [
     *     'canonical' => 'Иванов',
     *     'variants'  => ['Иванов', 'ИВАНОВ', 'Иванов, Петров', 'Иванов '],
     * ]
     *
     * 'variants' — это ПОЛНЫЕ значения полей worker1..5 из БД,
     * которые содержат данную фамилию. Именно по ним потом делается whereIn.
     */
    private function workersIndex(): array
    {
        if ($this->workersIndex !== null) {
            return $this->workersIndex;
        }

        $map   = config('sheets.map', []);
        $index = [];

        foreach ($map as $cfg) {
            $model = $cfg['model'];
            $table = (new $model)->getTable();

            $fields = array_values(array_filter(
                self::WORKER_FIELDS,
                fn($f) => Schema::hasColumn($table, $f)
            ));

            if (empty($fields)) {
                continue;
            }

            foreach ($model::query()->select($fields)->cursor() as $row) {
                foreach ($fields as $f) {
                    $fullValue = trim((string) ($row->$f ?? ''));
                    if ($fullValue === '') {
                        continue;
                    }

                    foreach ($this->parseWorkers($fullValue) as $part) {
                        $key = $this->normalizeName($part);
                        if ($key === '') {
                            continue;
                        }

                        if (!isset($index[$key])) {
                            $index[$key] = [
                                'canonical' => $part,
                                'variants'  => [],
                            ];
                        } elseif (mb_strlen($part) > mb_strlen($index[$key]['canonical'])) {
                            // Самый «длинный» вариант делаем каноническим
                            $index[$key]['canonical'] = $part;
                        }

                        // Сохраняем ПОЛНОЕ значение поля из БД
                        $index[$key]['variants'][$fullValue] = true;
                    }
                }
            }
        }

        foreach ($index as $key => $data) {
            $index[$key]['variants'] = array_keys($data['variants']);
        }

        return $this->workersIndex = $index;
    }
}