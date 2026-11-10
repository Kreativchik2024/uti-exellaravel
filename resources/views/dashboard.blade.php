<x-app-layout>
    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 5 ? 'Доброй ночи' : ($hour < 12 ? 'Доброе утро' : ($hour < 18 ? 'Добрый день' : 'Добрый вечер'));
        $nameParts = preg_split('/\s+/u', trim(auth()->user()->name));
        $firstName = $nameParts[1] ?? $nameParts[0];
        $periodLabels = [1 => 'Сегодня', 7 => '7 дней', 30 => '30 дней'];
        $maxTotal = max(1, collect($perSheet)->max('total'));
        $sortedSheets = collect($perSheet)->sortByDesc('total');
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-medium first-letter:uppercase" style="color: var(--ui-muted)">{{ now()->translatedFormat('l, j F') }}@if($section) · {{ $section->name }}@endif</p>
                <h2>{{ $greeting }}, {{ $firstName }}</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('stats.index') }}" class="ui-btn ui-btn--ghost">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Статистика
                </a>
                @if(auth()->user()->canManageContent())
                    <a href="{{ route('news.create') }}" class="ui-btn ui-btn--ghost">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Новость
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        @if(session('ok'))
            <div class="ui-card px-4 py-3 mb-6 text-sm font-medium" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif

        @unless($section)
            <div class="ui-card p-6 mb-6 flex items-start gap-4" role="status">
                <span class="rec-health" style="width:44px;height:44px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:var(--ui-accent-soft);color:var(--ui-accent-text)">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </span>
                <div>
                    <div class="font-semibold" style="color: var(--ui-text)">Вам пока не назначен участок</div>
                    <div class="text-sm mt-1" style="color: var(--ui-muted)">Учёт и статистика появятся, когда администратор назначит вам участок. Общие новости и чат доступны уже сейчас.</div>
                </div>
            </div>
        @endunless

        <div class="dashboard-cols">

            {{-- Лента новостей --}}
            <section class="dashboard-news" aria-labelledby="news-title">
                <div class="flex items-center justify-between mb-3">
                    <h3 id="news-title" class="text-base font-semibold" style="color: var(--ui-text)">Новости</h3>
                    <a href="{{ route('news.index') }}" class="text-sm font-medium" style="color: var(--ui-accent-text)">Все новости →</a>
                </div>
                <div id="news-feed" class="space-y-4">
                    @include('news._items', ['news' => $news])
                </div>

                <div id="news-sentinel" class="py-6 text-center text-sm" style="color: var(--ui-faint)"
                     data-has-more="{{ $news->hasMorePages() ? '1' : '0' }}">
                    @if($news->hasMorePages())
                        Загрузка…
                    @endif
                </div>
            </section>

            {{-- Выпуск по вкладкам --}}
            <aside class="dashboard-side" aria-labelledby="output-title">
                <div class="ui-card overflow-hidden dash-sticky" x-data="{ showAll: false }">
                    <div class="p-4 pb-3">
                        <div class="flex items-baseline justify-between gap-2 mb-3">
                            <h3 id="output-title" class="text-base font-semibold" style="color: var(--ui-text)">Выпуск</h3>
                            <span class="text-xs" style="color: var(--ui-muted)">по вкладкам</span>
                        </div>
                        <nav class="ui-segment" aria-label="Период">
                            @foreach($periodLabels as $p => $label)
                                <a href="{{ route('dashboard', ['stats_period' => $p]) }}"
                                   class="ui-segment__item {{ ($statsPeriod ?? 30) === $p ? 'ui-segment__item--active' : '' }}"
                                   @if(($statsPeriod ?? 30) === $p) aria-current="true" @endif>{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>

                    <ul class="dash-output dash-output--collapsed" :class="{ 'dash-output--collapsed': !showAll }">
                        @foreach($sortedSheets as $slug => $data)
                            <li>
                                <a href="{{ route('records.index', $slug) }}" class="dash-output__row {{ $data['total'] === 0 ? 'dash-output__row--empty' : '' }}">
                                    <span class="dash-output__name" title="{{ $data['title'] }}">{{ $data['title'] }}</span>
                                    <span class="dash-output__value">{{ number_format($data['total'], 0, ',', ' ') }}</span>
                                    <span class="dash-output__bar" aria-hidden="true">
                                        <span style="width: {{ round($data['total'] / $maxTotal * 100, 1) }}%"></span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if(count($perSheet) > 6)
                        <button type="button" class="dash-output__more" @click="showAll = !showAll"
                                x-text="showAll ? 'Свернуть' : 'Показать все ({{ count($perSheet) }})'">Показать все ({{ count($perSheet) }})</button>
                    @endif
                </div>
            </aside>

        </div>
    </div>

    @push('styles')
    <style>
        .dash-sticky { position: sticky; top: calc(var(--ui-nav-h) + 16px); }
        .dash-output { padding: 0 .5rem .5rem; max-height: calc(100vh - var(--ui-nav-h) - 190px); overflow-y: auto; }
        .dash-output__row {
            display: grid; grid-template-columns: 1fr auto; align-items: center; gap: .3rem .75rem;
            padding: .55rem .6rem; border-radius: 10px; transition: background-color .12s;
        }
        .dash-output__row:hover { background: var(--ui-surface-hover); }
        .dash-output__name  { font-size: .82rem; color: var(--ui-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dash-output__value { font-size: .9rem; font-weight: 700; color: var(--ui-text); font-variant-numeric: tabular-nums; }
        .dash-output__bar   { grid-column: 1 / -1; height: 4px; border-radius: 999px; background: var(--ui-surface-hover); overflow: hidden; }
        .dash-output__bar > span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #6366f1, #0ea5e9); }
        .dash-output__row--empty .dash-output__value,
        .dash-output__row--empty .dash-output__name { color: var(--ui-faint); }
        .dash-output__more { display: none; }
        @media (max-width: 1023px) {
            .dash-sticky { position: static; }
            .dash-output { max-height: none; }
            /* На телефоне — первые 6 вкладок, остальные по кнопке */
            .dash-output--collapsed > li:nth-child(n+7) { display: none; }
            .dash-output__more {
                display: block; width: 100%; padding: .7rem; font-size: .85rem; font-weight: 600;
                color: var(--ui-accent-text); border-top: 1px solid var(--ui-border);
            }
        }
    </style>
    @endpush

    {{-- Скрипт бесконечной ленты --}}
    <script>
        (function () {
            const feed     = document.getElementById('news-feed');
            const sentinel = document.getElementById('news-sentinel');
            if (!feed || !sentinel) return;

            let loading = false;
            let page    = 2;
            let hasMore = sentinel.dataset.hasMore === '1';

            async function loadNext() {
                if (loading || !hasMore) return;
                loading = true;
                try {
                    const statsPeriod = new URLSearchParams(window.location.search).get('stats_period') || '30';
                    const res = await fetch(`{{ url('/') }}?page=${page}&json=1&stats_period=${statsPeriod}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.html) feed.insertAdjacentHTML('beforeend', data.html);
                    hasMore = data.has_more;
                    page++;
                    if (!hasMore) {
                        sentinel.textContent = '— больше новостей нет —';
                        sentinel.dataset.hasMore = '0';
                    }
                } catch (e) {
                    sentinel.textContent = 'Ошибка загрузки: ' + e.message;
                } finally {
                    loading = false;
                }
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) loadNext();
                });
            }, { rootMargin: '300px' });

            observer.observe(sentinel);
        })();
    </script>
</x-app-layout>