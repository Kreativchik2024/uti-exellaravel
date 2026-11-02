<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Кто работает за рабочим местом?</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-6">

            <p class="text-sm text-gray-600 mb-6">
                Выберите до 5 исполнителей (можно ни одного — тогда нажмите «Пропустить»).
                Если работаете на прогоне бортов — укажите, где вы находитесь: на посту (1–30) или на периферии (1–50).
            </p>

            <form method="POST" action="{{ route('workstation.store') }}">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">

                @php
                    $slots = old('workers', $selected ?: []);
                    $slots = array_pad(array_values($slots), 5, '');
                    $type  = old('type', $workplaceType);
                @endphp

                {{-- Исполнители + PIN-модалка --}}
                <div x-data="workstationWorkers()" x-init="init()" x-destroy="destroy()">
                    <div class="text-sm font-semibold text-gray-700 mb-2">Исполнители</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
                        @for($i = 0; $i < 5; $i++)
                            @php $current = $slots[$i] ?? ''; @endphp
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">
                                    Исполнитель {{ $i + 1 }}
                                </label>
                                <select name="workers[{{ $i }}]"
                                        x-model="selected[{{ $i }}]"
                                        @change="onChange({{ $i }})"
                                        class="w-full border rounded px-2 py-1 text-sm">
                                    <option value="">— не выбран —</option>
                                    <template x-for="emp in availableFor({{ $i }})" :key="emp">
                                        <option :value="emp" 
                                                x-text="emp + (withoutPin.includes(emp) ? ' (нет ПИН)' : '')" 
                                                :selected="emp === selected[{{ $i }}]"
                                                :disabled="withoutPin.includes(emp) && !canManageWorkers"></option>
                                    </template>
                                </select>
                                
                                {{-- Блок для таймера ПИН --}}
                                <div class="mt-1 h-5" x-show="selected[{{ $i }}] && verifiedMap[selected[{{ $i }}]]" x-html="getTimerText(selected[{{ $i }}])"></div>
                            </div>
                        @endfor
                    </div>

                    @if($errors->any())
                        <div class="bg-red-100 text-red-800 p-3 rounded mt-4">
                            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                        </div>
                    @endif

                    {{-- PIN-модалка --}}
                    <div x-show="pinModalOpen" x-cloak
                         class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
                         @click.self="cancelPin()">
                        <div class="bg-white rounded-lg shadow-xl w-72 p-4 space-y-2">
                            <h3 class="font-semibold text-base text-gray-800">ПИН сотрудника</h3>
                            <p class="text-xs text-gray-600 leading-snug">
                                Подтвердите выбор:<br>
                                <strong x-text="pinTarget"></strong>
                            </p>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-1">ПИН (4–6 цифр)</label>
                                <input type="password" x-model="pinValue" x-ref="pinInput"
                                       inputmode="numeric" pattern="\d{4,6}"
                                       @keydown.enter.prevent="submitPin()"
                                       class="border rounded px-2 py-1.5 w-full text-sm">
                            </div>
                            <div x-show="pinError" x-text="pinError" class="text-red-600 text-xs"></div>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="cancelPin()"
                                        class="px-2.5 py-1 text-xs text-gray-600 hover:text-gray-900">
                                    Отмена
                                </button>
                                <button type="button" @click="submitPin()" :disabled="pinLoading"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 text-xs rounded disabled:opacity-50"
                                        x-text="pinLoading ? 'Проверка…' : 'Подтвердить'"></button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Тип рабочего места --}}
                <div class="text-sm font-semibold text-gray-700 mt-6 mb-2">Рабочее место</div>

                <div class="flex items-center gap-6 mb-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="post" id="type-post"
                               class="text-blue-600 focus:ring-blue-500"
                               @checked($type === 'post')>
                        <span class="text-sm text-gray-700">Пост</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="peripheral" id="type-peripheral"
                               class="text-blue-600 focus:ring-blue-500"
                               @checked($type === 'peripheral')>
                        <span class="text-sm text-gray-700">Периферия</span>
                    </label>
                </div>

                <div id="block-post" class="{{ $type === 'post' ? '' : 'hidden' }} mb-3">
                    <label class="block text-xs text-gray-600 mb-1">Пост (1–30)</label>
                    <select name="post" class="border rounded px-3 py-2 w-full bg-white">
                        <option value="">— не выбран —</option>
                        @foreach($posts as $p)
                            <option value="{{ $p }}" @selected((string) old('post', $selectedPost) === (string) $p)>
                                Пост {{ $p }}
                            </option>
                        @endforeach
                    </select>
                    @error('post')
                        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div id="block-peripheral" class="{{ $type === 'peripheral' ? '' : 'hidden' }} mb-3">
                    <label class="block text-xs text-gray-600 mb-1">Периферия (1–50)</label>
                    <select name="peripheral" class="border rounded px-3 py-2 w-full bg-white">
                        <option value="">— не выбрана —</option>
                        @foreach($peripherals as $p)
                            <option value="{{ $p }}" @selected((string) old('peripheral', $selectedPeripheral) === (string) $p)>
                                Периферия {{ $p }}
                            </option>
                        @endforeach
                    </select>
                    @error('peripheral')
                        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3 mt-6">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">
                        Продолжить
                    </button>
                    <button type="submit"
                            formaction="{{ route('workstation.skip') }}"
                            formmethod="POST"
                            class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded">
                        Пропустить (без исполнителей)
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('workstationWorkers', () => ({
                all: @json($employeesList),
                withoutPin: @json($employeesWithoutPin ?? []),
                canManageWorkers: @json($canManageWorkers ?? false),
                verifiedMap: @json($globalVerifiedWorkersMap ?? []),
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
                    
                    // Каждую секунду увеличиваем tick → Alpine перерисует x-html у таймеров
                    this.timerInterval = setInterval(() => {
                        this.tick++;
                    }, 1000);

                    for (let i = 0; i < 5; i++) {
                        if (this.selected[i] && this.verifiedMap[this.selected[i]]) {
                            this.confirmed[i] = this.selected[i];
                        }
                    }
                },

                destroy() {
                    if (this.timerInterval) clearInterval(this.timerInterval);
                },

                nowSeconds() {
                    return (Date.now() + this.clockOffsetMs) / 1000;
                },

                getTimerText(name) {
                    // Доступ к this.tick делает метод зависимым от реактивного свойства
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

                    if (this.withoutPin.includes(newVal) && !this.canManageWorkers) {
                        this.selected[index] = this.confirmed[index] || '';
                        alert('У сотрудника нет ПИН-кода. Выберите другого.');
                        return;
                    }

                    for (let i = 0; i < 5; i++) {
                        if (i !== index && this.selected[i] === newVal) {
                            this.selected[i] = '';
                            this.confirmed[i] = '';
                        }
                    }

                    if (this.confirmed[index] === newVal) return;

                    if (this.verifiedMap[newVal] && this.verifiedMap[newVal] > this.nowSeconds()) {
                        this.confirmed[index] = newVal;
                        return;
                    }

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
                        body.set('_token', this.csrf);
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