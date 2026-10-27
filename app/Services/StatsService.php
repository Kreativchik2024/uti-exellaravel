<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatsService
{
    public function forPeriod(?Carbon $from, ?User $user): array
    {
        $map = config('sheets.map', []);
        $result = [];

        foreach ($map as $slug => $cfg) {
            $model = $cfg['model'];
            $hasQty = isset($cfg['fields']['qty']);

            // Определяем, по какому полю фильтровать: record_date (дата события) или created_at (дата импорта)
            $table = (new $model)->getTable();
            $dateField = Schema::hasColumn($table, 'record_date') ? 'record_date' : 'created_at';

            $q = $model::query();

            // Фильтр по дате применяем только если задан период (не «всё время»)
            if ($from !== null) {
                $q->whereDate($dateField, '>=', $from->toDateString());
            }

            if ($user && $user->isExecutor()) {
                $q->where('created_by', $user->id);
            }

            if ($hasQty) {
                $total = (clone $q)->sum('qty');
            } else {
                $total = (clone $q)->count();
            }

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

        // Фильтр по дате применяем только если задан период (не «всё время»)
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
                $result[$post] = ['total' => 0, 'users' => []];
            }

            $result[$post]['total']++;

            $workers = array_filter([
                $record->worker1, $record->worker2, $record->worker3, $record->worker4, $record->worker5,
            ]);

            foreach ($workers as $worker) {
                if (!isset($result[$post]['users'][$worker])) {
                    $result[$post]['users'][$worker] = 0;
                }
                $result[$post]['users'][$worker]++;
            }
        }

        ksort($result);
        return $result;
    }
}