@foreach($news as $item)
    @php
        $cover = $item->images->first();
        $rest  = $item->images->slice(1);
    @endphp

    <article class="bg-white rounded shadow overflow-hidden {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
        @if($cover)
            {{-- Обложка: большая картинка сверху --}}
            <a href="{{ route('media.show', ['path' => $cover->path]) }}" target="_blank" class="block">
                <img src="{{ route('media.show', ['path' => $cover->path]) }}"
                     alt="{{ $cover->caption ?? $item->title ?? 'Новость' }}"
                     class="w-full max-h-[480px] object-cover"
                     loading="lazy">
            </a>
        @endif

        <div class="p-4 space-y-2">
            <header class="flex justify-between items-start">
                <div>
                    <strong>{{ $item->author?->name ?? '—' }}</strong>
                    <time class="text-sm text-gray-500 ml-2">{{ $item->published_at->diffForHumans() }}</time>
                    @if($item->is_pinned)
                        <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">закреплено</span>
                    @endif
                </div>
            </header>

            @if($item->title)
                <h3 class="font-semibold text-lg">{{ $item->title }}</h3>
            @endif

            {{-- Текст как описание под картинкой --}}
            <p class="whitespace-pre-line text-gray-700">{{ $item->body }}</p>

            @if($cover && $cover->caption)
                <p class="text-xs text-gray-500 italic">Фото: {{ $cover->caption }}</p>
            @endif

            @if($rest->count())
                {{-- Остальные картинки — галереей под текстом --}}
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach($rest as $img)
                        <figure class="text-center max-w-[160px]">
                            <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank">
                                <img src="{{ route('media.show', ['path' => $img->thumb_path]) }}"
                                     class="w-32 h-32 object-cover rounded border mx-auto"
                                     alt="{{ $img->caption ?? '' }}"
                                     loading="lazy">
                            </a>
                            @if($img->caption)
                                <figcaption class="text-xs text-gray-500 mt-1 italic">
                                    {{ $img->caption }}
                                </figcaption>
                            @endif
                        </figure>
                    @endforeach
                </div>
            @endif
        </div>
    </article>
@endforeach