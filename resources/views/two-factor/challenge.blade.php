<x-guest-layout>
    <div class="mb-8">
        <h2 class="text-2xl font-bold tracking-tight" style="color: var(--ui-text)">Код из приложения</h2>
        <p class="mt-1.5 text-sm" style="color: var(--ui-muted)">Откройте приложение-аутентификатор на телефоне и введите 6 цифр для «{{ config('app.name') }}»</p>
    </div>

    <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-5">
        @csrf
        <div>
            <x-input-label for="code" value="Код" />
            <x-text-input id="code" class="block mt-1.5 w-full text-center tracking-[.4em] text-xl" type="text" name="code"
                          inputmode="numeric" autocomplete="one-time-code" required autofocus placeholder="000000" maxlength="20" />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>
        <button type="submit" class="w-full">Войти</button>
    </form>

    <details class="mt-6 text-sm" style="color: var(--ui-muted)">
        <summary class="cursor-pointer">Нет телефона под рукой?</summary>
        <p class="mt-2">Введите в поле выше один из резервных кодов вида <code>A1B2-C3D4</code>, которые вы сохранили при настройке. Каждый код срабатывает один раз.
            Если кодов нет — обратитесь к другому администратору: он сбросит защиту в разделе «Пользователи».</p>
    </details>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="ui-linkbtn">Выйти</button>
    </form>
</x-guest-layout>
