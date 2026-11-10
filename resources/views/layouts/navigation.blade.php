<nav x-data="{ open: false }" class="ui-nav">
    @php
        $currentWorkers = session('workstation_workers', []);
        $workersCount   = count($currentWorkers);
        $activePins     = $globalVerifiedWorkersMap ?? [];
        $currentSheet   = request()->route('sheet');
        $user           = Auth::user();
        $canSwitch      = ($availableSections ?? collect())->count() > 1 || $user->isAdmin();

        // Вкладки учёта текущего участка (config/sheets.php → 'section')
        $recordTabs = collect($sectionSheets ?? [])->map(fn ($cfg) => $cfg['title'] ?? '')->all();
        $hasFirmware = (bool) ($currentSection?->has_firmware);

        $initials = collect(preg_split('/\s+/u', trim($user->name)))
            ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
        $roleLabel = ['admin' => 'Администратор', 'master' => 'Мастер', 'executor' => 'Исполнитель'][$user->role] ?? '';
        $isAdminArea = request()->routeIs('users.*') || request()->routeIs('employees.*') || request()->routeIs('firmware.categories.*') || request()->routeIs('sections.*');
    @endphp

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-4" style="height: var(--ui-nav-h)">

            {{-- Бренд --}}
            <a href="{{ route('dashboard') }}" class="ui-brand shrink-0">
                <x-application-logo class="ui-brand__logo" />
                <span class="hidden 2xl:block">
                    <span class="ui-brand__name block">{{ config('app.name', 'УТИ') }}</span>
                    <span class="ui-brand__sub block">учёт производства</span>
                </span>
            </a>

            {{-- Основные разделы (десктоп) --}}
            <div class="hidden md:flex items-center gap-1 flex-1 min-w-0">
                <a href="{{ route('dashboard') }}" class="ui-navlink {{ request()->routeIs('dashboard') ? 'ui-navlink--active' : '' }}">Главная</a>

                {{-- Учёт: панель со всеми вкладками --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open"
                            class="ui-navlink {{ request()->routeIs('records.*') ? 'ui-navlink--active' : '' }}">
                        Учёт
                        <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                         class="ui-menu absolute left-0 top-full mt-2 z-50 w-[min(560px,90vw)]">
                        <div class="ui-menu__label">Вкладки учёта · {{ $currentSection?->name }}</div>
                        @if(empty($recordTabs))
                            <div class="px-2.5 py-3 text-sm" style="color: var(--ui-muted)">У этого участка пока нет вкладок учёта.</div>
                        @endif
                        <div class="grid grid-cols-2 gap-0.5 max-h-[70vh] overflow-y-auto">
                            @foreach($recordTabs as $slug => $label)
                                <a href="{{ route('records.index', $slug) }}"
                                   class="ui-menu__item {{ $currentSheet === $slug ? 'ui-menu__item--active' : '' }}">
                                    <span class="ui-menu__dot"></span>
                                    <span class="truncate">{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <a href="{{ route('workstation.show') }}" class="ui-navlink {{ request()->routeIs('workstation.*') ? 'ui-navlink--active' : '' }}">
                    Исполнители
                    @if($workersCount > 0)<span class="ui-navlink__count">{{ $workersCount }}</span>@endif
                    @foreach($activePins as $name => $expires)
                        <span data-pin-timer="{{ $name }}" class="hidden xl:inline"></span>
                    @endforeach
                </a>
                @if($hasFirmware)
                    <a href="{{ route('firmware.index') }}" class="ui-navlink {{ request()->routeIs('firmware.*') && !request()->routeIs('firmware.categories.*') ? 'ui-navlink--active' : '' }}">Прошивки</a>
                @endif
                <a href="{{ route('stats.index') }}" class="ui-navlink {{ request()->routeIs('stats.*') ? 'ui-navlink--active' : '' }}">Статистика</a>

                @if($user->isAdmin())
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open"
                                class="ui-navlink {{ $isAdminArea ? 'ui-navlink--active' : '' }}">
                            Админ
                            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" x-cloak
                             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                             class="ui-menu absolute left-0 top-full mt-2 z-50 w-60">
                            <a href="{{ route('sections.index') }}" class="ui-menu__item {{ request()->routeIs('sections.*') ? 'ui-menu__item--active' : '' }}">
                                <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                Участки
                            </a>
                            <a href="{{ route('users.index') }}" class="ui-menu__item {{ request()->routeIs('users.*') ? 'ui-menu__item--active' : '' }}">
                                <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
                                Пользователи
                            </a>
                            <a href="{{ route('employees.index') }}" class="ui-menu__item {{ request()->routeIs('employees.*') ? 'ui-menu__item--active' : '' }}">
                                <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                                Сотрудники
                            </a>
                            <a href="{{ route('firmware.categories.index') }}" class="ui-menu__item {{ request()->routeIs('firmware.categories.*') ? 'ui-menu__item--active' : '' }}">
                                <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                Категории прошивок
                            </a>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Справа: тема + профиль --}}
            <div class="flex items-center gap-1.5">
                <x-theme-switcher />

                <div class="relative hidden md:block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open"
                            class="flex items-center gap-2.5 h-10 pl-1 pr-2 rounded-xl transition hover:bg-[var(--ui-surface-hover)]">
                        <span class="ui-avatar">{{ $initials }}</span>
                        <span class="hidden 2xl:block text-left leading-tight">
                            <span class="block text-sm font-semibold" style="color: var(--ui-text)">{{ \Illuminate\Support\Str::limit($user->name, 24) }}</span>
                            <span class="block text-[11px]" style="color: var(--ui-muted)">{{ $roleLabel }}</span>
                        </span>
                        <svg class="w-4 h-4" style="color: var(--ui-faint)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                         class="ui-menu absolute right-0 top-full mt-2 z-50 w-64">
                        <div class="px-2.5 py-2">
                            <div class="text-sm font-semibold truncate" style="color: var(--ui-text)">{{ $user->name }}</div>
                            <div class="text-xs truncate" style="color: var(--ui-muted)">{{ $user->email }}</div>
                        </div>

                        {{-- Участок: выбор (если их несколько) или просто название --}}
                        @if($currentSection)
                            <div class="ui-menu__sep"></div>
                            <div class="ui-menu__label">Участок</div>
                            @if($canSwitch)
                                @foreach($availableSections as $sec)
                                    <form method="POST" action="{{ route('section.switch') }}">
                                        @csrf
                                        <input type="hidden" name="section_id" value="{{ $sec->id }}">
                                        <button type="submit" class="ui-menu__item {{ $sec->id === $currentSection->id ? 'ui-menu__item--active' : '' }}">
                                            <span class="ui-menu__dot"></span><span class="flex-1 truncate">{{ $sec->name }}</span>
                                            @if($sec->id === $currentSection->id)
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            @endif
                                        </button>
                                    </form>
                                @endforeach
                                @if($user->isAdmin())
                                    <a href="{{ route('sections.index') }}" class="ui-menu__item" style="color: var(--ui-muted)">Управление участками…</a>
                                @endif
                            @else
                                <div class="px-2.5 py-1.5 text-sm" style="color: var(--ui-text-2)">{{ $currentSection->name }}</div>
                            @endif
                        @endif

                        @if(!empty($activePins))
                            <div class="ui-menu__sep"></div>
                            <div class="ui-menu__label">Активные ПИН</div>
                            @foreach($activePins as $name => $expires)
                                <div class="flex items-center justify-between px-2.5 py-1">
                                    <span class="text-sm" style="color: var(--ui-text-2)">{{ $name }}</span>
                                    <span data-pin-timer="{{ $name }}"></span>
                                </div>
                            @endforeach
                        @endif

                        <div class="ui-menu__sep"></div>
                        <a href="{{ route('profile.edit') }}" class="ui-menu__item">
                            <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            Профиль
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="ui-menu__item" style="color: var(--ui-bad)">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                Выйти
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Бургер (мобильные) --}}
                <button type="button" @click="open = !open" class="ui-iconbtn md:hidden" aria-label="Меню" :aria-expanded="open ? 'true' : 'false'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path x-show="!open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Мобильное меню --}}
    <div x-show="open" x-cloak x-transition.opacity class="md:hidden border-t" style="border-color: var(--ui-border)"
         @click="$event.target.closest('a') && (open = false)">
        <div class="px-4 py-3 space-y-1 max-h-[75vh] overflow-y-auto">
            <div class="flex items-center gap-3 px-1 pb-3 mb-1 border-b" style="border-color: var(--ui-border)">
                <span class="ui-avatar">{{ $initials }}</span>
                <div class="min-w-0">
                    <div class="text-sm font-semibold truncate" style="color: var(--ui-text)">{{ $user->name }}</div>
                    <div class="text-xs" style="color: var(--ui-muted)">{{ $roleLabel }}</div>
                </div>
            </div>

            @if($currentSection && $canSwitch)
                <div class="ui-menu__label">Участок</div>
                <form method="POST" action="{{ route('section.switch') }}" class="px-1 pb-2">
                    @csrf
                    <select name="section_id" onchange="this.form.submit()" class="w-full rounded-[10px] px-3 py-2 text-sm" aria-label="Участок">
                        @foreach($availableSections as $sec)
                            <option value="{{ $sec->id }}" @selected($sec->id === $currentSection->id)>{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif

            <a href="{{ route('dashboard') }}" class="ui-menu__item {{ request()->routeIs('dashboard') ? 'ui-menu__item--active' : '' }}">Главная</a>

            <div x-data="{ expanded: {{ request()->routeIs('records.*') ? 'true' : 'false' }} }">
                <button type="button" @click="expanded = !expanded" class="ui-menu__item justify-between {{ request()->routeIs('records.*') ? 'ui-menu__item--active' : '' }}">
                    <span>Учёт</span>
                    <svg class="w-4 h-4 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="expanded" x-cloak class="pl-3 mt-0.5 space-y-0.5">
                    @foreach($recordTabs as $slug => $label)
                        <a href="{{ route('records.index', $slug) }}" class="ui-menu__item {{ $currentSheet === $slug ? 'ui-menu__item--active' : '' }}">
                            <span class="ui-menu__dot"></span>{{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            <a href="{{ route('workstation.show') }}" class="ui-menu__item {{ request()->routeIs('workstation.*') ? 'ui-menu__item--active' : '' }}">
                Исполнители
                @if($workersCount > 0)<span class="ui-navlink__count ml-auto">{{ $workersCount }}</span>@endif
            </a>
            @if($hasFirmware)
                <a href="{{ route('firmware.index') }}" class="ui-menu__item {{ request()->routeIs('firmware.*') && !request()->routeIs('firmware.categories.*') ? 'ui-menu__item--active' : '' }}">Прошивки и инструкции</a>
            @endif
            <a href="{{ route('stats.index') }}" class="ui-menu__item {{ request()->routeIs('stats.*') ? 'ui-menu__item--active' : '' }}">Статистика</a>

            @if($user->isAdmin())
                <div class="ui-menu__label pt-3">Администрирование</div>
                <a href="{{ route('sections.index') }}" class="ui-menu__item {{ request()->routeIs('sections.*') ? 'ui-menu__item--active' : '' }}">Участки</a>
                <a href="{{ route('users.index') }}" class="ui-menu__item {{ request()->routeIs('users.*') ? 'ui-menu__item--active' : '' }}">Пользователи</a>
                <a href="{{ route('employees.index') }}" class="ui-menu__item {{ request()->routeIs('employees.*') ? 'ui-menu__item--active' : '' }}">Сотрудники</a>
                <a href="{{ route('firmware.categories.index') }}" class="ui-menu__item {{ request()->routeIs('firmware.categories.*') ? 'ui-menu__item--active' : '' }}">Категории прошивок</a>
            @endif

            @if(!empty($activePins))
                <div class="ui-menu__label pt-3">Активные ПИН</div>
                @foreach($activePins as $name => $expires)
                    <div class="flex items-center justify-between px-2.5 py-1">
                        <span class="text-sm" style="color: var(--ui-text-2)">{{ $name }}</span>
                        <span data-pin-timer="{{ $name }}"></span>
                    </div>
                @endforeach
            @endif

            <div class="ui-menu__sep"></div>
            <a href="{{ route('profile.edit') }}" class="ui-menu__item">Профиль</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="ui-menu__item" style="color: var(--ui-bad)">Выйти</button>
            </form>
        </div>
    </div>
</nav>
