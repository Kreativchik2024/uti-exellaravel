<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Новый сотрудник</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('employees.store') }}"
              class="bg-white p-6 rounded shadow space-y-4">
            @csrf

            @if($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Фамилия <span class="text-red-600">*</span></label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Имя <span class="text-red-600">*</span></label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Отчество</label>
                    <input type="text" name="middle_name" value="{{ old('middle_name') }}"
                           class="w-full border rounded px-3 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Должность</label>
                    <input type="text" name="position" value="{{ old('position') }}"
                           class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Участок / отдел</label>
                    <input type="text" name="department" value="{{ old('department') }}"
                           class="w-full border rounded px-3 py-2">
                </div>
            </div>

            <div>
                <label class="inline-flex items-center">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))
                           class="rounded border-gray-300">
                    <span class="ml-2 text-sm text-gray-700">Активен (участвует в работе)</span>
                </label>
            </div>

            <div class="flex gap-2">
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Сохранить</button>
                <a href="{{ route('employees.index') }}" class="px-4 py-2 text-gray-600">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>