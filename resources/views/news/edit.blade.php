<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Редактировать новость</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('news.update', $news) }}" enctype="multipart/form-data"
              class="bg-white p-6 rounded shadow space-y-4">
            @csrf @method('PUT')

            @if($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 mb-1">Заголовок</label>
                <input name="title" value="{{ old('title', $news->title) }}" maxlength="200"
                       class="w-full border rounded px-3 py-2">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Текст</label>
                <textarea name="body" rows="6" required
                          class="w-full border rounded px-3 py-2">{{ old('body', $news->body) }}</textarea>
            </div>

            @if($news->images->count())
                <div>
                    <div class="text-sm text-gray-600 mb-2">Текущие картинки и подписи</div>
                    <div class="space-y-3">
                        @foreach($news->images as $img)
                            <div class="flex gap-3 items-start bg-gray-50 p-2 rounded">
                                <div class="relative shrink-0">
                                    <img src="{{ route('media.show', ['path' => $img->thumb_path]) }}"
                                         class="w-24 h-24 object-cover rounded border">
                                    <button type="button"
                                            onclick="if(confirm('Удалить картинку?')) document.getElementById('del-img-{{ $img->id }}').submit();"
                                            class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-6 h-6 text-xs leading-6 text-center">
                                        ×
                                    </button>
                                </div>
                                <div class="flex-1">
                                    <label class="block text-xs text-gray-500 mb-1">Подпись к фото</label>
                                    <input type="text"
                                           name="existing_captions[{{ $img->id }}]"
                                           value="{{ old('existing_captions.'.$img->id, $img->caption) }}"
                                           maxlength="255"
                                           placeholder="Например: Общий вид стенда"
                                           class="w-full border rounded px-2 py-1 text-sm">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 mb-1">Добавить картинки с подписями (до 10)</label>
                <input type="file" name="images[]" id="news-images-input" accept="image/*" multiple
                       class="text-sm">
                <div id="caption-fields" class="mt-3 space-y-2"></div>
            </div>

            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $news->is_pinned))>
                <span>Закрепить на главной</span>
            </label>

            <div class="flex gap-2">
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Сохранить</button>
                <a href="{{ route('news.index') }}" class="px-4 py-2 text-gray-600">Отмена</a>
            </div>
        </form>

        @foreach($news->images as $img)
            <form id="del-img-{{ $img->id }}" method="POST"
                  action="{{ route('news.images.destroy', [$news, $img]) }}"
                  class="hidden">
                @csrf @method('DELETE')
            </form>
        @endforeach
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