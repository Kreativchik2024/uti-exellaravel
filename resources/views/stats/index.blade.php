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
                @if(auth()->user()->isExecutor() && request('mode'))
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

            @if(auth()->user()->isExecutor())
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
            @if($targetUser && $targetUser->isExecutor())
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
                @if(!empty($boardTypes))
                    @php
                        $btCards = [['label' => 'Всего бортов', 'total' => $boardTypes['total'], 'delivered' => $boardTypes['delivered'], 'accent' => true]];
                        foreach ($boardTypes['types'] as $code => $t) $btCards[] = $t + ['accent' => false];
                        if ($boardTypes['none']['total'] > 0) $btCards[] = ['label' => 'Тип не указан', 'accent' => false] + $boardTypes['none'];
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
                                    <div class="sheet-card__total">{{ number_format($bt, 0, ',', ' ') }}<span class="sheet-card__unit">шт.</span></div>
                                    @if($bt > 0)
                                        <div class="sheet-card__bar" role="img" aria-label="Сдано {{ $btOk }}, не сдано {{ $btBad }}">
                                            <span class="sheet-card__bar-good" style="width: {{ 100 - $btPct }}%"></span>
                                            <span class="sheet-card__bar-bad"  style="width: {{ $btPct }}%"></span>
                                        </div>
                                        <div class="sheet-card__split">
                                            <span class="sheet-card__good">✓ {{ number_format($btOk, 0, ',', ' ') }}</span>
                                            <span class="sheet-card__bad">✕ {{ number_format($btBad, 0, ',', ' ') }}</span>
                                        </div>
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
                                {{ number_format($total, 0, ',', ' ') }}
                                <span class="sheet-card__unit">{{ $unit }}</span>
                            </div>

                            @if($total > 0)
                                <div class="sheet-card__bar" role="img"
                                     aria-label="Исправных {{ $good }}, брак {{ $defects }}">
                                    <span class="sheet-card__bar-good" style="width: {{ 100 - $pct }}%"></span>
                                    <span class="sheet-card__bar-bad"  style="width: {{ $pct }}%"></span>
                                </div>
                                <div class="sheet-card__split">
                                    <span class="sheet-card__good">✓ {{ number_format($good, 0, ',', ' ') }}</span>
                                    @if($data['hasDefects'] ?? true)
                                        <span class="sheet-card__bad">✕ {{ number_format($defects, 0, ',', ' ') }} · {{ $pct }}%</span>
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
                                    <th class="p-3 text-right">Сделано бортов</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone3" :key="row.post_number">
                                    <tr class="border-t hover:bg-gray-50">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-medium" x-text="'Пост ' + row.post_number"></td>
                                        <td class="p-3 text-right font-bold text-blue-700" x-text="row.cnt"></td>
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
                                                                            <td class="p-2 font-mono text-xs text-gray-700" x-text="rec.fault_code || '—'"></td>
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
                                    <th class="p-3 text-left">Категория</th>
                                    <th class="p-3 text-right">Кол-во</th>
                                    <th class="p-3 text-left">Первое появление</th>
                                    <th class="p-3 text-left">Последнее появление</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in zone6Rows" :key="row.code + '|' + row.sheet">
                                    <tr class="border-t hover:bg-gray-50">
                                        <td class="p-3 text-gray-400" x-text="idx + 1"></td>
                                        <td class="p-3 font-mono font-semibold text-gray-900" x-text="row.code"></td>
                                        <td class="p-3 text-gray-600" x-text="row.sheet_title"></td>
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
        <script>
            // Цвета подписей и сетки графиков под текущую тему
            (function () {
                function applyChartTheme() {
                    if (!window.Chart) return;
                    var dark = document.documentElement.classList.contains('dark');
                    Chart.defaults.color       = dark ? '#cbd5e1' : '#4b5563';
                    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;   // Inter, как на сайте
                    Chart.defaults.font.size   = 13;                                         // было 12 — в тон общему кеглю
                    Chart.defaults.borderColor = dark ? 'rgba(148, 163, 184, 0.18)' : 'rgba(0, 0, 0, 0.1)';
                    Object.values(Chart.instances || {}).forEach(function (c) { c.update('none'); });
                }
                applyChartTheme();
                document.addEventListener('themechange', applyChartTheme);
            })();

            // Сохранённый в сессии фильтр зоны; если его нет — берём основной период
            function zoneDate(opts, zone, key) {
                const z = (opts.zoneFilters || {})[zone];
                if (z && typeof z[key] === 'string') return z[key];
                return (key === 'from' ? opts.initialFrom : opts.initialTo) || '';
            }

            function saveZoneFilter(zone, from, to) {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                fetch('{{ route("stats.saveFilters") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ zone, from: from || '', to: to || '' }),
                    keepalive: true,
                }).catch(e => console.error('[stats filters]', e));
            }

            function statsZones(opts = {}) {
                return {
                    init() {
                        // Запоминаем даты зон в сессии, чтобы они не сбрасывались при обновлении/уходе со страницы
                        [['zone2', 'dateFrom2', 'dateTo2'], ['zone4', 'dateFrom', 'dateTo'],
                         ['zone5', 'dateFrom5', 'dateTo5'], ['zone6', 'dateFrom6', 'dateTo6']]
                            .forEach(([zone, f, t]) => {
                                const save = () => saveZoneFilter(zone, this[f], this[t]);
                                this.$watch(f, save);
                                this.$watch(t, save);
                            });
                    },

                    initialFrom: opts.initialFrom || '',
                    initialTo:   opts.initialTo   || '',
                    statsMode:   opts.mode        || 'my',

                    expanded1: true,
                    expanded2: false,
                    expanded3: false,
                    expanded4: false,
                    expanded5: false,
                    expanded6: false,

                    loaded3: false, loading3: false, zone3: [],

                    loaded2: false, loading2: false,
                    zone2Rows: [], zone2Total: 0,
                    selectedExecutor: '', zone2ChartObj: null,
                    dateFrom2: zoneDate(opts, 'zone2', 'from'),
                    dateTo2: zoneDate(opts, 'zone2', 'to'),
                    chartError: '',

                    loadingDetails: false,
                    workerCategories: [],
                    workerRecords: {},
                    loadingRecords: {},
                    expandedCategories: {},

                    loaded4: false,
                    dateFrom: zoneDate(opts, 'zone4', 'from'),
                    dateTo: zoneDate(opts, 'zone4', 'to'),
                    zone4ChartObj: null,
                    zone4Error: '',
                    zone4Data: null,

                    recordsModalOpen: false,
                    recordsLoading: false,
                    recordsList: [],
                    recordsContext: { post: '', date: '' },

                    loaded5: false, loading5: false,
                    zone5Rows: [], selectedCategory: '',
                    dateFrom5: zoneDate(opts, 'zone5', 'from'),
                    dateTo5: zoneDate(opts, 'zone5', 'to'),

                    loaded6: false, loading6: false,
                    zone6Rows: [], selectedCategory6: '',
                    dateFrom6: zoneDate(opts, 'zone6', 'from'),
                    dateTo6: zoneDate(opts, 'zone6', 'to'),

                    toggleZone1() { this.expanded1 = !this.expanded1; },
                    toggleZone2() { this.expanded2 = !this.expanded2; if (this.expanded2 && !this.loaded2 && !this.loading2) this.loadZone2(); },
                    toggleZone3() { this.expanded3 = !this.expanded3; if (this.expanded3 && !this.loaded3 && !this.loading3) this.loadZone3(); },
                    toggleZone4() { this.expanded4 = !this.expanded4; if (this.expanded4) this.loadZone4(); },
                    toggleZone5() { this.expanded5 = !this.expanded5; if (this.expanded5 && !this.loaded5 && !this.loading5) this.loadZone5(); },
                    toggleZone6() { this.expanded6 = !this.expanded6; if (this.expanded6 && !this.loaded6 && !this.loading6) this.loadZone6(); },

                    async loadZone3() {
                        this.loading3 = true;
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp' });
                            if (this.initialFrom) params.set('from', this.initialFrom);
                            if (this.initialTo)   params.set('to',   this.initialTo);
                            params.set('mode', this.statsMode);
                            const res = await fetch('{{ route("stats.zone3") }}?' + params.toString());
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            this.zone3 = await res.json();
                            this.loaded3 = true;
                        } catch (e) { console.error('[zone3]', e); }
                        this.loading3 = false;
                    },

                    async loadZone2(force = false) {
                        if (!force) this.loaded2 = true;
                        this.loading2 = true;
                        try {
                            const params = new URLSearchParams();   // все вкладки участка
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);
                            const res = await fetch('{{ route("stats.zone2") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2 HTTP ' + res.status);
                            const data = await res.json();

                            this.zone2Rows  = data.rows || [];
                            this.zone2Total = data.total || 0;

                            if (this.selectedExecutor && !this.zone2Rows.some(r => r.name === this.selectedExecutor)) {
                                this.selectedExecutor = '';
                            }

                            this.loaded2 = true;

                            if (this.selectedExecutor) {
                                await this.loadWorkerDetails();
                            } else {
                                this.$nextTick(() => this.loadZone2Chart());
                            }
                        } catch (e) {
                            console.error('[zone2]', e);
                            this.chartError = 'Ошибка загрузки данных: ' + e.message;
                        }
                        this.loading2 = false;
                    },

                    resetDates() { this.dateFrom2 = ''; this.dateTo2 = ''; this.loadZone2(true); },

                    onExecutorChange() {
                        if (this.selectedExecutor) {
                            this.loadWorkerDetails();
                        } else {
                            this.$nextTick(() => this.loadZone2Chart());
                        }
                    },

                    async loadZone2Chart() {
                        if (typeof Chart === 'undefined') { this.chartError = 'Chart.js не загрузился.'; return; }
                        this.chartError = '';
                        try {
                            const params = new URLSearchParams();   // все вкладки участка
                            if (this.selectedExecutor) params.set('executor', this.selectedExecutor);
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);
                            const res = await fetch('{{ route("stats.zone2Chart") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2Chart HTTP ' + res.status);
                            const data = await res.json();

                            const ctx = document.getElementById('zone2Chart');
                            if (!ctx) return;
                            if (this.zone2ChartObj) this.zone2ChartObj.destroy();
                            this.zone2ChartObj = new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: data.labels,
                                    datasets: [{
                                        label: this.selectedExecutor || 'Все исполнители',
                                        data: data.values,
                                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                        borderColor: 'rgba(59, 130, 246, 1)',
                                        borderWidth: 1,
                                    }]
                                },
                                options: {
                                    responsive: true, maintainAspectRatio: false,
                                    scales: { x: { ticks: { autoSkip: true, maxRotation: 60 } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                                    plugins: { legend: { display: false } },
                                }
                            });
                        } catch (e) {
                            console.error('[zone2Chart]', e);
                            this.chartError = 'Ошибка графика: ' + e.message;
                        }
                    },

                    async loadWorkerDetails() {
                        if (!this.selectedExecutor) {
                            this.workerCategories = [];
                            this.workerRecords = {};
                            this.expandedCategories = {};
                            return;
                        }
                        this.loadingDetails = true;
                        this.workerCategories = [];
                        this.workerRecords = {};
                        this.expandedCategories = {};

                        try {
                            const params = new URLSearchParams({ executor: this.selectedExecutor });
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);
                            const res = await fetch('{{ route("stats.zone2Worker") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2Worker HTTP ' + res.status);
                            const data = await res.json();
                            const cats = data.categories || {};
                            this.workerCategories = Object.entries(cats).map(([slug, c]) => ({
                                slug, title: c.title, total: Number(c.total), unit: c.unit,
                            }));
                        } catch (e) { console.error('[zone2Worker]', e); }
                        this.loadingDetails = false;
                    },

                    async toggleCategory(slug) {
                        const isOpen = !!this.expandedCategories[slug];
                        this.expandedCategories = { ...this.expandedCategories, [slug]: !isOpen };

                        if (isOpen) return;
                        if (this.workerRecords[slug]) return;

                        this.loadingRecords = { ...this.loadingRecords, [slug]: true };
                        try {
                            const params = new URLSearchParams({ executor: this.selectedExecutor, sheet: slug });
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);
                            const res = await fetch('{{ route("stats.zone2Worker") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2Worker records HTTP ' + res.status);
                            const data = await res.json();
                            this.workerRecords = { ...this.workerRecords, [slug]: data.records || [] };
                        } catch (e) {
                            console.error('[zone2Worker records]', e);
                            this.workerRecords = { ...this.workerRecords, [slug]: [] };
                        }
                        this.loadingRecords = { ...this.loadingRecords, [slug]: false };
                    },

                    resetDates4() { this.dateFrom = ''; this.dateTo = ''; this.loadZone4(); },

                    async loadZone4() {
                        this.loaded4 = true;
                        this.zone4Error = '';
                        if (this.recordsModalOpen) this.recordsModalOpen = false;
                        if (typeof Chart === 'undefined') { this.zone4Error = 'Chart.js не загрузился.'; return; }
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp' });
                            if (this.dateFrom) params.set('from', this.dateFrom);
                            if (this.dateTo)   params.set('to',   this.dateTo);
                            const res = await fetch('{{ route("stats.zone4") }}?' + params.toString());
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            const data = await res.json();
                            this.zone4Data = data;

                            const ctx = document.getElementById('zone4Chart');
                            if (!ctx) return;
                            if (this.zone4ChartObj) this.zone4ChartObj.destroy();

                            const datasets = (data.datasets || []).map(ds => ({
                                ...ds,
                                pointRadius: 4, pointHoverRadius: 9, pointBorderWidth: 2,
                                pointBackgroundColor: ds.borderColor,
                            }));

                            this.zone4ChartObj = new Chart(ctx, {
                                type: 'line',
                                data: { labels: data.labels, datasets },
                                options: {
                                    responsive: true, maintainAspectRatio: false,
                                    interaction: { mode: 'nearest', intersect: true },
                                    onClick: (evt, elements, chart) => {
                                        let hit = null;
                                        if (elements && elements.length > 0) {
                                            hit = { di: elements[0].datasetIndex, pi: elements[0].index };
                                        } else {
                                            const clickX = evt.x, clickY = evt.y;
                                            let bestDist = 15;
                                            chart.data.datasets.forEach((ds, di) => {
                                                const meta = chart.getDatasetMeta(di);
                                                if (!meta || !meta.data) return;
                                                meta.data.forEach((pt, pi) => {
                                                    if (!pt || pt.skip) return;
                                                    const dx = pt.x - clickX, dy = pt.y - clickY;
                                                    const d = Math.sqrt(dx * dx + dy * dy);
                                                    if (d < bestDist) { bestDist = d; hit = { di, pi }; }
                                                });
                                            });
                                        }
                                        if (!hit) return;
                                        const ds = chart.data.datasets[hit.di];
                                        const postLabel = ds.label || '';
                                        const postNum = postLabel.replace(/^Пост\s+/, '');
                                        const date = chart.data.labels[hit.pi];
                                        this.openPostRecords(postNum, date);
                                    },
                                    scales: {
                                        x: { ticks: { autoSkip: true, maxRotation: 60 } },
                                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                                    },
                                    plugins: { legend: { position: 'bottom' } }
                                }
                            });
                        } catch (e) {
                            console.error('[zone4]', e);
                            this.zone4Error = 'Ошибка загрузки графика: ' + e.message;
                        }
                    },

                    async openPostRecords(post, date) {
                        if (!post || !date) return;
                        this.recordsContext = { post, date };
                        this.recordsModalOpen = true;
                        this.recordsLoading = true;
                        this.recordsList = [];
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp', post, date });
                            const res = await fetch('{{ route("stats.zone4Records") }}?' + params.toString());
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            this.recordsList = await res.json();
                        } catch (e) {
                            console.error('[zone4Records]', e);
                            this.recordsList = [];
                        }
                        this.recordsLoading = false;
                    },

                    recordUrl(id) { return '{{ url("/records/pgp") }}/' + id; },

                    recordWorkers(rec) {
                        return ['worker1', 'worker2', 'worker3', 'worker4', 'worker5']
                            .map(k => rec[k]).filter(Boolean).join(', ') || '—';
                    },

                    async loadZone5() {
                        this.loading5 = true;
                        try {
                            const params = new URLSearchParams();
                            if (this.dateFrom5) params.set('from', this.dateFrom5);
                            if (this.dateTo5)   params.set('to',   this.dateTo5);
                            params.set('mode', this.statsMode);
                            const res = await fetch('{{ route("stats.zone5") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone5 HTTP ' + res.status);
                            this.zone5Rows = await res.json();
                            if (this.selectedCategory && !this.zone5Rows.some(r => r.slug === this.selectedCategory)) {
                                this.selectedCategory = '';
                            }
                            this.loaded5 = true;
                            this.$nextTick(() => this.renderZone5Chart());
                        } catch (e) { console.error('[zone5]', e); }
                        this.loading5 = false;
                    },

                    // Категории с записями за период — только они на диаграмме
                    z5ChartRows() { return this.zone5Rows.filter(r => r.total > 0); },
                    // Итог: по всем категориям или по выбранной (all = всегда по всем)
                    z5Sum(all = false) {
                        const rows = !all && this.selectedCategory ? this.zone5Rows.filter(r => r.slug === this.selectedCategory) : this.zone5Rows;
                        return rows.reduce((s, r) => ({ total: s.total + r.total, defects: s.defects + r.defects, fixed: s.fixed + (r.fixed || 0) }), { total: 0, defects: 0, fixed: 0 });
                    },
                    z5Pct(part, total) { return total ? (Math.round(part / total * 1000) / 10) + '%' : '—'; },

                    onZone5FiltersChange() { this.loadZone5(); },
                    onCategoryChange() { this.renderZone5Chart(); },
                    resetDates5() { this.dateFrom5 = ''; this.dateTo5 = ''; this.loadZone5(); },

                    async renderZone5Chart() {
                        if (typeof Chart === 'undefined') return;
                        const ctx = document.getElementById('zone5Chart');
                        if (!ctx) return;
                        // График ищем по canvas, а не храним в данных Alpine: реактивная обёртка
                        // ломает Chart.js при изменении размера блока (getContext of null)
                        const old = Chart.getChart(ctx);
                        if (old) old.destroy();

                        if (!this.selectedCategory) {
                            const rows = this.z5ChartRows();
                            if (!rows.length) return;
                            const maxPct = Math.max(5, ...rows.map(r => Math.max(r.percent, r.fixed_percent || 0)));
                            // Подпись «брак N из M» справа от полосы
                            const barValues = {
                                id: 'z5Values',
                                afterDatasetsDraw: (chart) => {
                                    const c = chart.ctx, meta = chart.getDatasetMeta(0), metaFix = chart.getDatasetMeta(1);
                                    c.save();
                                    c.font = '600 12px ' + Chart.defaults.font.family;
                                    c.fillStyle = Chart.defaults.color;
                                    c.textBaseline = 'middle';
                                    meta.data.forEach((bar, i) => {
                                        const r = rows[i];
                                        const x = Math.max(bar.x, metaFix.hidden ? 0 : metaFix.data[i].x) + 6;
                                        const fixedTxt = r.fixed ? ', испр. ' + r.fixed : '';
                                        c.fillText(r.defects + ' из ' + r.total + ' (' + r.percent + '%)' + fixedTxt, x, (bar.y + metaFix.data[i].y) / 2);
                                    });
                                    c.restore();
                                },
                            };
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: rows.map(r => r.title),
                                    datasets: [
                                        { label: '% брака', data: rows.map(r => r.percent), backgroundColor: 'rgba(239,68,68,0.75)', borderColor: '#dc2626', borderWidth: 1, barPercentage: .9, categoryPercentage: .8 },
                                        { label: '% исправили на посту', data: rows.map(r => r.fixed_percent || 0), backgroundColor: 'rgba(16,185,129,0.75)', borderColor: '#059669', borderWidth: 1, barPercentage: .9, categoryPercentage: .8 },
                                    ]
                                },
                                plugins: [barValues],
                                options: {
                                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                                    layout: { padding: { right: 8 } },
                                    // место справа под подписи «N из M»
                                    scales: {
                                        x: { beginAtZero: true, suggestedMax: Math.min(100, Math.ceil(maxPct * 1.6)), ticks: { callback: v => v + '%' } },
                                        y: { ticks: { autoSkip: false, font: { size: 12 } } },
                                    },
                                    onClick: (e, els) => { if (els.length) { this.selectedCategory = rows[els[0].index].slug; this.onCategoryChange(); } },
                                    onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                                    plugins: {
                                        legend: { position: 'bottom' },
                                        tooltip: { callbacks: { label: (item) => {
                                            const r = rows[item.dataIndex];
                                            if (item.datasetIndex === 0) return 'Брак: ' + r.defects + ' из ' + r.total + ' (' + r.percent + '%)';
                                            return 'Исправили на посту: ' + r.fixed + ' из ' + r.total + ' (' + (r.fixed_percent || 0) + '%)';
                                        }, footer: () => 'Нажмите — график по дням' }}
                                    }
                                }
                            });
                            return;
                        }

                        try {
                            const params = new URLSearchParams({ sheet: this.selectedCategory });
                            if (this.dateFrom5) params.set('from', this.dateFrom5);
                            if (this.dateTo5)   params.set('to',   this.dateTo5);
                            params.set('mode', this.statsMode);
                            const res = await fetch('{{ route("stats.zone5Chart") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone5Chart HTTP ' + res.status);
                            const data = await res.json();
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: data.labels,
                                    datasets: [
                                        { label: 'Брак', data: data.defects || [], backgroundColor: 'rgba(239,68,68,0.75)', borderColor: '#dc2626', borderWidth: 1 },
                                        { label: 'Исправили на посту', data: data.fixed || [], backgroundColor: 'rgba(16,185,129,0.75)', borderColor: '#059669', borderWidth: 1 },
                                    ]
                                },
                                options: {
                                    responsive: true, maintainAspectRatio: false,
                                    scales: { x: { ticks: { autoSkip: true, maxRotation: 60 } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                                    plugins: { legend: { position: 'bottom' } },
                                }
                            });
                        } catch (e) { console.error('[zone5Chart]', e); }
                    },

                    async loadZone6(force = false) {
                        if (!force) this.loaded6 = true;
                        this.loading6 = true;
                        try {
                            const params = new URLSearchParams();
                            if (this.dateFrom6) params.set('from', this.dateFrom6);
                            if (this.dateTo6)   params.set('to',   this.dateTo6);
                            if (this.selectedCategory6) params.set('sheet', this.selectedCategory6);
                            params.set('mode', this.statsMode);
                            const res = await fetch('{{ route("stats.zone6") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone6 HTTP ' + res.status);
                            this.zone6Rows = await res.json();
                            this.loaded6 = true;
                        } catch (e) { console.error('[zone6]', e); }
                        this.loading6 = false;
                    },

                    resetDates6() { this.dateFrom6 = ''; this.dateTo6 = ''; this.loadZone6(true); },
                    zone6Total() { return this.zone6Rows.reduce((s, r) => s + Number(r.count), 0); },
                    formatDate(d) {
                        if (!d) return '—';
                        const [y, m, day] = String(d).split('-');
                        if (!y || !m || !day) return d;
                        return day + '.' + m + '.' + y;
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>