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
        if (!$user->isWorker()) return null;
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

    /** Ключи основных фильтров страницы статистики, которые храним в сессии. */
    private const MAIN_FILTER_KEYS = ['from', 'to', 'worker', 'mode'];

    /** Зоны с собственными фильтрами по дате (сохраняются через saveFilters). */
    private const ZONE_FILTER_KEYS = ['zone2', 'zone4', 'zone5', 'zone6', 'zone7', 'zone8'];

    private function sessionKey(string $suffix): string
    {
        return 'stats.filters.' . $this->sectionKey() . '.' . $suffix;
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /**
     * Фильтры статистики живут в сессии: переживают обновление и уход со страницы,
     * сбрасываются при выходе из учётной записи (session()->invalidate()) или истечении сессии.
     */
    public function saveFilters(Request $r)
    {
        $zone = (string) $r->input('zone');
        if (!in_array($zone, self::ZONE_FILTER_KEYS, true)) {
            return response()->json(['ok' => false], 422);
        }

        $r->session()->put($this->sessionKey($zone), [
            'from' => $this->cleanDate($r->input('from')),
            'to'   => $this->cleanDate($r->input('to')),
        ]);

        return response()->json(['ok' => true]);
    }

    public function index(Request $r, StatsService $s)
    {
        $mainKey = $this->sessionKey('main');

        // «Сбросить» — очищаем сохранённые фильтры (основные и зон)
        if ($r->boolean('reset')) {
            $r->session()->forget($mainKey);
            foreach (self::ZONE_FILTER_KEYS as $z) $r->session()->forget($this->sessionKey($z));
            return redirect()->route('stats.index');
        }

        if ($r->hasAny(self::MAIN_FILTER_KEYS)) {
            // Пришли явные фильтры — запоминаем
            $r->session()->put($mainKey, array_filter(
                $r->only(self::MAIN_FILTER_KEYS),
                fn ($v) => $v !== null && $v !== ''
            ));
        } elseif ($saved = $r->session()->get($mainKey)) {
            // Чистый заход на страницу — восстанавливаем сохранённые
            return redirect()->route('stats.index', $saved);
        }

        $zoneFilters = [];
        foreach (self::ZONE_FILTER_KEYS as $z) {
            $zoneFilters[$z] = $r->session()->get($this->sessionKey($z));
        }

        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);

        $canSeeWorkersStats = auth()->user()->isAdmin() || auth()->user()->isMaster();

        $worker      = $canSeeWorkersStats ? trim((string) $r->input('worker', '')) : '';
        $workersList = $canSeeWorkersStats ? $s->listWorkers() : [];

        // Кеш на минуту: расчёт по 19 таблицам — самая дорогая часть страницы
        $cacheKey = 'stats.index.v4.' . $this->sectionKey() . '.' . md5(json_encode([
            $from?->toDateString(), $to?->toDateString(), $targetUser?->id, $worker,
        ]));

        [$perSheet, $byPosts, $boardTypes, $boardTechs] = Cache::remember($cacheKey, 60, function () use ($s, $from, $to, $targetUser, $worker) {
            $perSheet = $s->forPeriod($from, $to, $targetUser, $worker !== '' ? $worker : null);
            return [
                $perSheet,
                $s->byPosts($from, $to, $targetUser),
                $s->boardTypes($from, $to, $targetUser, $worker !== '' ? $worker : null),
                $s->boardTechnologists($from, $to, $targetUser, $worker !== '' ? $worker : null),
            ];
        });
        // «Борта по технологам» — только мастеру, технологу (ОТК) и администратору
        if (! self::canSeeTechnologistStats($r->user())) $boardTechs = null;

        $totals = $s->totals($perSheet);

        $periodLabel = $this->periodLabel($from, $to);

        return view('stats.index', compact(
            'from', 'to', 'periodLabel',
            'perSheet', 'totals', 'byPosts', 'boardTypes', 'boardTechs',
            'targetUser', 'worker', 'workersList', 'canSeeWorkersStats', 'zoneFilters'
        ));
    }

    /**
     * Записи за цифрой карточки вкладки в сводке (выпуск / исправные / брак) — с теми же фильтрами,
     * что у сводки: период, исполнитель (только мастеру/админу), «мои записи» для исполнителя.
     */
    public function records(Request $r, StatsService $s)
    {
        $r->validate(['sheet' => ['required', 'string', 'max:60'], 'kind' => ['nullable', 'in:all,good,defect'],
                      'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'worker' => ['nullable', 'string', 'max:200'],
                      'board' => ['nullable', 'string', 'max:20'], 'post' => ['nullable', 'integer', 'min:0'],
                      'tech' => ['nullable', 'string', 'max:120']]);
        $slug = (string) $r->input('sheet');
        $cfg  = $this->sheetCfg($slug);
        abort_unless($cfg && ! empty($cfg['model']), 404);

        [$from, $to] = $this->resolveDateRange($r);
        $targetUser  = $this->resolveTargetUser($r);
        $canSeeWorkersStats = auth()->user()->isAdmin() || auth()->user()->isMaster();
        $worker = $canSeeWorkersStats ? trim((string) $r->input('worker', '')) : '';
        $kind   = $r->input('kind', 'all');

        // board — карточки «Борта по типам» (ПГП): код типа, '_none' или '_all'; там ✓/✕ — «сдан / не сдан»
        $board = $slug === 'pgp' && $r->filled('board') ? (string) $r->input('board') : null;
        // tech — карточки «Борта по технологам» (ПГП): имя технолога или '_none'; видят мастер, ОТК, админ
        $tech = $slug === 'pgp' && $r->filled('tech') ? (string) $r->input('tech') : null;
        if ($tech !== null) {
            abort_unless(self::canSeeTechnologistStats($r->user()), 403);
            $board ??= '_all';
        }
        $query = $board !== null
            ? $s->boardRecords($board === '_all' ? null : $board, $from, $to, $targetUser, $worker ?: null, $kind, $tech)
            : $s->periodRecords($slug, $from, $to, $targetUser, $worker ?: null, $kind);
        abort_if($board !== null && ! $query, 404);
        // post — клик по цифре в «Детализации по постам»: только записи этого поста
        $post = $r->filled('post') && isset($cfg['fields']['post_number']) ? (int) $r->input('post') : null;
        if ($query && $post !== null) $query->where('post_number', $post);
        $records = $query
            ? $query->with('creator')->paginate(100)->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 100);

        // какие записи на странице — брак (для отметки в таблице)
        $defectIds = [];
        if ($kind === 'defect' && $board === null) {
            $defectIds = array_fill_keys($records->pluck('id')->all(), true);
        } elseif ($records->count()) {
            $defectIds = array_fill_keys(
                $s->periodRecords($slug, $from, $to, $targetUser, $worker ?: null, 'defect')
                    ->whereIn('id', $records->pluck('id'))->pluck('id')->all(), true);
        }

        return view('stats.records', [
            'slug'    => $slug,
            'cfg'     => $cfg,
            'columns' => ReportController::listColumns($cfg),
            'records' => $records,
            'defectIds' => $defectIds,
            'kind'    => $kind,
            'board'   => $board,
            'tech'    => $tech,
            'boardLabel' => implode(' · ', array_filter([
                $post !== null ? 'пост ' . $post : null,
                $tech !== null ? ($tech === '_none' ? 'технолог не указан' : 'технолог ' . $tech) : null,
                $board === null || ($board === '_all' && ($post !== null || $tech !== null)) ? null
                    : ($board === '_all' ? 'все борта' : ($board === '_none' ? 'тип не указан' : 'тип ' . $board)),
            ])) ?: null,
            'post'    => $post,
            'worker'  => $worker,
            'periodLabel' => $this->periodLabel($from, $to),
            'filters' => array_filter($r->only(['from', 'to', 'worker', 'mode']), fn ($v) => $v !== null && $v !== ''),
        ]);
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

        // Топ постов — по СДАННЫМ бортам (status = «Сдан»), а не по общему прогону
        $hasStatus = isset($cfg['fields']['status']);
        $query = $model::select('post_number', DB::raw('COUNT(*) AS total'))
            ->selectRaw($hasStatus ? "SUM(CASE WHEN `status` = 'Сдан' THEN 1 ELSE 0 END) AS cnt" : 'COUNT(*) AS cnt')
            ->whereNotNull('post_number');

        $targetUser = $this->resolveTargetUser($r);
        if ($targetUser && $targetUser->isWorker()) {
            $s->applyUserScope($query, $cfg, $targetUser);
        }
        $this->applyDateRange($query, $from, $to);

        $rows = $query->groupBy('post_number')->orderByDesc('cnt')->orderByDesc('total')->orderBy('post_number')->get()
            ->map(fn ($row) => [
                'post_number' => $row->post_number,
                'cnt'         => (int) $row->cnt,                       // сдано
                'not_done'    => (int) $row->total - (int) $row->cnt,   // не сдано
                'total'       => (int) $row->total,
            ]);

        return response()->json($rows);
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
                if (!isset($cfg['model']) || !isset($cfg['fields']['fault_code'])) continue;   // группировка по сырому коду — ниже, привязка к справочнику в FaultCodeRating
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
            return app(\App\Services\FaultCodeRating::class)->rate($rows);
        });

        return response()->json($data);
    }

    /** Статистику по технологам видят только мастер, технолог (ОТК) и администратор. */
    public static function canSeeTechnologistStats(?User $u): bool
    {
        return $u && ($u->isAdmin() || $u->isMaster() || $u->isOtk());
    }

    /** Технологи: сколько бортов принял каждый за период и сколько из них сдано. */
    public function zone8(Request $r)
    {
        abort_unless(self::canSeeTechnologistStats($r->user()), 403);

        $from = $r->input('from');
        $to   = $r->input('to');
        $cacheKey = "stats.zone8.{$this->sectionKey()}.{$from}_{$to}";

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to) {
            $cfg = $this->sheets()['pgp'] ?? null;
            if (!$cfg || !isset($cfg['fields']['technologist'])) return [];

            $table = (new $cfg['model'])->getTable();
            [$dateSql, $dateBindings] = $this->sqlDateRange($from, $to);

            $res = DB::select(
                "SELECT `technologist` AS name, COUNT(*) AS boards,
                        SUM(CASE WHEN `status` = 'Сдан' THEN 1 ELSE 0 END) AS delivered
                 FROM `$table`
                 WHERE (`technologist` IS NOT NULL AND `technologist` != '')" . $dateSql . "
                 GROUP BY `technologist`",
                $dateBindings
            );

            $rows = array_map(fn ($row) => [
                'name'      => $row->name,
                'boards'    => (int) $row->boards,
                'delivered' => (int) $row->delivered,
            ], $res);
            usort($rows, fn ($a, $b) => [$b['boards'], $a['name']] <=> [$a['boards'], $b['name']]);

            return $rows;
        });

        return response()->json($data);
    }

    /**
     * Замены в исследованиях: что и сколько раз меняли за период, по каким узлам.
     * Одно и то же написание в разном регистре и с лишними пробелами — одна строка; «Без замены» не считается.
     */
    public function zone7(Request $r, StatsService $s)
    {
        $from = $r->input('from');
        $to   = $r->input('to');
        $targetUser = $this->resolveTargetUser($r);
        $cacheKey = "stats.zone7.{$this->sectionKey()}.{$from}_{$to}." . ($targetUser?->id ?? 'all');

        $data = Cache::remember($cacheKey, 120, function () use ($from, $to, $targetUser, $s) {
            $cfg = $this->sheets()['research'] ?? null;
            if (!$cfg || !isset($cfg['fields']['replacement'])) return [];

            $table = (new $cfg['model'])->getTable();
            $scope = $s->workerScopeSql($cfg, $targetUser);
            [$dateSql, $dateBindings] = $this->sqlDateRange($from, $to);

            $res = DB::select(
                "SELECT `replacement` AS name, `category` AS category, COUNT(*) AS cnt,
                        MIN(record_date) AS first_date, MAX(record_date) AS last_date
                 FROM `$table`
                 WHERE (`replacement` IS NOT NULL AND `replacement` != '')" . $scope['sql'] . $dateSql . "
                 GROUP BY `replacement`, `category`",
                array_merge($scope['bindings'], $dateBindings)
            );

            $agg = [];
            foreach ($res as $row) {
                $name = trim(preg_replace('/\s+/u', ' ', (string) $row->name));
                $key  = mb_strtolower($name);
                if ($name === '' || $key === 'без замены') continue;

                $agg[$key] ??= ['name' => $name, 'count' => 0, 'categories' => [], 'first_date' => null, 'last_date' => null, 'spelled' => []];
                $e = &$agg[$key];
                $e['count'] += (int) $row->cnt;
                $e['spelled'][$name] = ($e['spelled'][$name] ?? 0) + (int) $row->cnt;
                $cat = trim((string) $row->category) ?: 'Без категории';
                $e['categories'][$cat] = ($e['categories'][$cat] ?? 0) + (int) $row->cnt;
                $first = substr((string) $row->first_date, 0, 10);
                $last  = substr((string) $row->last_date, 0, 10);
                if ($first && (!$e['first_date'] || $first < $e['first_date'])) $e['first_date'] = $first;
                if ($last && (!$e['last_date'] || $last > $e['last_date'])) $e['last_date'] = $last;
                unset($e);
            }

            $rows = [];
            foreach ($agg as $e) {
                arsort($e['spelled']);
                arsort($e['categories']);
                $rows[] = [
                    'name'       => array_key_first($e['spelled']),   // самое частое написание
                    'count'      => $e['count'],
                    'categories' => $e['categories'],
                    'first_date' => $e['first_date'],
                    'last_date'  => $e['last_date'],
                ];
            }
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

            return $rows;
        });

        return response()->json($data);
    }
}