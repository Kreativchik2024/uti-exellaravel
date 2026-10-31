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
    private const DEFECT_VALUES = [
        'Не сдан', 'Неисправен', 'В ремонт',
        'Не работает', 'Требует ремонта', 'Требует замены',
    ];

    /**
     * Только для executor: режим «Моя / Общая».
     * Для admin/master всегда null (общая).
     */
    private function resolveTargetUser(Request $r): ?User
    {
        $user = auth()->user();
        if (!$user->isExecutor()) return null;
        if ($r->input('mode') === 'all') return null;
        return $user;
    }

    private function resolveDateRange(Request $r): array
    {
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : null;
        $to   = $r->filled('to')   ? Carbon::parse($r->input('to'))->endOfDay()     : null;
        if ($from && !$to) $to = now()->endOfDay();
        return [$from, $to];
    }

    private function periodLabel(?Carbon $from, ?Carbon $to): string
    {
        if (!$from && !$to) return 'За всё время';
        if ($from && $to && $from->isSameDay($to)) return 'За ' . $from->format('d.m.Y');
        if ($from && $to) return 'С ' . $from->format('d.m.Y') . ' по ' . $to->format('d.m.Y');
        if ($from) return 'С ' . $from->format('d.m.Y');
        return 'По ' . $to->format('d.m.Y');
    }

    public function index(Request $r, StatsService $s)
    {
        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);

        $worker      = trim((string) $r->input('worker', ''));
        $workersList = $s->listWorkers();

        $perSheet = $s->forPeriod($from, $to, $targetUser, $worker !== '' ? $worker : null);
        $totals   = $s->totals($perSheet);
        $byPosts  = $s->byPosts($from, $to, $targetUser);

        $periodLabel = $this->periodLabel($from, $to);

        return view('stats.index', compact(
            'from', 'to', 'periodLabel',
            'perSheet', 'totals', 'byPosts',
            'targetUser', 'worker', 'workersList'
        ));
    }

    public function export(Request $r, StatsService $s)
    {
        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);
        $worker      = trim((string) $r->input('worker', ''));

        $perSheet = $s->forPeriod($from, $to, $targetUser, $worker !== '' ? $worker : null);
        $totals   = $s->totals($perSheet);

        $label = !$from && !$to
            ? 'all'
            : (($from && $to && $from->isSameDay($to))
                ? $from->format('Ymd')
                : ($from ? $from->format('Ymd') : 'start') . '-' . ($to ? $to->format('Ymd') : 'now'));

        $filename = "uti-stats-{$label}-" . now()->format('Ymd-His') . ".xlsx";

        return Excel::download(new StatsExport(0, $from, $perSheet, $totals), $filename);
    }

    // === ЗОНА 3 ===
    public function zone2Worker(Request $r, StatsService $s)
    {
        $worker = trim((string) $r->input('executor', ''));
        $slug   = trim((string) $r->input('sheet', ''));

        if ($worker === '') {
            return response()->json(['error' => 'executor is required'], 422);
        }

        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : null;
        $to   = $r->filled('to')   ? Carbon::parse($r->input('to'))->endOfDay()     : null;

        if ($slug !== '') {
            return response()->json([
                'records' => $s->workerRecords($worker, $slug, $from, $to),
            ]);
        }

        return response()->json([
            'categories' => $s->workerBySheet($worker, $from, $to),
        ]);
    }

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
                WHERE (worker1 = ? OR worker2 = ? OR worker3 = ? OR worker4 = ? OR worker5 = ?)
                {$whereSql}
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

        $counts = [];
        foreach ($rows as $row) {
            $key = substr((string) $row->record_date, 0, 10);
            $counts[$key] = (int) $row->cnt;
        }

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

        $labels = [];
        $values = [];
        $cursor = $rangeFrom->copy();
        while ($cursor->lte($rangeTo)) {
            $day      = $cursor->format('Y-m-d');
            $labels[] = $day;
            $values[] = $counts[$day] ?? 0;
            $cursor->addDay();
        }

        return response()->json(['labels' => $labels, 'values' => $values]);
    }

    public function zone3(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $from  = $r->input('from');
        $to    = $r->input('to');

        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $model = $cfg['model'];

        $query = $model::select('post_number', DB::raw('COUNT(*) AS cnt'))
            ->whereNotNull('post_number');

        if ($from) $query->whereDate('record_date', '>=', $from);
        if ($to)   $query->whereDate('record_date', '<=', $to);

        $rows = $query->groupBy('post_number')
            ->orderByDesc('cnt')
            ->orderBy('post_number')
            ->get();

        return response()->json($rows);
    }

    /**
     * Зона 4: график по постам.
     * Исправлено: whereDate для корректного захвата последнего дня периода.
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

        if ($from) $query->whereDate('record_date', '>=', $from);
        if ($to)   $query->whereDate('record_date', '<=', $to);

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

    /**
     * Зона 4 — конкретные записи ПГП данного поста за конкретный день.
     */
    public function zone4Records(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $cfg   = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $post = $r->input('post');
        $date = $r->input('date');
        abort_unless($post !== null && $date, 422);

        $model = $cfg['model'];

        $records = $model::query()
            ->where('post_number', $post)
            ->whereDate('record_date', $date)
            ->orderByDesc('record_date')
            ->get();

        $out = [];
        foreach ($records as $row) {
            $arr = $row->toArray();
            $arr['record_date'] = $row->record_date instanceof \DateTimeInterface
                ? $row->record_date->format('Y-m-d')
                : substr((string) $row->record_date, 0, 10);
            $out[] = $arr;
        }

        return response()->json($out);
    }

    public function zone5(Request $r)
    {
        $from = $r->input('from');
        $to   = $r->input('to');

        $cacheKey = "stats.zone5.{$from}_{$to}";

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to) {
            $summary = [];

            $dateSql = '';
            $dateBindings = [];
            if ($from) { $dateSql .= " AND DATE(record_date) >= ?"; $dateBindings[] = $from; }
            if ($to)   { $dateSql .= " AND DATE(record_date) <= ?"; $dateBindings[] = $to; }

            foreach (config('sheets.map', []) as $slug => $cfg) {
                if (!isset($cfg['model']) || !class_exists($cfg['model'])) continue;

                $model = $cfg['model'];
                $table = (new $model)->getTable();

                $hasRecordDate = isset($cfg['fields']['record_date']);
                $hasStatus     = isset($cfg['fields']['status']);

                $defectConditions = []; $defectBindings = [];
                $fixedConditions  = []; $fixedBindings  = [];

                if (isset($cfg['fields']['fault_code'])) {
                    if ($hasStatus) {
                        $defectConditions[] = "( `fault_code` IS NOT NULL AND `fault_code` != '' AND (`status` IS NULL OR `status` != 'Сдан') )";
                        $fixedConditions[]  = "( `fault_code` IS NOT NULL AND `fault_code` != '' AND `status` = 'Сдан' )";
                    } else {
                        $defectConditions[] = "(`fault_code` IS NOT NULL AND `fault_code` != '')";
                    }
                }

                foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
                    if (($fieldCfg['type'] ?? '') !== 'select' || empty($fieldCfg['options'])) continue;
                    $matched = array_values(array_intersect($fieldCfg['options'], self::DEFECT_VALUES));
                    if (empty($matched)) continue;
                    $placeholders = implode(',', array_fill(0, count($matched), '?'));
                    $defectConditions[] = "`$fieldName` IN ($placeholders)";
                    foreach ($matched as $v) $defectBindings[] = $v;
                }

                foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
                    if (($fieldCfg['type'] ?? '') !== 'textarea') continue;
                    if (!preg_match('/(defect|дефект|брак)/iu', $fieldName)) continue;
                    if ($hasStatus) {
                        $defectConditions[] = "( `$fieldName` IS NOT NULL AND `$fieldName` != '' AND (`status` IS NULL OR `status` != 'Сдан') )";
                        $fixedConditions[]  = "( `$fieldName` IS NOT NULL AND `$fieldName` != '' AND `status` = 'Сдан' )";
                    } else {
                        $defectConditions[] = "(`$fieldName` IS NOT NULL AND `$fieldName` != '')";
                    }
                }

                if ($hasRecordDate) {
                    $totalSql = "SELECT COUNT(*) AS c FROM `$table` WHERE 1=1 {$dateSql}";
                    $total = (int) (DB::select($totalSql, $dateBindings)[0]->c ?? 0);
                } else {
                    $total = $model::count();
                }

                $defects = 0;
                if (!empty($defectConditions) && $total > 0) {
                    $where = '(' . implode(' OR ', $defectConditions) . ')';
                    $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE {$where}";
                    $bindings = $defectBindings;
                    if ($hasRecordDate) { $sql .= " {$dateSql}"; $bindings = array_merge($defectBindings, $dateBindings); }
                    $defects = (int) (DB::select($sql, $bindings)[0]->c ?? 0);
                }

                $fixed = 0;
                if (!empty($fixedConditions) && $total > 0) {
                    $where = '(' . implode(' OR ', $fixedConditions) . ')';
                    $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE {$where}";
                    $bindings = $fixedBindings;
                    if ($hasRecordDate) { $sql .= " {$dateSql}"; $bindings = array_merge($fixedBindings, $dateBindings); }
                    $fixed = (int) (DB::select($sql, $bindings)[0]->c ?? 0);
                }

                $summary[] = [
                    'slug'          => $slug,
                    'title'         => $cfg['title'] ?? $slug,
                    'total'         => $total,
                    'defects'       => $defects,
                    'fixed'         => $fixed,
                    'percent'       => $total > 0 ? round($defects / $total * 100, 1) : 0,
                    'fixed_percent' => $total > 0 ? round($fixed / $total * 100, 1) : 0,
                ];
            }

            usort($summary, fn($a, $b) => $b['percent'] <=> $a['percent']);
            return $summary;
        });

        return response()->json($data);
    }

    public function zone5Chart(Request $r)
    {
        $sheet = $r->input('sheet', 'pgp');
        $from  = $r->input('from');
        $to    = $r->input('to');

        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg && isset($cfg['model']), 404);

        $model = $cfg['model'];
        $table = (new $model)->getTable();

        $dateSql = ''; $dateBindings = [];
        if ($from) { $dateSql .= " AND DATE(record_date) >= ?"; $dateBindings[] = $from; }
        if ($to)   { $dateSql .= " AND DATE(record_date) <= ?"; $dateBindings[] = $to; }

        $hasStatus = isset($cfg['fields']['status']);
        $defectConditions = []; $defectBindings = [];
        $fixedConditions  = []; $fixedBindings  = [];

        if (isset($cfg['fields']['fault_code'])) {
            if ($hasStatus) {
                $defectConditions[] = "( `fault_code` IS NOT NULL AND `fault_code` != '' AND (`status` IS NULL OR `status` != 'Сдан') )";
                $fixedConditions[]  = "( `fault_code` IS NOT NULL AND `fault_code` != '' AND `status` = 'Сдан' )";
            } else {
                $defectConditions[] = "(`fault_code` IS NOT NULL AND `fault_code` != '')";
            }
        }

        foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
            if (($fieldCfg['type'] ?? '') !== 'select' || empty($fieldCfg['options'])) continue;
            $matched = array_values(array_intersect($fieldCfg['options'], self::DEFECT_VALUES));
            if (empty($matched)) continue;
            $placeholders = implode(',', array_fill(0, count($matched), '?'));
            $defectConditions[] = "`$fieldName` IN ($placeholders)";
            foreach ($matched as $v) $defectBindings[] = $v;
        }

        foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
            if (($fieldCfg['type'] ?? '') !== 'textarea') continue;
            if (!preg_match('/(defect|дефект|брак)/iu', $fieldName)) continue;
            if ($hasStatus) {
                $defectConditions[] = "( `$fieldName` IS NOT NULL AND `$fieldName` != '' AND (`status` IS NULL OR `status` != 'Сдан') )";
                $fixedConditions[]  = "( `$fieldName` IS NOT NULL AND `$fieldName` != '' AND `status` = 'Сдан' )";
            } else {
                $defectConditions[] = "(`$fieldName` IS NOT NULL AND `$fieldName` != '')";
            }
        }

        if (empty($defectConditions)) {
            return response()->json(['labels' => [], 'defects' => [], 'fixed' => []]);
        }

        $defectWhere = '(' . implode(' OR ', $defectConditions) . ')';
        $sqlDefects = "
            SELECT DATE(record_date) AS d, COUNT(*) AS c
            FROM `$table`
            WHERE {$defectWhere} {$dateSql}
            GROUP BY DATE(record_date)
            ORDER BY d
        ";
        $defectRows = DB::select($sqlDefects, array_merge($defectBindings, $dateBindings));

        $fixedCounts = [];
        if (!empty($fixedConditions)) {
            $fixedWhere = '(' . implode(' OR ', $fixedConditions) . ')';
            $sqlFixed = "
                SELECT DATE(record_date) AS d, COUNT(*) AS c
                FROM `$table`
                WHERE {$fixedWhere} {$dateSql}
                GROUP BY DATE(record_date)
                ORDER BY d
            ";
            $fixedRows = DB::select($sqlFixed, array_merge($fixedBindings, $dateBindings));
            foreach ($fixedRows as $row) {
                $key = substr((string) $row->d, 0, 10);
                $fixedCounts[$key] = (int) $row->c;
            }
        }

        $defectCounts = [];
        foreach ($defectRows as $row) {
            $key = substr((string) $row->d, 0, 10);
            $defectCounts[$key] = (int) $row->c;
        }

        $rangeFrom = $from ? Carbon::parse($from)->startOfDay() : null;
        $rangeTo   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();

        if (!$rangeFrom) {
            $allKeys = array_unique(array_merge(array_keys($defectCounts), array_keys($fixedCounts)));
            if (!empty($allKeys)) {
                $minDate   = collect($allKeys)->min();
                $rangeFrom = Carbon::parse($minDate)->startOfDay();
            } else {
                $rangeFrom = $rangeTo->copy()->subDays(29)->startOfDay();
            }
        }

        if ($rangeTo->lt($rangeFrom)) {
            $rangeTo = $rangeFrom->copy()->endOfDay();
        }

        $labels = []; $defectsSeries = []; $fixedSeries = [];
        $cursor = $rangeFrom->copy();
        while ($cursor->lte($rangeTo)) {
            $day = $cursor->format('Y-m-d');
            $labels[]        = $day;
            $defectsSeries[] = $defectCounts[$day] ?? 0;
            $fixedSeries[]   = $fixedCounts[$day] ?? 0;
            $cursor->addDay();
        }

        return response()->json([
            'labels'  => $labels,
            'defects' => $defectsSeries,
            'fixed'   => $fixedSeries,
        ]);
    }

    public function zone6(Request $r)
    {
        $from     = $r->input('from');
        $to       = $r->input('to');
        $category = $r->input('sheet');

        $cacheKey = "stats.zone6.{$from}_{$to}_{$category}";

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to, $category) {
            $rows = [];
            $sheets = config('sheets.map', []);
            if ($category && isset($sheets[$category])) {
                $sheets = [$category => $sheets[$category]];
            }

            foreach ($sheets as $slug => $cfg) {
                if (!isset($cfg['model']) || !isset($cfg['fields']['fault_code'])) continue;

                $model = $cfg['model'];
                $table = (new $model)->getTable();

                $where = "(`fault_code` IS NOT NULL AND `fault_code` != '')";
                $bindings = [];
                if ($from) { $where .= " AND DATE(record_date) >= ?"; $bindings[] = $from; }
                if ($to)   { $where .= " AND DATE(record_date) <= ?"; $bindings[] = $to; }

                $sql = "
                    SELECT `fault_code` AS code, COUNT(*) AS cnt,
                           MIN(record_date) AS first_date, MAX(record_date) AS last_date
                    FROM `$table`
                    WHERE {$where}
                    GROUP BY `fault_code`
                ";

                $res = DB::select($sql, $bindings);

                foreach ($res as $row) {
                    $rows[] = [
                        'code'         => $row->code,
                        'sheet'        => $slug,
                        'sheet_title'  => $cfg['title'] ?? $slug,
                        'count'        => (int) $row->cnt,
                        'first_date'   => substr((string) $row->first_date, 0, 10),
                        'last_date'    => substr((string) $row->last_date, 0, 10),
                    ];
                }
            }

            usort($rows, fn($a, $b) => $b['count'] <=> $a['count']);
            return $rows;
        });

        return response()->json($data);
    }
}