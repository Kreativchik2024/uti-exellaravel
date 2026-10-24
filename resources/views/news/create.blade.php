<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Новая новость</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('news.store') }}" enctype="multipart/form-data"
              class="bg-white p-6 rounded shadow space-y-4">
            @csrf

            @if($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 mb-1">Заголовок (опционально)</label>
                <input name="title" value="{{ old('title') }}" maxlength="200"
                       class="w-full border rounded px-3 py-2">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Текст</label>
                <textarea name="body" rows="6" required
                          class="w-full border rounded px-3 py-2">{{ old('body') }}</textarea>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Картинки (можно несколько)</label>
                <input type="file" name="images[]" accept="image/*" multiple>
            </div>

            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned'))>
                <span>Закрепить на главной</span>
            </label>

            <div class="flex gap-2">
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Опубликовать</button>
                <a href="{{ route('dashboard') }}" class="px-4 py-2 text-gray-600">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>