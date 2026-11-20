<?php

namespace App\Http\Controllers;

use App\Exports\StatsExport;
use App\Models\User;
use App\Services\SectionContext;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class StatsController extends Controller
{
    use Concerns\StatsScope;

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
        // «Борта по технологам» — только мастеру, технологу и администратору
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
        $r->validate(['sheet' => ['required', 'string', 'max:60'], 'kind' => ['nullable', 'in:all,good,defect,repaired'],
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
        // «Сдано после ремонта» — только у бортов ПГП
        if ($kind === 'repaired') { if ($slug === 'pgp') $board ??= '_all'; else $kind = 'all'; }
        // tech — карточки «Борта по технологам» (ПГП): имя технолога или '_none'; видят мастер, технолог, админ
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

    /** Статистику по технологам видят только мастер, технолог и администратор. */
    public static function canSeeTechnologistStats(?User $u): bool
    {
        return $u && ($u->isAdmin() || $u->isMaster() || $u->isTechnologist());
    }

    /** Отдельный блок «Сводка по представителям ОТК» на странице статистики — только мастер и администратор. */
    public static function canSeeTechnologistSummary(?User $u): bool
    {
        return $u && ($u->isAdmin() || $u->isMaster());
    }
}
