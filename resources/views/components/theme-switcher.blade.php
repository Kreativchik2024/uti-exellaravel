@props(['align' => 'right'])

{{-- Переключатель темы: Авто / Светлая / Тёмная (логика — public/js/theme.js) --}}
<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="{
         open: false,
         mode: window.getThemeMode ? window.getThemeMode() : 'auto',
         set(m) { window.setThemeMode(m); this.mode = m; this.open = false; },
     }"
     @themechange.document="mode = window.getThemeMode()"
     @click.outside="open = false" @keydown.escape.window="open = false">

    <button type="button" class="ui-iconbtn" @click="open = !open" :aria-expanded="open"
            aria-haspopup="menu" :title="'Тема: ' + ({ auto: 'как в системе', light: 'светлая', dark: 'тёмная', peaky: 'острые козырьки', blueprint: 'конструкторское бюро', minions: 'миньоны', darkknight: 'тёмный рыцарь' })[mode]">
        <span class="sr-only">Тема оформления</span>
        {{-- Иконка текущего режима --}}
        <svg x-show="mode === 'light'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        <svg x-show="mode === 'dark'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        <svg x-show="mode === 'peaky'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15c0-4.4 4-7 9-7s9 2.6 9 7M2 15h20M7 9.5 6 15"/></svg>
        <svg x-show="mode === 'blueprint'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12 3.27 3.13A59.8 59.8 0 0 1 21.49 12 59.8 59.8 0 0 1 3.27 20.88L6 12Zm0 0h7.5"/></svg>
        <svg x-show="mode === 'minions'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 5.5c.5 8.5 7 14.5 16 13.5-.2-1-.9-1.5-1.8-1.6-6.5-.4-11.2-5.4-12.1-11.8-.2-1-1.4-1.1-2.1-.1ZM4.5 5.5 3 4"/></svg>
        <svg x-show="mode === 'darkknight'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8l-.8 1.4-.7-1.1c-.5 1.4-1.9 1.9-3.4 1.6.6.8.7 1.7.3 2.6C6.2 11.8 4.6 11.9 3 13c1.6.4 2.8 1.6 3.2 3.2 1.1-1 2.6-1.2 3.7-.6.2.8.9 1.4 2.1 2.2 1.2-.8 1.9-1.4 2.1-2.2 1.1-.6 2.6-.4 3.7.6.4-1.6 1.6-2.8 3.2-3.2-1.6-1.1-3.2-1.2-4.4-.5-.4-.9-.3-1.8.3-2.6-1.5.3-2.9-.2-3.4-1.6l-.7 1.1Z"/></svg>
        <svg x-show="mode === 'auto'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </button>

    <div x-show="open" x-cloak role="menu"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         class="ui-menu absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} top-full mt-2 z-50 w-52">
        <div class="ui-menu__label">Тема</div>
        @foreach([
            'auto'  => ['Как в системе', 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            'light' => ['Светлая', 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z'],
            'dark'  => ['Тёмная', 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'],
            'peaky' => ['Острые козырьки', 'M3 15c0-4.4 4-7 9-7s9 2.6 9 7M2 15h20M7 9.5 6 15'],
            'blueprint' => ['Конструкторское бюро', 'M6 12 3.27 3.13A59.8 59.8 0 0 1 21.49 12 59.8 59.8 0 0 1 3.27 20.88L6 12Zm0 0h7.5'],
            'darkknight' => ['Тёмный рыцарь', 'M12 8l-.8 1.4-.7-1.1c-.5 1.4-1.9 1.9-3.4 1.6.6.8.7 1.7.3 2.6C6.2 11.8 4.6 11.9 3 13c1.6.4 2.8 1.6 3.2 3.2 1.1-1 2.6-1.2 3.7-.6.2.8.9 1.4 2.1 2.2 1.2-.8 1.9-1.4 2.1-2.2 1.1-.6 2.6-.4 3.7.6.4-1.6 1.6-2.8 3.2-3.2-1.6-1.1-3.2-1.2-4.4-.5-.4-.9-.3-1.8.3-2.6-1.5.3-2.9-.2-3.4-1.6l-.7 1.1Z'],
            'minions' => ['Миньоны', 'M4.5 5.5c.5 8.5 7 14.5 16 13.5-.2-1-.9-1.5-1.8-1.6-6.5-.4-11.2-5.4-12.1-11.8-.2-1-1.4-1.1-2.1-.1ZM4.5 5.5 3 4'],
        ] as $value => [$label, $icon])
            <button type="button" role="menuitemradio" :aria-checked="mode === '{{ $value }}'"
                    class="ui-menu__item" :class="mode === '{{ $value }}' && 'ui-menu__item--active'"
                    @click="set('{{ $value }}')">
                <svg class="w-4 h-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/></svg>
                <span class="flex-1">{{ $label }}</span>
                <svg x-show="mode === '{{ $value }}'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </button>
        @endforeach
    </div>
</div>
