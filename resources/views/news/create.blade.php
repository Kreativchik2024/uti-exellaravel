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
                <label class="block text-sm text-gray-600 mb-1">Картинки с подписями (до 10)</label>
                <input type="file" name="images[]" id="news-images-input" accept="image/*" multiple
                       class="text-sm">
                <p class="text-xs text-gray-500 mt-1">После выбора файлов появятся поля для подписей.</p>
                <div id="caption-fields" class="mt-3 space-y-2"></div>
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

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('news-images-input');
        var container = document.getElementById('caption-fields');
        if (!input || !container) return;

        input.addEventListener('change', function () {
            container.innerHTML = '';
            Array.prototype.forEach.call(this.files, function (file, i) {
                var row = document.createElement('div');
                row.className = 'flex gap-2 items-center';

                var name = document.createElement('span');
                name.className = 'text-xs text-gray-500 truncate';
                name.style.maxWidth = '180px';
                name.textContent = file.name;

                var inp = document.createElement('input');
                inp.type = 'text';
                inp.name = 'captions[' + i + ']';
                inp.placeholder = 'Подпись к фото (необязательно)';
                inp.maxLength = 255;
                inp.className = 'flex-1 border rounded px-2 py-1 text-sm';

                row.appendChild(name);
                row.appendChild(inp);
                container.appendChild(row);
            });
        });
    });
    </script>
</x-app-layout>