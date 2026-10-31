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
    $canEditWorkers = $canEditWorkers ?? (auth()->check() && auth()->user()->isAdmin());
@endphp

@if($hasWorkers)
    <div class="border rounded p-3 bg-gray-50">
        <div class="flex justify-between items-center mb-2">
            <div class="text-sm font-semibold text-gray-700">
                Исполнители (подставлены из рабочего места)
            </div>
            @if($canEditWorkers)
                <span class="text-xs text-blue-700">Вы админ — можете менять</span>
            @else
                <span class="text-xs text-gray-500">Изменить может только администратор</span>
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

                        @if($canEditWorkers)
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