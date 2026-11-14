<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </span>
            <div>
                <h2>Записи смены{{ $onlyDefect ? ' — брак' : '' }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                    {{ $from->format('d.m.Y H:i') }} — {{ $to->format('d.m.Y H:i') }}
                    @if($sheetTitle) · {{ $sheetTitle }} @endif
                    @if($worker) · {{ $worker }} @endif
                </p>
            </div>
        </div>
    </x-slot>

    @php $base = ['date' => $date->format('Y-m-d'), 'shift' => $shift]; @endphp

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.shift', $base) }}" class="ui-btn ui-btn--ghost">← К отчёту по смене</a>
            @if($onlyDefect)
                <a href="{{ route('reports.shift.records', array_filter($base + ['sheet' => $sheet, 'worker' => $worker])) }}" class="ui-btn ui-btn--ghost">Показать все записи</a>
            @else
                <a href="{{ route('reports.shift.records', array_filter($base + ['sheet' => $sheet, 'worker' => $worker, 'defect' => 1])) }}" class="ui-btn ui-btn--ghost">Только брак</a>
            @endif
        </div>

        @forelse($groups as $slug => $g)
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide flex items-center gap-2" style="color: var(--ui-muted)">
                    <a href="{{ route('records.index', $slug) }}" style="color: var(--ui-accent-text)">{{ $g['title'] }}</a>
                    <span>· {{ $g['records']->count() }}</span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th class="w-20">Внесено</th>
                                @foreach($g['columns'] as $col)
                                    <th>{{ $g['cfg']['fields'][$col]['label'] ?? $col }}</th>
                                @endforeach
                                <th>Исполнители</th>
                                <th>Брак</th>
                                <th class="w-10"><span class="sr-only">Открыть</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($g['records'] as $rec)
                                @php $url = route('records.show', [$slug, $rec->id]); @endphp
                                <tr onclick="window.location='{{ $url }}'" class="group cursor-pointer">
                                    <td class="tabular-nums whitespace-nowrap"><a href="{{ $url }}">{{ $rec->created_at?->format('H:i') }}</a></td>
                                    @foreach($g['columns'] as $col)
                                        @php
                                            $v = $rec->$col ?? null;
                                            $type = $g['cfg']['fields'][$col]['type'] ?? '';
                                            if ($type === 'checkbox' || !empty($g['cfg']['fields'][$col]['bool']) || $col === 'is_repaired') $v = $v ? 'Да' : 'Нет';
                                            elseif ($col === 'record_date' && $v) $v = \Carbon\Carbon::parse($v)->format('d.m.Y');
                                        @endphp
                                        <td><div class="break-words">{{ $v !== null && $v !== '' ? $v : '—' }}</div></td>
                                    @endforeach
                                    <td><div class="break-words max-w-[22rem]">{{ $rec->workers_list ?: '—' }}</div></td>
                                    <td>
                                        @if($rec->is_defect)
                                            <span class="font-semibold" style="color: var(--ui-bad)">{{ $rec->fault_code ?: 'брак' }}</span>
                                        @else
                                            <span class="ui-table__muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <svg class="w-4 h-4 ml-auto transition-transform group-hover:translate-x-0.5" style="color: var(--ui-faint)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">Записей нет</div>
        @endforelse
    </div>
</x-app-layout>
