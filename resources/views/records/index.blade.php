<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center gap-4">
            <div>
                <h2 class="font-bold text-2xl text-gray-900 tracking-tight">{{ $cfg['title'] }}</h2>
                <p class="text-sm text-gray-500 mt-1">Всего записей: {{ $records->total() }}</p>
            </div>
            <a href="{{ route('records.create', $sheet) }}"
               class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Добавить запись
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
        @if(session('ok'))
            <div class="flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 p-4 rounded-lg shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                {{ session('ok') }}
            </div>
        @endif

        <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 flex gap-2">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input name="q" value="{{ $q }}" placeholder="Поиск по записям..."
                       class="w-full border border-gray-200 rounded-lg pl-10 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:outline-none transition">
            </div>
            <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white font-medium px-5 py-2.5 rounded-lg text-sm transition active:scale-95">
                Найти
            </button>
            @if($q)
                <a href="{{ route('records.index', $sheet) }}" class="text-gray-500 hover:text-gray-700 px-4 py-2.5 text-sm flex items-center transition">
                    Сброс
                </a>
            @endif
        </form>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-100">
                            @foreach($listColumns as $col)
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">
                                    @if($col === 'worker1')
                                        Исполнитель
                                    @elseif($col === 'created_by')
                                        Автор
                                    @else
                                        {{ $cfg['fields'][$col]['label'] ?? $col }}
                                    @endif
                                </th>
                            @endforeach
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    @forelse($records as $record)
                        <tr onclick="window.location='{{ route('records.show', [$sheet, $record->id]) }}'"
                            class="group cursor-pointer transition-all duration-150 hover:bg-blue-50/60 active:bg-blue-100">
                            @foreach($listColumns as $col)
                                <td class="px-4 py-3 text-gray-700">
                                    @if($col === 'created_by')
                                        {{ $record->creator?->name ?? '—' }}
                                    @elseif($col === 'worker1')
                                        {{-- Все исполнители через запятую --}}
                                        {{ $record->workers_list ?: '—' }}
                                    @elseif($col === 'post_number')
                                        {{ $record->post_number ? 'Пост ' . $record->post_number : '—' }}
                                    @elseif($col === 'peripheral_number')
                                        {{ $record->peripheral_number ? 'Периферия ' . $record->peripheral_number : '—' }}
                                    @elseif($col === 'is_repaired')
                                        @if($record->is_repaired)
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                                Да
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                                Нет
                                            </span>
                                        @endif
                                    @elseif(in_array($col, ['record_date']))
                                        <span class="font-medium text-gray-900">
                                            {{ $record->$col ? \Carbon\Carbon::parse($record->$col)->format('d.m.Y') : '—' }}
                                        </span>
                                    @else
                                        {{ $record->$col ?: '—' }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right">
                                <svg class="w-4 h-4 text-gray-300 group-hover:text-blue-500 group-hover:translate-x-1 transition-all duration-150" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($listColumns) + 1 }}" class="px-4 py-16 text-center">
                                <div class="flex flex-col items-center gap-3 text-gray-400">
                                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-sm">Записей пока нет</p>
                                    <a href="{{ route('records.create', $sheet) }}" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                                        Добавить первую запись →
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $records->links() }}</div>
    </div>
</x-app-layout>