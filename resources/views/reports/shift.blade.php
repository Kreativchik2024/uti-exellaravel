<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
                <h2>Отчёт по смене</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">{{ $from->format('d.m.Y H:i') }} — {{ $to->format('d.m.Y H:i') }} · по времени внесения записей</p>
            </div>
        </div>
    </x-slot>

    @php $pct = fn ($p, $t) => \App\Exports\ShiftReportExport::pct($p, $t); $t = $data['totals']; @endphp

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
            <label class="block">
                <span class="ui-label">Дата</span>
                <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" class="ui-input">
            </label>
            <label class="block">
                <span class="ui-label">Смена</span>
                <select name="shift" class="ui-input">
                    @foreach($shifts as $n => $s)
                        <option value="{{ $n }}" @selected($shift === $n)>{{ $s['name'] }} ({{ $s['start'] }}–{{ $s['end'] }})</option>
                    @endforeach
                </select>
            </label>
            <button class="ui-btn ui-btn--primary">Показать</button>
            <a href="{{ route('reports.shift', ['date' => $date->format('Y-m-d'), 'shift' => $shift, 'excel' => 1]) }}" class="ui-btn ui-btn--ghost ml-auto">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Скачать Excel
            </a>
        </form>

        <div class="grid gap-3 sm:grid-cols-4">
            @foreach([['Записей', $t['total']], ['Изделий', $t['items']], ['Брак', $t['defect']], ['% брака', $pct($t['defect'], $t['total'])]] as [$label, $value])
                <div class="ui-card p-4">
                    <div class="text-xs" style="color: var(--ui-muted)">{{ $label }}</div>
                    <div class="text-2xl font-bold mt-1" style="color: {{ $label === 'Брак' && $value ? 'var(--ui-bad)' : 'var(--ui-text)' }}">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if(!$data['workers'])
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">За эту смену записей нет</div>
        @else
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Исполнители</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Исполнитель</th><th class="text-right">Записей</th><th class="text-right">Изделий</th><th class="text-right">Брак</th><th class="text-right">% брака</th><th>По вкладкам</th></tr></thead>
                        <tbody>
                            @foreach($data['workers'] as $name => $w)
                                <tr>
                                    <td class="font-medium">{{ $name }}</td>
                                    <td class="text-right">{{ $w['total'] }}</td>
                                    <td class="text-right">{{ $w['items'] }}</td>
                                    <td class="text-right" style="{{ $w['defect'] ? 'color: var(--ui-bad); font-weight: 600' : '' }}">{{ $w['defect'] }}</td>
                                    <td class="text-right">{{ $pct($w['defect'], $w['total']) }}</td>
                                    <td class="text-xs" style="color: var(--ui-muted)">
                                        @foreach($w['sheets'] as $slug => $s)
                                            <span class="whitespace-nowrap">{{ $data['sheets'][$slug]['title'] ?? $slug }}: {{ $s['total'] }}@if($s['defect']) <span style="color: var(--ui-bad)">(брак {{ $s['defect'] }})</span>@endif</span>@if(!$loop->last) · @endif
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Вкладки</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Вкладка</th><th class="text-right">Записей</th><th class="text-right">Изделий</th><th class="text-right">Брак</th><th class="text-right">% брака</th></tr></thead>
                        <tbody>
                            @foreach($data['sheets'] as $slug => $s)
                                <tr>
                                    <td><a href="{{ route('records.index', $slug) }}" style="color: var(--ui-accent-text)">{{ $s['title'] }}</a></td>
                                    <td class="text-right">{{ $s['total'] }}</td>
                                    <td class="text-right">{{ $s['items'] }}</td>
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
