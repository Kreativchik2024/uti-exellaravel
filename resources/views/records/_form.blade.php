@csrf
@if(isset($record))
    @method('PUT')
@endif

@php
    $workerFields   = ['worker1','worker2','worker3','worker4','worker5'];
    $hasWorkers     = collect($workerFields)->contains(fn($f) => isset($cfg['fields'][$f]));
    $workerDefaults = $defaults ?? [];
    $employeesList  = $employeesList ?? [];
    $lookups        = $lookups ?? [];
    $formUnlocked   = $formUnlocked ?? (session('form_unlocked_until', 0) > now()->timestamp);
    $unlockUntil    = (int) session('form_unlocked_until', 0);
@endphp

@if($hasWorkers)
    <div class="border rounded p-3 bg-gray-50">
        <div class="flex justify-between items-center mb-2">
            <div class="text-sm font-semibold text-gray-700">
                Исполнители (подставлены из рабочего места)
            </div>
            @if($formUnlocked)
                <span class="text-xs text-green-700">
                    ✓ Разблокировано до {{ \Carbon\Carbon::createFromTimestamp($unlockUntil)->format('H:i') }}
                </span>
            @else
                <button type="button" onclick="openPinUnlock()"
                        class="text-xs text-blue-600 hover:underline">
                    Изменить (нужен ваш ПИН)
                </button>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
            @foreach($workerFields as $name)
                @if(isset($cfg['fields'][$name]))
                    @php
                        $f = $cfg['fields'][$name];
                        $value = old($name, $record->$name ?? ($workerDefaults[$name] ?? ''));
                        $isReq = !empty($f['required_mark']);
                    @endphp
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">
                            {{ $f['label'] }}@if($isReq)<span class="text-red-600">*</span>@endif
                        </label>

                        @if($formUnlocked)
                            <select name="{{ $name }}" @if($isReq) required @endif
                                    class="w-full border rounded px-2 py-1 text-sm">
                                <option value="">— выберите —</option>
                                @foreach($employeesList as $emp)
                                    <option value="{{ $emp }}" @selected($value === $emp)>{{ $emp }}</option>
                                @endforeach
                            </select>
                        @else
                            <select disabled
                                    class="w-full border rounded px-2 py-1 text-sm bg-gray-100 text-gray-600 cursor-not-allowed">
                                <option>{{ $value !== '' ? $value : '— не выбран —' }}</option>
                            </select>
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endif

                        @error($name)
                            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Модалка: разблокировка своим ПИНом --}}
    <div id="pinUnlockModal"
         class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
         onclick="if(event.target===this)closePinUnlock()">
        <div class="bg-white rounded-lg shadow-xl w-72 p-4 space-y-2">
            <h3 class="font-semibold text-base text-gray-800">Изменить исполнителей</h3>
            <p class="text-xs text-gray-600 leading-snug">
                Введите <strong>ваш</strong> ПИН для разблокировки полей (действует 3 часа).
            </p>
            <div>
                <label class="block text-[11px] text-gray-500 mb-1">ПИН (4–6 цифр)</label>
                <input type="password" id="pinUnlockInput" inputmode="numeric" pattern="\d{4,6}"
                       class="border rounded px-2 py-1.5 w-full text-sm"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();submitPinUnlock();}">
            </div>
            <div id="pinUnlockError" class="text-red-600 text-xs hidden"></div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closePinUnlock()"
                        class="px-2.5 py-1 text-xs text-gray-600 hover:text-gray-900">Отмена</button>
                <button type="button" id="pinUnlockBtn" onclick="submitPinUnlock()"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 text-xs rounded">
                    Разблокировать
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var unlockUrl = '{{ route('records.pinUnlock') }}';
        var csrf = '{{ csrf_token() }}';

        window.openPinUnlock = function () {
            var m = document.getElementById('pinUnlockModal');
            var e = document.getElementById('pinUnlockError');
            var i = document.getElementById('pinUnlockInput');
            m.classList.remove('hidden');
            e.classList.add('hidden');
            i.value = '';
            setTimeout(function () { i.focus(); }, 50);
        };

        window.closePinUnlock = function () {
            document.getElementById('pinUnlockModal').classList.add('hidden');
        };

        window.submitPinUnlock = async function () {
            var input = document.getElementById('pinUnlockInput');
            var err   = document.getElementById('pinUnlockError');
            var btn   = document.getElementById('pinUnlockBtn');
            var pin = (input.value || '').trim();

            if (!/^\d{4,6}$/.test(pin)) {
                err.textContent = 'ПИН — 4–6 цифр.';
                err.classList.remove('hidden');
                return;
            }

            err.classList.add('hidden');
            btn.disabled = true;
            btn.textContent = 'Проверка…';

            try {
                var body = new URLSearchParams();
                body.set('_token', csrf);
                body.set('pin', pin);

                var res = await fetch(unlockUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                    credentials: 'same-origin',
                });

                var data = await res.json().catch(function () { return {}; });

                if (res.ok && data.ok) {
                    window.location.reload();
                } else {
                    err.textContent = data.error || ('Ошибка ' + res.status);
                    err.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Разблокировать';
                }
            } catch (e) {
                err.textContent = 'Ошибка соединения.';
                err.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Разблокировать';
            }
        };
    })();
    </script>
