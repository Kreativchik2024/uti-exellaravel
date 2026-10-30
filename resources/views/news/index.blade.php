<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center gap-4">
            <div>
                <h2 class="font-bold text-2xl text-gray-900 tracking-tight">Новости</h2>
                <p class="text-sm text-gray-500 mt-1">Всего: {{ $news->total() }}</p>
            </div>
            @if(auth()->user()->canManageContent())
                <a href="{{ route('news.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Новая новость
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 p-4 rounded-lg">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                {{ session('ok') }}
            </div>
        @endif

        @forelse($news as $item)
            @php
                $cover = $item->images->first();
            @endphp

            <article class="relative bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-lg transition-all duration-200 {{ $item->is_pinned ? 'ring-2 ring-blue-300' : '' }}">
                @if(auth()->user()->canManageContent())
                    <div class="absolute top-3 right-3 z-10 flex gap-2 text-sm">
                        <a href="{{ route('news.edit', $item) }}"
                           class="px-3 py-1.5 bg-white/95 hover:bg-blue-600 hover:text-white text-blue-600 rounded-lg shadow transition">
                            Ред.
                        </a>
                        <form method="POST" action="{{ route('news.destroy', $item) }}"
                              onsubmit="return confirm('Удалить новость?')">
                            @csrf @method('DELETE')
                            <button class="px-3 py-1.5 bg-white/95 hover:bg-red-600 hover:text-white text-red-600 rounded-lg shadow transition">
                                Удалить
                            </button>
                        </form>
                    </div>
                @endif

                <a href="{{ route('news.show', $item) }}" class="block group">
                    @if($cover)
                        <div style="width:100%; height:320px; background-image:url('{{ route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) }}'); background-size:cover; background-position:center; background-repeat:no-repeat;"></div>
                    @endif

                    <div class="p-4 space-y-2">
                        <header class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                {{ $item->author?->name ?? 'Удалённый пользователь' }}
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
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
                <p class="text-gray-500">Пока нет новостей.</p>
            </div>
        @endforelse

        <div>{{ $news->links() }}</div>
    </div>
</x-app-layout>