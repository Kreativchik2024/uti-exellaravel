<?php

namespace App\Http\Controllers;

use App\Exports\StatsExport;
use App\Models\User;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class StatsController extends Controller
{
    private const ALLOWED_PERIODS = [0, 1, 7, 30];

    private function resolveFrom(int $period): ?Carbon
    {
        if ($period === 0) {
            return null;
        }

        return now()->subDays($period - 1)->startOfDay();
    }

    private function resolveTargetUser(Request $r): ?User
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isMaster()) {
            if ($r->filled('user_id')) {
                return User::find($r->user_id) ?? $user;
            }

            return null;
        }

        if ($r->input('mode') === 'all') {
            return null;
        }

        return $user;
    }

    public function index(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = 7;
        }

        $from = $this->resolveFrom($period);

        $targetUser = $this->resolveTargetUser($r);
        $usersList  = collect();

        if (auth()->user()->isAdmin() || auth()->user()->isMaster()) {
            $usersList = User::whereIn('role', ['executor', 'master', 'admin'])
                ->orderBy('name')
                ->get();
        }

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);
        $byPosts  = $s->byPosts($from, $targetUser);

        // === Сводка по исполнителю ===
        $worker         = trim((string) $r->input('worker', ''));
        $workerDateFrom = $r->input('worker_date_from');
        $workerDateTo   = $r->input('worker_date_to');

        $workersList   = $s->listWorkers();
        $workerSummary = [];

        if ($worker !== '') {
            if ($workerDateFrom || $workerDateTo) {
                if ($workerDateFrom && !$workerDateTo) {
                    $wFrom = Carbon::parse($workerDateFrom)->startOfDay();
                    $wTo   = Carbon::parse($workerDateFrom)->endOfDay();
                } else {
                    $wFrom = $workerDateFrom ? Carbon::parse($workerDateFrom)->startOfDay() : null;
                    $wTo   = $workerDateTo   ? Carbon::parse($workerDateTo)->endOfDay()     : null;
                }
            } else {
                $wFrom = $from;
                $wTo   = null;
            }

            $workerSummary = $s->workerSummary($worker, $wFrom, $wTo);
        }

        return view('stats.index', compact(
            'period',
            'from',
            'perSheet',
            'totals',
            'byPosts',
            'usersList',
            'targetUser',
            'worker',
            'workersList',
            'workerSummary'
        ));
    }

    public function export(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = 7;
        }

        $from = $this->resolveFrom($period);

        $targetUser = $this->resolveTargetUser($r);

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);

        $periodLabel = $period === 0 ? 'all' : "{$period}d";
        $filename    = "uti-stats-{$periodLabel}-" . now()->format('Ymd-His') . ".xlsx";

        return Excel::download(new StatsExport($period, $from, $perSheet, $totals), $filename);
    }

    // =========================================================
    // Зоны статистики (ленивая подгрузка)
    // =========================================================

    public function zone1()
    {
        $data = Cache::remember('stats.zone1', 300, function () {
            $summary = [];

            foreach (config('sheets.map', []) as $slug => $cfg) {
                if (!isset($cfg['model']) || !class_exists($cfg['model'])) {
                    continue;
                }

                $model = $cfg['model'];

                $summary[] = [
                    'slug'  => $slug,
                    'title' => $cfg['title'] ?? $slug,
                    'total' => $model::count(),
                    'today' => $model::whereDate('created_at', today())->count(),
                    'week'  => $model::where('created_at', '>=', now()->subDays(7))->count(),
                ];
            }

            return $summary;
        });

        return response()->json($data);
    }

    /**
     * Зона 2: сводка по исполнителям с фильтром по времени.
     * Возвращает { rows: [...], total: N }, где total — кол-во уникальных записей.
     */
    public function zone2(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $from  = $r->input('from');
        $to    = $r->input('to');

        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model']), 404);

        $model = $cfg['model'];
        $table = (new $model)->getTable();

        $whereSql = '';
        $bindings = [];
        if ($from) {
            $whereSql .= " AND DATE(record_date) >= ?";
            $bindings[] = $from;
        }
        if ($to) {
            $whereSql .= " AND DATE(record_date) <= ?";
            $bindings[] = $to;
        }

        $unionBindings = array_merge($bindings, $bindings, $bindings, $bindings, $bindings);

        $cacheKey = "stats.zone2.$sheet.{$from}_{$to}";

        $result = Cache::remember($cacheKey, 120, function () use ($table, $whereSql, $bindings, $unionBindings) {
            $sql = "
                SELECT name, COUNT(*) AS cnt FROM (
                    SELECT worker1 AS name FROM `$table` WHERE worker1 IS NOT NULL AND worker1 != '' {$whereSql}
                    UNION ALL SELECT worker2 FROM `$table` WHERE worker2 IS NOT NULL AND worker2 != '' {$whereSql}
                    UNION ALL SELECT worker3 FROM `$table` WHERE worker3 IS NOT NULL AND worker3 != '' {$whereSql}
                    UNION ALL SELECT worker4 FROM `$table` WHERE worker4 IS NOT NULL AND worker4 != '' {$whereSql}
                    UNION ALL SELECT worker5 FROM `$table` WHERE worker5 IS NOT NULL AND worker5 != '' {$whereSql}
                ) w
                GROUP BY name
                ORDER BY cnt DESC
            ";
            $rows = DB::select($sql, $unionBindings);

            $totalSql = "
                SELECT COUNT(*) AS total FROM `$table`
                WHERE (
                    (worker1 IS NOT NULL AND worker1 != '')
                    OR (worker2 IS NOT NULL AND worker2 != '')
                    OR (worker3 IS NOT NULL AND worker3 != '')
                    OR (worker4 IS NOT NULL AND worker4 != '')
                    OR (worker5 IS NOT NULL AND worker5 != '')
                ) {$whereSql}
            ";
            $total = (int) (DB::select($totalSql, $bindings)[0]->total ?? 0);

            return ['rows' => $rows, 'total' => $total];
        });

        return response()->json($result);
    }

    /**
     * Зона 2 (график): количество записей по дням с шагом 1 день.
     * Пропущенные дни заполняются нулями. Ключи дат приводятся к 'Y-m-d'.
     */
    public function zone2Chart(Request $r)
    {
        $sheet    = $r->input('sheet', 'pgp');
        $executor = $r->input('executor');
        $from     = $r->input('from');
        $to       = $r->input('to');

        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model']), 404);

        $model = $cfg['model'];
        $table = (new $model)->getTable();

        $whereSql = '';
        $bindings = [];
        if ($from) {
            $whereSql .= " AND DATE(record_date) >= ?";
            $bindings[] = $from;
        }
        if ($to) {
            $whereSql .= " AND DATE(record_date) <= ?";
            $bindings[] = $to;
        }

        if ($executor) {
            $sql = "
                SELECT DATE(record_date) AS record_date, COUNT(*) AS cnt FROM `$table`
                WHERE (
                    worker1 = ? OR worker2 = ? OR worker3 = ? OR worker4 = ? OR worker5 = ?
                ) {$whereSql}
                GROUP BY DATE(record_date)
                ORDER BY record_date
            ";
            $execBindings = [$executor, $executor, $executor, $executor, $executor];
            $rows = DB::select($sql, array_merge($execBindings, $bindings));
        } else {
            $sql = "
                SELECT DATE(record_date) AS record_date, COUNT(*) AS cnt FROM `$table`
                WHERE (
                    (worker1 IS NOT NULL AND worker1 != '')
                    OR (worker2 IS NOT NULL AND worker2 != '')
                    OR (worker3 IS NOT NULL AND worker3 != '')
                    OR (worker4 IS NOT NULL AND worker4 != '')
                    OR (worker5 IS NOT NULL AND worker5 != '')
                ) {$whereSql}
                GROUP BY DATE(record_date)
                ORDER BY record_date
            ";
            $rows = DB::select($sql, $bindings);
        }

        // Ключи всегда "Y-m-d" (без времени)
        $counts = [];
        foreach ($rows as $row) {
            $key = substr((string) $row->record_date, 0, 10);
            $counts[$key] = (int) $row->cnt;
        }

        // Диапазон
        $rangeFrom = $from ? Carbon::parse($from)->startOfDay() : null;
        $rangeTo   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();

        if (!$rangeFrom) {
            if (!empty($counts)) {
                $minDate   = collect(array_keys($counts))->min();
                $rangeFrom = Carbon::parse($minDate)->startOfDay();
            } else {
                $rangeFrom = $rangeTo->copy()->subDays(29)->startOfDay();
            }
        }

        if ($rangeTo->lt($rangeFrom)) {
            $rangeTo = $rangeFrom->copy()->endOfDay();
        }

        // Непрерывный ряд по дням
        $labels = [];
        $values = [];
        $cursor = $rangeFrom->copy();
        while ($cursor->lte($rangeTo)) {
            $day      = $cursor->format('Y-m-d');
            $labels[] = $day;
            $values[] = $counts[$day] ?? 0;
            $cursor->addDay();
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    /**
     * Зона 3: детализация по постам (сортировка по убыванию сделанных бортов).
     */
    public function zone3(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $cfg   = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $model = $cfg['model'];

        $rows = $model::select('post_number', DB::raw('COUNT(*) AS cnt'))
            ->whereNotNull('post_number')
            ->groupBy('post_number')
            ->orderByDesc('cnt')
            ->orderBy('post_number')
            ->get();

        return response()->json($rows);
    }

    /**
     * Зона 4: график по постам с выбором временного интервала.
     * Даты в labels — 'Y-m-d'.
     */
    public function zone4(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $cfg   = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $model = $cfg['model'];
        $from  = $r->input('from');
        $to    = $r->input('to');

        $query = $model::select('post_number', 'record_date', DB::raw('COUNT(*) AS cnt'))
            ->whereNotNull('post_number');

        if ($from) {
            $query->where('record_date', '>=', $from);
        }
        if ($to) {
            $query->where('record_date', '<=', $to);
        }

        $rows = $query->groupBy('post_number', 'record_date')
            ->orderBy('record_date')
            ->get();

        $dates = $rows->pluck('record_date')
            ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->unique()->sort()->values()->all();
        $posts = $rows->pluck('post_number')->unique()->sort()->values()->all();

        $colors = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#14b8a6'];
        $datasets = [];

        foreach ($posts as $i => $post) {
            $values = [];
            foreach ($dates as $date) {
                $found = $rows->first(fn($r) =>
                    $r->post_number == $post
                    && Carbon::parse($r->record_date)->format('Y-m-d') === $date
                );
                $values[] = $found ? (int) $found->cnt : 0;
            }

            $datasets[] = [
                'label'           => 'Пост ' . $post,
                'data'            => $values,
                'borderColor'     => $colors[$i % count($colors)],
                'backgroundColor' => $colors[$i % count($colors)],
                'tension'         => 0.2,
            ];
        }

        return response()->json([
            'labels'   => $dates,
            'datasets' => $datasets,
        ]);
    }
}