@endif

@foreach($cfg['fields'] as $name => $f)
    @if(in_array($name, $workerFields)) @continue @endif

    @php
        $value = old($name, $record->$name ?? ($defaults[$name] ?? ($f['default'] ?? null)));
        $isReq = str_contains($f['rules'] ?? '', 'required') && !str_contains($f['rules'] ?? '', 'nullable');
        $hasLookup = !empty($f['lookup']);
        $lookupData = $lookups[$name] ?? null;
    @endphp
    <div>
        <label class="block text-sm text-gray-600 mb-1">
            {{ $f['label'] }}@if($isReq)<span class="text-red-600">*</span>@endif
        </label>

        @if($hasLookup)
            <div class="lookup-result mb-1">
                @if($lookupData)
                    @php
                        $isGreen = ($lookupData['health'] ?? 'green') === 'green';
                        $cls = $isGreen ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800';
                    @endphp
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border {{ $cls }} text-xs font-medium">
                        <span>{{ $isGreen ? '✓' : '✗' }}</span>
                        <span>{{ $lookupData['worker1'] ?? '—' }}</span>
                        <span class="opacity-50">·</span>
                        <span>{{ $lookupData['record_date'] ?? '—' }}</span>
                        <span class="opacity-50">·</span>
                        <span>{{ $lookupData['status_text'] ?? '' }}</span>
                    </div>
                @endif
            </div>

            <input type="text" name="{{ $name }}"
                   value="{{ $value }}"
                   @if($isReq) required @endif
                   data-lookup-field="{{ $name }}"
                   data-lookup-url="{{ route('records.lookup', $sheet) }}"
                   class="lookup-input w-full border rounded px-3 py-2">
        @elseif(($f['type'] ?? 'text') === 'textarea')
            <textarea name="{{ $name }}" rows="3" @if($isReq) required @endif
                      class="w-full border rounded px-3 py-2">{{ $value }}</textarea>
        @elseif(($f['type'] ?? 'text') === 'select')
            <select name="{{ $name }}" @if($isReq) required @endif class="w-full border rounded px-3 py-2">
                <option value="">— выберите —</option>
                @foreach($f['options'] as $optKey => $optVal)
                    @php
                        $optValue = is_string($optKey) ? $optKey : $optVal;
                        $optLabel = is_string($optKey) ? $optVal : $optVal;
                    @endphp
                    <option value="{{ $optValue }}" @selected((string)$value === (string)$optValue)>{{ $optLabel }}</option>
                @endforeach
            </select>
        @elseif(($f['type'] ?? 'text') === 'date')
            <input type="date" name="{{ $name }}"
                   value="{{ $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : \Illuminate\Support\Carbon::now()->format('Y-m-d') }}"
                   @if($isReq) required @endif class="w-full border rounded px-3 py-2">
        @elseif(($f['type'] ?? 'text') === 'time')
            <input type="time" name="{{ $name }}"
                   value="{{ $value ? \Carbon\Carbon::parse($value)->format('H:i') : '' }}"
                   @if($isReq) required @endif class="w-full border rounded px-3 py-2">
        @elseif(($f['type'] ?? 'text') === 'number')
            <input type="number" name="{{ $name }}" value="{{ $value }}"
                   @if($isReq) required @endif class="w-full border rounded px-3 py-2">
        @else
            <input type="text" name="{{ $name }}" value="{{ $value }}"
                   @if($isReq) required @endif class="w-full border rounded px-3 py-2">
        @endif

        @error($name)
            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
        @enderror
    </div>
@endforeach

<script>
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    document.querySelectorAll('.lookup-input').forEach(function (input) {
        var timer = null;
        var resultEl = input.parentElement.querySelector('.lookup-result');

        function render(data) {
            if (!data || !data.found) {
                resultEl.innerHTML = '<div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border bg-gray-50 border-gray-200 text-xs text-gray-500">Не найдено в таблице</div>';
                return;
            }
            var isGreen = data.health === 'green';
            var cls = isGreen ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800';
            var icon = isGreen ? '✓' : '✗';
            resultEl.innerHTML =
                '<div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border ' + cls + ' text-xs font-medium">'
                + '<span>' + icon + '</span>'
                + '<span>' + esc(data.worker1 || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.record_date || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.status_text || '') + '</span>'
                + '</div>';
        }

        function doLookup() {
            var val = input.value.trim();
            if (val === '') { resultEl.innerHTML = ''; return; }
            var url = input.dataset.lookupUrl + '?field=' + encodeURIComponent(input.dataset.lookupField) + '&value=' + encodeURIComponent(val);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { render(data); })
                .catch(function () { resultEl.innerHTML = ''; });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(doLookup, 400);
        });
    });
});
</script>