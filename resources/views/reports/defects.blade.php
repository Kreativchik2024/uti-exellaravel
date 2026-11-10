<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </span>
            <div>
                <h2>Причины брака</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">{{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }} · по текущему участку</p>
            </div>
        </div>
    </x-slot>

    @php
        $pct = fn ($p, $t) => \App\Exports\ShiftReportExport::pct($p, $t);
        $bars = function (array $items) {
            $max = max(1, ...array_column($items, 'count') ?: [1]);
            return array_map(fn ($i) => $i + ['w' => round($i['count'] / $max * 100)], $items);
        };
    @endphp

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
            <label class="block"><span class="ui-label">С</span><input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="ui-input"></label>
            <label class="block"><span class="ui-label">По</span><input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="ui-input"></label>
            <label class="block">
                <span class="ui-label">Вкладка</span>
                <select name="sheet" class="ui-input">
                    <option value="">Все вкладки участка</option>
                    @foreach($sheets as $slug => $cfg)
                        <option value="{{ $slug }}" @selected($sheet === $slug)>{{ $cfg['title'] }}</option>
                    @endforeach
                </select>
            </label>
            <button class="ui-btn ui-btn--primary">Показать</button>
        </form>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="ui-card p-4"><div class="text-xs" style="color: var(--ui-muted)">Записей</div><div class="text-2xl font-bold mt-1">{{ $data['total'] }}</div></div>
            <div class="ui-card p-4"><div class="text-xs" style="color: var(--ui-muted)">Брак</div><div class="text-2xl font-bold mt-1" style="color: var(--ui-bad)">{{ $data['defect'] }}</div></div>
            <div class="ui-card p-4"><div class="text-xs" style="color: var(--ui-muted)">% брака</div><div class="text-2xl font-bold mt-1">{{ $pct($data['defect'], $data['total']) }}</div></div>
        </div>

        @if(!$data['defect'])
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">За период брака нет</div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach([['Частые причины', $data['reasons'], 'Причины не указаны'], ['Коды неисправностей', $data['codes'], 'Коды не указаны'], ['Неисправные узлы', $data['parts'], 'Нет отметок «не работает»']] as [$title, $items, $empty])
                    <section class="ui-card overflow-hidden">
                        <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">{{ $title }}</h3>
                        @forelse($bars($items) as $i)
                            <div class="ui-bar-row" title="{{ implode(', ', $i['sheets']) }}">
                                <span class="ui-bar-row__label">{{ $i['text'] }}</span>
                                <span class="ui-bar-row__track"><span class="ui-bar-row__fill" style="width: {{ $i['w'] }}%"></span></span>
                                <span class="ui-bar-row__value">{{ $i['count'] }}</span>
                            </div>
                        @empty
                            <p class="px-4 pb-4 text-sm" style="color: var(--ui-muted)">{{ $empty }}</p>
                        @endforelse
                    </section>
                @endforeach

                <section class="ui-card overflow-hidden">
                    <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">По неделям</h3>
                    @php $maxWeek = max(1, ...array_column($data['weeks'], 'total') ?: [1]); @endphp
                    @foreach($data['weeks'] as $week => $w)
                        <div class="ui-bar-row">
                            <span class="ui-bar-row__label">с {{ \Carbon\Carbon::parse($week)->format('d.m') }}</span>
                            <span class="ui-bar-row__track">
                                <span class="ui-bar-row__fill ui-bar-row__fill--muted" style="width: {{ round($w['total'] / $maxWeek * 100) }}%">
                                    <span class="ui-bar-row__fill" style="width: {{ $w['total'] ? round($w['defect'] / $w['total'] * 100) : 0 }}%"></span>
                                </span>
                            </span>
                            <span class="ui-bar-row__value">{{ $w['defect'] }}/{{ $w['total'] }} · {{ $pct($w['defect'], $w['total']) }}</span>
                        </div>
                    @endforeach
                </section>
            </div>

            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Исполнители с браком</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Исполнитель</th><th class="text-right">Записей</th><th class="text-right">Брак</th><th class="text-right">% брака</th></tr></thead>
                        <tbody>
                            @foreach($data['workers'] as $name => $w)
                                <tr>
                                    <td class="font-medium">{{ $name }}</td>
                                    <td class="text-right">{{ $w['total'] }}</td>
                                    <td class="text-right" style="color: var(--ui-bad); font-weight: 600">{{ $w['defect'] }}</td>
                                    <td class="text-right">{{ $pct($w['defect'], $w['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">По вкладкам</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Вкладка</th><th class="text-right">Записей</th><th class="text-right">Брак</th><th class="text-right">% брака</th></tr></thead>
                        <tbody>
                            @foreach(collect($data['sheets'])->sortByDesc('defect') as $slug => $s)
                                <tr>
                                    <td><a href="{{ route('reports.defects', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'sheet' => $slug]) }}" style="color: var(--ui-accent-text)">{{ $s['title'] }}</a></td>
                                    <td class="text-right">{{ $s['total'] }}</td>
                                    <td class="text-right" style="{{ $s['defect'] ? 'color: var(--ui-bad); font-weight: 600' : '' }}">{{ $s['defect'] }}</td>
                                    <td class="text-right">{{ $pct($s['defect'], $s['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
