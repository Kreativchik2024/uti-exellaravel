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
                    <div class="text-sm text-gray-600 mb-2">Текущие картинки</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($news->images as $img)
                            <div class="relative">
                                <img src="{{ route('media.show', ['path' => $img->thumb_path]) }}"
                                     class="w-24 h-24 object-cover rounded border">
                                <button form="del-img-{{ $img->id }}"
                                        class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-6 h-6 text-xs">×</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 mb-1">Добавить картинки</label>
                <input type="file" name="images[]" accept="image/*" multiple>
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
                  onsubmit="return confirm('Удалить картинку?')">
                @csrf @method('DELETE')
            </form>
        @endforeach
    </div>
</x-app-layout>