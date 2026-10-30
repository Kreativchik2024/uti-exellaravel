@foreach($news as $item)
    @php
        $cover = $item->images->first();
    @endphp

    <article class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-lg transition-all duration-200 {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
        <a href="{{ route('news.show', $item) }}" class="block group">

            @if($cover)
                <div style="width:100%; height:280px; background-image:url('{{ route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat;"></div>
            @endif

            <div class="p-4 space-y-2">
                <header class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        {{ $item->author?->name ?? '—' }}
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $item->published_at?->diffForHumans() }}
                    </span>
                    @if($item->is_pinned)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">закреплено</span>
                    @endif
                </header>

                @if($item->title)
                    <h3 class="font-semibold text-lg text-gray-900 group-hover:text-blue-600 transition">{{ $item->title }}</h3>
                @endif

                <p class="text-sm text-gray-700 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($item->body, 300) }}</p>

                @if($cover && $cover->caption)
                    <p class="text-xs text-gray-500 italic">Фото: {{ $cover->caption }}</p>
                @endif

                <div class="pt-2">
                    <span class="inline-flex items-center gap-1.5 text-blue-600 text-sm font-medium group-hover:gap-2.5 transition-all">
                        Читать далее
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </span>
                </div>
            </div>
        </a>
    </article>
@endforeach