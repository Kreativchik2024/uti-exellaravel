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

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif

        @forelse($news as $item)
            <article class="bg-white p-4 rounded shadow {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
                <header class="flex justify-between items-start mb-2">
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

                @if($item->title)<h3 class="font-semibold mb-1">{{ $item->title }}</h3>@endif
                <p class="whitespace-pre-line">{{ $item->body }}</p>

                @if($item->images->count())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($item->images as $img)
                            <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank">
                                <img src="{{ route('media.show', ['path' => $img->thumb_path]) }}"
                                     class="w-32 h-32 object-cover rounded border">
                            </a>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="text-gray-500">Пока нет новостей.</p>
        @endforelse

        <div>{{ $news->links() }}</div>
    </div>
</x-app-layout>