<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
            </span>
            <div>
                <h2>Состояние сервера</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Диск, резервные копии, ночные задачи, логи и настройки</p>
            </div>
        </div>
    </x-slot>

    @php
        $all = collect($checks)->flatten(1);
        $bad = $all->where('status', 'bad')->count();
        $warn = $all->where('status', 'warn')->count();
    @endphp

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="ui-card p-4">
                <div class="text-xs" style="color: var(--ui-muted)">Итог</div>
                <div class="text-lg font-bold mt-1" style="color: {{ $bad ? 'var(--ui-bad)' : ($warn ? '#d97706' : 'var(--ui-good)') }}">
                    {{ $bad ? "Проблем: $bad" : ($warn ? "Замечаний: $warn" : 'Всё в порядке') }}
                </div>
            </div>
            <div class="ui-card p-4">
                <div class="text-xs" style="color: var(--ui-muted)">Сейчас работают</div>
                <div class="text-lg font-bold mt-1" style="color: var(--ui-text)">{{ $online }} сотр.</div>
            </div>
            <div class="ui-card p-4" x-data="{ drift: Math.round((Date.now() - {{ $serverTime->getTimestampMs() }}) / 1000) }">
                <div class="text-xs" style="color: var(--ui-muted)">Время сервера</div>
                <div class="text-lg font-bold mt-1" style="color: var(--ui-text)">{{ $serverTime->format('d.m.Y H:i:s') }}</div>
                <div class="text-xs mt-0.5" :style="Math.abs(drift) > 60 ? 'color: var(--ui-bad)' : 'color: var(--ui-muted)'"
                     x-text="Math.abs(drift) > 60 ? 'Расходится с вашим компьютером на ' + Math.abs(drift) + ' с — время в записях будет неточным' : 'Совпадает с вашим компьютером'"></div>
            </div>
        </div>

        @foreach($checks as $group => $items)
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">{{ $group }}</h3>
                @foreach($items as $c)
                    <div class="ui-syscheck ui-syscheck--{{ $c['status'] }}">
                        <span class="ui-syscheck__dot" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <div class="ui-syscheck__title">{{ $c['title'] }}</div>
                            @if($c['detail'])<div class="ui-syscheck__detail">{{ $c['detail'] }}</div>@endif
                        </div>
                        <span class="ui-syscheck__label">{{ ['ok' => 'норма', 'warn' => 'внимание', 'bad' => 'проблема'][$c['status']] }}</span>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
</x-app-layout>
