{{-- Одна турнирная таблица: $board (ключ), $title, $rows (place, user_id, name, value, extra), $empty.
     Администратор может убрать любой результат (кнопка × в строке) — за выбранный период. --}}
@php($canForget = auth()->user()?->isAdmin() && isset($board))
<section class="ui-card overflow-hidden">
    <h4 class="px-4 pt-3 pb-2 text-sm font-semibold" style="color: var(--ui-text)">{{ $title }}</h4>
    @forelse($rows as $row)
        <div class="ui-board-row {{ $row['user_id'] === auth()->id() ? 'is-me' : '' }}">
            <span class="ui-board-row__place ui-board-row__place--{{ $row['place'] }}">{{ $row['place'] }}</span>
            <span class="ui-board-row__name">{{ $row['name'] }}</span>
            <span class="ui-board-row__value">{{ $row['value'] }}</span>
            @if($canForget)
                <form method="POST" action="{{ route('games.board.forget') }}" class="ui-board-row__del"
                      onsubmit="return confirm(@js('Удалить результат «' . $row['name'] . '» из таблицы «' . $title . '» (' . mb_strtolower(\App\Services\Games\Leaderboard::PERIODS[$period ?? 'week']) . ')? Партии этого периода будут удалены.'))">
                    @csrf @method('DELETE')
                    <input type="hidden" name="board" value="{{ $board }}">
                    <input type="hidden" name="user_id" value="{{ $row['user_id'] }}">
                    <input type="hidden" name="period" value="{{ $period ?? 'week' }}">
                    <button type="submit" class="ui-board-row__del-btn" title="Удалить результат" aria-label="Удалить результат {{ $row['name'] }}">
                        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </form>
            @endif
        </div>
    @empty
        <p class="px-4 pb-4 text-sm" style="color: var(--ui-muted)">{{ $empty }}</p>
    @endforelse
</section>
