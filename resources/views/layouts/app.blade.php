<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{-- Тема: применяем до рендера, чтобы не мигало --}}
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
        
        {{-- ВАЖНО: Сюда страницы могут пушить свои стили --}}
        @stack('styles')
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @include('layouts.navigation')

            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main>
                {{ $slot }}
            </main>
        </div>

        {{-- Кнопка смены темы --}}
        <button type="button" onclick="toggleDark()"
                style="position:fixed; bottom:24px; right:24px; z-index:9999;
                       width:48px; height:48px; border-radius:50%;
                       background:#4f46e5; color:#fff; border:none; cursor:pointer;
                       box-shadow:0 4px 12px rgba(0,0,0,0.3);
                       display:flex; align-items:center; justify-content:center;
                       transition:transform 0.15s;"
                onmouseover="this.style.transform='scale(1.1)'"
                onmouseout="this.style.transform='scale(1)'"
                title="Сменить тему">
            <svg data-theme-icon="moon" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg data-theme-icon="sun" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
        </button>

        {{-- АНИМЕ-ДЕВОЧКА (если передумали, этот блок можно удалить) --}}
        <div id="walking-anime-girl" style="position: fixed; bottom: 0; left: 0; width: 120px; height: 120px; z-index: 9998; pointer-events: none; display: none;">
            <img id="anime-girl-img" src="{{ asset('images/girl.gif') }}" style="width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3)); transition: transform 0.1s;">
        </div>

        <script>
            // --- Логика смены темы ---
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
                    document.querySelectorAll('[data-theme-icon="moon"]').forEach(function (el) { el.style.display = isDark ? 'none' : 'block'; });
                    document.querySelectorAll('[data-theme-icon="sun"]').forEach(function (el) { el.style.display = isDark ? 'block' : 'none'; });
                }
                document.addEventListener('DOMContentLoaded', syncIcons);
            })();

            // --- Логика аниме-девочки ---
            document.addEventListener('DOMContentLoaded', function() {
                const girl = document.getElementById('walking-anime-girl');
                const img = document.getElementById('anime-girl-img');
                if (!girl || !img) return;
                img.onerror = function() { girl.style.display = 'none'; };
                img.onload = function() { girl.style.display = 'block'; };
                let x = 0, direction = 1, speed = 1.5, isPaused = false, pauseTimeout;
                function move() {
                    if (!isPaused && girl.style.display !== 'none') {
                        x += speed * direction;
                        const maxX = window.innerWidth - girl.offsetWidth;
                        if (x >= maxX) { direction = -1; img.style.transform = 'scaleX(-1)'; } 
                        else if (x <= 0) { direction = 1; img.style.transform = 'scaleX(1)'; }
                        girl.style.left = x + 'px';
                    }
                    requestAnimationFrame(move);
                }
                window.addEventListener('scroll', function() {
                    isPaused = true;
                    clearTimeout(pauseTimeout);
                    pauseTimeout = setTimeout(function() { isPaused = false; }, 800);
                });
                document.addEventListener('click', function(e) {
                    if (e.target.closest('a, button, input, select, textarea')) return;
                    isPaused = true;
                    girl.style.bottom = '20px';
                    setTimeout(() => girl.style.bottom = '0px', 200);
                    clearTimeout(pauseTimeout);
                    pauseTimeout = setTimeout(() => isPaused = false, 400);
                });
                move();
            });
        </script>

        {{-- ВАЖНО: Сюда страницы пушат свои скрипты (например, графики статистики) --}}
        @stack('scripts')
    </body>
</html>
