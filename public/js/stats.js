/* Страница статистики (resources/views/stats/index.blade.php). Адреса — window.STATS_URLS. */
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
        fetch(STATS_URLS.saveFilters, {
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
                 ['zone5', 'dateFrom5', 'dateTo5'], ['zone6', 'dateFrom6', 'dateTo6'], ['zone7', 'dateFrom7', 'dateTo7'], ['zone8', 'dateFrom8', 'dateTo8']]
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

            expanded7: false, loaded7: false, loading7: false,
            zone7Rows: [],
            dateFrom7: zoneDate(opts, 'zone7', 'from'),
            dateTo7: zoneDate(opts, 'zone7', 'to'),
            toggleZone7() { this.expanded7 = !this.expanded7; if (this.expanded7 && !this.loaded7 && !this.loading7) this.loadZone7(); },
            async loadZone7(force = false) {
                if (!force) this.loaded7 = true;
                this.loading7 = true;
                try {
                    const params = new URLSearchParams();
                    if (this.dateFrom7) params.set('from', this.dateFrom7);
                    if (this.dateTo7)   params.set('to',   this.dateTo7);
                    params.set('mode', this.statsMode);
                    const res = await fetch(STATS_URLS.zone7 + '?' + params.toString());
                    if (!res.ok) throw new Error('zone7 HTTP ' + res.status);
                    this.zone7Rows = await res.json();
                    this.loaded7 = true;
                } catch (e) { console.error('[zone7]', e); }
                this.loading7 = false;
            },
            resetDates7() { this.dateFrom7 = ''; this.dateTo7 = ''; this.loadZone7(true); },

            expanded8: false, loaded8: false, loading8: false,
            zone8Rows: [],
            dateFrom8: zoneDate(opts, 'zone8', 'from'),
            dateTo8: zoneDate(opts, 'zone8', 'to'),
            toggleZone8() { this.expanded8 = !this.expanded8; if (this.expanded8 && !this.loaded8 && !this.loading8) this.loadZone8(); },
            async loadZone8(force = false) {
                if (!force) this.loaded8 = true;
                this.loading8 = true;
                try {
                    const params = new URLSearchParams();
                    if (this.dateFrom8) params.set('from', this.dateFrom8);
                    if (this.dateTo8)   params.set('to',   this.dateTo8);
                    const res = await fetch(STATS_URLS.zone8 + '?' + params.toString());
                    if (!res.ok) throw new Error('zone8 HTTP ' + res.status);
                    this.zone8Rows = await res.json();
                    this.loaded8 = true;
                } catch (e) { console.error('[zone8]', e); }
                this.loading8 = false;
            },
            resetDates8() { this.dateFrom8 = ''; this.dateTo8 = ''; this.loadZone8(true); },
            zone8Boards() { return this.zone8Rows.reduce((s, r) => s + Number(r.boards), 0); },
            // Список бортов технолога: kind = all | good (сдано) | defect (не сдано); период — как у блока 8,
            // mode=all — все записи (цифры блока считаются по всем, не только по своим)
            zone8Url(tech, kind) {
                const p = new URLSearchParams({ sheet: 'pgp', board: '_all', tech, kind, mode: 'all' });
                if (this.dateFrom8) p.set('from', this.dateFrom8);
                if (this.dateTo8)   p.set('to',   this.dateTo8);
                return STATS_URLS.records + '?' + p.toString();
            },
            zone7Total() { return this.zone7Rows.reduce((s, r) => s + Number(r.count), 0); },

            toggleZone1() { this.expanded1 = !this.expanded1; },
            toggleZone2() { this.expanded2 = !this.expanded2; if (this.expanded2 && !this.loaded2 && !this.loading2) this.loadZone2(); },
            toggleZone3() { this.expanded3 = !this.expanded3; if (this.expanded3 && !this.loaded3 && !this.loading3) this.loadZone3(); },
            toggleZone4() { this.expanded4 = !this.expanded4; if (this.expanded4) this.loadZone4(); },
            toggleZone5() { this.expanded5 = !this.expanded5; if (this.expanded5 && !this.loaded5 && !this.loading5) this.loadZone5(); },
            toggleZone6() { this.expanded6 = !this.expanded6; if (this.expanded6 && !this.loaded6 && !this.loading6) this.loadZone6(); },

            // ссылка с цифры «Детализации по постам» на список бортов поста (как в «Бортах по типам»)
            postUrl(post, kind) {
                const p = new URLSearchParams({ sheet: 'pgp', board: '_all', kind: kind, post: post });
                if (this.initialFrom) p.set('from', this.initialFrom);
                if (this.initialTo)   p.set('to',   this.initialTo);
                if (this.statsMode)   p.set('mode', this.statsMode);
                return STATS_URLS.records + '?' + p.toString();
            },

            async loadZone3() {
                this.loading3 = true;
                try {
                    const params = new URLSearchParams({ sheet: 'pgp' });
                    if (this.initialFrom) params.set('from', this.initialFrom);
                    if (this.initialTo)   params.set('to',   this.initialTo);
                    params.set('mode', this.statsMode);
                    const res = await fetch(STATS_URLS.zone3 + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone2 + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone2Chart + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone2Worker + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone2Worker + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone4 + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone4Records + '?' + params.toString());
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    this.recordsList = await res.json();
                } catch (e) {
                    console.error('[zone4Records]', e);
                    this.recordsList = [];
                }
                this.recordsLoading = false;
            },

            recordUrl(id) { return STATS_URLS.pgpRecord + '/' + id; },

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
                    const res = await fetch(STATS_URLS.zone5 + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone5Chart + '?' + params.toString());
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
                    const res = await fetch(STATS_URLS.zone6 + '?' + params.toString());
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
