<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('ПИН для подтверждения действий') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            @if(auth()->user()->hasPin())
                {{ __('ПИН установлен. Введите новый, чтобы сменить.') }}
            @else
                {{ __('ПИН ещё не задан. Без ПИНа нельзя создавать или редактировать записи.') }}
            @endif
        </p>
    </header>

    @if (session('ok'))
        <div class="mt-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">
            {{ session('ok') }}
        </div>
    @endif

    <form method="post" action="{{ route('profile.pin') }}" class="mt-6 space-y-6">
        @csrf

        <div>
            <x-input-label for="pin" :value="__('Новый ПИН (4–6 цифр)')" />
            <x-text-input id="pin" name="pin" type="password" inputmode="numeric" pattern="\d{4,6}"
                          class="mt-1 block w-40" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('pin')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="pin_confirm" :value="__('Повтор')" />
            <x-text-input id="pin_confirm" name="pin_confirm" type="password" inputmode="numeric" pattern="\d{4,6}"
                          class="mt-1 block w-40" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('pin_confirm')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Сохранить ПИН') }}</x-primary-button>
        </div>
    </form>
</section>