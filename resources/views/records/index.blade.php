<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $cfg['title'] }}</h2>
            <a href="{{ route('records.create', $sheet) }}" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
                + Добавить
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif

        {{-- Поиск --}}
        <form method="GET" action="{{ route('records.index', $sheet) }}" class="bg-white p-3 rounded shadow flex gap-2 flex-wrap items-center">
            <input type="text" name="q" value="{{ $q }}" placeholder="Поиск по S/N, ФИО, номеру борта, IP и т.д."
                   class="border rounded px-3 py-2 flex-1 min-w-[200px] text-sm">
            <button class="bg-gray-800 text-white px-4 py-2 rounded text-sm hover:bg-gray-700 transition">Найти</button>
            @if($q !== '')
                <a href="{{ route('records.index', $sheet) }}" class="text-sm text-gray-500 hover:text-gray-700 underline">Сбросить</a>
            @endif
        </form>

        {{-- Таблица --}}
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    @foreach($cfg['list_columns'] as $col)
                        <th class="p-2 text-left">{{ $cfg['fields'][$col]['label'] ?? $col }}</th>
                    @endforeach
                    <th class="p-2 text-left">Исполнители</th>
                    <th class="p-2 text-left">Автор</th>
                    <th class="p-2 text-left">Действия</th>
                </tr>
                </thead>
                <tbody>
                @forelse($records as $rec)
                    <tr class="border-t">
                        @foreach($cfg['list_columns'] as $col)
                            <td class="p-2">
                                @if(($cfg['fields'][$col]['type'] ?? '') === 'date' && $rec->$col)
                                    {{ \Carbon\Carbon::parse($rec->$col)->format('d.m.Y') }}
                                @else
                                    {{ $rec->$col }}
                                @endif
                            </td>
                        @endforeach
                        <td class="p-2 text-sm">
                            {{ collect([$rec->worker1,$rec->worker2,$rec->worker3,$rec->worker4,$rec->worker5])->filter()->join(', ') }}
                        </td>
                        <td class="p-2 text-xs text-gray-500">{{ $rec->creator?->name ?? '—' }}</td>
                        <td class="p-2 whitespace-nowrap space-x-2">
                            @can('update', $rec)
                                <a href="{{ route('records.edit', [$sheet, $rec->id]) }}"
                                   class="text-blue-600 underline">Ред.</a>
                            @endcan
                            @can('delete', $rec)
                                <form method="POST" action="{{ route('records.destroy', [$sheet, $rec->id]) }}"
                                      class="inline" onsubmit="return confirm('Удалить запись?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 underline">Удалить</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="100" class="p-4 text-center text-gray-500">
                        @if($q !== '')
                            По запросу «{{ $q }}» ничего не найдено.
                        @else
                            Нет записей
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $records->links() }}</div>
    </div>
</x-app-layout>