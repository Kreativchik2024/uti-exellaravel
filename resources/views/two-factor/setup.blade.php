<x-guest-layout>
    @if($enabled)
        <div class="mb-6">
            <h2 class="text-2xl font-bold tracking-tight" style="color: var(--ui-text)">Вход с кодом включён</h2>
            <p class="mt-1.5 text-sm" style="color: var(--ui-muted)">При каждом входе после пароля нужен код из приложения на телефоне</p>
        </div>

        @if($codes)
            <div class="mb-6 p-4 rounded-xl" style="background: var(--ui-accent-soft); border: 1px solid var(--ui-border)">
                <p class="text-sm font-semibold mb-2" style="color: var(--ui-text)">Резервные коды — сохраните их сейчас</p>
                <p class="text-xs mb-3" style="color: var(--ui-muted)">Запишите или распечатайте и храните отдельно от телефона. Каждый код можно использовать один раз, если телефона не будет под рукой. Больше они показаны не будут.</p>
                <div class="grid grid-cols-2 gap-2 font-mono text-sm" style="color: var(--ui-text)">
                    @foreach($codes as $c)<span class="px-2 py-1 rounded" style="background: var(--ui-surface)">{{ $c }}</span>@endforeach
                </div>
            </div>
        @else
            <p class="text-sm mb-6" style="color: var(--ui-muted)">Резервных кодов осталось: <strong>{{ count((array) $user->two_factor_recovery_codes) }}</strong></p>
        @endif

        <a href="{{ route('dashboard') }}" class="ui-btn ui-btn--primary w-full">Продолжить работу</a>

        <form method="POST" action="{{ route('two-factor.regenerate') }}" class="mt-6 space-y-3">
            @csrf
            <x-input-label for="password" value="Новые резервные коды (нужен пароль)" />
            <x-text-input id="password" type="password" name="password" class="block w-full" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-1" />
            <button type="submit" class="w-full">Создать новые коды</button>
        </form>

        @unless($user->requiresTwoFactor())
            <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-4 space-y-3" onsubmit="return confirm('Отключить вход с кодом?')">
                @csrf
                <x-text-input type="password" name="password" class="block w-full" required placeholder="Пароль для отключения" autocomplete="current-password" />
                <button type="submit" class="w-full" style="background: var(--ui-bad) !important">Отключить</button>
            </form>
        @endunless
    @else
        <div class="mb-6">
            <h2 class="text-2xl font-bold tracking-tight" style="color: var(--ui-text)">Вход с кодом из телефона</h2>
            <p class="mt-1.5 text-sm" style="color: var(--ui-muted)">
                @if($user->requiresTwoFactor())
                    Для администратора это обязательно: даже узнав пароль, без вашего телефона в систему не войти.
                @else
                    Даже узнав пароль, без вашего телефона в систему не войти.
                @endif
            </p>
        </div>

        <ol class="space-y-4 text-sm" style="color: var(--ui-text-2)">
            <li><strong>1.</strong> Установите на телефон приложение-аутентификатор: Яндекс Ключ, Google Authenticator, Microsoft Authenticator или любое с поддержкой TOTP. Интернет для кодов не нужен.</li>
            <li>
                <strong>2.</strong> В приложении добавьте аккаунт и отсканируйте QR-код:
                <div class="mt-3 flex justify-center"><div class="p-3 rounded-xl" style="background: #fff">{!! $qr !!}</div></div>
                <p class="mt-2 text-xs" style="color: var(--ui-muted)">Или введите ключ вручную: <code class="font-mono" style="color: var(--ui-text)">{{ $secret }}</code></p>
            </li>
            <li><strong>3.</strong> Введите 6 цифр, которые покажет приложение:</li>
        </ol>

        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-3 space-y-4">
            @csrf
            <x-text-input id="code" class="block w-full text-center tracking-[.4em] text-xl" type="text" name="code"
                          inputmode="numeric" autocomplete="one-time-code" required autofocus placeholder="000000" maxlength="10" />
            <x-input-error :messages="$errors->get('code')" class="mt-1" />
            <button type="submit" class="w-full">Включить</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="ui-linkbtn">Выйти</button>
        </form>
    @endif
</x-guest-layout>
