<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{-- Скрипт тёмной темы (чтобы не мигало при загрузке) --}}
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

        <!-- Fonts (локально) -->
        <link rel="stylesheet" href="{{ asset('css/fonts.css') }}?v={{ filemtime(public_path('css/fonts.css')) }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Тёмная тема: отдельный CSS, не зависит от сборки vite --}}
        <link rel="stylesheet" href="{{ asset('css/dark-theme.css') }}?v={{ filemtime(public_path('css/dark-theme.css')) }}">

        {{-- Адаптивность и отступы под фиксированные элементы (тоже без сборки vite) --}}
        <link rel="stylesheet" href="{{ asset('css/responsive.css') }}?v={{ filemtime(public_path('css/responsive.css')) }}">
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>

            {{-- Кнопка смены темы для страниц логина --}}
            <button type="button" id="themeToggle" onclick="toggleDark()"
                    class="theme-toggle theme-toggle--guest"
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
                    if (!window.toggleDark) {
                        window.toggleDark = function () {
                            var html = document.documentElement;
                            var nowDark = !html.classList.contains('dark');
                            html.classList.toggle('dark', nowDark);
                            try { localStorage.setItem('theme', nowDark ? 'dark' : 'light'); } catch (e) {}
                            syncIcons();
                        };
                    }
                    function syncIcons() {
                        var isDark = document.documentElement.classList.contains('dark');
                        document.querySelectorAll('[data-theme-icon="moon"]').forEach(function (el) {
                            el.style.display = isDark ? 'none' : 'block';
                        });
                        document.querySelectorAll('[data-theme-icon="sun"]').forEach(function (el) {
                            el.style.display = isDark ? 'block' : 'none';
                        });
                    }
                    document.addEventListener('DOMContentLoaded', syncIcons);
                })();
            </script>
        </div>
    </body>
</html>
