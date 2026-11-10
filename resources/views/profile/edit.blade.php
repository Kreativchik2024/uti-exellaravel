<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            {{-- ПИН для подтверждения действий --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-pin-form')
                </div>
            </div>

            {{-- Вход с кодом из приложения на телефоне --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">Вход с кодом из телефона</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        @if(auth()->user()->hasTwoFactor())
                            Включён: после пароля система спрашивает код из приложения на телефоне.
                        @else
                            Выключен. Включите, чтобы без вашего телефона в учётку нельзя было войти, даже зная пароль.
                        @endif
                    </p>
                    <a href="{{ route('two-factor.setup') }}" class="ui-btn ui-btn--ghost mt-4">
                        {{ auth()->user()->hasTwoFactor() ? 'Управление и резервные коды' : 'Включить' }}
                    </a>
                </div>
            </div>

            {{-- Привязка к сотруднику (информационный блок) --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.employee-link-info')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>