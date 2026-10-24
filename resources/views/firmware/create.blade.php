<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Загрузить прошивку</h2>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('firmware.store') }}" enctype="multipart/form-data"
              class="bg-white p-6 rounded shadow space-y-4">
            @csrf

            @if($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 mb-1">Категория</label>
                <select name="category_id" required class="w-full border rounded px-3 py-2">
                    <option value="">— выберите —</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Версия</label>
                <input name="version" value="{{ old('version') }}" required maxlength="80"
                       class="w-full border rounded px-3 py-2 font-mono" placeholder="например 4738 или 1.1.0">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Файл (до 500 МБ)</label>
                <input type="file" name="file" required>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Описание (опционально)</label>
                <textarea name="notes" rows="3" class="w-full border rounded px-3 py-2">{{ old('notes') }}</textarea>
            </div>

            <label class="flex items-center gap-2">
                <input type="checkbox" name="make_actual" value="1" @checked(old('make_actual', true))>
                <span>Сделать актуальной (старая актуальная уйдёт в устаревшие)</span>
            </label>

            <div class="flex gap-2">
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Загрузить</button>
                <a href="{{ route('firmware.index') }}" class="px-4 py-2 text-gray-600">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>