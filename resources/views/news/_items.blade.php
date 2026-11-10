@foreach($news as $item)
    @php
        $cover = $item->images->first();
    @endphp

    <article class="news-card ui-card ui-card--hover overflow-hidden {{ $item->is_pinned ? 'news-card--pinned' : '' }}">
        <a href="{{ route('news.show', $item) }}" class="block group">

            @if($cover)
                <div class="news-card__media">
                    <img src="{{ route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) }}"
                         alt="{{ $cover->caption ?: ($item->title ?: 'Фото к новости') }}"
                         width="1200" height="800"
                         loading="lazy" decoding="async"
                         class="news-card__img">
                </div>
            @endif

            <div class="p-5">
                <header class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" style="color: var(--ui-muted)">
                    @if($item->section_id === null)
                        <span class="ui-pill ui-pill--neutral" title="Видна на всех участках">Для всех участков</span>
                    @endif
                    @if($item->is_pinned)
                        <span class="ui-badge">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10.5 2.5a1 1 0 00-1.6-.8L5.7 4.1a1 1 0 01-.6.2H3a1 1 0 00-.7 1.7l3.1 3.1-3.1 4.9a.5.5 0 00.7.7l4.9-3.1 3.1 3.1A1 1 0 0012.7 14v-2.1a1 1 0 01.2-.6l2.4-3.2a1 1 0 00-.8-1.6H12a1 1 0 01-1-1V2.5z"/></svg>
                            Закреплено
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1.5">
                        <span class="news-card__avatar">{{ mb_strtoupper(mb_substr($item->author?->name ?? '—', 0, 1)) }}</span>
                        {{ $item->author?->name ?? '—' }}
                    </span>
                    <time datetime="{{ $item->published_at?->toIso8601String() }}" title="{{ $item->published_at?->format('d.m.Y H:i') }}">
                        {{ $item->published_at?->diffForHumans() }}
                    </time>
                </header>

                @if($item->title)
                    <h3 class="news-card__title mt-2.5">{{ $item->title }}</h3>
                @endif

                <p class="news-card__body mt-1.5">{{ \Illuminate\Support\Str::limit($item->body, 300) }}</p>

                @if($cover && $cover->caption)
                    <p class="text-xs mt-2 italic" style="color: var(--ui-faint)">Фото: {{ $cover->caption }}</p>
                @endif

                <span class="news-card__more mt-3">
                    Читать далее
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </div>
        </a>

        {{-- Действия администратора (только на странице «Новости»: $manage = true) --}}
        @if(($manage ?? false) && auth()->user()?->canManageContent())
            <div class="flex items-center gap-1 px-3 py-2 border-t" style="border-color: var(--ui-border)">
                <a href="{{ route('news.edit', $item) }}" class="ui-btn ui-btn--sm" style="color: var(--ui-text-2)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Изменить
                </a>
                <form method="POST" action="{{ route('news.destroy', $item) }}" onsubmit="return confirm('Удалить новость?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="ui-btn ui-btn--sm" style="color: var(--ui-bad)">Удалить</button>
                </form>
                @unless($item->is_published)
                    <span class="ui-pill ui-pill--warn ml-auto">черновик</span>
                @endunless
            </div>
        @endif
    </article>
@endforeach

@once
    @push('styles')
    <style>
        .news-card--pinned { box-shadow: inset 3px 0 0 var(--ui-accent), var(--ui-shadow-sm); }
        .news-card__media {
            aspect-ratio: 21 / 8; overflow: hidden;
            background: linear-gradient(135deg, var(--ui-accent-soft), var(--ui-surface-2));
        }
        .news-card__img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s ease; }
        .news-card:hover .news-card__img { transform: scale(1.02); }
        .news-card__avatar {
            width: 20px; height: 20px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
            font-size: .62rem; font-weight: 700; color: var(--ui-accent-text); background: var(--ui-accent-soft);
        }
        .news-card__title { font-size: 1.08rem; font-weight: 650; letter-spacing: -.01em; color: var(--ui-text); transition: color .15s; }
        .news-card a:hover .news-card__title { color: var(--ui-accent-text); }
        .news-card__body  { font-size: .9rem; line-height: 1.55; color: var(--ui-text-2); white-space: pre-line; }
        .news-card__more  { display: inline-flex; align-items: center; gap: .25rem; font-size: .85rem; font-weight: 600; color: var(--ui-accent-text); }
    </style>
    @endpush
@endonce
