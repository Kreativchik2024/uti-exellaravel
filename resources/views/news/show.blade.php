<x-app-layout>
    @php
        $cover = $news->images->first();
        $gallery = $news->images->slice(1);
        $author = $news->author?->name ?? 'Неизвестный автор';
    @endphp

    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('news.index') }}" class="inline-flex items-center gap-2 text-sm font-medium" style="color: var(--ui-muted)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Все новости
            </a>
            @if(auth()->check() && auth()->user()->canManageContent())
                <div class="flex gap-2">
                    <a href="{{ route('news.edit', $news) }}" class="ui-btn ui-btn--ghost ui-btn--sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Изменить
                    </a>
                    <form method="POST" action="{{ route('news.destroy', $news) }}" onsubmit="return confirm('Удалить новость? Это действие нельзя отменить.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)">Удалить</button>
                    </form>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <article class="ui-card overflow-hidden">
            @if($cover)
                <a href="{{ route('media.show', ['path' => $cover->path]) }}" target="_blank" rel="noopener" class="block news-hero">
                    <img src="{{ route('media.show', ['path' => $cover->path]) }}"
                         alt="{{ $cover->caption ?: ($news->title ?: 'Фото к новости') }}"
                         width="1600" height="900" decoding="async">
                </a>
                @if($cover->caption)
                    <p class="px-6 sm:px-10 pt-2 text-xs italic" style="color: var(--ui-faint)">{{ $cover->caption }}</p>
                @endif
            @endif

            <div class="px-6 sm:px-10 py-8">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" style="color: var(--ui-muted)">
                    @if($news->is_pinned)
                        <span class="ui-badge">Закреплено</span>
                    @endif
                    @unless($news->is_published)
                        <span class="ui-pill ui-pill--warn">черновик</span>
                    @endunless
                    <span class="inline-flex items-center gap-2">
                        <span class="news-card__avatar" style="width: 24px; height: 24px; font-size: .7rem">{{ mb_strtoupper(mb_substr($author, 0, 1)) }}</span>
                        {{ $author }}
                    </span>
                    <time datetime="{{ $news->published_at?->toIso8601String() }}">{{ $news->published_at?->translatedFormat('j F Y, H:i') }}</time>
                </div>

                @if($news->title)
                    <h1 class="news-article__title">{{ $news->title }}</h1>
                @endif

                <div class="news-article__body">{{ $news->body }}</div>

                @if($gallery->count())
                    <section class="mt-10 pt-6 border-t" style="border-color: var(--ui-border)">
                        <h2 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--ui-muted)">Фотографии · {{ $gallery->count() }}</h2>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($gallery as $img)
                                <figure>
                                    <a href="{{ route('media.show', ['path' => $img->path]) }}" target="_blank" rel="noopener" class="news-gallery__item">
                                        <img src="{{ route('media.show', ['path' => $img->thumb_path ?? $img->path]) }}"
                                             alt="{{ $img->caption ?: 'Фото к новости' }}"
                                             width="800" height="600" loading="lazy" decoding="async">
                                    </a>
                                    @if($img->caption)
                                        <figcaption class="text-xs mt-1.5" style="color: var(--ui-muted)">{{ $img->caption }}</figcaption>
                                    @endif
                                </figure>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        </article>
    </div>

    @push('styles')
    <style>
        .news-hero { aspect-ratio: 16 / 7; overflow: hidden; background: linear-gradient(135deg, var(--ui-accent-soft), var(--ui-surface-2)); }
        .news-hero img { width: 100%; height: 100%; object-fit: cover; }
        .news-article__title { margin-top: 1rem; font-size: clamp(1.6rem, 3vw, 2.1rem); line-height: 1.2; font-weight: 700; letter-spacing: -.02em; color: var(--ui-text); }
        .news-article__body { margin-top: 1.25rem; font-size: 1.02rem; line-height: 1.75; color: var(--ui-text-2); white-space: pre-line; overflow-wrap: anywhere; }
        .news-gallery__item { display: block; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 12px; background: var(--ui-surface-2); }
        .news-gallery__item img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
        .news-gallery__item:hover img { transform: scale(1.04); }
        .news-card__avatar {
            border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; color: var(--ui-accent-text); background: var(--ui-accent-soft);
        }
    </style>
    @endpush
</x-app-layout>
