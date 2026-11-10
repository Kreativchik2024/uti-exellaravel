<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
            </span>
            <div>
                <h2>Состояние сервера</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Диск, резервные копии, плановые задачи, логи и настройки</p>
            </div>
        </div>
    </x-slot>

    @php
        $all = collect($checks)->flatten(1);
        $bad = $all->where('status', 'bad')->count();
        $warn = $all->where('status', 'warn')->count();
    @endphp

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">{{ $errors->first() }}</div>
        @endif

        {{-- Фоновая задача: ход и результат (обновляется сам) --}}
        <div x-data="systemTask(@js($task), @js(route('system.status')))" x-show="task" x-cloak
             class="ui-card ui-systask" :class="'ui-systask--' + (task && task.status)">
            <div class="flex items-center gap-3">
                <span class="ui-systask__icon" aria-hidden="true">
                    <svg x-show="task && task.status === 'running'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <svg x-show="task && task.status === 'done'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <svg x-show="task && task.status === 'failed'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold text-sm" style="color: var(--ui-text)" x-text="task && task.label"></div>
                    <div class="text-xs" style="color: var(--ui-muted)" x-text="statusText()"></div>
                </div>
                <button type="button" x-show="task && task.status !== 'running'" class="ui-btn ui-btn--ghost ui-btn--sm" @click="location.reload()">Обновить страницу</button>
            </div>
            <pre x-show="task && task.output" class="ui-systask__out" x-text="task && task.output"></pre>
        </div>

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
                        <div class="min-w-0 flex-1">
                            <div class="ui-syscheck__title">{{ $c['title'] }}</div>
                            @if($c['detail'])<div class="ui-syscheck__detail">{{ $c['detail'] }}</div>@endif
                            @if(!empty($c['actions']))
                                <div class="ui-syscheck__actions">
                                    @foreach($c['actions'] as $a)
                                        @if($a['type'] === 'run')
                                            <form method="POST" action="{{ route('system.run', $a['task']) }}" @if(!empty($a['confirm'])) onsubmit="return confirm(@js($a['confirm']))" @endif>
                                                @csrf
                                                <button type="submit" class="ui-btn ui-btn--ghost ui-btn--sm">{{ $a['label'] }}</button>
                                            </form>
                                        @elseif($a['type'] === 'post')
                                            <form method="POST" action="{{ $a['url'] }}" @if(!empty($a['confirm'])) onsubmit="return confirm(@js($a['confirm']))" @endif>
                                                @csrf
                                                <button type="submit" class="ui-btn ui-btn--ghost ui-btn--sm">{{ $a['label'] }}</button>
                                            </form>
                                        @elseif($a['type'] === 'link')
                                            <a href="{{ $a['url'] }}" class="ui-btn ui-btn--ghost ui-btn--sm">{{ $a['label'] }}</a>
                                        @elseif($a['type'] === 'copy')
                                            <span x-data="{ done: false }" class="inline-flex flex-col gap-1 min-w-0">
                                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" :title="@js($a['text'])"
                                                        @click="copyText(@js($a['text'])).then(() => { done = true; setTimeout(() => done = false, 2000) })">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    <span x-text="done ? 'Скопировано' : @js($a['label'])"></span>
                                                </button>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <span class="ui-syscheck__label">{{ ['ok' => 'норма', 'warn' => 'внимание', 'bad' => 'проблема'][$c['status']] }}</span>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>

    <script>
        // Копирование: Clipboard API (HTTPS) или запасной способ через выделение
        window.copyText = function (text) {
            if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
            return new Promise(function (resolve) {
                var t = document.createElement('textarea');
                t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
                document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); } catch (e) {}
                t.remove(); resolve();
            });
        };
        document.addEventListener('alpine:init', function () {
            Alpine.data('systemTask', function (initial, url) {
                return {
                    task: initial, timer: null,
                    init() { this.timer = setInterval(() => this.poll(), 3000); },
                    async poll() {
                        if (!this.task || this.task.status !== 'running') return;
                        try {
                            const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                            if (r.ok) this.task = await r.json();
                        } catch (e) {}
                    },
                    statusText() {
                        if (!this.task) return '';
                        const t = (iso) => iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
                        if (this.task.status === 'running') return 'Выполняется… начато в ' + t(this.task.started_at);
                        return (this.task.status === 'done' ? 'Готово' : 'Ошибка') + ' в ' + t(this.task.finished_at);
                    },
                };
            });
        });
    </script>
</x-app-layout>
