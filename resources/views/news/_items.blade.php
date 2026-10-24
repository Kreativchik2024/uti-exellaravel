@foreach($news as $item)
    <article class="bg-white p-4 rounded shadow {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
        <header class="mb-2">
            <strong>{{ $item->author?->name ?? '—' }}</strong>
            <time class="text-sm text-gray-500 ml-2">{{ $item->published_at->diffForHumans() }}</time>
            @if($item->is_pinned)
                <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">закреплено</span>
            @endif
        </header>
        @if($item->title)<h3 class="font-semibold mb-1">{{ $item->title }}</h3>@endif
        <p class="whitespace-pre-line">{{ $item->body }}</p>
        @if($item->images->count())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($item->images as $img)
                    <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank">
                        <img src="{{ route('media.show', ['path' => $img->thumb_path]) }}"
                             class="w-32 h-32 object-cover rounded border" loading="lazy">
                    </a>
                @endforeach
            </div>
        @endif
    </article>
@endforeach