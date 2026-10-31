<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-gray-800">Сотрудники</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('employees.template') }}"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-2 rounded shadow text-sm">
                    Скачать шаблон
                </a>
                <a href="{{ route('employees.export') }}"
                   class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded shadow text-sm">
                    Экспорт в Excel
                </a>
                <a href="{{ route('employees.importForm') }}"
                   class="bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded shadow text-sm">
                    Импорт из Excel
                </a>
                <a href="{{ route('employees.create') }}"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded shadow text-sm">
                    Добавить сотрудника
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
        @endif

        @if(session('import_errors') && count(session('import_errors')))
            <div class="bg-amber-100 text-amber-900 p-3 rounded">
                <div class="font-semibold mb-1">Предупреждения при импорте:</div>
                <ul class="list-disc list-inside text-sm">
                    @foreach(session('import_errors') as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="GET" action="{{ route('employees.index') }}"
              class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 flex gap-2 items-end">
            <div class="flex-1">
                <label class="block text-xs text-gray-500 mb-1">Поиск</label>
                <input type="text" name="q" value="{{ $q }}"
                       placeholder="Фамилия, имя, должность, участок..."
                       class="border rounded px-3 py-2 w-full">
            </div>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Найти</button>
            @if($q !== '')
                <a href="{{ route('employees.index') }}" class="text-sm text-gray-500 underline py-2">Сбросить</a>
            @endif
        </form>

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="p-3 text-left">#</th>
                        <th class="p-3 text-left">Фамилия</th>
                        <th class="p-3 text-left">Имя</th>
                        <th class="p-3 text-left">Отчество</th>
                        <th class="p-3 text-left">Кратко</th>
                        <th class="p-3 text-left">Должность</th>
                        <th class="p-3 text-left">Участок</th>
                        <th class="p-3 text-left">Учётка</th>
                        <th class="p-3 text-center">Активен</th>
                        <th class="p-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $idx => $e)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="p-3 text-gray-400">{{ $employees->firstItem() + $idx }}</td>
                            <td class="p-3 font-medium">{{ $e->last_name }}</td>
                            <td class="p-3">{{ $e->first_name }}</td>
                            <td class="p-3">{{ $e->middle_name }}</td>
                            <td class="p-3 font-mono text-xs">{{ $e->short_name }}</td>
                            <td class="p-3">{{ $e->position }}</td>
                            <td class="p-3">{{ $e->department }}</td>
                            <td class="p-3 text-sm">
                                @if($e->user)
                                    <div class="text-gray-800">{{ $e->user->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $e->user->email }}</div>
                                @else
                                    <span class="text-amber-600 text-xs">— не привязан —</span>
                                @endif
                            </td>
                            <td class="p-3 text-center">
                                @if($e->is_active)
                                    <span class="text-green-700">✓</span>
                                @else
                                    <span class="text-red-600">✗</span>
                                @endif
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <a href="{{ route('employees.edit', $e) }}"
                                   class="text-blue-600 hover:underline text-sm">Изменить</a>
                                <form method="POST" action="{{ route('employees.destroy', $e) }}" class="inline"
                                      onsubmit="return confirm('Удалить сотрудника? Записи, где он был исполнителем, останутся без ссылки.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-600 hover:underline text-sm ml-3">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="p-6 text-center text-gray-500">Сотрудников пока нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $employees->links() }}</div>
    </div>
</x-app-layout>