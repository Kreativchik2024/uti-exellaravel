<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </span>
            <div>
                <h2>Поиск по участку</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Номер борта, серийный номер, IP, код неисправности — во всех вкладках сразу</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <form method="GET" class="ui-card p-3 flex gap-2">
            <input type="search" name="q" value="{{ $q }}" autofocus minlength="2" required
                   class="ui-input flex-1 h-11 text-base" placeholder="Например: RA-41027 или 192.168.1.25" aria-label="Что найти">
            <button class="ui-btn ui-btn--primary h-11">Найти</button>
        </form>

        @if(mb_strlen($q) >= 2)
            <p class="text-sm" style="color: var(--ui-muted)">
                @if($total)
                    Найдено записей: <strong style="color: var(--ui-text)">{{ $total }}</strong> во вкладках: {{ count($groups) }}
                @else
                    По запросу «{{ $q }}» ничего не найдено
                @endif
            </p>

            @foreach($groups as $g)
                <section class="ui-card overflow-hidden">
                    <div class="flex items-center justify-between gap-3 px-4 py-3" style="border-bottom: 1px solid var(--ui-border)">
                        <h3 class="font-semibold" style="color: var(--ui-text)">{{ $g['title'] }} <span class="text-sm font-normal" style="color: var(--ui-muted)">· {{ $g['count'] }}</span></h3>
                        @if($g['count'] > count($g['rows']))
                            <a href="{{ route('records.index', [$g['slug'], 'q' => $q]) }}" class="text-sm" style="color: var(--ui-accent-text)">Все {{ $g['count'] }} →</a>
                        @endif
                    </div>
                    @foreach($g['rows'] as $row)
                        <a href="{{ route('records.show', [$g['slug'], $row['id']]) }}" class="ui-search-row">
                            <span class="ui-search-row__id">#{{ $row['id'] }}</span>
                            <span class="ui-search-row__date">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.Y') : '—' }}</span>
                            <span class="ui-search-row__hits">
                                @foreach($row['hits'] as $h)
                                    <span><span style="color: var(--ui-muted)">{{ $h['label'] }}:</span> {!! preg_replace('/(' . preg_quote(e($q), '/') . ')/iu', '<mark>$1</mark>', e($h['value'])) !!}</span>
                                @endforeach
                            </span>
                            <span class="ui-search-row__who">{{ $row['worker'] }}</span>
                            @if($row['status'])
                                <span class="ui-search-row__status {{ in_array($row['status'], ['Сдан', 'Работает', 'green', 'Годен'], true) ? 'is-good' : (in_array($row['status'], ['Не сдан', 'red', 'Брак'], true) ? 'is-bad' : '') }}">{{ $row['status'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </section>
            @endforeach
        @endif
    </div>
</x-app-layout>
