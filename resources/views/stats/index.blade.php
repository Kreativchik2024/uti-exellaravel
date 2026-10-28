<x-app-layout>
    <style>[x-cloak]{display:none!important}</style>

    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Статистика</h2>
            <a href="{{ route('stats.export', ['period' => $period, 'user_id' => request('user_id'), 'mode' => request('mode')]) }}"
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow transition">
                Скачать Excel
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
         x-data="statsZones({
             initialFrom: '{{ $from ? $from->format('Y-m-d') : '' }}'
         })">

        {{-- ============ ФИЛЬТРЫ ============ --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-4">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Период</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 30 => '30 дней', 0 => 'Всё время'] as $p => $label)
                            <a href="{{ route('stats.index', ['period' => $p, 'user_id' => request('user_id'), 'mode' => request('mode')]) }}"
                               class="px-3 py-1 text-sm rounded border transition {{ $period === $p ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                @if(auth()->user()->isExecutor())
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Вид статистики</label>
                        <div class="flex gap-1">
                            <a href="{{ route('stats.index', ['period' => $period, 'mode' => 'my']) }}"
                               class="px-3 py-1 text-sm rounded border {{ request('mode') !== 'all' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300' }}">
                                Моя
                            </a>
                            <a href="{{ route('stats.index', ['period' => $period, 'mode' => 'all']) }}"
                               class="px-3 py-1 text-sm rounded border {{ request('mode') === 'all' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300' }}">
                                Общая
                            </a>
                        </div>
                    </div>
                @endif

                @if(auth()->user()->isAdmin() || auth()->user()->isMaster())
                    <form method="GET" action="{{ route('stats.index') }}" class="flex items-end gap-2">
                        <input type="hidden" name="period" value="{{ $period }}">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Сотрудник (автор)</label>
                            <select name="user_id" onchange="this.form.submit()"
                                    class="border rounded px-3 py-1 text-sm bg-white min-w-[200px]">
                                <option value="">Все авторы</option>
                                @foreach($usersList as $u)
                                    <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="text-sm text-gray-600">
            @if($period === 0)
                Период: <strong>всё время</strong>
            @else
                Период: с <strong>{{ $from->format('d.m.Y') }}</strong> по <strong>{{ now()->format('d.m.Y') }}</strong>
            @endif
            @if($targetUser && $targetUser->isExecutor())
                <span class="ml-2 text-amber-700">(записи: {{ $targetUser->name }})</span>
            @elseif(request('mode') === 'all' || !$targetUser)
                <span class="ml-2 text-amber-700">(общая статистика)</span>
            @endif
        </div>

        {{-- ============ ЗОНА 1: сводка по вкладкам ============ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <h3 class="font-semibold text-lg mb-4 text-gray-800">1. Сводка по вкладкам</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                @foreach($perSheet as $slug => $data)
                    <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition border border-gray-200 p-6 flex flex-col items-center justify-center text-center">
                        <div class="text-sm font-medium text-gray-500 mb-3 truncate w-full" title="{{ $data['title'] }}">
                            {{ $data['title'] }}
                        </div>
                        <div class="flex flex-col items-center justify-center">
                            <span class="text-5xl font-extrabold {{ $data['total'] > 0 ? 'text-blue-600' : 'text-gray-300' }} leading-none mb-1">
                                {{ $data['total'] }}
                            </span>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                                {{ $data['unit'] === 'записей' ? 'штук' : $data['unit'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ============ ЗОНА 2: сводка по исполнителю ============ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold text-lg text-gray-800">2. Сводка по исполнителю</h3>
                <button x-show="!loaded2" @click="loadZone2()"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 text-sm rounded">
                    Показать
                </button>
            </div>
            <div x-show="loading2" class="text-gray-500 text-sm">Загрузка…</div>
            <div x-show="loaded2" x-cloak>
                <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                    <label class="text-sm text-gray-600">
                        С: <input type="date" x-model="dateFrom2" @change="loadZone2(true)"
                                  class="border rounded px-2 py-1 ml-1">
                    </label>
                    <label class="text-sm text-gray-600">
                        По: <input type="date" x-model="dateTo2" @change="loadZone2(true)"
                                   class="border rounded px-2 py-1 ml-1">
                    </label>
                    <button @click="resetDates()"
                            class="text-sm text-gray-500 hover:text-gray-800 underline">
                        Сбросить даты
                    </button>
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-gray-500 mb-1">Исполнитель</label>
                    <select x-model="selectedExecutor" @change="onExecutorChange()"
                            class="border rounded px-3 py-2 text-sm bg-white w-full md:w-1/2">
                        <option value="">— Все исполнители —</option>
                        <template x-for="row in zone2Rows" :key="row.name">
                            <option :value="row.name" x-text="row.name + ' (' + row.cnt + ')'"></option>
                        </template>
                    </select>
                </div>

                <div class="mb-4 flex items-baseline gap-2">
                    <span class="text-sm text-gray-600">Найдено записей:</span>
                    <span class="text-3xl font-extrabold text-blue-700" x-text="currentSummary()"></span>
                </div>

                <div class="relative h-72"><canvas id="zone2Chart"></canvas></div>
                <div x-show="chartError" x-text="chartError" class="mt-2 text-sm text-red-600"></div>
            </div>
        </div>

        {{-- ============ ЗОНА 3: детализация по постам ============ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold text-lg text-gray-800">3. Детализация по постам</h3>
                <button x-show="!loaded3" @click="loadZone3()"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 text-sm rounded">
                    Показать
                </button>
            </div>
            <div x-show="loading3" class="text-gray-500 text-sm">Загрузка…</div>
            <div x-show="loaded3" x-cloak>
                <div class="overflow-x-auto">
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
                    <div x-show="zone3.length === 0" class="p-3 text-gray-500 text-sm">Нет данных</div>
                </div>
            </div>
        </div>

        {{-- ============ ЗОНА 4: график по постам ============ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold text-lg text-gray-800">4. График по постам</h3>
                <button x-show="!loaded4" @click="loadZone4()"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 text-sm rounded">
                    Открыть график
                </button>
            </div>
            <div x-show="loaded4" x-cloak>
                <div class="flex flex-wrap gap-3 mb-3 items-end">
                    <label class="text-sm text-gray-600">
                        С: <input type="date" x-model="dateFrom" @change="loadZone4()"
                                  class="border rounded px-2 py-1 ml-1">
                    </label>
                    <label class="text-sm text-gray-600">
                        По: <input type="date" x-model="dateTo" @change="loadZone4()"
                                   class="border rounded px-2 py-1 ml-1">
                    </label>
                </div>
                <div class="relative h-80"><canvas id="zone4Chart"></canvas></div>
            </div>
        </div>

        {{-- ============ ЗОНА 5: брак по категориям ============ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold text-lg text-gray-800">5. Брак по категориям</h3>
                <button x-show="!loaded5" @click="loadZone5()"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 text-sm rounded">
                    Показать
                </button>
            </div>
            <div x-show="loading5" class="text-gray-500 text-sm">Загрузка…</div>
            <div x-show="loaded5" x-cloak>
                {{-- Фильтр по времени + выпадающее меню категории --}}
                <div class="flex flex-wrap gap-3 mb-4 items-end bg-gray-50 p-3 rounded border border-gray-100">
                    <label class="text-sm text-gray-600">
                        С:
                        <input type="date" x-model="dateFrom5" @change="onZone5FiltersChange()"
                               class="border rounded px-2 py-1 ml-1">
                    </label>
                    <label class="text-sm text-gray-600">
                        По:
                        <input type="date" x-model="dateTo5" @change="onZone5FiltersChange()"
                               class="border rounded px-2 py-1 ml-1">
                    </label>
                    <button @click="resetDates5()"
                            class="text-sm text-gray-500 hover:text-gray-800 underline">
                        Сбросить даты
                    </button>
                    <div class="flex-1 min-w-[240px]">
                        <label class="block text-xs text-gray-500 mb-1">Категория</label>
                        <select x-model="selectedCategory" @change="onCategoryChange()"
                                class="border rounded px-3 py-2 text-sm bg-white w-full">
                            <option value="">— Все категории —</option>
                            <template x-for="row in zone5Rows" :key="row.slug">
                                <option :value="row.slug" x-text="row.title + ' (' + row.percent + '%)'"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="relative h-96"><canvas id="zone5Chart"></canvas></div>
            </div>
        </div>

    </div>

    @push('scripts')
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
            function statsZones(opts = {}) {
                return {
                    // Зона 2
                    loaded2: false, loading2: false,
                    zone2Rows: [], zone2Total: 0,
                    selectedExecutor: '', zone2ChartObj: null,
                    dateFrom2: opts.initialFrom || '',
                    dateTo2: '',
                    chartError: '',

                    // Зона 3
                    loaded3: false, loading3: false, zone3: [],

                    // Зона 4
                    loaded4: false, dateFrom: '', dateTo: '', zone4ChartObj: null,

                    // Зона 5
                    loaded5: false, loading5: false,
                    zone5Rows: [], selectedCategory: '',
                    dateFrom5: opts.initialFrom || '',
                    dateTo5: '',
                    zone5ChartObj: null,

                    // ============ ЗОНА 2 ============
                    async loadZone2(force = false) {
                        if (!force) this.loaded2 = true;
                        this.loading2 = true;
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp' });
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);

                            const res = await fetch('{{ route("stats.zone2") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2 HTTP ' + res.status);
                            const data = await res.json();

                            this.zone2Rows  = data.rows || [];
                            this.zone2Total = data.total || 0;

                            if (this.selectedExecutor &&
                                !this.zone2Rows.some(r => r.name === this.selectedExecutor)) {
                                this.selectedExecutor = '';
                            }

                            this.loaded2 = true;
                            this.$nextTick(() => this.loadZone2Chart());
                        } catch (e) {
                            console.error('[zone2]', e);
                            this.chartError = 'Ошибка загрузки данных: ' + e.message;
                        }
                        this.loading2 = false;
                    },

                    resetDates() {
                        this.dateFrom2 = '';
                        this.dateTo2 = '';
                        this.loadZone2(true);
                    },

                    onExecutorChange() {
                        this.loadZone2Chart();
                    },

                    currentSummary() {
                        if (!this.selectedExecutor) return this.zone2Total;
                        const found = this.zone2Rows.find(r => r.name === this.selectedExecutor);
                        return found ? Number(found.cnt) : 0;
                    },

                    async loadZone2Chart() {
                        if (typeof Chart === 'undefined') {
                            this.chartError = 'Chart.js не загрузился.';
                            return;
                        }
                        this.chartError = '';
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp' });
                            if (this.selectedExecutor) params.set('executor', this.selectedExecutor);
                            if (this.dateFrom2) params.set('from', this.dateFrom2);
                            if (this.dateTo2)   params.set('to',   this.dateTo2);

                            const res = await fetch('{{ route("stats.zone2Chart") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone2Chart HTTP ' + res.status);
                            const data = await res.json();

                            const ctx = document.getElementById('zone2Chart');
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
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        x: { ticks: { autoSkip: true, maxRotation: 60 } },
                                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                                    },
                                    plugins: { legend: { display: false } },
                                }
                            });
                        } catch (e) {
                            console.error('[zone2Chart]', e);
                            this.chartError = 'Ошибка графика: ' + e.message;
                        }
                    },

                    // ============ ЗОНА 3 ============
                    async loadZone3() {
                        this.loading3 = true;
                        try {
                            const res = await fetch('{{ route("stats.zone3") }}?sheet=pgp');
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            this.zone3 = await res.json();
                            this.loaded3 = true;
                        } catch (e) { console.error('[zone3]', e); }
                        this.loading3 = false;
                    },

                    // ============ ЗОНА 4 ============
                    async loadZone4() {
                        this.loaded4 = true;
                        if (typeof Chart === 'undefined') return;
                        try {
                            const params = new URLSearchParams({ sheet: 'pgp' });
                            if (this.dateFrom) params.set('from', this.dateFrom);
                            if (this.dateTo)   params.set('to',   this.dateTo);

                            const res = await fetch('{{ route("stats.zone4") }}?' + params.toString());
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            const data = await res.json();

                            const ctx = document.getElementById('zone4Chart');
                            if (this.zone4ChartObj) this.zone4ChartObj.destroy();
                            this.zone4ChartObj = new Chart(ctx, {
                                type: 'line',
                                data: { labels: data.labels, datasets: data.datasets },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: { mode: 'index', intersect: false },
                                }
                            });
                        } catch (e) { console.error('[zone4]', e); }
                    },

                    // ============ ЗОНА 5 ============
                    async loadZone5() {
                        this.loading5 = true;
                        try {
                            const params = new URLSearchParams();
                            if (this.dateFrom5) params.set('from', this.dateFrom5);
                            if (this.dateTo5)   params.set('to',   this.dateTo5);

                            const res = await fetch('{{ route("stats.zone5") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone5 HTTP ' + res.status);
                            this.zone5Rows = await res.json();

                            if (this.selectedCategory &&
                                !this.zone5Rows.some(r => r.slug === this.selectedCategory)) {
                                this.selectedCategory = '';
                            }

                            this.loaded5 = true;
                            this.$nextTick(() => this.renderZone5Chart());
                        } catch (e) { console.error('[zone5]', e); }
                        this.loading5 = false;
                    },

                    onZone5FiltersChange() {
                        this.loadZone5();
                    },

                    onCategoryChange() {
                        this.renderZone5Chart();
                    },

                    resetDates5() {
                        this.dateFrom5 = '';
                        this.dateTo5 = '';
                        this.loadZone5();
                    },

                    async renderZone5Chart() {
                        if (typeof Chart === 'undefined') return;
                        const ctx = document.getElementById('zone5Chart');
                        if (!ctx) return;
                        if (this.zone5ChartObj) this.zone5ChartObj.destroy();

                        if (!this.selectedCategory) {
                            // «Все категории» — два dataset: % брака (красный) и % исправили (зелёный)
                            this.zone5ChartObj = new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: this.zone5Rows.map(r => r.title),
                                    datasets: [
                                        {
                                            label: '% брака',
                                            data: this.zone5Rows.map(r => r.percent),
                                            backgroundColor: 'rgba(239,68,68,0.75)',
                                            borderColor: '#dc2626',
                                            borderWidth: 1,
                                        },
                                        {
                                            label: '% исправили на посту',
                                            data: this.zone5Rows.map(r => r.fixed_percent || 0),
                                            backgroundColor: 'rgba(16,185,129,0.75)',
                                            borderColor: '#059669',
                                            borderWidth: 1,
                                        },
                                    ]
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            ticks: { callback: v => v + '%' }
                                        }
                                    },
                                    plugins: {
                                        legend: { position: 'bottom' },
                                        tooltip: {
                                            callbacks: {
                                                label: (item) => {
                                                    const r = this.zone5Rows[item.dataIndex];
                                                    if (item.datasetIndex === 0) {
                                                        return 'Брак: ' + r.defects + ' из ' + r.total + ' (' + r.percent + '%)';
                                                    }
                                                    return 'Исправили: ' + r.fixed + ' из ' + r.total + ' (' + (r.fixed_percent || 0) + '%)';
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                            return;
                        }

                        // Конкретная категория — два dataset по дням: брак (красный) и исправили (зелёный)
                        try {
                            const params = new URLSearchParams({ sheet: this.selectedCategory });
                            if (this.dateFrom5) params.set('from', this.dateFrom5);
                            if (this.dateTo5)   params.set('to',   this.dateTo5);

                            const res = await fetch('{{ route("stats.zone5Chart") }}?' + params.toString());
                            if (!res.ok) throw new Error('zone5Chart HTTP ' + res.status);
                            const data = await res.json();

                            this.zone5ChartObj = new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: data.labels,
                                    datasets: [
                                        {
                                            label: 'Брак',
                                            data: data.defects || [],
                                            backgroundColor: 'rgba(239,68,68,0.75)',
                                            borderColor: '#dc2626',
                                            borderWidth: 1,
                                        },
                                        {
                                            label: 'Исправили на посту',
                                            data: data.fixed || [],
                                            backgroundColor: 'rgba(16,185,129,0.75)',
                                            borderColor: '#059669',
                                            borderWidth: 1,
                                        },
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        x: { ticks: { autoSkip: true, maxRotation: 60 } },
                                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                                    },
                                    plugins: { legend: { position: 'bottom' } },
                                }
                            });
                        } catch (e) {
                            console.error('[zone5Chart]', e);
                        }
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>