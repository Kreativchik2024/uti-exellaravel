<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}" data-refresh-url="{{ route('csrf.refresh') }}">
        {{-- Автообновление CSRF-токена: защита от ошибки 419 --}}
        <script src="{{ asset('js/csrf.js') }}?v={{ filemtime(public_path('js/csrf.js')) }}"></script>

        <title>{{ config('app.name', 'Laravel') }}</title>

        <script>
            (function () {
                try {
                    // ?theme=dark|light — ручное переключение (в т.ч. для проверки)
                    var forced = new URLSearchParams(window.location.search).get('theme');
                    var stored = (forced === 'dark' || forced === 'light') ? forced : localStorage.getItem('theme');
                    var prefers = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var isDark = stored === 'dark' || (!stored && prefers);
                    if (isDark) document.documentElement.classList.add('dark');
                } catch (e) {}
            })();
        </script>

        {{-- Шрифты локально (раньше грузились с fonts.bunny.net) --}}
        <link rel="stylesheet" href="{{ asset('css/fonts.css') }}?v={{ filemtime(public_path('css/fonts.css')) }}">
        <link rel="preload" as="font" type="font/woff2" href="{{ asset('fonts/figtree-latin-400-normal.woff2') }}" crossorigin>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Тёмная тема: отдельный CSS, не зависит от сборки vite --}}
        <link rel="stylesheet" href="{{ asset('css/dark-theme.css') }}?v={{ filemtime(public_path('css/dark-theme.css')) }}">

        {{-- Адаптивность и отступы под фиксированные элементы (тоже без сборки vite) --}}
        <link rel="stylesheet" href="{{ asset('css/responsive.css') }}?v={{ filemtime(public_path('css/responsive.css')) }}">

        @stack('styles')
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            {{-- Навигация требует авторизации; публичные страницы (новости) рендерятся без неё --}}
            @auth
                @include('layouts.navigation')
            @endauth

            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="app-main">
                {{-- Форма была отправлена с устаревшей страницы (419) — данные возвращены в форму --}}
                @if(session('csrf_expired'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm font-medium" role="alert">
                            {{ session('csrf_expired') }}
                        </div>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>

        {{-- Кнопка смены темы (на мобильных поднята над панелью «Онлайн» и уменьшена) --}}
        <button type="button" id="themeToggle" onclick="toggleDark()"
                class="theme-toggle"
                style="position:fixed"
                title="Сменить тему" aria-label="Сменить тему">
            <svg data-theme-icon="moon" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg data-theme-icon="sun" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
        </button>

        <script>
            (function () {
                window.toggleDark = function () {
                    var html = document.documentElement;
                    var nowDark = !html.classList.contains('dark');
                    html.classList.toggle('dark', nowDark);
                    try { localStorage.setItem('theme', nowDark ? 'dark' : 'light'); } catch (e) {}
                    syncIcons();
                    // Для компонентов, которые красят себя сами (графики и т.п.)
                    document.dispatchEvent(new CustomEvent('themechange', { detail: { dark: nowDark } }));
                };
                function syncIcons() {
                    var isDark = document.documentElement.classList.contains('dark');
                    document.querySelectorAll('[data-theme-icon="moon"]').forEach(function (el) { el.style.display = isDark ? 'none' : 'block'; });
                    document.querySelectorAll('[data-theme-icon="sun"]').forEach(function (el) { el.style.display = isDark ? 'block' : 'none'; });
                }
                document.addEventListener('DOMContentLoaded', syncIcons);
            })();
        </script>

        {{-- ПАНЕЛЬ ОНЛАЙН --}}
        @include('partials.online-footer')

        {{-- ГЛОБАЛЬНЫЙ ТАЙМЕР ПИН --}}
        <script>
            window.GLOBAL_PIN_MAP = @json($globalVerifiedWorkersMap ?? []);
            
            function startGlobalPinTimers() {
                document.querySelectorAll('[data-pin-timer]').forEach(function(el) {
                    var name = el.getAttribute('data-pin-timer');
                    var expires = window.GLOBAL_PIN_MAP[name];
                    
                    if (!expires) {
                        el.innerHTML = '<span class="text-[11px] text-gray-400">Нет ПИН</span>';
                        return;
                    }
                    
                    function tick() {
                        var rem = Math.floor(expires - (Date.now() / 1000));
                        if (rem <= 0) {
                            el.innerHTML = '<span class="text-[11px] text-red-500 font-medium">Истёк</span>';
                            return;
                        }
                        var h = Math.floor(rem / 3600);
                        var m = Math.floor((rem % 3600) / 60);
                        var s = rem % 60;
                        function p(n){return n<10?'0'+n:String(n);}
                        
                        var cls = rem <= 15 * 60 ? 'text-amber-500' : 'text-emerald-500';
                        el.innerHTML = '<span class="text-[11px] font-medium ' + cls + ' font-mono tabular-nums">🔒 ' + p(h)+':'+p(m)+':'+p(s) + '</span>';
                        setTimeout(tick, 1000);
                    }
                    tick();
                });
            }
            document.addEventListener('DOMContentLoaded', startGlobalPinTimers);
        </script>

        @stack('scripts')
    </body>
</html>