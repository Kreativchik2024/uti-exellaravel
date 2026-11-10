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

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <link rel="stylesheet" href="{{ asset('css/dark-theme.css') }}?v={{ filemtime(public_path('css/dark-theme.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/responsive.css') }}?v={{ filemtime(public_path('css/responsive.css')) }}">

        <style>
            .auth { min-height: 100vh; display: grid; grid-template-columns: 1fr; background: var(--ui-bg); color: var(--ui-text); }
            @media (min-width: 1024px) { .auth { grid-template-columns: minmax(420px, 46%) 1fr; } }

            .auth__brand {
                position: relative; overflow: hidden; display: none;
                padding: 48px; color: #e0e7ff;
                background:
                    radial-gradient(600px 400px at 0% 0%, rgba(129, 140, 248, .45), transparent 60%),
                    radial-gradient(500px 400px at 100% 100%, rgba(14, 165, 233, .35), transparent 60%),
                    linear-gradient(160deg, #1e1b4b 0%, #0f172a 70%);
            }
            @media (min-width: 1024px) { .auth__brand { display: flex; flex-direction: column; justify-content: space-between; } }
            .auth__brand::after {
                content: ''; position: absolute; inset: 0; pointer-events: none;
                background-image: linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
                background-size: 32px 32px;
                mask-image: radial-gradient(ellipse at 30% 40%, #000 30%, transparent 75%);
            }
            .auth__brand > * { position: relative; z-index: 1; }
            .auth__logo { width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,.08); padding: 4px; box-shadow: 0 0 0 1px rgba(255,255,255,.12); }
            .auth__headline { font-size: 2.25rem; line-height: 1.15; font-weight: 700; letter-spacing: -.025em; color: #fff; max-width: 15ch; }
            .auth__lead { margin-top: 1rem; max-width: 40ch; color: #c7d2fe; font-size: 1rem; line-height: 1.6; }
            .auth__features { display: grid; gap: .75rem; margin-top: 2rem; }
            .auth__feature { display: flex; align-items: center; gap: .75rem; font-size: .9rem; color: #e0e7ff; }
            .auth__feature-icon { width: 32px; height: 32px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); box-shadow: 0 0 0 1px rgba(255,255,255,.1); }

            .auth__main { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 32px 20px; position: relative; }
            .auth__card { width: 100%; max-width: 400px; }
            .auth__mobile-brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 2rem; }
            @media (min-width: 1024px) { .auth__mobile-brand { display: none; } }

            /* Поля и кнопки форм входа/регистрации (компоненты Breeze) */
            .auth label { color: var(--ui-text-2) !important; font-weight: 500; }
            .auth input[type="email"], .auth input[type="password"], .auth input[type="text"] {
                width: 100%; height: 44px; border-radius: 10px;
                border: 1px solid var(--ui-border-strong); background: var(--ui-surface) !important; color: var(--ui-text) !important;
                padding: 0 .9rem; font-size: .95rem; box-shadow: var(--ui-shadow-sm);
                transition: border-color .15s, box-shadow .15s;
            }
            .auth input:focus { outline: none; border-color: var(--ui-accent) !important; box-shadow: 0 0 0 4px var(--ui-ring) !important; }
            .auth input[type="checkbox"] { border-radius: 5px; color: var(--ui-accent); border-color: var(--ui-border-strong); }
            .auth button[type="submit"] {
                height: 44px; border-radius: 10px; background: var(--ui-accent) !important; color: #fff !important;
                font-size: .9rem; font-weight: 600; letter-spacing: 0; text-transform: none;
                box-shadow: 0 1px 2px rgba(15,23,42,.1), inset 0 1px 0 rgba(255,255,255,.12);
            }
            .auth button[type="submit"]:hover { background: var(--ui-accent-hover) !important; }
            .auth a { color: var(--ui-accent-text); }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div class="auth">
            <aside class="auth__brand">
                <div class="flex items-center gap-3">
                    <x-application-logo class="auth__logo" />
                    <div>
                        <div class="text-lg font-bold text-white">{{ config('app.name', 'УТИ') }}</div>
                        <div class="text-sm" style="color:#a5b4fc">учёт производства</div>
                    </div>
                </div>

                <div>
                    <h1 class="auth__headline">Учёт сборки и проверок в одном месте</h1>
                    <p class="auth__lead">Записи по всем участкам, исполнители с подтверждением ПИН-кодом и статистика выпуска и брака.</p>
                    <div class="auth__features">
                        <div class="auth__feature">
                            <span class="auth__feature-icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></span>
                            19 участков учёта
                        </div>
                        <div class="auth__feature">
                            <span class="auth__feature-icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                            Подтверждение исполнителей ПИН-кодом
                        </div>
                        <div class="auth__feature">
                            <span class="auth__feature-icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></span>
                            Статистика выпуска и брака
                        </div>
                    </div>
                </div>

                <p class="text-xs" style="color:#818cf8">© {{ now()->year }} {{ config('app.name', 'УТИ') }}</p>
            </aside>

            <main class="auth__main">
                <div class="absolute top-4 right-4"><x-theme-switcher /></div>

                <div class="auth__card">
                    <div class="auth__mobile-brand">
                        <x-application-logo class="w-12 h-12 rounded-xl" />
                        <div>
                            <div class="font-bold" style="color: var(--ui-text)">{{ config('app.name', 'УТИ') }}</div>
                            <div class="text-sm" style="color: var(--ui-muted)">учёт производства</div>
                        </div>
                    </div>

                    {{ $slot }}
                </div>
            </main>
        </div>

        @include('partials.consent')
    </body>
</html>
