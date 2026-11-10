<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <a href="{{ route('games.index') }}" class="ui-iconbtn" aria-label="К играм"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
            <h2>Сапёр</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8" x-data="minesweeper(@js($levels), @js(route('games.minesweeper.start')), @js(url('/games/minesweeper')))">
        <div class="ui-card p-4 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <template x-for="(l, key) in levels" :key="key">
                    <button type="button" class="ui-btn ui-btn--sm" :class="level === key ? 'ui-btn--primary' : 'ui-btn--ghost'" @click="start(key)"
                            x-text="l.name + ' · ' + l.cols + '×' + l.rows"></button>
                </template>
                <button type="button" class="ui-btn ui-btn--sm ml-auto sm:hidden" :class="flagMode ? 'ui-btn--primary' : 'ui-btn--ghost'" @click="flagMode = !flagMode"
                        x-text="flagMode ? 'Режим: флажок' : 'Режим: открыть'"></button>
            </div>

            <div class="flex items-center justify-between gap-3 ui-ms-bar">
                <span title="Осталось мин">Мины: <b class="tabular-nums" x-text="minesLeft()"></b></span>
                <button type="button" class="ui-ms-face" @click="start(level)" title="Новая игра" x-text="result === 'lost' ? '✖' : (result === 'won' ? '✔' : '↻')"></button>
                <span title="Время">Время: <b class="tabular-nums" x-text="(elapsed / 1000).toFixed(1)"></b> с</span>
            </div>

            <div class="overflow-x-auto pb-2">
                <div class="ui-ms-grid ui-nocopy" :style="'grid-template-columns: repeat(' + cols + ', var(--ms-cell))'" @contextmenu.prevent>
                    <template x-for="(cell, i) in cells" :key="i">
                        <button type="button" class="ui-ms-cell" :class="cellClass(cell)"
                                @click="clickCell(cell)" @contextmenu.prevent="toggleFlag(cell)"
                                @touchstart.passive="touchStart(cell)" @touchend="touchEnd($event)"
                                x-text="cellText(cell)"></button>
                    </template>
                </div>
            </div>

            <p class="text-sm text-center" style="min-height: 1.5em" :style="result === 'won' ? 'color: var(--ui-good)' : (result === 'lost' ? 'color: var(--ui-bad)' : 'color: var(--ui-muted)')" x-text="message"></p>
            <p class="text-xs text-center" style="color: var(--ui-faint)">Левая кнопка — открыть, правая (или долгое нажатие) — флажок. Нажатие на цифру открывает соседей, если флажков вокруг хватает.</p>
        </div>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('minesweeper', (levels, startUrl, baseUrl) => ({
            levels, level: 'easy', session: null, rows: 9, cols: 9, mines: 10,
            cells: [], result: null, busy: false, flagMode: false, message: 'Нажмите на любую клетку, чтобы начать',
            startedAt: null, elapsed: 0, timer: null, pressTimer: null, longPress: false,

            init() { this.start('easy'); },
            async api(url, body) {
                const r = await fetch(url, { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.csrfToken ? window.csrfToken() : '' },
                    body: JSON.stringify(body || {}) });
                if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Ошибка ' + r.status);
                return r.json();
            },
            async start(level) {
                clearInterval(this.timer); this.elapsed = 0; this.startedAt = null; this.result = null;
                this.level = level;
                const d = await this.api(startUrl, { level });
                Object.assign(this, { session: d.id, rows: d.rows, cols: d.cols, mines: d.mines });
                this.cells = Array.from({ length: d.rows * d.cols }, (_, i) => ({ r: Math.floor(i / d.cols), c: i % d.cols, open: false, n: 0, flag: false, mine: false, boom: false }));
                this.message = 'Нажмите на любую клетку, чтобы начать';
            },
            at(r, c) { return (r >= 0 && c >= 0 && r < this.rows && c < this.cols) ? this.cells[r * this.cols + c] : null; },
            minesLeft() { return this.mines - this.cells.filter(c => c.flag).length; },
            cellText(c) { return c.boom || (c.mine && this.result) ? '●' : (c.flag ? '⚑' : (c.open && c.n ? c.n : '')); },
            cellClass(c) { return { 'is-open': c.open, 'is-flag': c.flag && !c.open, 'is-mine': c.mine, 'is-boom': c.boom, ['n' + c.n]: c.open && c.n }; },
            toggleFlag(c) { if (this.result || c.open) return; c.flag = !c.flag; },
            touchStart(c) { this.longPress = false; this.pressTimer = setTimeout(() => { this.longPress = true; this.toggleFlag(c); navigator.vibrate && navigator.vibrate(30); }, 400); },
            touchEnd(e) { clearTimeout(this.pressTimer); if (this.longPress) e.preventDefault(); },
            clickCell(c) {
                if (this.longPress) { this.longPress = false; return; }
                if (this.result || this.busy) return;
                if (this.flagMode && !c.open) return this.toggleFlag(c);
                if (c.flag) return;
                if (c.open) {   // «аккорд»: открыть соседей числа, если флажков вокруг ровно столько
                    const around = [];
                    for (let dr = -1; dr <= 1; dr++) for (let dc = -1; dc <= 1; dc++) { const x = this.at(c.r + dr, c.c + dc); if (x && x !== c) around.push(x); }
                    if (c.n && around.filter(x => x.flag).length === c.n) this.open(around.filter(x => !x.open && !x.flag));
                    return;
                }
                this.open([c]);
            },
            async open(list) {
                if (!list.length) return;
                this.busy = true;
                try {
                    const d = await this.api(baseUrl + '/' + this.session + '/open', { cells: list.map(x => [x.r, x.c]) });
                    if (!this.startedAt) { this.startedAt = Date.now(); this.timer = setInterval(() => this.elapsed = Date.now() - this.startedAt, 100); }
                    d.opened.forEach(([r, c, n]) => { const x = this.at(r, c); x.open = true; x.n = n; x.flag = false; });
                    if (d.result) {
                        clearInterval(this.timer);
                        this.result = d.result;
                        (d.mines || []).forEach(k => { const [r, c] = k.split(',').map(Number); this.at(r, c).mine = true; });
                        if (d.boom) { const [r, c] = d.boom.split(',').map(Number); this.at(r, c).boom = true; }
                        if (d.result === 'won') { this.elapsed = d.time_ms; this.message = 'Победа! Время: ' + (d.time_ms / 1000).toFixed(1) + ' с — результат в турнирной таблице'; }
                        else this.message = 'Подрыв. Нажмите ↻, чтобы сыграть ещё';
                    } else this.message = '';
                } catch (e) { this.message = e.message; }
                this.busy = false;
            },
        }));
    });
    </script>
</x-app-layout>
