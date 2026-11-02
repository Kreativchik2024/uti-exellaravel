@csrf
@if(isset($record))
    @method('PUT')
@endif

@php
    $workerFields          = ['worker1','worker2','worker3','worker4','worker5'];
    $hasWorkers            = collect($workerFields)->contains(fn($f) => isset($cfg['fields'][$f]));
    $workerDefaults        = $defaults ?? [];
    $employeesList         = $employeesList ?? [];
    $lookups               = $lookups ?? [];
    $isTimeLocked          = $isTimeLocked ?? false;
    $canManageWorkers      = $canManageWorkers ?? false;
    $originalWorkers       = $originalWorkers ?? [];
    $employeesWithoutPin   = $employeesWithoutPin ?? []; // <--- ДОБАВЛЕНО

    $groupDefinitions = [
        'main' => [
            'title'    => 'Основное',
            'subtitle' => 'Дата, пост, борт и рабочее время',
            'icon'     => 'calendar',
            'fields'   => [
                'record_date', 'post_number', 'peripheral_number',
                'board_number', 'ip_address', 'fuselage_number', 'frame_serial',
                'fuselage_no', 'fuselage_serial',
                'work_start', 'work_end',
                'qty', 'seq',
            ],
        ],
        'equipment' => [
            'title'    => 'Оборудование',
            'subtitle' => 'Кросс 3, кронштейн, манет и другие устройства',
            'icon'     => 'chip',
            'fields'   => [
                'cross3_number', 'bracket_serial', 'manet_number',
                'serial_number', 'pc_version', 'ahrs', 'compass_version',
                'device_type', 'runs_count',
            ],
        ],
        'status' => [
            'title'    => 'Статус и дефекты',
            'subtitle' => 'Результаты проверки и обнаруженные проблемы',
            'icon'     => 'shield',
            'fields'   => [
                'status', 'camera_status', 'night_camera_status',
                'speed_sensor_status', 'suspension_status',
                'is_working', 'is_repaired', 'stand_test', 'barometer_status',
                'defect', 'defect_reason', 'fault_code',
                'reported_defect', 'found_defect', 'rejected_by',
                'analysis_actions', 'result', 'actions_taken', 'work_done',
            ],
        ],
        'notes' => [
            'title'    => 'Примечания',
            'subtitle' => 'Дополнительная информация',
            'icon'     => 'note',
            'fields'   => ['notes', 'comments'],
        ],
    ];

    $groupedFields = [];
    $usedFields    = [];

    foreach ($groupDefinitions as $key => $def) {
        $found = [];
        foreach ($def['fields'] as $fn) {
            if (isset($cfg['fields'][$fn]) && !in_array($fn, $workerFields, true)) {
                $found[]      = $fn;
                $usedFields[] = $fn;
            }
        }
        if (!empty($found)) {
            $groupedFields[$key] = array_merge($def, ['fields' => $found]);
        }
    }

    $otherFields = [];
    foreach ($cfg['fields'] as $fn => $fc) {
        if (in_array($fn, $workerFields, true)) continue;
        if (in_array($fn, $usedFields, true)) continue;
        $otherFields[] = $fn;
    }
    if (!empty($otherFields)) {
        $groupedFields['other'] = [
            'title'    => 'Дополнительно',
            'subtitle' => null,
            'icon'     => 'dot',
            'fields'   => $otherFields,
        ];
    }
@endphp

@if($isTimeLocked)
    <div class="mx-6 mt-6 sm:mx-8 sm:mt-8 bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl flex items-start gap-3">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        <div>
            <b class="block text-sm font-semibold">Редактирование заблокировано</b>
            <span class="text-xs">С момента создания записи прошло более 24 часов. Данные доступны только для просмотра.</span>
        </div>
    </div>
@endif

