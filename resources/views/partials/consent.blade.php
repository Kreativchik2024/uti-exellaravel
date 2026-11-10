{{-- Окно согласия: cookie + правила пользования + обработка персональных данных.
     Показывается при первом посещении и после изменения редакции правил (config/legal.php). --}}
@if (\App\Http\Controllers\ConsentController::required(request()))
    @php $onRules = request()->routeIs('legal.rules'); @endphp

    <div class="ui-consent {{ $onRules ? 'ui-consent--bar' : '' }}" role="dialog" aria-modal="{{ $onRules ? 'false' : 'true' }}" aria-labelledby="consent-title">
        <form method="POST" action="{{ route('consent.accept') }}" class="ui-consent__card">
            @csrf

            <div class="ui-consent__head">
                <span class="ui-consent__icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </span>
                <h2 id="consent-title" class="ui-consent__title">Cookie и персональные данные</h2>
            </div>

            @unless ($onRules)
                <p class="ui-consent__text">
                    Сайт использует только технические cookie, без которых не работают вход, защита форм и настройки.
                    При работе в системе обрабатываются ваши персональные данные: ФИО, email, должность, участок,
                    записи учёта, сообщения чата и время входа.
                </p>
            @endunless

            <label class="ui-consent__check">
                <input type="checkbox" name="accept" value="1" required>
                <span>
                    Я принимаю <a href="{{ route('legal.rules') }}#rules">правила пользования</a>,
                    использование <a href="{{ route('legal.rules') }}#cookies">cookie</a> и даю согласие на
                    <a href="{{ route('legal.rules') }}#personal-data">обработку персональных данных</a>
                </span>
            </label>
            @error('accept') <p class="ui-consent__error">{{ $message }}</p> @enderror

            <div class="ui-consent__actions">
                @unless ($onRules)
                    <a href="{{ route('legal.rules') }}" class="ui-btn ui-btn--ghost">Прочитать правила</a>
                @endunless
                <button type="submit" class="ui-btn ui-btn--primary">Принять</button>
            </div>
        </form>
    </div>
@endif
