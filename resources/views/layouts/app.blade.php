<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{-- Тема: до рендера, без мигания --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    var prefers = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var isDark = stored === 'dark' || (!stored && prefers);
                    if (isDark) document.documentElement.classList.add('dark');
                } catch (e) {}
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

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

        {{-- Плавающая кнопка смены темы — фиксирована, видна всегда --}}
        <button type="button"
                onclick="toggleDark()"
                style="position:fixed; bottom:24px; right:24px; z-index:9999;
                       width:48px; height:48px; border-radius:50%;
                       background:#4f46e5; color:#fff; border:none; cursor:pointer;
                       box-shadow:0 4px 12px rgba(0,0,0,0.3);
                       display:flex; align-items:center; justify-content:center;
                       transition:transform 0.15s;"
                onmouseover="this.style.transform='scale(1.1)'"
                onmouseout="this.style.transform='scale(1)'"
                title="Сменить тему" aria-label="Сменить тему">
            <svg data-theme-icon="moon" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg data-theme-icon="sun" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
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
                };

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
    </body>
</html>