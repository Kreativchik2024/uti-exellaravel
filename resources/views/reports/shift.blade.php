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

    @php
        $pct = fn ($p, $t) => \App\Exports\ShiftReportExport::pct($p, $t); $t = $data['totals'];
        // ссылка на список записей, из которых сложено число
        $rec = fn (array $f = []) => route('reports.shift.records', ['date' => $date->format('Y-m-d'), 'shift' => $shift] + $f);
        $link = 'color: var(--ui-accent-text); text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 3px';
    @endphp

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
            @php $nextFuture = \App\Services\ShiftReport::bounds(\Carbon\Carbon::parse($next['date']), $next['shift'])[0]->isFuture(); @endphp
            <div class="flex gap-2">
                <a href="{{ route('reports.shift', $prev) }}" class="ui-btn ui-btn--ghost" title="Предыдущая смена">← Пред.</a>
                @if($nextFuture)
                    <span class="ui-btn ui-btn--ghost opacity-40 pointer-events-none" title="Смена ещё не началась">След. →</span>
                @else
                    <a href="{{ route('reports.shift', $next) }}" class="ui-btn ui-btn--ghost" title="Следующая смена">След. →</a>
                @endif
            </div>
            <a href="{{ route('reports.shift', ['date' => $date->format('Y-m-d'), 'shift' => $shift, 'excel' => 1]) }}" class="ui-btn ui-btn--ghost ml-auto">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Скачать Excel
            </a>
        </form>

        <div class="grid gap-3 sm:grid-cols-4">
            @foreach([['Записей', $t['total'], $rec()], ['Изделий', $t['items'], $rec()], ['Брак', $t['defect'], $rec(['defect' => 1])], ['% брака', $pct($t['defect'], $t['total']), null]] as [$label, $value, $href])
                @php $tag = $href && $value ? 'a' : 'div'; @endphp
                <{{ $tag }} @if($tag === 'a') href="{{ $href }}" title="Открыть список записей" @endif class="ui-card p-4 block {{ $tag === 'a' ? 'transition hover:shadow-md' : '' }}">
                    <div class="text-xs" style="color: var(--ui-muted)">{{ $label }}</div>
                    <div class="text-2xl font-bold mt-1" style="color: {{ $label === 'Брак' && $value ? 'var(--ui-bad)' : 'var(--ui-text)' }}">{{ $value }}</div>
                </{{ $tag }}>
            @endforeach
        </div>

        @if(!$data['sheets'])
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">За эту смену записей нет</div>
        @else
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Исполнители</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Исполнитель</th><th class="text-right w-28">Записей</th><th class="text-right w-28">Изделий</th><th class="text-right w-28">Брак</th><th class="text-right w-28">% брака</th><th>По вкладкам</th></tr></thead>
                        <tbody>
                            @if(!$data['workers'])
                                <tr><td colspan="6" class="text-center" style="color: var(--ui-muted)">Ни в одной записи не указан исполнитель</td></tr>
                            @endif
                            @foreach($data['workers'] as $name => $w)
                                <tr>
                                    <td class="font-medium">
                                        {{ $name }}
                                        @if(empty($w['known']))<span class="ui-badge-warn" title="Такого сотрудника нет в справочнике — проверьте написание в записях">нет в справочнике</span>@endif
                                        @if(!empty($w['variants']))<div class="text-xs font-normal" style="color: var(--ui-muted)">также записан: {{ implode(', ', array_slice($w['variants'], 0, 4)) }}@if(count($w['variants']) > 4) и ещё {{ count($w['variants']) - 4 }}@endif</div>@endif
                                    </td>
                                    <td class="text-right"><a href="{{ $rec(['worker' => $name]) }}" style="{{ $link }}" title="Открыть записи исполнителя">{{ $w['total'] }}</a></td>
                                    <td class="text-right"><a href="{{ $rec(['worker' => $name]) }}" style="{{ $link }}" title="Открыть записи исполнителя">{{ $w['items'] }}</a></td>
                                    <td class="text-right" style="{{ $w['defect'] ? 'color: var(--ui-bad); font-weight: 600' : '' }}">
                                        @if($w['defect'])<a href="{{ $rec(['worker' => $name, 'defect' => 1]) }}" style="color: inherit; text-decoration: underline dotted; text-underline-offset: 3px" title="Открыть брак исполнителя">{{ $w['defect'] }}</a>@else{{ $w['defect'] }}@endif
                                    </td>
                                    <td class="text-right">{{ $pct($w['defect'], $w['total']) }}</td>
                                    <td>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach($w['sheets'] as $slug => $s)
                                                <a href="{{ $rec(['worker' => $name, 'sheet' => $slug]) }}" title="Открыть записи" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-0.5 text-xs transition hover:opacity-80" style="background: var(--ui-surface-2); border: 1px solid var(--ui-border); color: var(--ui-text-2)">
                                                    {{ $data['sheets'][$slug]['title'] ?? $slug }}
                                                    <b style="color: var(--ui-text)">{{ $s['total'] }}</b>
                                                    @if($s['defect'])<span style="color: var(--ui-bad)" title="Брак">· брак {{ $s['defect'] }}</span>@endif
                                                </a>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($t['unassigned'])
                    <p class="px-4 py-2 text-xs" style="color: var(--ui-muted); border-top: 1px solid var(--ui-border)">Записей без исполнителя: {{ $t['unassigned'] }} — они никому не засчитаны. Запись с несколькими исполнителями засчитывается каждому.</p>
                @endif
            </section>

            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Вкладки</h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Вкладка</th><th class="text-right w-28">Записей</th><th class="text-right w-28">Изделий</th><th class="text-right w-28">Брак</th><th class="text-right w-28">% брака</th></tr></thead>
                        <tbody>
                            @foreach($data['sheets'] as $slug => $s)
                                <tr>
                                    <td><a href="{{ route('records.index', $slug) }}" style="color: var(--ui-accent-text)">{{ $s['title'] }}</a></td>
                                    <td class="text-right"><a href="{{ $rec(['sheet' => $slug]) }}" style="{{ $link }}" title="Открыть записи вкладки за смену">{{ $s['total'] }}</a></td>
                                    <td class="text-right"><a href="{{ $rec(['sheet' => $slug]) }}" style="{{ $link }}" title="Открыть записи вкладки за смену">{{ $s['items'] }}</a></td>
                                    <td class="text-right" style="{{ $s['defect'] ? 'color: var(--ui-bad); font-weight: 600' : '' }}">
                                        @if($s['defect'])<a href="{{ $rec(['sheet' => $slug, 'defect' => 1]) }}" style="color: inherit; text-decoration: underline dotted; text-underline-offset: 3px" title="Открыть брак вкладки">{{ $s['defect'] }}</a>@else{{ $s['defect'] }}@endif
                                    </td>
                                    <td class="text-right">{{ $pct($s['defect'], $s['total']) }}</td>
                                </tr>
                            @endforeach
                            @if(count($data['sheets']) > 1)
                                <tr class="font-semibold" style="background: var(--ui-surface-2)">
                                    <td style="color: var(--ui-text)">Итого</td>
                                    <td class="text-right"><a href="{{ $rec() }}" style="{{ $link }}">{{ $t['total'] }}</a></td>
                                    <td class="text-right"><a href="{{ $rec() }}" style="{{ $link }}">{{ $t['items'] }}</a></td>
                                    <td class="text-right" style="{{ $t['defect'] ? 'color: var(--ui-bad)' : 'color: var(--ui-text)' }}">{{ $t['defect'] }}</td>
                                    <td class="text-right" style="color: var(--ui-text)">{{ $pct($t['defect'], $t['total']) }}</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
