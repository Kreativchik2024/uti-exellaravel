<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </span>
            <div>
                <h2>Рабочее место</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Кто работает и где — подставится в новые записи автоматически</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('workstation.store') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="intended" value="{{ $intended }}">

            @php
                $slots = old('workers', $selected ?: []);
                $slots = array_pad(array_values($slots), 5, '');
                $type  = old('type', $workplaceType);
            @endphp

            @if($errors->any())
                <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            {{-- Исполнители --}}
            <section class="ui-card p-5" x-data="workstationWorkers()" x-init="init()" x-destroy="destroy()">
                <div class="flex flex-col sm:flex-row sm:items-baseline sm:justify-between gap-x-3 gap-y-0.5 mb-4">
                    <h3 class="text-base font-semibold" style="color: var(--ui-text)">Кто работает</h3>
                    <span class="text-xs" style="color: var(--ui-muted)">до 5 человек · других — по их ПИН</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @for($i = 0; $i < 5; $i++)
                        <div class="ws-slot">
                            <label class="block text-xs font-medium mb-1.5" style="color: var(--ui-muted)" for="worker-{{ $i }}">
                                Исполнитель {{ $i + 1 }}@if($i === 0) <span style="color: var(--ui-faint)">· основной</span>@endif
                            </label>
                            <select id="worker-{{ $i }}" name="workers[{{ $i }}]"
                                    x-model="selected[{{ $i }}]"
                                    @change="onChange({{ $i }})"
                                    class="w-full rounded-[10px] px-3 py-2 text-sm">
                                <option value="">— не выбран —</option>
                                <template x-for="emp in availableFor({{ $i }})" :key="emp">
                                    <option :value="emp"
                                            x-text="emp + (withoutPin.includes(emp) && !canManageWorkers && emp !== currentEmployee ? ' (нет ПИН)' : '')"
                                            :selected="emp === selected[{{ $i }}]"
                                            :disabled="!canManageWorkers && emp !== currentEmployee && withoutPin.includes(emp)"></option>
                                </template>
                            </select>

                            <div class="mt-1.5 min-h-[20px] text-[11px] font-medium">
                                <span x-show="selected[{{ $i }}] && selected[{{ $i }}] === currentEmployee" style="color: var(--ui-good)">✓ это вы — ПИН не нужен</span>
                                <span x-show="selected[{{ $i }}] && selected[{{ $i }}] !== currentEmployee && verifiedMap[selected[{{ $i }}]]"
                                      x-html="getTimerText(selected[{{ $i }}])"></span>
                                <span x-show="selected[{{ $i }}] && selected[{{ $i }}] !== currentEmployee && !verifiedMap[selected[{{ $i }}]] && !withoutPin.includes(selected[{{ $i }}])"
                                      style="color: #d97706">Требуется ПИН</span>
                                <span x-show="selected[{{ $i }}] && selected[{{ $i }}] !== currentEmployee && !verifiedMap[selected[{{ $i }}]] && withoutPin.includes(selected[{{ $i }}]) && !canManageWorkers"
                                      style="color: var(--ui-bad)">✗ нет ПИН</span>
                            </div>
                        </div>
                    @endfor
                </div>

                {{-- Окно ввода ПИН --}}
                <div x-show="pinModalOpen" x-cloak
                     class="fixed inset-0 z-[70] flex items-center justify-center p-4" style="background: rgba(15, 23, 42, .55); backdrop-filter: blur(2px)"
                     @click.self="cancelPin()" @keydown.escape.window="pinModalOpen && cancelPin()">
                    <div class="ui-card w-full max-w-xs p-5 space-y-3" style="box-shadow: var(--ui-shadow-lg)" role="dialog" aria-modal="true" aria-labelledby="pin-title">
                        <div class="flex items-center gap-3">
                            <span class="ui-icon-tile ui-icon-tile--sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </span>
                            <div>
                                <h3 id="pin-title" class="font-semibold" style="color: var(--ui-text)">ПИН сотрудника</h3>
                                <p class="text-xs" style="color: var(--ui-muted)" x-text="pinTarget"></p>
                            </div>
                        </div>
                        {{-- form="" отвязывает поле от формы: иначе браузер подставляет сюда
                             сохранённый пароль, поле не проходит проверку формата и
                             «Продолжить» молча не срабатывает. Формат ПИН проверяет submitPin(). --}}
                        <input type="password" x-model="pinValue" x-ref="pinInput"
                               form="workstation-pin-detached"
                               inputmode="numeric" maxlength="6"
                               autocomplete="one-time-code"
                               placeholder="4–6 цифр" aria-label="ПИН"
                               @keydown.enter.prevent="submitPin()"
                               class="w-full rounded-[10px] px-3 py-2.5 text-center text-lg tracking-[.4em] font-semibold">
                        <div x-show="pinError" x-text="pinError" class="text-xs font-medium" style="color: var(--ui-bad)"></div>
                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <button type="button" @click="cancelPin()" class="ui-btn ui-btn--ghost">Отмена</button>
                            <button type="button" @click="submitPin()" :disabled="pinLoading" class="ui-btn ui-btn--primary"
                                    x-text="pinLoading ? 'Проверка…' : 'Подтвердить'"></button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Рабочее место --}}
            <section class="ui-card p-5">
                <h3 class="text-base font-semibold mb-4" style="color: var(--ui-text)">Где вы работаете</h3>

                <div class="ui-segment mb-4 max-w-xs" role="radiogroup" aria-label="Тип рабочего места">
                    <label class="ui-segment__item ws-seg cursor-pointer">
                        <input type="radio" name="type" value="post" id="type-post" class="sr-only" @checked($type === 'post')>
                        Пост
                    </label>
                    <label class="ui-segment__item ws-seg cursor-pointer">
                        <input type="radio" name="type" value="peripheral" id="type-peripheral" class="sr-only" @checked($type === 'peripheral')>
                        Периферия
                    </label>
                </div>

                <div id="block-post" class="{{ $type === 'post' ? '' : 'hidden' }}">
                    <label class="block text-xs font-medium mb-1.5" style="color: var(--ui-muted)" for="post-select">Номер поста (1–30)</label>
                    <select id="post-select" name="post" class="w-full sm:max-w-xs rounded-[10px] px-3 py-2 text-sm">
                        <option value="">— не выбран —</option>
                        @foreach($posts as $p)
                            <option value="{{ $p }}" @selected((string) old('post', $selectedPost) === (string) $p)>Пост {{ $p }}</option>
                        @endforeach
                    </select>
                    @error('post')<div class="text-xs mt-1" style="color: var(--ui-bad)">{{ $message }}</div>@enderror
                </div>

                <div id="block-peripheral" class="{{ $type === 'peripheral' ? '' : 'hidden' }}">
                    <label class="block text-xs font-medium mb-1.5" style="color: var(--ui-muted)" for="peripheral-select">Номер периферии (1–50)</label>
                    <select id="peripheral-select" name="peripheral" class="w-full sm:max-w-xs rounded-[10px] px-3 py-2 text-sm">
                        <option value="">— не выбрана —</option>
                        @foreach($peripherals as $p)
                            <option value="{{ $p }}" @selected((string) old('peripheral', $selectedPeripheral) === (string) $p)>Периферия {{ $p }}</option>
                        @endforeach
                    </select>
                    @error('peripheral')<div class="text-xs mt-1" style="color: var(--ui-bad)">{{ $message }}</div>@enderror
                </div>
            </section>

            <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-2">
                <button type="submit" formaction="{{ route('workstation.skip') }}" formmethod="POST" class="ui-btn ui-btn--ghost">
                    Продолжить без исполнителей
                </button>
                <button type="submit" class="ui-btn ui-btn--primary sm:min-w-[180px]">
                    Продолжить
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </button>
            </div>
        </form>
    </div>

    @push('styles')
    <style>
        /* Сегмент «Пост / Периферия» на радиокнопках */
        .ws-seg:has(input:checked) { background: var(--ui-surface); color: var(--ui-text); box-shadow: var(--ui-shadow-sm); }
        .ws-seg:has(input:focus-visible) { outline: 2px solid var(--ui-accent); outline-offset: 1px; }
    </style>
    @endpush

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('workstationWorkers', () => ({
                all: @json($employeesList),
                withoutPin: @json($employeesWithoutPin ?? []),
                canManageWorkers: @json($canManageWorkers ?? false),
                verifiedMap: @json($verifiedMap ?? []),
                currentEmployee: @json($currentEmployeeName ?? ''),
                serverNow: {{ now()->timestamp }},
                clockOffsetMs: 0,
                timerInterval: null,
                tick: 0,

                pinUrl: '{{ route('records.pinCheck') }}',
                csrf: '{{ csrf_token() }}',

                selected: {
                    0: '{{ $slots[0] ?? '' }}',
                    1: '{{ $slots[1] ?? '' }}',
                    2: '{{ $slots[2] ?? '' }}',
                    3: '{{ $slots[3] ?? '' }}',
                    4: '{{ $slots[4] ?? '' }}'
                },
                confirmed: {
                    0: '{{ $slots[0] ?? '' }}',
                    1: '{{ $slots[1] ?? '' }}',
                    2: '{{ $slots[2] ?? '' }}',
                    3: '{{ $slots[3] ?? '' }}',
                    4: '{{ $slots[4] ?? '' }}'
                },

                pinModalOpen: false,
                pinValue: '',
                pinTarget: '',
                pinTargetIndex: null,
                pinLoading: false,
                pinError: '',

                init() {
                    this.clockOffsetMs = (this.serverNow * 1000) - Date.now();
                    this.timerInterval = setInterval(() => { this.tick++; }, 1000);
                },

                destroy() {
                    if (this.timerInterval) clearInterval(this.timerInterval);
                },

                nowSeconds() {
                    return (Date.now() + this.clockOffsetMs) / 1000;
                },

                getTimerText(name) {
                    const _tick = this.tick;
                    var expires = this.verifiedMap[name];
                    if (!expires) return '';
                    var rem = Math.floor(expires - this.nowSeconds());
                    if (rem <= 0) return '<span class="text-[11px] text-red-600 font-medium">Истёк</span>';
                    var h = Math.floor(rem / 3600);
                    var m = Math.floor((rem % 3600) / 60);
                    var s = rem % 60;
                    function p(n){return n<10?'0'+n:String(n);}
                    var cls = rem <= 15 * 60 ? 'text-amber-600' : 'text-emerald-600';
                    return '<span class="text-[11px] font-medium ' + cls + ' font-mono tabular-nums">🔒 ' + p(h)+':'+p(m)+':'+p(s) + '</span>';
                },

                availableFor(index) {
                    const used = Object.entries(this.selected)
                        .filter(([i, v]) => Number(i) !== index && v)
                        .map(([, v]) => v);
                    return this.all.filter(e => !used.includes(e) || e === this.selected[index]);
                },

                onChange(index) {
                    const newVal = this.selected[index];

                    if (newVal === '') {
                        this.confirmed[index] = '';
                        return;
                    }

                    // Убираем дубликаты в других полях
                    for (let i = 0; i < 5; i++) {
                        if (i !== index && this.selected[i] === newVal) {
                            this.selected[i] = '';
                            this.confirmed[i] = '';
                        }
                    }

                    // Админ/мастер — ПИН не нужен
                    if (this.canManageWorkers) {
                        this.confirmed[index] = newVal;
                        return;
                    }

                    // Владелец записи (текущий пользователь) — ПИН не нужен
                    if (newVal === this.currentEmployee) {
                        this.confirmed[index] = newVal;
                        return;
                    }

                    // Сотрудник без ПИН — запрет для обычных пользователей
                    if (this.withoutPin.includes(newVal)) {
                        this.selected[index] = this.confirmed[index] || '';
                        alert('У сотрудника нет ПИН-кода. Выберите другого.');
                        return;
                    }

                    // Уже подтверждён и не истёк
                    if (this.confirmed[index] === newVal) return;
                    if (this.verifiedMap[newVal] && this.verifiedMap[newVal] > this.nowSeconds()) {
                        this.confirmed[index] = newVal;
                        return;
                    }

                    // Спрашиваем ПИН
                    this.pinTarget = newVal;
                    this.pinTargetIndex = index;
                    this.pinValue = '';
                    this.pinError = '';
                    this.pinModalOpen = true;
                    this.$nextTick(() => this.$refs.pinInput?.focus());
                },

                async submitPin() {
                    this.pinError = '';
                    const pin = (this.pinValue || '').trim();
                    if (!/^\d{4,6}$/.test(pin)) {
                        this.pinError = 'ПИН — 4–6 цифр.';
                        return;
                    }
                    this.pinLoading = true;
                    try {
                        const body = new URLSearchParams();
                        body.set('_token', window.csrfToken ? (window.csrfToken() || this.csrf) : this.csrf);
                        body.set('short_name', this.pinTarget);
                        body.set('pin', pin);

                        const res = await fetch(this.pinUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: body.toString(),
                            credentials: 'same-origin',
                        });
                        const data = await res.json().catch(() => ({}));
                        if (res.ok && data.ok) {
                            this.confirmed[this.pinTargetIndex] = this.pinTarget;
                            if (data.expires_at) {
                                this.verifiedMap[this.pinTarget] = parseInt(data.expires_at, 10);
                            }
                            this.pinModalOpen = false;
                            this.pinValue = '';
                        } else {
                            this.pinError = data.error || ('Ошибка ' + res.status);
                        }
                    } catch (e) {
                        this.pinError = 'Ошибка соединения.';
                    }
                    this.pinLoading = false;
                },

                cancelPin() {
                    this.selected[this.pinTargetIndex] = this.confirmed[this.pinTargetIndex] || '';
                    this.pinModalOpen = false;
                    this.pinValue = '';
                    this.pinError = '';
                }
            }));
        });

        (function () {
            var radios = document.querySelectorAll('input[name="type"]');
            var postBlock = document.getElementById('block-post');
            var peripheralBlock = document.getElementById('block-peripheral');

            function sync() {
                var val = document.querySelector('input[name="type"]:checked');
                val = val ? val.value : null;
                if (postBlock) postBlock.classList.toggle('hidden', val !== 'post');
                if (peripheralBlock) peripheralBlock.classList.toggle('hidden', val !== 'peripheral');
            }

            radios.forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        })();
    </script>
</x-app-layout>