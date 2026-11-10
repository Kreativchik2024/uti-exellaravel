{{-- Одна турнирная таблица: $title, $rows (place, user_id, name, value, extra), $empty --}}
<section class="ui-card overflow-hidden">
    <h4 class="px-4 pt-3 pb-2 text-sm font-semibold" style="color: var(--ui-text)">{{ $title }}</h4>
    @forelse($rows as $row)
        <div class="ui-board-row {{ $row['user_id'] === auth()->id() ? 'is-me' : '' }}">
            <span class="ui-board-row__place ui-board-row__place--{{ $row['place'] }}">{{ $row['place'] }}</span>
            <span class="ui-board-row__name">{{ $row['name'] }}</span>
            <span class="ui-board-row__value">{{ $row['value'] }}</span>
        </div>
    @empty
        <p class="px-4 pb-4 text-sm" style="color: var(--ui-muted)">{{ $empty }}</p>
    @endforelse
</section>
