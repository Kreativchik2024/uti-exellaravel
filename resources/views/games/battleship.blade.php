<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <a href="{{ route('games.index') }}" class="ui-iconbtn" aria-label="К играм"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
            <h2>Морской бой</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8"
         x-data="battleship(@js($state), @js(url('/games/battleship/' . $game->id)))">
        <div class="ui-card p-4 space-y-4">
            {{-- Строка состояния --}}
            <div class="ui-bs-status" :class="'is-' + tone()">
                <span x-text="statusText()"></span>
                <span class="flex gap-2 flex-wrap">
                    <template x-if="s.can_accept">
                        <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="post('accept')">Принять</button>
                    </template>
                    <template x-if="['invited','placing'].includes(s.status)">
                        <form method="POST" :action="base + '/decline'">
                            <input type="hidden" name="_token" :value="token()">
                            <button class="ui-btn ui-btn--ghost ui-btn--sm" x-text="s.can_accept ? 'Отказаться' : 'Отменить'"></button>
                        </form>
                    </template>
                    <template x-if="s.status === 'placing' && !s.ready">
                        <span class="flex gap-2">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = false; post('shuffle')">Случайно</button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="!edit" @click="startEdit()">Расставить самому</button>
                            <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="ready()" :disabled="edit && left().length > 0">Готов</button>
                        </span>
                    </template>
                    <template x-if="s.status === 'playing'">
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="confirm('Сдаться?') && post('surrender')">Сдаться</button>
                    </template>
                    <template x-if="s.status === 'finished' && s.vs_bot">
                        <form method="POST" action="{{ route('games.battleship.bot') }}">
                            <input type="hidden" name="_token" :value="token()">
                            <button class="ui-btn ui-btn--primary ui-btn--sm">Ещё партию</button>
                        </form>
                    </template>
                </span>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <div class="ui-bs-title">Ваше поле</div>
                    {{-- Ручная расстановка: выбрать корабль, повернуть (R), кликнуть по полю; клик по кораблю — убрать --}}
                    <div x-show="edit" class="ui-bs-editor">
                        <div class="flex flex-wrap items-center gap-2">
                            <template x-for="len in [4, 3, 2, 1]" :key="len">
                                <button type="button" class="ui-bs-pick" :class="size === len && 'is-active'" :disabled="!countLeft(len)" @click="size = len">
                                    <span class="ui-bs-pick__ship"><template x-for="k in len" :key="k"><i></i></template></span>
                                    <span x-text="'× ' + countLeft(len)"></span>
                                </button>
                            </template>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="vertical = !vertical" x-text="vertical ? 'Вертикально ↕ (R)' : 'Горизонтально ↔ (R)'"></button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="draft = []">Убрать все</button>
                            <span class="text-xs" style="color: var(--ui-muted)" x-text="left().length ? 'Осталось поставить: ' + left().length : 'Все корабли на месте — нажмите «Готов»'"></span>
                        </div>
                        <p x-show="error" x-text="error" class="text-xs mt-1" style="color: var(--ui-bad)"></p>
                    </div>
                    <div class="ui-bs-board ui-nocopy" :class="edit && 'is-editing'" @mouseleave="hover = null" @keydown.window.r="edit && (vertical = !vertical)">
                        <template x-for="i in 100" :key="'m' + i">
                            <div class="ui-bs-cell" :class="edit ? editCell(i - 1) : myCell(i - 1)"
                                 @mouseenter="hover = i - 1" @click="edit && editClick(i - 1)"></div>
                        </template>
                    </div>
                </div>
                <div>
                    <div class="ui-bs-title" x-text="'Поле соперника · ' + s.opponent"></div>
                    <div class="ui-bs-board ui-bs-board--enemy ui-nocopy" :class="s.my_turn && !busy && 'is-active'">
                        <template x-for="i in 100" :key="'e' + i">
                            <button type="button" class="ui-bs-cell" :class="enemyCell(i - 1)" @click="shoot(i - 1)" :disabled="!s.my_turn || busy"></button>
                        </template>
                    </div>
                </div>
            </div>
            <p class="text-xs text-center" style="color: var(--ui-faint)">Попали — стреляете ещё. Вокруг потопленного корабля клетки отмечаются сами.</p>
        </div>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('battleship', (initial, base) => ({
            s: initial, base, busy: false, timer: null,
            edit: false, draft: [], size: 4, vertical: false, hover: null, error: '',
            fleet: [4, 3, 3, 2, 2, 2, 1, 1, 1, 1],
            init() { this.timer = setInterval(() => this.poll(), 2000); },
            token() { return window.csrfToken ? window.csrfToken() : ''; },
            async poll() {
                if (this.busy || document.hidden) return;
                if (this.s.status === 'finished' || this.s.status === 'cancelled' || this.s.status === 'declined') return;
                if (this.s.my_turn) return;   // ход за мной — ждать нечего
                try {
                    const r = await fetch(base + '/state', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (r.ok) this.s = await r.json();
                } catch (e) {}
            },
            async post(action, body) {
                this.busy = true;
                try {
                    const r = await fetch(base + '/' + action, { method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.token() },
                        body: JSON.stringify(body || {}) });
                    if (r.ok) this.s = await r.json();
                } catch (e) {}
                this.busy = false;
            },
            // ---- ручная расстановка ----
            startEdit() { this.edit = true; this.draft = []; this.size = 4; this.error = ''; },
            left() {
                const rest = [...this.fleet];
                this.draft.forEach(sh => rest.splice(rest.indexOf(sh.length), 1));
                return rest;
            },
            countLeft(len) { return this.left().filter(l => l === len).length; },
            cellsAt(i) {
                const r = Math.floor(i / 10), c = i % 10, out = [];
                for (let k = 0; k < this.size; k++) out.push(this.vertical ? [r + k, c] : [r, c + k]);
                return out;
            },
            canPlace(cells) {
                if (!this.countLeft(cells.length)) return false;
                return cells.every(([r, c]) => r < 10 && c < 10 &&
                    !this.draft.some(sh => sh.some(([sr, sc]) => Math.abs(sr - r) <= 1 && Math.abs(sc - c) <= 1)));   // не касаться других
            },
            editClick(i) {
                const r = Math.floor(i / 10), c = i % 10;
                const idx = this.draft.findIndex(sh => sh.some(p => p[0] === r && p[1] === c));
                if (idx >= 0) { this.draft.splice(idx, 1); return; }   // клик по кораблю — убрать
                const cells = this.cellsAt(i);
                if (!this.canPlace(cells)) return;
                this.draft.push(cells);
                if (!this.countLeft(this.size)) this.size = this.left()[0] || this.size;   // следующий по размеру
            },
            editCell(i) {
                const r = Math.floor(i / 10), c = i % 10;
                const placed = this.draft.some(sh => sh.some(p => p[0] === r && p[1] === c));
                let preview = false, bad = false;
                if (this.hover !== null && this.countLeft(this.size)) {
                    const cells = this.cellsAt(this.hover);
                    if (cells.some(p => p[0] === r && p[1] === c)) { preview = true; bad = !this.canPlace(cells); }
                }
                return { 'is-ship': placed, 'is-preview': preview && !bad, 'is-bad': preview && bad };
            },
            async ready() {
                this.error = '';
                if (this.edit) {
                    if (this.left().length) { this.error = 'Поставьте все корабли'; return; }
                    const r = await fetch(base + '/place', { method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.token() },
                        body: JSON.stringify({ ships: this.draft }) });
                    if (!r.ok) { this.error = (await r.json().catch(() => ({}))).message || 'Расстановка не принята'; return; }
                    this.edit = false;
                }
                this.post('ready');
            },

            shoot(i) {
                const r = Math.floor(i / 10), c = i % 10;
                if (!this.s.my_turn || this.s.my_shots[r + ',' + c]) return;
                this.post('shoot', { r, c });
            },
            shipAt(ships, r, c) { return (ships || []).some(sh => sh.some(p => p[0] === r && p[1] === c)); },
            myCell(i) {
                const r = Math.floor(i / 10), c = i % 10, shot = this.s.their_shots[r + ',' + c];
                return { 'is-ship': this.shipAt(this.s.my_ships, r, c), 'is-hit': shot === 'hit', 'is-miss': shot === 'miss' };
            },
            enemyCell(i) {
                const r = Math.floor(i / 10), c = i % 10, shot = this.s.my_shots[r + ',' + c];
                return { 'is-hit': shot === 'hit', 'is-miss': shot === 'miss', 'is-sunk': this.shipAt(this.s.sunk, r, c),
                         'is-ship': this.shipAt(this.s.their_ships, r, c) && shot !== 'hit' };
            },
            tone() {
                if (this.s.status === 'finished') return this.s.winner === 'me' ? 'good' : 'bad';
                return this.s.my_turn ? 'go' : 'wait';
            },
            statusText() {
                const s = this.s;
                switch (s.status) {
                    case 'invited': return s.can_accept ? s.opponent + ' приглашает вас в морской бой' : 'Ждём, когда ' + s.opponent + ' примет приглашение…';
                    case 'placing': return s.ready ? 'Вы готовы. Ждём соперника…' : 'Расставьте корабли (можно переставить) и нажмите «Готов»';
                    case 'playing': return s.my_turn ? 'Ваш ход — стреляйте по полю соперника' : 'Ход соперника…';
                    case 'finished': return s.winner === 'me' ? 'Победа! Результат в турнирной таблице' : (s.winner === 'bot' ? 'Компьютер победил' : 'Победил ' + s.opponent);
                    case 'declined': return 'Приглашение отклонено';
                    case 'cancelled': return 'Партия отменена';
                }
                return '';
            },
        }));
    });
    </script>
</x-app-layout>
