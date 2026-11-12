<?php

namespace App\Http\Controllers;

use App\Exports\StatsExport;
use App\Models\Employee;
use App\Models\User;
use App\Services\SectionContext;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class StatsController extends Controller
{
    /** Вкладки учёта текущего участка. */
    private function sheets(): array
    {
        return app(SectionContext::class)->sheets();
    }

    /** Вкладка текущего участка (чужие вкладки статистики не отдаём). */
    private function sheetCfg(?string $sheet): ?array
    {
        return $this->sheets()[(string) $sheet] ?? null;
    }

    /** Часть ключа кеша: данные разных участков не смешиваются. */
    private function sectionKey(): string
    {
        return app(SectionContext::class)->cacheKey();
    }

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

    /**
     * Диапазон по «сырому» полю даты — без DATE(column), чтобы использовался индекс.
     */
    private function applyDateRange($query, ?string $from, ?string $to, string $field = 'record_date'): void
    {
        if ($from) $query->where($field, '>=', Carbon::parse($from)->startOfDay());
        if ($to)   $query->where($field, '<', Carbon::parse($to)->addDay()->startOfDay());
    }

    /**
     * Тот же диапазон для сырого SQL: [фрагмент с ведущим AND, bindings].
     *
     * @return array{0: string, 1: array}
     */
    private function sqlDateRange(?string $from, ?string $to, string $column = 'record_date'): array
    {
        $sql = '';
        $bindings = [];
        if ($from) {
            $sql .= " AND `$column` >= ?";
            $bindings[] = Carbon::parse($from)->toDateString() . ' 00:00:00';
        }
        if ($to) {
            $sql .= " AND `$column` < ?";
            $bindings[] = Carbon::parse($to)->addDay()->toDateString() . ' 00:00:00';
        }
        return [$sql, $bindings];
    }

    /**
     * Список активных сотрудников (short_name).
     */
    private function activeWorkerNames(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    /**
     * Проверка: имя — активный сотрудник из справочника.
     */
    private function isActiveWorker(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') return false;
        return Employee::query()->active()->where('short_name', $name)->exists();
    }

    public function index(Request $r, StatsService $s)
    {
        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);

        $canSeeWorkersStats = auth()->user()->isAdmin() || auth()->user()->isMaster();

        $worker      = $canSeeWorkersStats ? trim((string) $r->input('worker', '')) : '';
        $workersList = $canSeeWorkersStats ? $s->listWorkers() : [];

        // Кеш на минуту: расчёт по 19 таблицам — самая дорогая часть страницы
        $cacheKey = 'stats.index.v2.' . $this->sectionKey() . '.' . md5(json_encode([
            $from?->toDateString(), $to?->toDateString(), $targetUser?->id, $worker,
        ]));

        [$perSheet, $byPosts] = Cache::remember($cacheKey, 60, function () use ($s, $from, $to, $targetUser, $worker) {
            $perSheet = $s->forPeriod($from, $to, $targetUser, $worker !== '' ? $worker : null);
            return [$perSheet, $s->byPosts($from, $to, $targetUser)];
        });

        $totals = $s->totals($perSheet);

        $periodLabel = $this->periodLabel($from, $to);

        return view('stats.index', compact(
            'from', 'to', 'periodLabel',
            'perSheet', 'totals', 'byPosts',
            'targetUser', 'worker', 'workersList', 'canSeeWorkersStats'
        ));
    }

    public function export(Request $r, StatsService $s)
    {
        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);
        $canSeeWorkersStats = auth()->user()->isAdmin() || auth()->user()->isMaster();

        $worker = $canSeeWorkersStats ? trim((string) $r->input('worker', '')) : '';

        $perSheet = $s->forPeriod($from, $to, $targetUser, $worker !== '' ? $worker : null);
        $totals   = $s->totals($perSheet);

        $label = !$from && !$to
            ? 'all'
            : (($from && $to && $from->isSameDay($to))
                ? $from->format('Ymd')
                : ($from ? $from->format('Ymd') : 'start') . '-' . ($to ? $to->format('Ymd') : 'now'));

        $filename = "uti-stats-{$label}-" . now()->format('Ymd-His') . ".xlsx";

        $section = app(SectionContext::class)->current()?->name ?? '—';

        return Excel::download(new StatsExport($this->periodLabel($from, $to), $section, $perSheet, $totals), $filename);
    }

    /**
     * Зона 3 — только admin/master. Исполнитель — только активный сотрудник.
     */
    public function zone2Worker(Request $r, StatsService $s)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->isMaster(), 403);

        $worker = trim((string) $r->input('executor', ''));
        $slug   = trim((string) $r->input('sheet', ''));

        if ($worker === '') {
            return response()->json(['error' => 'executor is required'], 422);
        }
        if (!$this->isActiveWorker($worker)) {
            return response()->json(['error' => 'Сотрудник не найден или неактивен'], 422);
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
        $data = Cache::remember('stats.zone1.' . $this->sectionKey(), 300, function () {
            $summary = [];

            foreach ($this->sheets() as $slug => $cfg) {
                if (!isset($cfg['model']) || !class_exists($cfg['model'])) continue;
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
     * Зона 3 — список исполнителей (селект) и итог. Только admin/master.
     * Считается по ВСЕМ вкладкам участка (StatsService::executorsOverview), только активные сотрудники.
     * Параметр sheet (старые клиенты) лишь проверяется: чужая вкладка — 404.
     */
    public function zone2(Request $r, StatsService $s)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->isMaster(), 403);
        if ($r->filled('sheet')) abort_unless($this->sheetCfg($r->input('sheet')), 404);

        [$from, $to] = $this->resolveDateRange($r);
        $data = $s->executorsOverview($from, $to);

        return response()->json(['rows' => $data['rows'], 'total' => $data['total']]);
    }

    /**
     * Зона 3 — график по дням (все вкладки участка). Только admin/master. Только активный сотрудник.
     */
    public function zone2Chart(Request $r, StatsService $s)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->isMaster(), 403);
        if ($r->filled('sheet')) abort_unless($this->sheetCfg($r->input('sheet')), 404);

        $executor = trim((string) $r->input('executor', ''));
        $from     = $r->input('from');
        $to       = $r->input('to');

        if ($executor !== '' && !$this->isActiveWorker($executor)) {
            return response()->json(['labels' => [], 'values' => []]);
        }

        [$fromC, $toC] = $this->resolveDateRange($r);
        $data = $s->executorsOverview($fromC, $toC);
        $counts = $executor !== '' ? ($data['workerDays'][$executor] ?? []) : $data['days'];

        $rangeFrom = $from ? Carbon::parse($from)->startOfDay() : null;
        $rangeTo   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();

        if (!$rangeFrom) {
            if (!empty($counts)) $rangeFrom = Carbon::parse(collect(array_keys($counts))->min())->startOfDay();
            else $rangeFrom = $rangeTo->copy()->subDays(29)->startOfDay();
        }
        if ($rangeTo->lt($rangeFrom)) $rangeTo = $rangeFrom->copy()->endOfDay();

        $labels = []; $values = [];
        $cursor = $rangeFrom->copy();
        while ($cursor->lte($rangeTo)) {
            $day = $cursor->format('Y-m-d');
            $labels[] = $day;
            $values[] = $counts[$day] ?? 0;
            $cursor->addDay();
        }

        return response()->json(['labels' => $labels, 'values' => $values]);
    }

    public function zone3(Request $r, StatsService $s)
    {
        $sheet = $r->input('sheet', 'pgp');
        $from  = $r->input('from');
        $to    = $r->input('to');

        $cfg = $this->sheetCfg($sheet);
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $model = $cfg['model'];

        $query = $model::select('post_number', DB::raw('COUNT(*) AS cnt'))
            ->whereNotNull('post_number');

        $targetUser = $this->resolveTargetUser($r);
        if ($targetUser && $targetUser->isExecutor()) {
            $s->applyUserScope($query, $cfg, $targetUser);
        }
        $this->applyDateRange($query, $from, $to);

        return response()->json(
            $query->groupBy('post_number')->orderByDesc('cnt')->orderBy('post_number')->get()
        );
    }

    public function zone4(Request $r)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->isMaster(), 403);

        $sheet = $r->input('sheet', 'pgp');
        $cfg   = $this->sheetCfg($sheet);
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $model = $cfg['model'];
        $from  = $r->input('from');
        $to    = $r->input('to');

        $query = $model::select('post_number', 'record_date', DB::raw('COUNT(*) AS cnt'))
            ->whereNotNull('post_number');
        $this->applyDateRange($query, $from, $to);

        $rows = $query->groupBy('post_number', 'record_date')->orderBy('record_date')->get();

        $dates = $rows->pluck('record_date')->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->unique()->sort()->values()->all();
        $posts = $rows->pluck('post_number')->unique()->sort()->values()->all();

        $colors = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16','#f97316','#14b8a6'];
        $datasets = [];

        foreach ($posts as $i => $post) {
            $values = [];
            foreach ($dates as $date) {
                $found = $rows->first(fn($r) => $r->post_number == $post
                    && Carbon::parse($r->record_date)->format('Y-m-d') === $date);
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

        return response()->json(['labels' => $dates, 'datasets' => $datasets]);
    }

    public function zone4Records(Request $r)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->isMaster(), 403);

        $sheet = $r->input('sheet', 'pgp');
        $cfg   = $this->sheetCfg($sheet);
        abort_unless($cfg && isset($cfg['model'], $cfg['fields']['post_number']), 404);

        $post = $r->input('post');
        $date = $r->input('date');
        abort_unless($post !== null && $date, 422);

        $records = $cfg['model']::query()
            ->where('post_number', $post)
            ->where('record_date', '>=', Carbon::parse($date)->startOfDay())
            ->where('record_date', '<', Carbon::parse($date)->addDay()->startOfDay())
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

    public function zone5(Request $r, StatsService $s)
    {
        $from = $r->input('from');
        $to   = $r->input('to');
        $targetUser = $this->resolveTargetUser($r);
        $scopeKey   = $targetUser?->id ?? 'all';
        $cacheKey = "stats.zone5.v2.{$this->sectionKey()}.{$from}_{$to}.{$scopeKey}";

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to, $targetUser, $s) {
            $summary = [];
            [$dateSql, $dateBindings] = $this->sqlDateRange($from, $to);

            foreach ($this->sheets() as $slug => $cfg) {
                if (!isset($cfg['model']) || !class_exists($cfg['model'])) continue;
                $model = $cfg['model'];
                $table = (new $model)->getTable();
                $scope = $s->workerScopeSql($cfg, $targetUser);

                $hasRecordDate = isset($cfg['fields']['record_date']);
                $rules = $s->defectSql($cfg);

                $scopeSql      = $scope['sql'];
                $scopeBindings = $scope['bindings'];

                if ($hasRecordDate) {
                    $totalSql = "SELECT COUNT(*) AS c FROM `$table` WHERE 1=1 {$scopeSql} {$dateSql}";
                    $total = (int) (DB::select($totalSql, array_merge($scopeBindings, $dateBindings))[0]->c ?? 0);
                } else {
                    $totalSql = "SELECT COUNT(*) AS c FROM `$table` WHERE 1=1 {$scopeSql}";
                    $total = (int) (DB::select($totalSql, $scopeBindings)[0]->c ?? 0);
                }

                $countWhere = function (?array $rule) use ($table, $scopeSql, $scopeBindings, $hasRecordDate, $dateSql, $dateBindings): int {
                    if ($rule === null) return 0;
                    $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE {$rule['sql']} {$scopeSql}";
                    $bindings = array_merge($rule['bindings'], $scopeBindings);
                    if ($hasRecordDate) { $sql .= " {$dateSql}"; $bindings = array_merge($bindings, $dateBindings); }
                    return (int) (DB::select($sql, $bindings)[0]->c ?? 0);
                };

                $defects = $total > 0 ? $countWhere($rules['defect']) : 0;
                $fixed   = $total > 0 ? $countWhere($rules['fixed'])  : 0;

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

    public function zone5Chart(Request $r, StatsService $s)
    {
        $sheet = $r->input('sheet', 'pgp');
        $from  = $r->input('from');
        $to    = $r->input('to');

        $cfg = $this->sheetCfg($sheet);
        abort_unless($cfg && isset($cfg['model']), 404);

        $model = $cfg['model'];
        $table = (new $model)->getTable();

        $targetUser = $this->resolveTargetUser($r);
        $scope      = $s->workerScopeSql($cfg, $targetUser);
        $scopeSql   = $scope['sql'];
        $scopeBindings = $scope['bindings'];

        [$dateSql, $dateBindings] = $this->sqlDateRange($from, $to);

        $rules = $s->defectSql($cfg);
        if ($rules['defect'] === null) {
            return response()->json(['labels' => [], 'defects' => [], 'fixed' => []]);
        }

        $countByDay = function (array $rule) use ($table, $scopeSql, $scopeBindings, $dateSql, $dateBindings): array {
            $sql = "SELECT DATE(record_date) AS d, COUNT(*) AS c FROM `$table`
                    WHERE {$rule['sql']} {$scopeSql} {$dateSql} GROUP BY DATE(record_date) ORDER BY d";
            $out = [];
            foreach (DB::select($sql, array_merge($rule['bindings'], $scopeBindings, $dateBindings)) as $row) {
                $out[substr((string) $row->d, 0, 10)] = (int) $row->c;
            }
            return $out;
        };

        $defectCounts = $countByDay($rules['defect']);
        $fixedCounts  = $rules['fixed'] ? $countByDay($rules['fixed']) : [];

        $rangeFrom = $from ? Carbon::parse($from)->startOfDay() : null;
        $rangeTo   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();

        if (!$rangeFrom) {
            $allKeys = array_unique(array_merge(array_keys($defectCounts), array_keys($fixedCounts)));
            if (!empty($allKeys)) $rangeFrom = Carbon::parse(collect($allKeys)->min())->startOfDay();
            else $rangeFrom = $rangeTo->copy()->subDays(29)->startOfDay();
        }
        if ($rangeTo->lt($rangeFrom)) $rangeTo = $rangeFrom->copy()->endOfDay();

        $labels = []; $defectsSeries = []; $fixedSeries = [];
        $cursor = $rangeFrom->copy();
        while ($cursor->lte($rangeTo)) {
            $day = $cursor->format('Y-m-d');
            $labels[]        = $day;
            $defectsSeries[] = $defectCounts[$day] ?? 0;
            $fixedSeries[]   = $fixedCounts[$day] ?? 0;
            $cursor->addDay();
        }

        return response()->json(['labels' => $labels, 'defects' => $defectsSeries, 'fixed' => $fixedSeries]);
    }

    public function zone6(Request $r, StatsService $s)
    {
        $from     = $r->input('from');
        $to       = $r->input('to');
        $category = $r->input('sheet');
        $targetUser = $this->resolveTargetUser($r);
        $scopeKey   = $targetUser?->id ?? 'all';
        $cacheKey = "stats.zone6.{$this->sectionKey()}.{$from}_{$to}_{$category}.{$scopeKey}";

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to, $category, $targetUser, $s) {
            $rows = [];
            $sheets = $this->sheets();
            if ($category && isset($sheets[$category])) $sheets = [$category => $sheets[$category]];

            foreach ($sheets as $slug => $cfg) {
                if (!isset($cfg['model']) || !isset($cfg['fields']['fault_code'])) continue;
                $model = $cfg['model'];
                $table = (new $model)->getTable();
                $scope = $s->workerScopeSql($cfg, $targetUser);

                [$dateSql, $dateBindings] = $this->sqlDateRange($from, $to);

                $where = "(`fault_code` IS NOT NULL AND `fault_code` != '')" . $scope['sql'] . $dateSql;
                $bindings = array_merge($scope['bindings'], $dateBindings);

                $sql = "SELECT `fault_code` AS code, COUNT(*) AS cnt,
                               MIN(record_date) AS first_date, MAX(record_date) AS last_date
                        FROM `$table` WHERE {$where} GROUP BY `fault_code`";
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