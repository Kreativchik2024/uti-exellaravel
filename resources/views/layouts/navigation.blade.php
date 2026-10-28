<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    @php
        $currentWorkers = session('workstation_workers', []);
        $workersCount   = count($currentWorkers);
    @endphp

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <div class="hidden space-x-4 sm:-my-px sm:ms-6 sm:flex items-center">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Главная') }}
                    </x-nav-link>

                    <x-dropdown align="left" width="64">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-2 text-sm font-medium leading-4 rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150 {{ request()->routeIs('records.*') ? 'text-gray-900' : '' }}">
                                <span>{{ __('Учёт') }}</span>
                                <svg class="ms-1 fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            @foreach([
                                'pgp' => 'ПГП (борта)',
                                'comm_module' => 'Модуль связи',
                                'cross1' => 'Кросс 1',
                                'cross3' => 'Кросс 3',
                                'mini_pc' => 'Мини ПК',
                                'research' => 'Исследования',
                                'technologist' => 'Лист технолога',
                                'pc_check' => 'Проверка ПК',
                                'sophon_check' => 'Проверка Sophon',
                                'software_install' => 'Установка ПО',
                                'motor_regulator' => 'Регулятор мотора',
                                'camera_bracket' => 'Кронштейн с камерой',
                                'speed_sensor' => 'Датчик скорости',
                                'router_firmware' => 'Прошивка роутеров',
                            ] as $slug => $label)
                                <x-dropdown-link :href="route('records.index', $slug)" :class="request()->route('sheet') === $slug && $slug !== 'speed_sensor' ? 'font-semibold text-indigo-600' : ''">
                                    {{ __($label) }}
                                </x-dropdown-link>
                            @endforeach
                        </x-slot>
                    </x-dropdown>

                    <x-nav-link :href="route('workstation.show')" :active="request()->routeIs('workstation.*')">
                        {{ __('Исполнители') }}@if($workersCount > 0) ({{ $workersCount }})@endif
                    </x-nav-link>

                    <x-nav-link :href="route('firmware.index')" :active="request()->routeIs('firmware.*')">
                        {{ __('Прошивки') }}
                    </x-nav-link>

                    <x-nav-link :href="route('stats.index')" :active="request()->routeIs('stats.*')">
                        {{ __('Статистика') }}
                    </x-nav-link>

                    @if(auth()->user()->isAdmin())
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <button class="inline-flex items-center px-3 py-2 text-sm font-medium leading-4 rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150 {{ request()->routeIs('users.*') || request()->routeIs('firmware.categories.*') || request()->routeIs('employees.*') ? 'text-gray-900' : '' }}">
                                    <span>{{ __('Админ') }}</span>
                                    <svg class="ms-1 fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('users.index')" :class="request()->routeIs('users.*') ? 'font-semibold text-indigo-600' : ''">
                                    {{ __('Пользователи') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('employees.index')" :class="request()->routeIs('employees.*') ? 'font-semibold text-indigo-600' : ''">
                                    {{ __('Сотрудники') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('firmware.categories.index')" :class="request()->routeIs('firmware.categories.*') ? 'font-semibold text-indigo-600' : ''">
                                    {{ __('Категории прошивок') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Профиль') }}
                        </x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Выйти') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1 max-h-[70vh] overflow-y-auto">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Главная') }}
            </x-responsive-nav-link>

            <div x-data="{ expanded: false }" class="border-t border-gray-100 pt-1">
                <button @click="expanded = !expanded" class="w-full flex justify-between items-center ps-3 pe-4 py-2 border-l-4 border-transparent text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                    <span>Учёт</span>
                    <svg :class="expanded ? 'rotate-180' : ''" class="h-4 w-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div x-show="expanded" x-collapse class="ps-4">
                    @foreach([
                        'pgp' => 'ПГП (борта)',
                        'comm_module' => 'Модуль связи',
                        'cross1' => 'Кросс 1',
                        'cross3' => 'Кросс 3',
                        'mini_pc' => 'Мини ПК',
                        'research' => 'Исследования',
                        'technologist' => 'Лист технолога',
                        'pc_check' => 'Проверка ПК',
                        'sophon_check' => 'Проверка Sophon',
                        'software_install' => 'Установка ПО',
                        'motor_regulator' => 'Регулятор мотора',
                        'camera_bracket' => 'Кронштейн с камерой',
                        'speed_sensor' => 'Датчик скорости',
                        'router_firmware' => 'Прошивка роутеров',
                    ] as $slug => $label)
                        <x-responsive-nav-link :href="route('records.index', $slug)" :active="request()->route('sheet') === $slug && $slug !== 'speed_sensor'">
                            {{ __($label) }}
                        </x-responsive-nav-link>
                    @endforeach
                </div>
            </div>

            <x-responsive-nav-link :href="route('workstation.show')" :active="request()->routeIs('workstation.*')">
                {{ __('Исполнители') }}@if($workersCount > 0) ({{ $workersCount }})@endif
            </x-responsive-nav-link>

            <x-responsive-nav-link :href="route('firmware.index')" :active="request()->routeIs('firmware.*')">
                {{ __('Прошивки') }}
            </x-responsive-nav-link>

            <x-responsive-nav-link :href="route('stats.index')" :active="request()->routeIs('stats.*')">
                {{ __('Статистика') }}
            </x-responsive-nav-link>

            @if(auth()->user()->isAdmin())
                <div x-data="{ expanded: false }" class="border-t border-gray-100 pt-1">
                    <button @click="expanded = !expanded" class="w-full flex justify-between items-center ps-3 pe-4 py-2 border-l-4 border-transparent text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                        <span>Админ</span>
                        <svg :class="expanded ? 'rotate-180' : ''" class="h-4 w-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div x-show="expanded" x-collapse class="ps-4">
                        <x-responsive-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                            {{ __('Пользователи') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('employees.index')" :active="request()->routeIs('employees.*')">
                            {{ __('Сотрудники') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('firmware.categories.index')" :active="request()->routeIs('firmware.categories.*')">
                            {{ __('Категории прошивок') }}
                        </x-responsive-nav-link>
                    </div>
                </div>
            @endif
        </div>

        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Профиль') }}
                </x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Выйти') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>