<x-app-layout>
    @push('styles')
    <style>
        [x-cloak]{display:none!important}

        /* ---- Зона 1: карточки вкладок ---- */
        .sheet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .75rem; }
        .sheet-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem;
            padding: 1rem; display: flex; flex-direction: column; gap: .5rem;
            transition: box-shadow .15s;
        }
        .sheet-card:hover { box-shadow: 0 4px 10px rgba(0,0,0,.06); }
        .sheet-card--empty { opacity: .6; }
        .sheet-card__title { font-size: .875rem; font-weight: 500; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sheet-card__total { font-size: 2rem; line-height: 1; font-weight: 800; color: #2563eb; font-variant-numeric: tabular-nums; }
        .sheet-card--empty .sheet-card__total { color: #d1d5db; }
        .sheet-card__unit  { font-size: .75rem; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .05em; margin-left: .25rem; }
        .sheet-card__bar   { display: flex; height: 6px; border-radius: 999px; overflow: hidden; background: #e5e7eb; }
        .sheet-card__bar-good { background: #10b981; }
        .sheet-card__bar-bad  { background: #ef4444; }
        .sheet-card__split { display: flex; justify-content: space-between; gap: .5rem; font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .sheet-card__good  { color: #059669; }
        .sheet-card__link  { color: inherit; text-decoration: underline dotted; text-decoration-thickness: 1px; text-underline-offset: 4px; }
        .sheet-card__link:hover { opacity: .75; }
        .sheet-card__bad   { color: #dc2626; }
        .sheet-card__muted { color: #9ca3af; font-weight: 400; }

        html.dark .sheet-card__title { color: #94a3b8; }
        html.dark .sheet-card        { background: #1e293b; border-color: #334155; }
        html.dark .sheet-card__total { color: #60a5fa; }
        html.dark .sheet-card--empty .sheet-card__total { color: #475569; }
        html.dark .sheet-card__bar   { background: #334155; }
        html.dark .sheet-card__good  { color: #34d399; }
        html.dark .sheet-card__bad   { color: #f87171; }

        /* ---- Борта по типам (УМН / МН / СОН …) ---- */
        .board-types { margin-bottom: 1rem; }
        .board-types__head { display: flex; align-items: baseline; gap: .5rem; margin-bottom: .5rem; }
        .board-types__title { font-size: .875rem; font-weight: 600; color: #374151; }
        .board-types__hint { font-size: .75rem; color: #9ca3af; }
        .sheet-card--accent { border-color: #93c5fd; background: #eff6ff; }
        html.dark .board-types__title { color: #cbd5e1; }
        html.dark .sheet-card--accent { border-color: #1d4ed8; background: #172554; }
    </style>
    @endpush

    <x-slot name="header">
        <div class="page-head">
            <h2 class="font-semibold text-xl text-gray-800">Статистика</h2>
            <a href="{{ route('stats.export', [
                    'from'   => $from ? $from->format('Y-m-d') : null,
                    'to'     => $to   ? $to->format('Y-m-d')   : null,
                    'worker' => $canSeeWorkersStats ? ($worker ?: null) : null,
                    'mode'   => request('mode'),
                ]) }}"
               class="ui-btn ui-btn--ghost">
                <svg class="w-4 h-4" style="color: var(--ui-good)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Скачать Excel
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6"
         x-data="statsZones({
             initialFrom: '{{ $from ? $from->format('Y-m-d') : '' }}',
             initialTo: '{{ $to ? $to->format('Y-m-d') : '' }}',
             mode: '{{ request('mode') === 'all' ? 'all' : 'my' }}',
             zoneFilters: @js($zoneFilters ?? [])
         })">

        {{-- ФИЛЬТРЫ --}}
        <div class="ui-card p-4">
            <form method="GET" action="{{ route('stats.index') }}" class="flex flex-wrap items-end gap-4">
                @if(auth()->user()->isWorker() && request('mode'))
                    <input type="hidden" name="mode" value="{{ request('mode') }}">
                @endif

                <div>
                    <label class="block text-xs text-gray-500 mb-1">С</label>
                    <input type="date" name="from" value="{{ $from ? $from->format('Y-m-d') : '' }}"
                           class="border rounded px-3 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">По</label>
                    <input type="date" name="to" value="{{ $to ? $to->format('Y-m-d') : '' }}"
                           class="border rounded px-3 py-1 text-sm">
                </div>
                @if($canSeeWorkersStats)
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Исполнитель</label>
                        <select name="worker" class="border rounded px-3 py-1 text-sm bg-white min-w-[220px]">
                            <option value="">— все —</option>
                            @foreach($workersList as $w)
                                <option value="{{ $w }}" @selected($worker === $w)>{{ $w }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm">Применить</button>
                <a href="{{ route('stats.index', ['reset' => 1]) }}" class="text-sm text-gray-500 hover:text-gray-800 underline px-2 py-1">Сбросить</a>
            </form>

            @if(auth()->user()->isWorker())
                <div class="mt-3 flex gap-1">
                    <a href="{{ route('stats.index', ['from' => $from ? $from->format('Y-m-d') : null, 'to' => $to ? $to->format('Y-m-d') : null, 'mode' => 'my']) }}"
                       class="px-3 py-1 text-sm rounded border {{ request('mode') !== 'all' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300' }}">
                        Моя
                    </a>
                    <a href="{{ route('stats.index', ['from' => $from ? $from->format('Y-m-d') : null, 'to' => $to ? $to->format('Y-m-d') : null, 'mode' => 'all']) }}"
                       class="px-3 py-1 text-sm rounded border {{ request('mode') === 'all' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300' }}">
                        Общая
                    </a>
                </div>
            @endif
        </div>

        <div class="text-sm text-gray-600">
            <strong>{{ $periodLabel }}</strong>
            @if($canSeeWorkersStats && $worker)
                <span class="ml-2 text-amber-700">· исполнитель: {{ $worker }}</span>
            @endif
            @if($targetUser && $targetUser->isWorker())
                <span class="ml-2 text-amber-700">· ваша статистика ({{ $targetUser->name }})</span>
            @endif
        </div>

        {{-- ЗОНА 1: сводка по вкладкам --}}
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">1. Сводка по вкладкам</h3>
                <div class="flex gap-2">
                    <button x-show="!expanded1" @click="toggleZone1()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded1" @click="toggleZone1()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded1" x-cloak>
                {{-- Борта ПГП: общее количество и по типам (типы — config/board_types.php) --}}
                @php
                    // ссылки с карточек на список записей — с теми же фильтрами, что у сводки
                    $statFilters = array_filter(['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'),
                        'worker' => $worker ?: null, 'mode' => request('mode')], fn ($v) => $v !== null && $v !== '');
                    $recUrl = fn ($slug, $kind = 'all', $board = null) => route('stats.records', $statFilters + array_filter(['sheet' => $slug, 'kind' => $kind, 'board' => $board]));
                @endphp
                @if(!empty($boardTypes))
                    @php
                        $btCards = [['label' => 'Всего бортов', 'total' => $boardTypes['total'], 'delivered' => $boardTypes['delivered'], 'after_repair' => $boardTypes['after_repair'] ?? 0, 'accent' => true, 'code' => '_all']];
                        foreach ($boardTypes['types'] as $code => $t) $btCards[] = $t + ['accent' => false, 'code' => (string) $code];
                        if ($boardTypes['none']['total'] > 0) $btCards[] = ['label' => 'Тип не указан', 'accent' => false, 'code' => '_none'] + $boardTypes['none'];
                    @endphp
                    <div class="board-types">
                        <div class="board-types__head">
                            <span class="board-types__title">Борта по типам</span>
                            <span class="board-types__hint">{{ $periodLabel }} · ✓ сдано / ✕ не сдано</span>
                        </div>
                        <div class="sheet-grid">
                            @foreach($btCards as $card)
                                @php
                                    $bt = $card['total']; $btOk = $card['delivered']; $btBad = $bt - $btOk;
                                    $btPct = $bt > 0 ? round($btBad / $bt * 100, 1) : 0;
                                    $btShare = $boardTypes['total'] > 0 ? round($bt / $boardTypes['total'] * 100) : 0;
                                @endphp
                                <div class="sheet-card {{ $card['accent'] ? 'sheet-card--accent' : '' }} {{ $bt === 0 ? 'sheet-card--empty' : '' }}">
                                    <div class="sheet-card__title" title="{{ $card['label'] }}">
                                        {{ $card['label'] }}@if(!$card['accent'] && $bt > 0) <span class="sheet-card__muted">· {{ $btShare }}%</span>@endif
                                    </div>
                                    <div class="sheet-card__total">@if($bt > 0)<a href="{{ $recUrl('pgp', 'all', $card['code']) }}" class="sheet-card__link" title="Открыть список бортов">{{ number_format($bt, 0, ',', ' ') }}</a>@else{{ number_format($bt, 0, ',', ' ') }}@endif<span class="sheet-card__unit">шт.</span></div>
                                    @if($bt > 0)
                                        <div class="sheet-card__bar" role="img" aria-label="Сдано {{ $btOk }}, не сдано {{ $btBad }}">
                                            <span class="sheet-card__bar-good" style="width: {{ 100 - $btPct }}%"></span>
                                            <span class="sheet-card__bar-bad"  style="width: {{ $btPct }}%"></span>
                                        </div>
                                        <div class="sheet-card__split">
                                            @if($btOk)<a href="{{ $recUrl('pgp', 'good', $card['code']) }}" class="sheet-card__good sheet-card__link" title="Открыть сданные">✓ {{ number_format($btOk, 0, ',', ' ') }}</a>@else<span class="sheet-card__good">✓ 0</span>@endif
                                            @if($btBad)<a href="{{ $recUrl('pgp', 'defect', $card['code']) }}" class="sheet-card__bad sheet-card__link" title="Открыть несданные">✕ {{ number_format($btBad, 0, ',', ' ') }}</a>@else<span class="sheet-card__bad">✕ 0</span>@endif
                                        </div>
                                        {{-- Подкатегория сданных: сдан после ремонта (повторная проверка) --}}
                                        @if($card['after_repair'] ?? 0)
                                            <div class="sheet-card__split">
                                                <a href="{{ $recUrl('pgp', 'repaired', $card['code']) }}" class="sheet-card__muted sheet-card__link" title="Открыть сданные после ремонта">в т.ч. после ремонта: {{ number_format($card['after_repair'], 0, ',', ' ') }}</a>
                                            </div>
                                        @endif
                                    @else
                                        <div class="sheet-card__split"><span class="sheet-card__muted">нет данных</span></div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif


                {{-- По вкладкам: выпуск / исправные / брак (общего итога нет — устройства разные) --}}
                <div class="sheet-grid">
                    @foreach($perSheet as $slug => $data)
                        @php
                            $total   = $data['total'];
                            $defects = $data['defects'] ?? 0;
                            $good    = $data['good'] ?? $total;
                            $pct     = $total > 0 ? round($defects / $total * 100, 1) : 0;
                            $unit    = $data['unit'] === 'записей' ? 'шт.' : $data['unit'];
                        @endphp
                        <div class="sheet-card {{ $total === 0 ? 'sheet-card--empty' : '' }}">
                            <div class="sheet-card__title" title="{{ $data['title'] }}">{{ $data['title'] }}</div>
                            <div class="sheet-card__total">
                                @if($total > 0)
                                    <a href="{{ $recUrl($slug) }}" class="sheet-card__link" title="Открыть список записей">{{ number_format($total, 0, ',', ' ') }}</a>
                                @else
                                    {{ number_format($total, 0, ',', ' ') }}
                                @endif
                                <span class="sheet-card__unit">{{ $unit }}</span>
                            </div>

                            @if($total > 0)
                                <div class="sheet-card__bar" role="img"
                                     aria-label="Исправных {{ $good }}, брак {{ $defects }}">
                                    <span class="sheet-card__bar-good" style="width: {{ 100 - $pct }}%"></span>
                                    <span class="sheet-card__bar-bad"  style="width: {{ $pct }}%"></span>
                                </div>
                                <div class="sheet-card__split">
                                    <a href="{{ $recUrl($slug, 'good') }}" class="sheet-card__good sheet-card__link" title="Открыть исправные">✓ {{ number_format($good, 0, ',', ' ') }}</a>
                                    @if($data['hasDefects'] ?? true)
                                        @if($defects > 0)
                                            <a href="{{ $recUrl($slug, 'defect') }}" class="sheet-card__bad sheet-card__link" title="Открыть брак">✕ {{ number_format($defects, 0, ',', ' ') }} · {{ $pct }}%</a>
                                        @else
                                            <span class="sheet-card__bad">✕ {{ number_format($defects, 0, ',', ' ') }} · {{ $pct }}%</span>
                                        @endif
                                    @else
                                        <span class="sheet-card__muted">брак не учитывается</span>
                                    @endif
                                </div>
                            @else
                                <div class="sheet-card__split"><span class="sheet-card__muted">нет данных</span></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Сводка по представителям ОТК (технологам): кто принял борта — отдельный блок, только мастеру и администратору --}}
        @if(\App\Http\Controllers\StatsController::canSeeTechnologistSummary(auth()->user()) && !empty($boardTechs) && ($boardTechs['techs'] || $boardTechs['none']['total']))
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">Сводка по представителям ОТК <span class="text-sm font-normal text-gray-400">({{ $periodLabel }})</span></h3>
            </div>
                @php
                    $techUrl = fn ($kind, $tech) => route('stats.records', $statFilters + array_filter(['sheet' => 'pgp', 'kind' => $kind, 'board' => '_all', 'tech' => $tech]));
                    $techCards = [];
                    foreach ($boardTechs['techs'] as $name => $t) $techCards[] = $t + ['code' => $name];
                    if ($boardTechs['none']['total'] > 0) $techCards[] = ['label' => 'Технолог не указан', 'code' => '_none'] + $boardTechs['none'];
                    $techTotal = array_sum(array_column($techCards, 'total'));
                @endphp
                <div class="board-types">
                    <div class="board-types__head">
                        <span class="board-types__title">Борта по представителям ОТК</span>
                        <span class="board-types__hint">{{ $periodLabel }} · кто принял, по количеству · ✓ сдано / ✕ не сдано</span>
                    </div>
                    <div class="sheet-grid">
                        @foreach($techCards as $card)
                            @php
                                $bt = $card['total']; $btOk = $card['delivered']; $btBad = $bt - $btOk;
                                $btPct = $bt > 0 ? round($btBad / $bt * 100, 1) : 0;
                                $btShare = $techTotal > 0 ? round($bt / $techTotal * 100) : 0;
                            @endphp
                            <div class="sheet-card">
                                <div class="sheet-card__title" title="{{ $card['label'] }}">
                                    {{ $card['label'] }} <span class="sheet-card__muted">· {{ $btShare }}%</span>
                                </div>
                                <div class="sheet-card__total"><a href="{{ $techUrl('all', $card['code']) }}" class="sheet-card__link" title="Открыть список бортов">{{ number_format($bt, 0, ',', ' ') }}</a><span class="sheet-card__unit">шт.</span></div>
                                <div class="sheet-card__bar" role="img" aria-label="Сдано {{ $btOk }}, не сдано {{ $btBad }}">
                                    <span class="sheet-card__bar-good" style="width: {{ 100 - $btPct }}%"></span>
                                    <span class="sheet-card__bar-bad"  style="width: {{ $btPct }}%"></span>
                                </div>
                                <div class="sheet-card__split">
                                    @if($btOk)<a href="{{ $techUrl('good', $card['code']) }}" class="sheet-card__good sheet-card__link" title="Открыть сданные">✓ {{ number_format($btOk, 0, ',', ' ') }}</a>@else<span class="sheet-card__good">✓ 0</span>@endif
                                    @if($btBad)<a href="{{ $techUrl('defect', $card['code']) }}" class="sheet-card__bad sheet-card__link" title="Открыть несданные">✕ {{ number_format($btBad, 0, ',', ' ') }}</a>@else<span class="sheet-card__bad">✕ 0</span>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
        </div>
        @endif

        {{-- ЗОНА 2: детализация по постам --}}
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">2. Детализация по постам <span class="text-sm font-normal text-gray-400">({{ $periodLabel }})</span></h3>
                <div class="flex gap-2">
                    <button x-show="!expanded3" @click="toggleZone3()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded3" @click="toggleZone3()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded3" x-cloak>
                <div x-show="loading3" class="text-gray-500 text-sm">Загрузка…</div>
                <div x-show="loaded3 && !loading3">
                    <div class="overflow-x-auto table-scroll">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="p-3 text-left">#</th>
                                    <th class="p-3 text-left">Пост</th>
                                    <th class="p-3 text-right">Сдано бортов</th>
                                    <th class="p-3 text-right">Не сдано</th>
                                    <th class="p-3 text-right">Всего</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone3" :key="row.post_number">
                                    <tr class="border-t hover:bg-gray-50">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-medium" x-text="'Пост ' + row.post_number"></td>
                                        <td class="p-3 text-right font-bold">
                                            <a x-show="row.cnt > 0" :href="postUrl(row.post_number, 'good')" class="text-green-700 hover:underline" title="Открыть сданные" x-text="'✓ ' + row.cnt"></a>
                                            <span x-show="row.cnt === 0" class="text-gray-400">✓ 0</span>
                                        </td>
                                        <td class="p-3 text-right font-bold">
                                            <a x-show="row.not_done > 0" :href="postUrl(row.post_number, 'defect')" class="text-red-600 hover:underline" title="Открыть несданные" x-text="'✕ ' + row.not_done"></a>
                                            <span x-show="row.not_done === 0" class="text-gray-400">✕ 0</span>
                                        </td>
                                        <td class="p-3 text-right">
                                            <a :href="postUrl(row.post_number, 'all')" class="text-blue-700 hover:underline" title="Открыть все борта поста" x-text="row.total"></a>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="zone3.length === 0" class="p-3 text-gray-500 text-sm">Нет данных за период</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ЗОНА 3: сводка по исполнителю — только admin/master --}}
        @if($canSeeWorkersStats)
            <div class="ui-card p-5">
                <div class="stat-card-head mb-3">
                    <h3 class="font-semibold text-lg text-gray-800">3. Сводка по исполнителю</h3>
                    <div class="flex gap-2">
                        <button x-show="!expanded2" @click="toggleZone2()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                        <button x-show="expanded2" @click="toggleZone2()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                    </div>
                </div>
                <div x-show="expanded2" x-cloak>
                    <div x-show="loading2" class="text-gray-500 text-sm">Загрузка…</div>
                    <div x-show="loaded2 && !loading2">
                        <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                            <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom2" @change="loadZone2(true)" class="border rounded px-2 py-1 ml-1"></label>
                            <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo2" @change="loadZone2(true)" class="border rounded px-2 py-1 ml-1"></label>
                            <button @click="resetDates()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                        </div>
                        <div class="mb-4">
                            <label class="block text-xs text-gray-500 mb-1">Исполнитель</label>
                            <select x-model="selectedExecutor" @change="onExecutorChange()" class="border rounded px-3 py-2 text-sm bg-white w-full md:w-1/2">
                                <option value="">— Все исполнители —</option>
                                <template x-for="row in zone2Rows" :key="row.name">
                                    <option :value="row.name" x-text="row.name + ' (' + row.cnt + ')'"></option>
                                </template>
                            </select>
                        </div>

                        <template x-if="!selectedExecutor">
                            <div>
                                <div class="mb-4 flex items-baseline gap-2">
                                    <span class="text-sm text-gray-600">Найдено записей (все вкладки участка):</span>
                                    <span class="text-3xl font-extrabold text-blue-700" x-text="zone2Total"></span>
                                </div>
                                <div class="relative h-72"><canvas id="zone2Chart"></canvas></div>
                                <div x-show="chartError" x-text="chartError" class="mt-2 text-sm text-red-600"></div>
                            </div>
                        </template>

                        <template x-if="selectedExecutor">
                            <div>
                                <div x-show="loadingDetails" class="text-gray-500 text-sm py-4">Загрузка категорий…</div>
                                <div x-show="!loadingDetails">
                                    <div class="mb-3 flex items-baseline gap-2">
                                        <span class="text-sm text-gray-600">Исполнитель:</span>
                                        <span class="text-lg font-bold text-gray-800" x-text="selectedExecutor"></span>
                                        <span class="text-sm text-gray-500">· категорий: <strong x-text="workerCategories.length"></strong></span>
                                    </div>
                                    <div class="space-y-2">
                                        <template x-for="cat in workerCategories" :key="cat.slug">
                                            <div class="border border-gray-200 rounded">
                                                <button type="button" @click="toggleCategory(cat.slug)" class="w-full flex justify-between items-center p-3 hover:bg-gray-50 text-left">
                                                    <span class="font-medium text-gray-800" x-text="cat.title"></span>
                                                    <span class="flex items-baseline gap-2">
                                                        <span class="text-blue-700 font-bold text-lg" x-text="cat.total"></span>
                                                        <span class="text-xs text-gray-500" x-text="cat.unit"></span>
                                                        <span class="ml-2 text-gray-400 text-xs" x-text="expandedCategories[cat.slug] ? '▼' : '▶'"></span>
                                                    </span>
                                                </button>
                                                <div x-show="expandedCategories[cat.slug]" x-cloak class="border-t border-gray-200 bg-gray-50 p-3">
                                                    <div x-show="loadingRecords[cat.slug]" class="text-sm text-gray-500">Загрузка записей…</div>
                                                    <div x-show="!loadingRecords[cat.slug]">
                                                        <div class="overflow-x-auto table-scroll">
                                                            <table class="min-w-full text-sm">
                                                                <thead class="text-gray-600">
                                                                    <tr>
                                                                        <th class="p-2 text-left">Дата</th>
                                                                        <th class="p-2 text-left">Пост</th>
                                                                        <th class="p-2 text-left">Статус</th>
                                                                        <th class="p-2 text-right">Кол-во</th>
                                                                        <th class="p-2 text-left">Код неиспр.</th>
                                                                        <th class="p-2 text-left">Поле</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <template x-for="rec in (workerRecords[cat.slug] || [])" :key="rec.id">
                                                                        <tr class="border-t border-gray-200 hover:bg-white">
                                                                            <td class="p-2 text-gray-700" x-text="rec.date || '—'"></td>
                                                                            <td class="p-2 text-gray-700" x-text="rec.post || '—'"></td>
                                                                            <td class="p-2 text-gray-700" x-text="rec.status || '—'"></td>
                                                                            <td class="p-2 text-right text-gray-700" x-text="rec.qty ?? '—'"></td>
                                                                            <td class="p-2 font-mono text-xs text-gray-700 ui-tip" :data-tip="rec.fault_desc || null" :tabindex="rec.fault_desc ? 0 : null" x-text="rec.fault_code || '—'"></td>
                                                                            <td class="p-2 text-xs text-gray-400" x-text="rec.worker_field || '—'"></td>
                                                                        </tr>
                                                                    </template>
                                                                </tbody>
                                                            </table>
                                                            <div x-show="(workerRecords[cat.slug] || []).length === 0" class="p-2 text-sm text-gray-500">Нет записей</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div x-show="workerCategories.length === 0" class="p-3 text-sm text-gray-500">За выбранный период исполнитель не участвовал ни в одной категории.</div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        @endif

        {{-- ЗОНА 4: график по постам — только admin/master --}}
        @if($canSeeWorkersStats)
            <div class="ui-card p-5">
                <div class="stat-card-head mb-3">
                    <h3 class="font-semibold text-lg text-gray-800">4. График по постам</h3>
                    <div class="flex gap-2">
                        <button x-show="!expanded4" @click="toggleZone4()" class="ui-btn ui-btn--ghost ui-btn--sm">Открыть график</button>
                        <button x-show="expanded4" @click="toggleZone4()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                    </div>
                </div>
                <div x-show="expanded4" x-cloak>
                    <div class="flex flex-wrap gap-3 mb-3 items-end bg-gray-50 p-3 rounded border border-gray-100">
                        <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom" @change="loadZone4()" class="border rounded px-2 py-1 ml-1"></label>
                        <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo" @change="loadZone4()" class="border rounded px-2 py-1 ml-1"></label>
                        <button @click="resetDates4()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                        <span class="text-xs text-gray-500 ml-auto">Клик по точке → список записей поста за день</span>
                    </div>
                    <div class="relative h-80"><canvas id="zone4Chart" style="cursor:pointer"></canvas></div>
                    <div x-show="zone4Error" x-text="zone4Error" class="mt-2 text-sm text-red-600"></div>
                </div>
            </div>
        @endif

        {{-- ЗОНА 5: брак по категориям --}}
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">5. Брак по категориям</h3>
                <div class="flex gap-2">
                    <button x-show="!expanded5" @click="toggleZone5()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded5" @click="toggleZone5()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded5" x-cloak>
                <div x-show="loading5" class="text-gray-500 text-sm">Загрузка…</div>
                <div x-show="loaded5 && !loading5">
                    <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                        <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom5" @change="onZone5FiltersChange()" class="border rounded px-2 py-1 ml-1"></label>
                        <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo5" @change="onZone5FiltersChange()" class="border rounded px-2 py-1 ml-1"></label>
                        <button @click="resetDates5()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                        <div class="flex-1 min-w-[240px]">
                            <label class="block text-xs text-gray-500 mb-1">Категория</label>
                            <select x-model="selectedCategory" @change="onCategoryChange()" class="border rounded px-3 py-2 text-sm bg-white w-full">
                                <option value="">— Все категории —</option>
                                <template x-for="row in zone5Rows" :key="row.slug">
                                    <option :value="row.slug" x-text="row.title + ' — брак ' + row.defects + ' из ' + row.total + (row.total ? ' (' + row.percent + '%)' : '')"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    {{-- Итог по выбранному (всем категориям или одной) --}}
                    <div class="grid gap-2 grid-cols-2 md:grid-cols-4 mb-4">
                        <div class="z5-kpi"><span>Записей</span><strong x-text="z5Sum().total"></strong></div>
                        <div class="z5-kpi"><span>Брак</span><strong style="color: var(--ui-bad)" x-text="z5Sum().defects"></strong></div>
                        <div class="z5-kpi"><span>% брака</span><strong x-text="z5Pct(z5Sum().defects, z5Sum().total)"></strong></div>
                        <div class="z5-kpi"><span>Исправили на посту</span><strong style="color: var(--ui-good)" x-text="z5Sum().fixed + ' (' + z5Pct(z5Sum().fixed, z5Sum().total) + ')'"></strong></div>
                    </div>

                    <div x-show="!selectedCategory && !z5ChartRows().length" class="text-center text-sm py-10" style="color: var(--ui-muted)">За период записей нет</div>
                    <div x-show="selectedCategory || z5ChartRows().length" class="relative"
                         :style="'height:' + (selectedCategory ? 384 : Math.max(220, z5ChartRows().length * 38 + 70)) + 'px'">
                        <canvas id="zone5Chart"></canvas>
                    </div>

                    <div x-show="selectedCategory" class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="selectedCategory = ''; onCategoryChange()">← Все категории</button>
                        @if(auth()->user()->canManageContent())
                            <a :href="'{{ route('reports.defects') }}?sheet=' + selectedCategory + (dateFrom5 ? '&from=' + dateFrom5 : '') + (dateTo5 ? '&to=' + dateTo5 : '')"
                               class="ui-btn ui-btn--ghost ui-btn--sm">Причины брака в этой категории →</a>
                        @endif
                    </div>

                    {{-- Таблица по всем категориям: полные названия и цифры --}}
                    <div x-show="!selectedCategory" class="mt-5 overflow-x-auto">
                        <table class="ui-table z5-table">
                            <thead>
                                <tr>
                                    <th>Категория</th>
                                    <th class="text-right">Записей</th>
                                    <th class="text-right">Брак</th>
                                    <th class="text-right">% брака</th>
                                    <th class="text-right">Исправили</th>
                                    <th style="width: 22%"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="row in zone5Rows" :key="'t' + row.slug">
                                    <tr @click="row.total && (selectedCategory = row.slug, onCategoryChange())" :class="row.total ? 'cursor-pointer' : 'z5-empty'"
                                        :title="row.total ? 'Показать по дням' : 'За период записей нет'">
                                        <td class="font-medium" x-text="row.title"></td>
                                        <td class="text-right tabular-nums" x-text="row.total"></td>
                                        <td class="text-right tabular-nums" :style="row.defects ? 'color: var(--ui-bad); font-weight: 600' : ''" x-text="row.defects"></td>
                                        <td class="text-right tabular-nums" x-text="row.total ? row.percent + '%' : '—'"></td>
                                        <td class="text-right tabular-nums" :style="row.fixed ? 'color: var(--ui-good)' : ''" x-text="row.fixed"></td>
                                        <td><div class="ui-meter"><span :style="'width:' + Math.min(100, row.percent) + '%; background: var(--ui-bad)'"></span></div></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot>
                                <tr class="font-semibold">
                                    <td>Итого</td>
                                    <td class="text-right tabular-nums" x-text="z5Sum(true).total"></td>
                                    <td class="text-right tabular-nums" style="color: var(--ui-bad)" x-text="z5Sum(true).defects"></td>
                                    <td class="text-right tabular-nums" x-text="z5Pct(z5Sum(true).defects, z5Sum(true).total)"></td>
                                    <td class="text-right tabular-nums" style="color: var(--ui-good)" x-text="z5Sum(true).fixed"></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ЗОНА 6: рейтинг кодов неисправностей --}}
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">6. Рейтинг кодов неисправностей</h3>
                <div class="flex gap-2">
                    <button x-show="!expanded6" @click="toggleZone6()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded6" @click="toggleZone6()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded6" x-cloak>
                <div x-show="loading6" class="text-gray-500 text-sm">Загрузка…</div>
                <div x-show="loaded6 && !loading6">
                    <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                        <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom6" @change="loadZone6(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo6" @change="loadZone6(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <button @click="resetDates6()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                        <div class="flex-1 min-w-[240px]">
                            <label class="block text-xs text-gray-500 mb-1">Категория</label>
                            <select x-model="selectedCategory6" @change="loadZone6(true)" class="border rounded px-3 py-2 text-sm bg-white w-full">
                                <option value="">— Все категории —</option>
                                @foreach(app(\App\Services\SectionContext::class)->sheets() as $slug => $cfg)
                                    @if(isset($cfg['fields']['fault_code']))
                                        <option value="{{ $slug }}">{{ $cfg['title'] ?? $slug }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-4 flex flex-wrap gap-4 items-baseline text-sm">
                        <span class="text-gray-600">Всего кодов: <strong class="text-lg text-gray-800" x-text="zone6Rows.length"></strong></span>
                        <span class="text-gray-600">Суммарно вхождений: <strong class="text-lg text-blue-700" x-text="zone6Total()"></strong></span>
                    </div>
                    <div class="overflow-x-auto table-scroll">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="p-3 text-left w-12">#</th>
                                    <th class="p-3 text-left">Код неисправности</th>
                                    <th class="p-3 text-left">Описание</th>
                                    <th class="p-3 text-left">Категория</th>
                                    <th class="p-3 text-right">Кол-во</th>
                                    <th class="p-3 text-left">Первое появление</th>
                                    <th class="p-3 text-left">Последнее появление</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone6Rows" :key="row.key">
                                    <tr class="border-t hover:bg-gray-50" :title="Object.keys(row.variants).length > 1 ? 'Записано по-разному: ' + Object.keys(row.variants).join(' | ') : null">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-mono font-semibold text-gray-900">
                                            <span x-text="row.code || row.text"></span>
                                            <span x-show="!row.known" x-cloak class="ml-1 text-xs font-normal text-amber-600">нет в справочнике</span>
                                        </td>
                                        <td class="p-3 text-gray-700" x-text="row.description || '—'"></td>
                                        <td class="p-3 text-gray-600" x-text="Object.values(row.sheets).join(', ')"></td>
                                        <td class="p-3 text-right font-bold text-red-700" x-text="row.count"></td>
                                        <td class="p-3 text-gray-500" x-text="formatDate(row.first_date)"></td>
                                        <td class="p-3 text-gray-500" x-text="formatDate(row.last_date)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="zone6Rows.length === 0" class="p-3 text-gray-500 text-sm">Нет данных за период</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ЗОНА 7: замены в исследованиях --}}
        @if(isset(app(\App\Services\SectionContext::class)->sheets()['research']))
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">7. Ремонт и замена в исследованиях</h3>
                <div class="flex gap-2">
                    <button x-show="!expanded7" @click="toggleZone7()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded7" @click="toggleZone7()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded7" x-cloak>
                <div x-show="loading7" class="text-gray-500 text-sm">Загрузка…</div>
                <div x-show="loaded7 && !loading7">
                    <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                        <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom7" @change="loadZone7(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo7" @change="loadZone7(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <button @click="resetDates7()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                    </div>
                    <div class="mb-4 flex flex-wrap gap-4 items-baseline text-sm">
                        <span class="text-gray-600">Узлов: <strong class="text-lg text-gray-800" x-text="zone7Rows.length"></strong></span>
                        <span class="text-gray-600">Замен: <strong class="text-lg text-red-700" x-text="zone7Rows.reduce((s, r) => s + Number(r.replacements), 0)"></strong></span>
                        <span class="text-gray-600">Ремонтов: <strong class="text-lg text-blue-700" x-text="zone7Rows.reduce((s, r) => s + Number(r.repairs), 0)"></strong></span>
                    </div>
                    <div class="overflow-x-auto table-scroll">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="p-3 text-left w-12">#</th>
                                    <th class="p-3 text-left">Узел</th>
                                    <th class="p-3 text-left">Категории</th>
                                    <th class="p-3 text-right">Замен</th>
                                    <th class="p-3 text-right">Ремонтов</th>
                                    <th class="p-3 text-left">Первая</th>
                                    <th class="p-3 text-left">Последняя</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone7Rows" :key="row.name">
                                    <tr class="border-t hover:bg-gray-50">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-semibold text-gray-900" x-text="row.name"></td>
                                        <td class="p-3 text-gray-600" x-text="Object.entries(row.categories).map(([c, n]) => c + ' (' + n + ')').join(', ')"></td>
                                        <td class="p-3 text-right font-bold text-red-700" x-text="row.replacements"></td>
                                        <td class="p-3 text-right font-bold text-blue-700" x-text="row.repairs"></td>
                                        <td class="p-3 text-gray-500" x-text="formatDate(row.first_date)"></td>
                                        <td class="p-3 text-gray-500" x-text="formatDate(row.last_date)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="zone7Rows.length === 0" class="p-3 text-gray-500 text-sm">Нет ремонтов и замен за период</div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- ЗОНА 8: технологи — только мастер, технолог и администратор --}}
        @if(\App\Http\Controllers\StatsController::canSeeTechnologistStats(auth()->user()) && isset(app(\App\Services\SectionContext::class)->sheets()['pgp']))
        <div class="ui-card p-5">
            <div class="stat-card-head mb-3">
                <h3 class="font-semibold text-lg text-gray-800">8. Представители ОТК: принятые борта</h3>
                <div class="flex gap-2">
                    <button x-show="!expanded8" @click="toggleZone8()" class="ui-btn ui-btn--ghost ui-btn--sm">Показать</button>
                    <button x-show="expanded8" @click="toggleZone8()" class="ui-btn ui-btn--ghost ui-btn--sm">Скрыть</button>
                </div>
            </div>
            <div x-show="expanded8" x-cloak>
                <div x-show="loading8" class="text-gray-500 text-sm">Загрузка…</div>
                <div x-show="loaded8 && !loading8">
                    <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                        <label class="text-sm text-gray-600">С: <input type="date" x-model="dateFrom8" @change="loadZone8(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <label class="text-sm text-gray-600">По: <input type="date" x-model="dateTo8" @change="loadZone8(true)" class="border rounded px-2 py-1 ml-1"></label>
                        <button @click="resetDates8()" class="text-sm text-gray-500 hover:text-gray-800 underline">Сбросить даты</button>
                    </div>
                    <div class="mb-4 flex flex-wrap gap-4 items-baseline text-sm">
                        <span class="text-gray-600">Принято бортов: <strong class="text-lg text-gray-800" x-text="zone8Boards()"></strong></span>
                    </div>
                    <div class="overflow-x-auto table-scroll">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="p-3 text-left w-12">#</th>
                                    <th class="p-3 text-left">Представитель ОТК</th>
                                    <th class="p-3 text-right">Принято бортов</th>
                                    <th class="p-3 text-right">Сдано</th>
                                    <th class="p-3 text-right">Не сдано</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone8Rows" :key="row.name">
                                    <tr class="border-t hover:bg-gray-50">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-semibold text-gray-900" x-text="row.name"></td>
                                        {{-- Цифры — ссылки на список бортов технолога за период блока --}}
                                        <td class="p-3 text-right font-bold text-gray-800">
                                            <a :href="zone8Url(row.name, 'all')" class="underline decoration-dotted underline-offset-4 hover:opacity-75" title="Открыть список бортов" x-text="row.boards"></a>
                                        </td>
                                        <td class="p-3 text-right text-green-700">
                                            <a x-show="row.delivered > 0" :href="zone8Url(row.name, 'good')" class="underline decoration-dotted underline-offset-4 hover:opacity-75" title="Открыть сданные" x-text="row.delivered"></a>
                                            <span x-show="row.delivered === 0">0</span>
                                        </td>
                                        <td class="p-3 text-right text-red-700">
                                            <a x-show="row.boards - row.delivered > 0" :href="zone8Url(row.name, 'defect')" class="underline decoration-dotted underline-offset-4 hover:opacity-75" title="Открыть несданные" x-text="row.boards - row.delivered"></a>
                                            <span x-show="row.boards - row.delivered === 0">0</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="zone8Rows.length === 0" class="p-3 text-gray-500 text-sm">Нет принятых бортов за период</div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- МОДАЛКА: записи поста за день --}}
        <div x-show="recordsModalOpen" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
             @click.self="recordsModalOpen = false">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
                <div class="flex justify-between items-center gap-2 p-4 border-b border-gray-200">
                    <h3 class="font-semibold text-lg text-gray-800">
                        Записи поста <span x-text="recordsContext.post"></span>
                        за <span x-text="formatDate(recordsContext.date)"></span>
                    </h3>
                    <button type="button" @click="recordsModalOpen = false"
                            class="text-gray-400 hover:text-gray-800 text-2xl leading-none w-8 h-8 flex items-center justify-center rounded">
                        &times;
                    </button>
                </div>
                <div class="p-4 overflow-y-auto flex-1">
                    <div x-show="recordsLoading" class="text-gray-500 text-sm">Загрузка…</div>
                    <div x-show="!recordsLoading">
                        <div class="table-scroll">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="p-2 text-left w-10">#</th>
                                    <th class="p-2 text-left">Дата</th>
                                    <th class="p-2 text-left">Пост</th>
                                    <th class="p-2 text-left">Статус</th>
                                    <th class="p-2 text-left">Исполнители</th>
                                    <th class="p-2 text-right w-24">Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(rec, idx) in recordsList" :key="rec.id">
                                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                                        <td class="p-2 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-2 text-gray-700" x-text="formatDate(rec.record_date)"></td>
                                        <td class="p-2 text-gray-700" x-text="'Пост ' + rec.post_number"></td>
                                        <td class="p-2">
                                            <span :class="rec.status === 'Сдан' ? 'text-green-700 font-semibold' : 'text-amber-700'"
                                                  x-text="rec.status || '—'"></span>
                                        </td>
                                        <td class="p-2 text-xs text-gray-600" x-text="recordWorkers(rec)"></td>
                                        <td class="p-2 text-right">
                                            <a :href="recordUrl(rec.id)" target="_blank"
                                               class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 text-xs rounded">
                                                Открыть
                                            </a>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        </div>
                        <div x-show="recordsList.length === 0" class="p-3 text-sm text-gray-500">
                            За этот день записей поста нет
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    @push('scripts')
        <script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
        <script>window.STATS_URLS = @js(['saveFilters' => route('stats.saveFilters'), 'zone7' => route('stats.zone7'), 'zone8' => route('stats.zone8'), 'records' => route('stats.records'), 'zone3' => route('stats.zone3'), 'zone2' => route('stats.zone2'), 'zone2Chart' => route('stats.zone2Chart'), 'zone2Worker' => route('stats.zone2Worker'), 'zone4' => route('stats.zone4'), 'zone4Records' => route('stats.zone4Records'), 'zone5' => route('stats.zone5'), 'zone5Chart' => route('stats.zone5Chart'), 'zone6' => route('stats.zone6'), 'pgpRecord' => url('/records/pgp')]);</script>
        <script src="{{ asset('js/stats.js') }}?v={{ filemtime(public_path('js/stats.js')) }}"></script>
    @endpush
</x-app-layout>