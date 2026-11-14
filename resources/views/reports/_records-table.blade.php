{{-- Таблица записей вкладки для списков из отчётов: строка открывает карточку записи.
     $slug, $cfg, $columns, $records, $defectIds (id => true), $timeOnly (время без даты) --}}
@php $timeOnly ??= false; @endphp
<div class="overflow-x-auto">
    <table class="ui-table">
        <thead>
            <tr>
                <th class="{{ $timeOnly ? 'w-20' : 'w-36' }}">Внесено</th>
                @foreach($columns as $col)
                    <th>{{ $cfg['fields'][$col]['label'] ?? $col }}</th>
                @endforeach
                <th>Исполнители</th>
                <th>Брак</th>
                <th class="w-10"><span class="sr-only">Открыть</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($records as $rec)
                @php
                    $url = route('records.show', [$slug, $rec->id]);
                    $workersList = collect(['worker1', 'worker2', 'worker3', 'worker4', 'worker5'])
                        ->map(fn ($f) => isset($cfg['fields'][$f]) ? trim((string) $rec->{$f}) : '')->filter()->implode(', ');
                @endphp
                <tr onclick="window.location='{{ $url }}'" class="group cursor-pointer">
                    <td class="tabular-nums whitespace-nowrap"><a href="{{ $url }}">{{ $rec->created_at?->format($timeOnly ? 'H:i' : 'd.m.Y H:i') }}</a></td>
                    @foreach($columns as $col)
                        @php
                            $v = $rec->$col ?? null;
                            $type = $cfg['fields'][$col]['type'] ?? '';
                            if ($type === 'checkbox' || !empty($cfg['fields'][$col]['bool']) || $col === 'is_repaired') $v = $v ? 'Да' : 'Нет';
                            elseif ($col === 'record_date' && $v) $v = \Carbon\Carbon::parse($v)->format('d.m.Y');
                        @endphp
                        <td><div class="break-words">{{ $v !== null && $v !== '' ? $v : '—' }}</div></td>
                    @endforeach
                    <td><div class="break-words max-w-[22rem]">{{ $workersList ?: '—' }}</div></td>
                    <td>
                        @if(isset($defectIds[$rec->id]))
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
