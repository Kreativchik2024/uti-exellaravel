<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')

        @auth
        {{-- Поп-ап новостей для залогиненных --}}
        <div id="news-popup"
             style="display:none;"
             class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 p-4">
            <div class="bg-white rounded-lg shadow-2xl max-w-lg w-full overflow-hidden">
                <div id="news-popup-cover-wrap" style="display:none;">
                    <img id="news-popup-cover" src="" alt=""
                         class="w-full h-[220px] object-cover">
                </div>
                <div class="p-6">
                    <h2 id="news-popup-title" class="text-xl font-semibold text-gray-900 mb-3"></h2>
                    <div id="news-popup-body"
                         class="text-gray-700 text-sm mb-4 whitespace-pre-line max-h-[40vh] overflow-y-auto"></div>
                    <a id="news-popup-more" href="#"
                       class="text-indigo-600 text-sm underline mb-4 inline-block">Читать полностью →</a>
                    <button id="news-popup-close"
                            type="button"
                            disabled
                            class="w-full bg-indigo-600 text-white px-4 py-2 rounded disabled:bg-gray-300 disabled:cursor-not-allowed">
                        Закрыть (<span id="news-popup-countdown">5</span>)
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var LAST_ID_KEY     = 'news_last_seen_id';
            var POLL_INTERVAL   = 15000;
            var CLOSE_DELAY_SEC = 5;

            function getLastId() {
                return parseInt(localStorage.getItem(LAST_ID_KEY) || '0', 10) || 0;
            }
            function setLastId(id) {
                localStorage.setItem(LAST_ID_KEY, String(id));
            }
            function isPopupOpen() {
                var p = document.getElementById('news-popup');
                return p && p.style.display === 'flex';
            }

            function showPopup(news) {
                var popup     = document.getElementById('news-popup');
                var title     = document.getElementById('news-popup-title');
                var body      = document.getElementById('news-popup-body');
                var cover     = document.getElementById('news-popup-cover');
                var coverWrap = document.getElementById('news-popup-cover-wrap');
                var more      = document.getElementById('news-popup-more');
                var btn       = document.getElementById('news-popup-close');
                if (!popup) return;

                title.textContent = news.title || 'Новая новость';
                body.textContent  = news.body || '';

                if (news.cover_url) {
                    cover.src = news.cover_url;
                    coverWrap.style.display = 'block';
                } else {
                    coverWrap.style.display = 'none';
                }

                more.href = news.url || '/news';

                document.body.style.overflow = 'hidden';

                var left = CLOSE_DELAY_SEC;
                btn.disabled = true;
                btn.innerHTML = 'Закрыть (<span id="news-popup-countdown">' + left + '</span>)';
                popup.style.display = 'flex';

                var timer = setInterval(function () {
                    left--;
                    if (left <= 0) {
                        clearInterval(timer);
                        btn.disabled = false;
                        btn.textContent = 'Закрыть';
                    } else {
                        var c = document.getElementById('news-popup-countdown');
                        if (c) c.textContent = String(left);
                    }
                }, 1000);

                btn.onclick = function () {
                    if (btn.disabled) return;
                    popup.style.display = 'none';
                    document.body.style.overflow = '';
                    setLastId(news.id);
                };
            }

            async function poll(silentFirst) {
                if (isPopupOpen()) return;
                try {
                    var after = getLastId();
                    var res = await fetch('/news/latest?after=' + after, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    if (!res.ok) return;
                    var news = await res.json();

                    if (silentFirst) {
                        if (news && news.id) setLastId(news.id);
                        return;
                    }

                    if (news && news.id && news.id > after) {
                        showPopup(news);
                    }
                } catch (e) {}
            }

            document.addEventListener('DOMContentLoaded', function () {
                var firstVisit = !localStorage.getItem(LAST_ID_KEY);
                if (firstVisit) {
                    poll(true);
                } else {
                    poll(false);
                }
                setInterval(function () { poll(false); }, POLL_INTERVAL);
            });
        })();
        </script>
        @endauth
    </body>
</html>