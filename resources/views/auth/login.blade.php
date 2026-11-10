<x-guest-layout>
    <div class="mb-8">
        <h2 class="text-2xl font-bold tracking-tight" style="color: var(--ui-text)">Вход в систему</h2>
        <p class="mt-1.5 text-sm" style="color: var(--ui-muted)">Введите рабочую почту и пароль</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Почта" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')"
                          required autofocus autocomplete="username" placeholder="name@uti.local" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" value="Пароль" />
                @if (Route::has('password.request'))
                    <a class="text-sm font-medium hover:underline" href="{{ route('password.request') }}">Забыли пароль?</a>
                @else
                    <span class="text-xs" style="color: var(--ui-muted)" title="Пароль сбрасывает администратор">Забыли пароль? — к администратору</span>
                @endif
            </div>
            <x-text-input id="password" class="block mt-1.5 w-full" type="password" name="password"
                          required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer select-none">
            <input id="remember_me" type="checkbox" name="remember">
            <span class="text-sm" style="color: var(--ui-text-2)">Запомнить меня</span>
        </label>

        <button type="submit" class="w-full inline-flex items-center justify-center gap-2">
            Войти
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
        </button>

        @if (Route::has('register'))
            <p class="text-center text-sm" style="color: var(--ui-muted)">
                Нет учётной записи? <a href="{{ route('register') }}" class="font-medium hover:underline">Зарегистрироваться</a>
            </p>
        @endif
    </form>
</x-guest-layout>
