<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div class="flex items-center gap-3.5">
                <span class="ui-icon-tile">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </span>
                <div>
                    <h2>{{ $cfg['title'] }}</h2>
                    <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                        {{ $q ? 'Найдено' : 'Всего записей' }}: <span class="font-semibold" style="color: var(--ui-text-2)">{{ number_format($records->total(), 0, ',', ' ') }}</span>
                    </p>
                </div>
            </div>
            <a href="{{ route('records.create', $sheet) }}" class="ui-btn ui-btn--primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4v16m8-8H4"/></svg>
                Добавить запись
            </a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        {{-- Уведомления --}}
        @if(session('ok'))
            <div class="ui-card flex items-center gap-3 px-4 py-3 text-sm font-medium" role="status"
                 style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                {{ session('ok') }}
            </div>
        @endif

        @if(session('error') || $errors->has('general'))
            <div class="ui-card flex items-center gap-3 px-4 py-3 text-sm font-medium" role="alert"
                 style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                {{ session('error') ?? $errors->first('general') }}
            </div>
        @endif

        {{-- Поиск --}}
        <form method="GET" class="flex flex-wrap gap-2" role="search">
            <label class="ui-search">
                <span class="sr-only">Поиск по записям</span>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input name="q" value="{{ $q }}" placeholder="Поиск: борт, серийный номер, исполнитель…" autocomplete="off">
            </label>
            @if($listFilter && $listFilter['current'] !== '')
                <input type="hidden" name="{{ $listFilter['field'] }}" value="{{ $listFilter['current'] }}">
            @endif
            <button type="submit" class="ui-btn ui-btn--ghost">Найти</button>
            @if($q)
                <a href="{{ route('records.index', $sheet) }}" class="ui-btn" style="color: var(--ui-muted)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Сбросить
                </a>
            @endif
        </form>

        {{-- Вкладки-фильтры (ПГП: тип борта). Список вкладок — из config/board_types.php --}}
        @if($listFilter)
            @php
                $lfTabs = [['value' => '', 'label' => 'Все', 'count' => $listFilter['total']]];
                foreach ($listFilter['options'] as $opt) {
                    $lfTabs[] = ['value' => $opt, 'label' => $opt, 'count' => $listFilter['counts'][$opt] ?? 0];
                }
                if (($listFilter['counts']['_none'] ?? 0) > 0 || $listFilter['current'] === '_none') {
                    $lfTabs[] = ['value' => '_none', 'label' => $listFilter['empty_label'] ?? 'Без значения', 'count' => $listFilter['counts']['_none'] ?? 0];
                }
            @endphp
            <nav class="flex flex-wrap items-center gap-2" aria-label="{{ $listFilter['label'] }}">
                <span class="text-sm font-medium mr-1" style="color: var(--ui-muted)">{{ $listFilter['label'] }}:</span>
                @foreach($lfTabs as $tab)
                    @php $active = $listFilter['current'] === $tab['value']; @endphp
                    <a href="{{ route('records.index', array_filter([$sheet, $listFilter['field'] => $tab['value'], 'q' => $q], fn ($v) => $v !== '' && $v !== null)) }}"
                       class="ui-btn ui-btn--sm {{ $active ? 'ui-btn--primary' : 'ui-btn--ghost' }}"
                       @if($active) aria-current="page" @endif>
                        {{ $tab['label'] }}
                        <span class="tabular-nums text-xs font-semibold px-1.5 rounded-full"
                              style="background: {{ $active ? 'rgba(255,255,255,.22)' : 'var(--ui-surface-hover)' }}">{{ number_format($tab['count'], 0, ',', ' ') }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        {{-- Карточки записей: мобильные (< 768px) --}}
        <div class="records-cards">
            @forelse($records as $record)
                @php
                    $skipOnCard = ['record_date', 'post_number', 'status', 'created_by', 'worker1', 'health_indicator', 'fault_code'];
                    $cardFields = array_values(array_filter($listColumns, fn ($col) => !in_array($col, $skipOnCard, true)));
                @endphp

                <article class="record-card ui-card p-4">
                    <a href="{{ route('records.show', [$sheet, $record->id]) }}" class="block">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <div class="min-w-0">
                                <div class="text-base font-semibold" style="color: var(--ui-text)">
                                    {{ $record->record_date ? \Carbon\Carbon::parse($record->record_date)->format('d.m.Y') : '—' }}
                                </div>
                                @if(array_key_exists('post_number', $cfg['fields']) && $record->post_number)
                                    <div class="text-xs mt-0.5" style="color: var(--ui-muted)">Пост №{{ $record->post_number }}</div>
                                @endif
                            </div>
                            <div class="flex flex-col items-end gap-1 flex-shrink-0">
                                @if($record->status ?? null)
                                    <x-status-pill :value="$record->status" />
                                @endif
                                @if(($record->health ?? null) === 'green')
                                    <x-status-pill value="Работает" />
                                @elseif(($record->health ?? null) === 'yellow')
                                    <x-status-pill value="Внимание" />
                                @endif
                            </div>
                        </div>

                        @if(!empty($cardFields))
                            <dl>
                                @foreach($cardFields as $col)
                                    @php
                                        $colValue = $record->$col ?? null;
                                        if ($col === 'is_repaired' || ($cfg['fields'][$col]['type'] ?? '') === 'checkbox' || !empty($cfg['fields'][$col]['bool'])) $colValue = $colValue ? 'Да' : 'Нет';
                                    @endphp
                                    <div class="min-w-0">
                                        <dt>{{ $cfg['fields'][$col]['label'] ?? $col }}</dt>
                                        <dd>{{ $colValue !== null && $colValue !== '' ? $colValue : '—' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if($record->fault_code)
                            <div class="mt-2 text-xs" style="color: var(--ui-muted)">
                                Код неиспр.: <span class="font-mono font-semibold" style="color: var(--ui-text)">{{ $record->fault_code }}</span>
                            </div>
                        @endif
                        @if($record->workers_list)
                            <div class="mt-1.5 text-xs" style="color: var(--ui-muted)">
                                Исполнители: <span style="color: var(--ui-text-2)">{{ $record->workers_list }}</span>
                            </div>
                        @endif
                    </a>

                    @can('update', $record)
                        <div class="mt-3 pt-3 flex gap-2 border-t" style="border-color: var(--ui-border)">
                            <a href="{{ route('records.show', [$sheet, $record->id]) }}" class="ui-btn ui-btn--ghost flex-1">Открыть</a>
                            <a href="{{ route('records.edit', [$sheet, $record->id]) }}" class="ui-btn ui-btn--ghost flex-1">Изменить</a>
                        </div>
                    @endcan
                </article>
            @empty
                <div class="ui-card p-8 text-center">
                    <p class="text-sm font-medium" style="color: var(--ui-muted)">{{ $q ? 'Ничего не найдено' : 'Записей пока нет' }}</p>
                    <a href="{{ route('records.create', $sheet) }}" class="inline-block text-sm font-semibold mt-2" style="color: var(--ui-accent-text)">Добавить запись</a>
                </div>
            @endforelse
        </div>

        {{-- Таблица (md+) --}}
        <div class="records-table ui-card overflow-hidden">
            <div class="overflow-x-auto table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            @foreach($listColumns as $col)
                                <th scope="col">
                                    @switch($col)
                                        @case('worker1') Исполнители @break
                                        @case('created_by') Автор @break
                                        @case('health_indicator') Проверка @break
                                        @case('fault_code') Код неиспр. @break
                                        @default {{ $cfg['fields'][$col]['label'] ?? $col }}
                                    @endswitch
                                </th>
                            @endforeach
                            <th class="w-10"><span class="sr-only">Открыть</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($records as $record)
                        <tr onclick="window.location='{{ route('records.show', [$sheet, $record->id]) }}'" class="group cursor-pointer">
                            @foreach($listColumns as $col)
                                <td>
                                    @if($col === 'created_by')
                                        <span class="ui-table__muted">{{ $record->creator?->name ?? '—' }}</span>
                                    @elseif($col === 'worker1')
                                        <div class="break-words max-w-[22rem]" title="{{ $record->workers_list }}">{{ $record->workers_list ?: '—' }}</div>
                                    @elseif($col === 'health_indicator')
                                        @if(($record->health ?? null) === 'green')
                                            <x-status-pill value="Работает" />
                                        @elseif(($record->health ?? null) === 'yellow')
                                            <x-status-pill value="Внимание" />
                                        @else
                                            <span class="ui-table__muted">—</span>
                                        @endif
                                    @elseif($col === 'status')
                                        <x-status-pill :value="$record->status ?? null" />
                                    @elseif($col === 'post_number')
                                        <span class="ui-table__strong whitespace-nowrap">{{ $record->post_number ? '№' . $record->post_number : '—' }}</span>
                                    @elseif($col === 'peripheral_number')
                                        <span class="ui-table__strong whitespace-nowrap">{{ $record->peripheral_number ? 'П' . $record->peripheral_number : '—' }}</span>
                                    @elseif($col === 'is_repaired' || ($cfg['fields'][$col]['type'] ?? '') === 'checkbox' || !empty($cfg['fields'][$col]['bool']))
                                        <x-status-pill :value="$record->$col ? 'Да' : 'Нет'" />
                                    @elseif($col === 'fault_code')
                                        @if($record->fault_code)
                                            <span class="font-mono text-xs font-semibold px-1.5 py-0.5 rounded-md" style="background: var(--ui-surface-hover); color: var(--ui-text)">{{ $record->fault_code }}</span>
                                        @else
                                            <span class="ui-table__muted">—</span>
                                        @endif
                                    @elseif($col === 'record_date')
                                        <span class="ui-table__strong whitespace-nowrap tabular-nums">{{ $record->$col ? \Carbon\Carbon::parse($record->$col)->format('d.m.Y') : '—' }}</span>
                                    @else
                                        <div class="break-words" title="{{ $record->$col }}">{{ $record->$col ?: '—' }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-right">
                                <svg class="w-4 h-4 ml-auto transition-transform group-hover:translate-x-0.5" style="color: var(--ui-faint)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($listColumns) + 1 }}" class="py-16 text-center">
                                <div class="flex flex-col items-center gap-3" style="color: var(--ui-faint)">
                                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <p class="text-sm font-medium">{{ $q ? 'По запросу ничего не найдено' : 'Записей пока нет' }}</p>
                                    <a href="{{ route('records.create', $sheet) }}" class="text-sm font-semibold" style="color: var(--ui-accent-text)">Добавить запись →</a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pt-2">{{ $records->links() }}</div>
    </div>
</x-app-layout>
