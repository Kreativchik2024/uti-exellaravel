<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <a href="{{ route('games.index') }}" class="ui-iconbtn" aria-label="К играм"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
            <h2>Змейка</h2>
        </div>
    </x-slot>

    <div class="py-6 max-w-xl mx-auto px-4" x-data="snakeGame(@js(route('games.snake.start')), @js(url('/games/snake')))"
         @keydown.window="key($event)">
        <div class="ui-card p-4 space-y-3">
            <div class="flex items-center justify-between ui-ms-bar">
                <span>Счёт: <b class="tabular-nums" x-text="score"></b></span>
                <span>Рекорд: <b class="tabular-nums" x-text="best"></b></span>
            </div>
            <div class="relative">
                <canvas x-ref="cv" class="ui-snake ui-nocopy" width="400" height="400"
                        @touchstart.passive="touchStart($event)" @touchend.passive="touchEnd($event)"></canvas>
                <div x-show="state !== 'run'" class="ui-snake__overlay">
                    <div class="text-lg font-bold" x-text="state === 'over' ? 'Игра окончена · ' + score + ' очк.' : 'Змейка'"></div>
                    <p class="text-sm opacity-80" x-text="state === 'over' ? note : 'Стрелки / WASD или свайпы. Пробел — пауза.'"></p>
                    <button type="button" class="ui-btn ui-btn--primary" @click="start()" x-text="state === 'over' ? 'Ещё раз' : (state === 'pause' ? 'Продолжить' : 'Начать')"></button>
                </div>
            </div>
            {{-- Кнопки для телефона --}}
            <div class="grid grid-cols-3 gap-2 sm:hidden max-w-[220px] mx-auto">
                <span></span><button type="button" class="ui-btn ui-btn--ghost" @click="turn(0,-1)">↑</button><span></span>
                <button type="button" class="ui-btn ui-btn--ghost" @click="turn(-1,0)">←</button>
                <button type="button" class="ui-btn ui-btn--ghost" @click="turn(0,1)">↓</button>
                <button type="button" class="ui-btn ui-btn--ghost" @click="turn(1,0)">→</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('snakeGame', (startUrl, baseUrl) => {
            const N = 20;
            let snake, dir, nextDir, food, loop = null, speed, session = null, tx = 0, ty = 0;
            return {
                score: 0, best: 0, state: 'idle', note: '',
                init() { try { this.best = +localStorage.getItem('uti-snake-best') || 0; } catch (e) {} this.draw(); },
                async post(url, body) {
                    const r = await fetch(url, { method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.csrfToken ? window.csrfToken() : '' },
                        body: JSON.stringify(body || {}) });
                    return r.ok ? r.json() : null;
                },
                async start() {
                    if (this.state === 'pause') { this.state = 'run'; this.tick(); return; }
                    snake = [{ x: 9, y: 10 }, { x: 8, y: 10 }, { x: 7, y: 10 }];
                    dir = nextDir = { x: 1, y: 0 }; speed = 140; this.score = 0; this.note = '';
                    this.placeFood();
                    const d = await this.post(startUrl);
                    session = d && d.id;
                    this.state = 'run';
                    this.tick();
                },
                placeFood() {
                    do { food = { x: Math.floor(Math.random() * N), y: Math.floor(Math.random() * N) }; }
                    while (snake.some(s => s.x === food.x && s.y === food.y));
                },
                tick() {
                    clearTimeout(loop);
                    if (this.state !== 'run') return;
                    dir = nextDir;
                    const head = { x: snake[0].x + dir.x, y: snake[0].y + dir.y };
                    if (head.x < 0 || head.y < 0 || head.x >= N || head.y >= N || snake.some(s => s.x === head.x && s.y === head.y)) return this.over();
                    snake.unshift(head);
                    if (head.x === food.x && head.y === food.y) {
                        this.score++;
                        speed = Math.max(60, speed - 3);   // быстрее с каждым яблоком
                        this.placeFood();
                    } else snake.pop();
                    this.draw();
                    loop = setTimeout(() => this.tick(), speed);
                },
                async over() {
                    this.state = 'over';
                    this.draw();
                    if (this.score > this.best) { this.best = this.score; try { localStorage.setItem('uti-snake-best', this.best); } catch (e) {} }
                    if (session) {
                        const d = await this.post(baseUrl + '/' + session + '/finish', { score: this.score });
                        this.note = d ? 'Результат записан в турнирную таблицу' : 'Не удалось записать результат';
                        session = null;
                    }
                },
                turn(x, y) {
                    if (this.state !== 'run') return;
                    if (x === -dir.x && y === -dir.y) return;   // нельзя развернуться в себя
                    nextDir = { x, y };
                },
                key(e) {
                    const map = { ArrowUp: [0,-1], KeyW: [0,-1], ArrowDown: [0,1], KeyS: [0,1], ArrowLeft: [-1,0], KeyA: [-1,0], ArrowRight: [1,0], KeyD: [1,0] };
                    if (map[e.code]) { e.preventDefault(); this.turn(...map[e.code]); }
                    if (e.code === 'Space') {
                        e.preventDefault();
                        if (this.state === 'run') { this.state = 'pause'; clearTimeout(loop); } else this.start();
                    }
                },
                touchStart(e) { tx = e.touches[0].clientX; ty = e.touches[0].clientY; },
                touchEnd(e) {
                    const dx = e.changedTouches[0].clientX - tx, dy = e.changedTouches[0].clientY - ty;
                    if (Math.max(Math.abs(dx), Math.abs(dy)) < 20) return;
                    Math.abs(dx) > Math.abs(dy) ? this.turn(dx > 0 ? 1 : -1, 0) : this.turn(0, dy > 0 ? 1 : -1);
                },
                draw() {
                    const cv = this.$refs.cv, g = cv.getContext('2d'), s = cv.width / N;
                    const css = getComputedStyle(document.documentElement);
                    g.fillStyle = css.getPropertyValue('--ui-surface-2') || '#f8fafc';
                    g.fillRect(0, 0, cv.width, cv.height);
                    if (!snake) return;
                    g.fillStyle = '#ef4444';
                    g.beginPath(); g.arc(food.x * s + s / 2, food.y * s + s / 2, s * .38, 0, Math.PI * 2); g.fill();
                    snake.forEach((p, i) => {
                        g.fillStyle = i === 0 ? '#15803d' : '#22c55e';
                        g.fillRect(p.x * s + 1, p.y * s + 1, s - 2, s - 2);
                    });
                },
            };
        });
    });
    </script>
</x-app-layout>
