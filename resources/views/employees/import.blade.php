<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Импорт сотрудников из Excel</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if(session('error'))
            <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <div class="bg-white rounded-lg shadow p-6 space-y-4">
            <p class="text-sm text-gray-600">
                Загрузите файл <strong>.xlsx</strong>, <strong>.xls</strong> или <strong>.csv</strong>
                с колонками:
            </p>

            <ul class="list-disc list-inside text-sm text-gray-700 bg-gray-50 p-3 rounded">
                <li><strong>Фамилия</strong> — обязательно</li>
                <li><strong>Имя</strong> — обязательно</li>
                <li><strong>Отчество</strong> — опционально</li>
                <li><strong>Должность</strong> — опционально</li>
                <li><strong>Участок</strong> — опционально</li>
                <li><strong>Активен</strong> — опционально (<code>Да</code>/<code>Нет</code>, по умолчанию <code>Да</code>)</li>
            </ul>

            <p class="text-sm text-gray-600">
                Если у вас старый список в виде <em>«Иванов И.И.»</em> одной колонкой — назовите её
                <strong>«Кратко»</strong> или <strong>«ФИО»</strong>, импорт попробует разобрать.
                Сотрудник с такой же фамилией+именем+отчеством будет <strong>обновлён</strong>, новый — <strong>добавлен</strong>.
            </p>

            <p class="text-sm">
                <a href="{{ route('employees.template') }}" class="text-blue-600 hover:underline">
                    Скачать шаблон Excel с заголовками →
                </a>
            </p>

            <form method="POST" action="{{ route('employees.import') }}" enctype="multipart/form-data"
                  class="space-y-4 pt-2 border-t">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Файл</label>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                           class="block w-full text-sm border rounded px-3 py-2 bg-white">
                </div>

                <div class="flex gap-2">
                    <button class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded">
                        Загрузить и импортировать
                    </button>
                    <a href="{{ route('employees.index') }}" class="px-4 py-2 text-gray-600">Отмена</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>