<div class="p-6 sm:p-8">
    {{-- Исполнители (наверху) --}}
    @if($hasWorkers)
        <div class="mb-8">
            <div class="flex items-start gap-3 mb-4">
                <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-gray-900 leading-tight">Исполнители</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        @if($canManageWorkers)
                            До 5 сотрудников. Без повторов.
                        @else
                            Себя — без ПИН. Другого — с его личным ПИН (действует 3 часа).
                        @endif
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 {{ $isTimeLocked ? 'opacity-75' : '' }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    @foreach($workerFields as $name)
                        @if(isset($cfg['fields'][$name]))
                            @php
                                $f     = $cfg['fields'][$name];
                                $value = old($name, $record->$name ?? ($workerDefaults[$name] ?? ''));
                                $isReq = !empty($f['required_mark']);
                            @endphp
                            <div class="worker-cell">
                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                    {{ $f['label'] }}@if($isReq && !$isTimeLocked)<span class="text-red-600">*</span>@endif
                                </label>

                                <select name="{{ $name }}"
                                        data-worker-field="{{ $name }}"
                                        data-original="{{ $originalWorkers[$name] ?? '' }}"
                                        @if($isReq) required @endif
                                        @if($isTimeLocked) disabled @endif
                                        class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 hover:border-gray-300">
                                    <option value="">— выберите —</option>
                                    @foreach($employeesList as $emp)
                                        @php $noPin = in_array($emp, $employeesWithoutPin, true); @endphp
                                        <option value="{{ $emp }}" @selected($value === $emp) @if($noPin) disabled @endif>
                                            {{ $emp }}{{ $noPin ? ' (нет ПИН)' : '' }}
                                        </option>
                                    @endforeach
                                </select>

                                <div class="worker-pin-badge mt-1.5"></div>

                                @error($name)
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Секции полей --}}
    @foreach($groupedFields as $key => $group)
        <div class="pt-8 first:pt-0">
            <div class="flex items-start gap-3 mb-5">
                <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                    @switch($group['icon'])
                        @case('calendar')
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            @break
                        @case('chip')
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                            </svg>
                            @break
                        @case('shield')
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            @break
                        @case('note')
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            @break
                        @case('users')
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            @break
                        @default
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                    @endswitch
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-gray-900 leading-tight">{{ $group['title'] }}</h3>
                    @if(!empty($group['subtitle']))
                        <p class="text-xs text-gray-500 mt-0.5">{{ $group['subtitle'] }}</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($group['fields'] as $name)
                    @php
                        $f = $cfg['fields'][$name];
                        $value = old($name, $record->{$name} ?? ($defaults[$name] ?? ($f['default'] ?? null)));
                        $isReq = str_contains($f['rules'] ?? '', 'required') && !str_contains($f['rules'] ?? '', 'nullable');
                        $hasLookup  = !empty($f['lookup']);
                        $lookupData = $lookups[$name] ?? null;
                        $label = $f['label'] ?? $name;
                        $type  = $f['type'] ?? 'text';

                        $baseInput = 'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition-all duration-150 '
                                   . 'focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 '
                                   . 'border-gray-200 hover:border-gray-300';

                        if ($isTimeLocked) {
                            $baseInput = 'block w-full rounded-lg border bg-gray-50 px-3.5 py-2.5 text-sm text-gray-500 cursor-not-allowed border-gray-200';
                        }
                    @endphp

                    <div class="{{ $isTimeLocked ? 'opacity-80' : '' }}">
                        <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-1.5">
                            {{ $label }}
                            @if($isReq && !$isTimeLocked)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-red-50 text-red-600 text-[10px] font-semibold uppercase tracking-wide">Обязательно</span>
                            @endif
                        </label>

                        @if($hasLookup)
                            <div class="lookup-result mb-1.5">
                                @if($lookupData)
                                    @php
                                        $isGreen = ($lookupData['health'] ?? 'green') === 'green';
                                        $cls = $isGreen
                                            ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                                            : 'bg-red-50 border-red-200 text-red-800';
                                        $icon = $isGreen ? '✓' : '✗';
                                    @endphp
                                    <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border {{ $cls }} text-xs font-medium">
                                        <span class="font-bold">{{ $icon }}</span>
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
                                   @if($isTimeLocked) disabled @endif
                                   data-lookup-field="{{ $name }}"
                                   data-lookup-url="{{ route('records.lookup', $sheet) }}"
                                   placeholder="Введите {{ mb_strtolower($label) }}…"
                                   class="lookup-input {{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

                        @elseif($type === 'textarea')
                            <textarea name="{{ $name }}" rows="3"
                                      @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                      placeholder="{{ $f['placeholder'] ?? '' }}"
                                      class="{{ $baseInput }} resize-none @error($name) !border-red-400 !ring-red-500/15 @enderror">{{ $value }}</textarea>

                        @elseif($type === 'select')
                            <select name="{{ $name }}" @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                    class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
                                <option value="">— выберите —</option>
                                @foreach(($f['options'] ?? []) as $optKey => $optVal)
                                    @php
                                        $optValue = is_string($optKey) ? $optKey : $optVal;
                                        $optLabel = is_string($optKey) ? $optVal : $optVal;
                                    @endphp
                                    <option value="{{ $optValue }}" @selected((string)$value === (string)$optValue)>{{ $optLabel }}</option>
                                @endforeach
                            </select>

                        @elseif($type === 'date')
                            <input type="date" name="{{ $name }}"
                                   value="{{ $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : \Illuminate\Support\Carbon::now()->format('Y-m-d') }}"
                                   @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                   class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

                        @elseif($type === 'time')
                            <input type="time" name="{{ $name }}"
                                   value="{{ $value ? \Carbon\Carbon::parse($value)->format('H:i') : '' }}"
                                   @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                   class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

                        @elseif($type === 'number')
                            <input type="number" name="{{ $name }}" value="{{ $value }}"
                                   @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                   class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

                        @else
                            <input type="text" name="{{ $name }}" value="{{ $value }}"
                                   @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                   placeholder="{{ $f['placeholder'] ?? '' }}"
                                   class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
                        @endif

                        @error($name)
                            <p class="mt-1.5 text-xs text-red-600 flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Панель кнопок --}}
    <div class="mt-8 pt-6 border-t border-gray-200 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('records.index', $sheet) }}"
           class="inline-flex items-center gap-1.5 px-4 py-2.5 text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg font-medium text-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Назад
        </a>
        @if(!$isTimeLocked)
            <button type="submit"
                    class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-lg shadow transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                {{ isset($record) ? 'Сохранить изменения' : 'Создать запись' }}
            </button>
        @endif
    </div>
</div>

{{-- Lookup-скрипт --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    document.querySelectorAll('.lookup-input:not([disabled])').forEach(function (input) {
        var timer = null;
        var resultEl = input.parentElement.querySelector('.lookup-result');
        if (!resultEl) return;

        function render(data) {
            if (!data || !data.found) {
                resultEl.innerHTML = '<div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border bg-gray-50 border-gray-200 text-xs text-gray-500">Не найдено в таблице</div>';
                return;
            }
            var isGreen = data.health === 'green';
            var cls = isGreen ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800';
            var icon = isGreen ? '✓' : '✗';
            resultEl.innerHTML =
                '<div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border ' + cls + ' text-xs font-medium">'
                + '<span class="font-bold">' + icon + '</span>'
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