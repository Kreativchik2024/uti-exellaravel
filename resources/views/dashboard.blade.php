<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Главная</h2>
            @if(auth()->user()->canManageContent())
                <a href="{{ route('news.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
                    + Новость
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded mb-6">{{ session('ok') }}</div>
        @endif

        {{-- Двухколоночная верстка --}}
        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start;">
            
            {{-- ЛЕВАЯ КОЛОНКА: Лента новостей --}}
            <div style="flex: 1 1 600px; min-width: 0; display: flex; flex-direction: column; gap: 1rem;">
                <div id="news-feed" class="space-y-4">
                    @include('news._items', ['news' => $news])
                </div>

                <div id="news-sentinel" class="py-4 text-center text-sm text-gray-400"
                     data-has-more="{{ $news->hasMorePages() ? '1' : '0' }}">
                    @if($news->hasMorePages())
                        Загрузка...
                    @endif
                </div>
            </div>

            {{-- ПРАВАЯ КОЛОНКА: Статистика --}}
            <div style="flex: 0 0 280px; width: 100%; max-width: 280px; position: sticky; top: 1rem;">
                <div class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
                    
                    {{-- Переключатель периода --}}
                    <div class="p-3 bg-gray-50 border-b border-gray-100">
                        <div class="flex gap-1">
                            @foreach([1 => '1 день', 7 => '7 дней', 30 => '30 дней'] as $p => $label)
                                <a href="{{ route('dashboard', ['stats_period' => $p]) }}"
                                   style="flex: 1; text-align: center; padding: 4px 0; font-size: 12px; border-radius: 4px; border: 1px solid {{ ($statsPeriod ?? 30) === $p ? '#4f46e5' : '#d1d5db' }}; background-color: {{ ($statsPeriod ?? 30) === $p ? '#4f46e5' : '#ffffff' }}; color: {{ ($statsPeriod ?? 30) === $p ? '#ffffff' : '#374151' }}; text-decoration: none; font-weight: 500;">
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Сетка карточек --}}
                    <div class="p-2">
                        {{-- grid-auto-rows: 1fr выравнивает высоту карточек в одном ряду --}}
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; grid-auto-rows: 1fr;">
                            @foreach($perSheet as $slug => $data)
                                <div class="bg-gray-50 rounded border border-gray-200 p-2 flex flex-col justify-between text-center hover:bg-white hover:shadow-sm transition min-h-[64px]">
                                    
                                    {{-- Заголовок: ограничиваем 2 строками, чтобы не раздувал карточку --}}
                                    <div class="text-[11px] font-medium text-gray-600 mb-2 w-full leading-tight line-clamp-2" title="{{ $data['title'] }}">
                                        {{ $data['title'] }}
                                    </div>
                                    
                                    {{-- Цифра: всегда прижата к низу --}}
                                    <div class="mt-auto">
                                        <span class="text-lg font-bold {{ $data['total'] > 0 ? 'text-blue-600' : 'text-gray-300' }} leading-none">
                                            {{ $data['total'] }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

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