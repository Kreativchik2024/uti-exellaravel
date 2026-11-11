<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5 flex-wrap">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
            </span>
            <div class="flex-1">
                <h2>Игры</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Для перерыва · результаты не входят в рабочую статистику</p>
            </div>
            @if(auth()->user()->isAdmin())
                <form method="POST" action="{{ route('games.toggle') }}">
                    @csrf
                    <button class="ui-games-switch {{ $enabled ? 'is-on' : '' }}" title="Включить или выключить игры для всех сотрудников">
                        <span class="ui-games-switch__knob"></span>
                        <span>{{ $enabled ? 'Игры включены' : 'Игры выключены' }}</span>
                    </button>
                </form>
            @endif
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if(! $enabled)
            <div class="ui-card px-4 py-3 text-sm" style="background: var(--ui-accent-soft); border-color: transparent; color: var(--ui-accent-text)">
                Игры выключены: сотрудники их не видят. Включите переключателем справа вверху.
            </div>
        @endif

        {{-- Игры --}}
        <div class="grid gap-4 md:grid-cols-3">
            <a href="{{ route('games.minesweeper') }}" class="ui-game-card">
                <span class="ui-game-card__icon" style="background: linear-gradient(135deg, #64748b, #0f172a)">
                    <svg viewBox="0 0 24 24" class="w-7 h-7" fill="currentColor"><circle cx="12" cy="12" r="5"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                <span class="ui-game-card__title">Сапёр</span>
                <span class="ui-game-card__text">Открыть поле и не подорваться. Три уровня, рекорд — по времени.</span>
            </a>
            <a href="{{ route('games.snake') }}" class="ui-game-card">
                <span class="ui-game-card__icon" style="background: linear-gradient(135deg, #22c55e, #15803d)">
                    <svg viewBox="0 0 24 24" class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 18h6a3 3 0 000-6H8a3 3 0 010-6h10"/><circle cx="19" cy="6" r="1.2" fill="currentColor"/></svg>
                </span>
                <span class="ui-game-card__title">Змейка</span>
                <span class="ui-game-card__text">Собирайте яблоки и не врезайтесь. Скорость растёт.</span>
            </a>
            <div class="ui-game-card" x-data="battleInvite(@js(route('chat.users')), @js(route('games.battleship.invite')))">
                <span class="ui-game-card__icon" style="background: linear-gradient(135deg, #0ea5e9, #1d4ed8)">
                    <svg viewBox="0 0 24 24" class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l2 3h14l2-3M5 17V9l7-4 7 4v8M12 5v12"/></svg>
                </span>
                <span class="ui-game-card__title">Морской бой</span>
                <span class="ui-game-card__text">С компьютером или с коллегой. Пригласить можно и из личного чата.</span>
                <div class="flex flex-wrap gap-2 mt-3">
                    <form method="POST" action="{{ route('games.battleship.bot') }}">@csrf<input type="hidden" name="level" value="normal"><button class="ui-btn ui-btn--primary ui-btn--sm">С компьютером</button></form>
                    <form method="POST" action="{{ route('games.battleship.bot') }}">@csrf<input type="hidden" name="level" value="strong"><button class="ui-btn ui-btn--ghost ui-btn--sm" title="Считает вероятности, как опытный игрок">Сильный компьютер</button></form>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="open = !open; open && $nextTick(() => $refs.q.focus())">С коллегой</button>
                </div>
                <div x-show="open" x-cloak class="mt-3 w-full">
                    <input x-ref="q" type="search" x-model="q" @input.debounce.300ms="search()" class="ui-input w-full" placeholder="Фамилия коллеги">
                    <div class="mt-1 max-h-48 overflow-y-auto">
                        <template x-for="u in users" :key="u.peer">
                            <button type="button" class="ui-chat__mention-item" @click="invite(u)">
                                <span x-text="u.name"></span><span x-show="u.online" class="ui-chat__online-dot"></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="error" x-text="error" class="text-xs mt-1" style="color: var(--ui-bad)"></p>
                </div>
            </div>
        </div>

        @if($myBattles->isNotEmpty())
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Мои партии в морской бой</h3>
                @foreach($myBattles as $b)
                    @php
                        $opp = $b->vsBot() ? 'Компьютер' : ($b->player1_id === auth()->id() ? $b->player2?->name : $b->player1?->name);
                        $label = ['invited' => $b->player2_id === auth()->id() ? 'вас приглашают' : 'ждём ответа', 'placing' => 'расстановка', 'playing' => 'идёт игра'][$b->status] ?? $b->status;
                    @endphp
                    <a href="{{ route('games.battleship.show', $b) }}" class="ui-search-row" style="grid-template-columns: 1fr auto">
                        <span>с {{ $opp }}</span>
                        <span class="ui-search-row__status {{ $b->status === 'invited' && $b->player2_id === auth()->id() ? 'is-good' : '' }}">{{ $label }}</span>
                    </a>
                @endforeach
            </section>
        @endif

        {{-- Турнирные таблицы --}}
        <div class="flex items-center gap-2 flex-wrap">
            <h3 class="font-semibold text-lg mr-2" style="color: var(--ui-text)">Турнирные таблицы</h3>
            @foreach(\App\Services\Games\Leaderboard::PERIODS as $k => $v)
                <a href="{{ route('games.index', ['period' => $k]) }}" class="ui-btn ui-btn--sm {{ $period === $k ? 'ui-btn--primary' : 'ui-btn--ghost' }}">{{ $v }}</a>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            @foreach($minesweeper as $lvl)
                @include('games._board', ['board' => $lvl['key'], 'title' => 'Сапёр · ' . $lvl['name'], 'rows' => $lvl['rows'], 'empty' => 'Пока никто не прошёл'])
            @endforeach
            @include('games._board', ['board' => 'snake', 'title' => 'Змейка', 'rows' => $snake, 'empty' => 'Пока никто не играл'])
            @include('games._board', ['board' => 'bs:people', 'title' => 'Морской бой · победы над коллегами', 'rows' => $battleship['people'], 'empty' => 'Пока нет побед'])
            @include('games._board', ['board' => 'bs:bot', 'title' => 'Морской бой · победы над компьютером', 'rows' => $battleship['bot'], 'empty' => 'Пока нет побед'])
            @include('games._board', ['board' => 'bs:strong', 'title' => 'Морской бой · победы над сильным компьютером', 'rows' => $battleship['strong'], 'empty' => 'Пока никто не победил'])
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('battleInvite', (usersUrl, inviteUrl) => ({
                open: false, q: '', users: [], error: '',
                async search() {
                    try {
                        const r = await fetch(usersUrl + '?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        this.users = (await r.json()).users || [];
                    } catch (e) {}
                },
                async invite(u) {
                    this.error = '';
                    const r = await fetch(inviteUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.csrfToken ? window.csrfToken() : '' },
                        body: JSON.stringify({ user_id: u.peer }),
                    });
                    const data = await r.json().catch(() => ({}));
                    if (r.ok && data.url) location.href = data.url; else this.error = data.message || 'Не удалось пригласить';
                },
            }));
        });
    </script>
</x-app-layout>
