<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Новости</h2>
            @if(auth()->user()->canManageContent())
                <a href="{{ route('news.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded">
                    + Новая новость
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif

        @forelse($news as $item)
            @php
                $cover = $item->images->first();
                $rest  = $item->images->slice(1);
            @endphp

            <article class="bg-white rounded shadow overflow-hidden {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
                @if($cover)
                    <a href="{{ route('media.show', ['path' => $cover->path]) }}" target="_blank" class="block">
                        <div style="width:100%; height:320px; background-image:url('{{ route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat;"></div>
                    </a>
                @endif

                <div class="p-4 space-y-2">
                    <header class="flex justify-between items-start">
                        <div>
                            <strong>{{ $item->author?->name ?? 'Удалённый пользователь' }}</strong>
                            <time class="text-sm text-gray-500 ml-2">{{ $item->published_at->diffForHumans() }}</time>
                            @if($item->is_pinned)
                                <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">закреплено</span>
                            @endif
                        </div>
                        @if(auth()->user()->canManageContent())
                            <div class="flex gap-2 text-sm">
                                <a href="{{ route('news.edit', $item) }}" class="text-blue-600 underline">Ред.</a>
                                <form method="POST" action="{{ route('news.destroy', $item) }}"
                                      onsubmit="return confirm('Удалить новость?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 underline">Удалить</button>
                                </form>
                            </div>
                        @endif
                    </header>

                    @if($item->title)<h3 class="font-semibold text-lg">{{ $item->title }}</h3>@endif
                    <p class="whitespace-pre-line text-gray-700">{{ $item->body }}</p>

                    @if($cover && $cover->caption)
                        <p class="text-xs text-gray-500 italic">Фото: {{ $cover->caption }}</p>
                    @endif

                    @if($rest->count())
                        <div class="mt-3 flex flex-wrap gap-3">
                            @foreach($rest as $img)
                                <figure class="text-center max-w-[160px]">
                                    <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank">
                                        <div style="width:128px; height:128px; background-image:url('{{ route('media.show', ['path' => $img->thumb_path ?? $img->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat; border-radius:4px; border:1px solid #e5e7eb;"></div>
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
        @empty
            <p class="text-gray-500">Пока нет новостей.</p>
        @endforelse

        <div>{{ $news->links() }}</div>
    </div>
</x-app-layout>