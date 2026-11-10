<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}" data-refresh-url="{{ route('csrf.refresh') }}">
        {{-- Автообновление CSRF-токена: защита от ошибки 419 --}}
        <script src="{{ asset('js/csrf.js') }}?v={{ filemtime(public_path('js/csrf.js')) }}"></script>

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{-- Тема (авто / светлая / тёмная) — синхронно, до отрисовки, без мигания --}}
        <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}"></script>

        {{-- Шрифт Inter (с кириллицей) подключается из resources/css/app.css --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Тёмная тема: отдельный CSS, не зависит от сборки vite --}}
        <link rel="stylesheet" href="{{ asset('css/dark-theme.css') }}?v={{ filemtime(public_path('css/dark-theme.css')) }}">

        {{-- Адаптивность и отступы под фиксированные элементы (тоже без сборки vite) --}}
        <link rel="stylesheet" href="{{ asset('css/responsive.css') }}?v={{ filemtime(public_path('css/responsive.css')) }}">

        @stack('styles')
    </head>
    <body class="font-sans antialiased">
        <div class="ui-app">
            {{-- Навигация требует авторизации; публичные страницы (новости) рендерятся без неё --}}
            @auth
                @include('layouts.navigation')
            @endauth

            @isset($header)
                <header class="ui-page-header">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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


        {{-- ПАНЕЛЬ ОНЛАЙН --}}
        @include('partials.online-footer')

        {{-- ЧАТ (правый нижний угол) --}}
        @include('partials.chat-widget')
        @include('partials.consent')

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