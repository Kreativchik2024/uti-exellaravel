<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0 w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-md shadow-blue-500/25">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-bold text-2xl text-gray-900 tracking-tight">{{ $cfg['title'] }}</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Всего записей: {{ $records->total() }}</p>
                </div>
            </div>
            <a href="{{ route('records.create', $sheet) }}"
               class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-xl shadow-md shadow-blue-500/20 hover:shadow-lg hover:shadow-blue-500/30 transition-all active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Добавить запись
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

        {{-- Flash --}}
        @if(session('ok'))
            <div class="flex items-center gap-3 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm font-medium">{{ session('ok') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm font-medium">{{ session('error') }}</span>
            </div>
        @endif

        {{-- Search --}}
        <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-200/70 p-3 flex flex-wrap gap-2">
            <div class="relative flex-1 min-w-[240px]">
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input name="q" value="{{ $q }}" placeholder="Поиск по записям…"
                       class="w-full rounded-lg border border-gray-200 bg-gray-50/60 pl-10 pr-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition focus:bg-white focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 hover:border-gray-300">
            </div>
            <button type="submit" class="inline-flex items-center gap-1.5 bg-gray-900 hover:bg-gray-800 text-white font-medium px-5 py-2.5 rounded-lg text-sm transition active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Найти
            </button>
            @if($q)
                <a href="{{ route('records.index', $sheet) }}"
                   class="inline-flex items-center gap-1 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Сброс
                </a>
            @endif
        </form>

        {{-- Table --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200/70 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-base">
                    <thead>
                        <tr class="bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200">
                            @foreach($listColumns as $col)
                                <th class="px-4 py-4 text-left text-sm font-bold text-gray-600 uppercase tracking-wider whitespace-nowrap">
                                    @if($col === 'worker1')
                                        Исполнители
                                    @elseif($col === 'created_by')
                                        Автор
                                    @elseif($col === 'health_indicator')
                                        Проверка
                                    @elseif($col === 'fault_code')
                                        Код неиспр.
                                    @else
                                        {{ $cfg['fields'][$col]['label'] ?? $col }}
                                    @endif
                                </th>
                            @endforeach
                            <th class="px-2 py-4 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    @forelse($records as $record)
                        <tr onclick="window.location='{{ route('records.show', [$sheet, $record->id]) }}'"
                            class="group cursor-pointer transition-colors hover:bg-blue-50/50">
                            @foreach($listColumns as $col)
                                <td class="px-4 py-4 text-base text-gray-700 align-middle">
                                    @if($col === 'created_by')
                                        <span class="text-base text-gray-700">
                                            {{ $record->creator?->name ?? '—' }}
                                        </span>
                                    @elseif($col === 'worker1')
                                        <div class="text-base text-gray-800 break-words" title="{{ $record->workers_list }}">
                                            {{ $record->workers_list ?: '—' }}
                                        </div>
                                    @elseif($col === 'health_indicator')
                                        @if(($record->health ?? null) === 'green')
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-emerald-500 text-white whitespace-nowrap shadow-sm">
                                                <span class="w-2 h-2 rounded-full bg-white"></span>
                                                Работает
                                            </span>
                                        @elseif(($record->health ?? null) === 'yellow')
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-red-500 text-white whitespace-nowrap shadow-sm">
                                                <span class="w-2 h-2 rounded-full bg-white"></span>
                                                Внимание
                                            </span>
                                        @else
                                            <span class="text-base text-gray-400">—</span>
                                        @endif
                                    @elseif($col === 'status')
                                        @php
                                            $statusVal = $record->status ?? null;
                                            $isGood = in_array($statusVal, ['Сдан', 'Исправен', 'Проверен', 'Работает', 'Да'], true);
                                            $isBad  = in_array($statusVal, ['Не сдан', 'Неисправен', 'Не работает', 'Брак', 'Нет'], true);
                                            $isWarn = in_array($statusVal, ['В работе', 'В ремонт', 'Требует ремонта', 'Требует замены'], true);

                                            $statusCls = match (true) {
                                                $isGood => 'bg-emerald-500 text-white',
                                                $isBad  => 'bg-red-500 text-white',
                                                $isWarn => 'bg-amber-500 text-white',
                                                default => 'bg-gray-200 text-gray-700',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold whitespace-nowrap shadow-sm {{ $statusCls }}">
                                            @if($statusVal)
                                                <span class="w-2 h-2 rounded-full bg-white"></span>
                                            @endif
                                            {{ $statusVal ?: '—' }}
                                        </span>
                                    @elseif($col === 'post_number')
                                        <span class="text-base font-medium text-gray-900 whitespace-nowrap">
                                            {{ $record->post_number ? '№' . $record->post_number : '—' }}
                                        </span>
                                    @elseif($col === 'peripheral_number')
                                        <span class="text-base font-medium text-gray-900 whitespace-nowrap">
                                            {{ $record->peripheral_number ? 'П' . $record->peripheral_number : '—' }}
                                        </span>
                                    @elseif($col === 'is_repaired')
                                        @if($record->is_repaired)
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-emerald-500 text-white whitespace-nowrap shadow-sm">
                                                <span class="w-2 h-2 rounded-full bg-white"></span>
                                                Да
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-red-500 text-white whitespace-nowrap shadow-sm">
                                                <span class="w-2 h-2 rounded-full bg-white"></span>
                                                Нет
                                            </span>
                                        @endif
                                    @elseif($col === 'fault_code')
                                        <span class="text-sm font-mono text-gray-700 whitespace-nowrap">
                                            {{ $record->fault_code ?: '—' }}
                                        </span>
                                    @elseif(in_array($col, ['record_date']))
                                        <span class="text-base font-semibold text-gray-900 whitespace-nowrap">
                                            {{ $record->$col ? \Carbon\Carbon::parse($record->$col)->format('d.m.Y') : '—' }}
                                        </span>
                                    @else
                                        <div class="text-base text-gray-700 break-words" title="{{ $record->$col }}">
                                            {{ $record->$col ?: '—' }}
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-2 py-4 text-right">
                                <svg class="w-4 h-4 text-gray-300 group-hover:text-blue-500 ml-auto transition-all duration-150 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($listColumns) + 1 }}" class="px-4 py-16 text-center">
                                <div class="flex flex-col items-center gap-3 text-gray-400">
                                    <svg class="w-14 h-14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-sm font-medium">Записей пока нет</p>
                                    <a href="{{ route('records.create', $sheet) }}"
                                       class="inline-flex items-center gap-1.5 text-blue-600 hover:text-blue-700 text-sm font-medium">
                                        Добавить первую запись
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                        </svg>
                                    </a>
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