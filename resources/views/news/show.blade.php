<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('news.index') }}"
                   class="flex-shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95"
                   title="Назад к новостям">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="min-w-0">
                    <h2 class="font-bold text-2xl text-gray-900 tracking-tight truncate">Новость</h2>
                    <p class="text-sm text-gray-500 mt-0.5">#{{ $news->id }}</p>
                </div>
            </div>
            @if(auth()->check() && auth()->user()->canManageContent())
                <div class="flex gap-2 flex-shrink-0">
                    <a href="{{ route('news.edit', $news) }}"
                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Редактировать
                    </a>
                    <form method="POST" action="{{ route('news.destroy', $news) }}"
                          onsubmit="return confirm('Удалить новость? Это действие нельзя отменить.')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Удалить
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <article class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            @php $cover = $news->images->first(); @endphp
            @if($cover)
                <a href="{{ route('media.show', ['path' => $cover->path]) }}" target="_blank" class="block">
                    <div style="width:100%; height:400px; background-image:url('{{ route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat;"></div>
                </a>
            @endif

            <div class="p-6 sm:p-8 space-y-4">
                <header class="flex flex-wrap items-center gap-3 text-sm text-gray-500">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        {{ $news->author?->name ?? 'Неизвестный автор' }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $news->published_at?->format('d.m.Y H:i') }}
                    </span>
                    @if($news->is_pinned)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                            закреплено
                        </span>
                    @endif
                    @if(!$news->is_published)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                            черновик
                        </span>
                    @endif
                </header>

                @if($news->title)
                    <h1 class="text-3xl font-bold text-gray-900 tracking-tight">{{ $news->title }}</h1>
                @endif

                <div class="prose prose-sm sm:prose max-w-none text-gray-700 whitespace-pre-line leading-relaxed">
                    {{ $news->body }}
                </div>

                @if($cover && $cover->caption)
                    <p class="text-xs text-gray-500 italic">Фото: {{ $cover->caption }}</p>
                @endif

                @if($news->images->count() > 1)
                    <div class="pt-6 border-t border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Фотографии</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($news->images->slice(1) as $img)
                                <figure class="text-center">
                                    <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank">
                                        <div style="width:100%; height:160px; background-image:url('{{ route('media.show', ['path' => $img->thumb_path ?? $img->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat; border-radius:6px;"></div>
                                    </a>
                                    @if($img->caption)
                                        <figcaption class="text-xs text-gray-500 mt-1 italic">{{ $img->caption }}</figcaption>
                                    @endif
                                </figure>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </article>

        <div class="mt-4 text-center">
            <a href="{{ route('news.index') }}" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                ← Все новости
            </a>
        </div>
    </div>
</x-app-layout>