@csrf
@if(isset($record))
    @method('PUT')
@endif

@include('records._draft')

@unless(isset($record))
    {{-- Одноразовый номер формы: повторная отправка той же формы не создаст вторую запись --}}
    <input type="hidden" name="_submit_token" value="{{ old('_submit_token', (string) \Illuminate\Support\Str::uuid()) }}">
@endunless

@php
    $workerFields          = ['worker1','worker2','worker3','worker4','worker5'];
    $hasWorkers            = collect($workerFields)->contains(fn($f) => isset($cfg['fields'][$f]));
    $workerDefaults        = $defaults ?? [];
    $employeesList         = $employeesList ?? [];
    $lookups               = $lookups ?? [];
    $isTimeLocked          = $isTimeLocked ?? false;
    $canManageWorkers      = $canManageWorkers ?? false;
    $originalWorkers       = $originalWorkers ?? [];
    $employeesWithoutPin   = $employeesWithoutPin ?? [];

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
                'qty', 'flashed_with_day', 'seq',
            ],
        ],
        'equipment' => [
            'title'    => 'Оборудование',
            'subtitle' => 'Кросс 3, кронштейн, манет и другие устройства',
            'icon'     => 'chip',
            'fields'   => [
                'cross3_number', 'bracket_serial', 'manet_number',
                'serial_number', 'camera_serial', 'assembled_by', 'pc_version', 'ahrs', 'compass_version',
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

    $comboOptions = $comboOptions ?? [];

    // Вкладка может задать собственную раскладку ('layout' в config/sheets.php)
    if (!empty($cfg['layout'])) {
        $groupDefinitions = [];
        foreach ($cfg['layout'] as $i => $def) {
            $groupDefinitions['layout_' . $i] = $def + ['subtitle' => null, 'icon' => 'dot'];
        }
    }

    // Текущее значение поля (с учётом old() после ошибки валидации)
    $currentValue = fn (string $field) => old($field, isset($record) ? $record->{$field} : ($defaults[$field] ?? null));

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

<div class="record-form-card">
    {{-- Исполнители (наверху) --}}
    @if($hasWorkers)
        <div class="mb-8">
            <div class="flex items-start gap-3 mb-4">
                <div class="ui-icon-tile ui-icon-tile--sm">
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
                                        class="tap-44 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 hover:border-gray-300">
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
        @php
            // Блок, который появляется только после заполнения поля (например, статуса)
            $showWhen   = $group['show_when_filled'] ?? null;
            $groupShown = !$showWhen || filled($currentValue($showWhen));
        @endphp
        <div class="pt-8 first:pt-0"
             @if($showWhen) data-show-when-filled="{{ $showWhen }}" @endif
             @unless($groupShown) hidden @endunless>
            <div class="flex items-start gap-3 mb-5">
                <div class="ui-icon-tile ui-icon-tile--sm">
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
                        // rules бывают строкой ('required|string') или массивом (когда внутри regex с «|»)
                        $ruleList = is_array($f['rules'] ?? null) ? $f['rules'] : explode('|', (string) ($f['rules'] ?? ''));
                        $isReq = in_array('required', $ruleList, true) && !in_array('nullable', $ruleList, true);

                        // Обязательность по значению другого поля: 'required_when' => ['status' => 'Не сдан']
                        // и обратное: 'required_unless' => ['status' => 'Не сдан'] — обязательно, кроме этого значения
                        $reqWhenField = $reqWhenValue = null;
                        $reqUnless = !empty($f['required_unless']);
                        $reqCond = $f['required_when'] ?? ($f['required_unless'] ?? null);
                        if (!empty($reqCond)) {
                            $reqWhenField = array_key_first($reqCond);
                            $reqWhenValue = (string) $reqCond[$reqWhenField];
                            $isReq = ((string) $currentValue($reqWhenField) === $reqWhenValue) !== $reqUnless;
                        }
                        $reqAttrs = $reqWhenField
                            ? 'data-required-when-field="'.e($reqWhenField).'" data-required-when-value="'.e($reqWhenValue).'"'
                              . ($reqUnless ? ' data-required-unless="1"' : '')
                            : '';
                        $hasLookup  = !empty($f['lookup']);
                        $lookupData = $lookups[$name] ?? null;
                        $label = $f['label'] ?? $name;
                        $type  = $f['type'] ?? 'text';

                        $baseInput = 'tap-44 block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition-all duration-150 '
                                   . 'focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 '
                                   . 'border-gray-200 hover:border-gray-300';

                        if ($isTimeLocked) {
                            $baseInput = 'tap-44 block w-full rounded-lg border bg-gray-50 px-3.5 py-2.5 text-sm text-gray-500 cursor-not-allowed border-gray-200';
                        }
                    @endphp

                    <div class="{{ $isTimeLocked ? 'opacity-80' : '' }} {{ $type === 'textarea' && !empty($cfg['layout']) ? 'md:col-span-2' : '' }}"
                         {!! $reqAttrs !!}>
                        <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-1.5">
                            {{ $label }}
                            @if(!$isTimeLocked && ($isReq || $reqWhenField))
                                <span data-required-badge class="ui-req" title="Обязательное поле"
                                      @unless($isReq) hidden @endunless>*<span class="sr-only"> обязательно</span></span>
                            @endif
                        </label>

                        @if($type === 'ip_suffix')
                            @php
                                // Маска IP: фиксированный префикс + последний октет
                                $ipPrefix = $f['prefix'] ?? '';
                                $ipFull   = trim((string) $value);
                                $ipSuffix = str_starts_with($ipFull, $ipPrefix) ? substr($ipFull, strlen($ipPrefix)) : '';
                                $ipOther  = $ipFull !== '' && $ipSuffix === '' && !ctype_digit($ipFull) ? $ipFull : null;
                                if ($ipSuffix === '' && ctype_digit($ipFull)) $ipSuffix = $ipFull;
                            @endphp
                            <div class="ip-mask flex items-stretch rounded-lg shadow-sm @error($name) ip-mask--error @enderror">
                                <span class="ip-mask__prefix inline-flex items-center px-3.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-sm font-mono text-gray-500 select-none">{{ $ipPrefix }}</span>
                                <input type="text" name="{{ $name }}" value="{{ $ipSuffix }}"
                                       inputmode="numeric" maxlength="3" autocomplete="off"
                                       pattern="25[0-4]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]?"
                                       title="Число от 1 до 254"
                                       data-ip-suffix
                                       @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                       placeholder="1–254"
                                       class="{{ $baseInput }} !rounded-l-none font-mono @error($name) !border-red-400 !ring-red-500/15 @enderror">
                            </div>
                            @if($ipOther)
                                <p class="mt-1 text-xs text-amber-700">Прежнее значение: <span class="font-mono">{{ $ipOther }}</span> — введите новое число</p>
                            @endif

                        @elseif($type === 'checkbox')
                            {{-- Галочка: скрытый «0» уходит, если галочку не поставили --}}
                            <input type="hidden" name="{{ $name }}" value="0" @if($isTimeLocked) disabled @endif>
                            <label class="tap-44 flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm transition hover:border-gray-300 {{ $isTimeLocked ? 'cursor-not-allowed bg-gray-50' : 'cursor-pointer' }}">
                                <input type="checkbox" name="{{ $name }}" value="1"
                                       @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN)) @if($isTimeLocked) disabled @endif
                                       class="h-5 w-5 flex-shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500/30">
                                <span class="text-sm text-gray-900">Да</span>
                            </label>

                        @elseif($type === 'combo' && $name === 'fault_code')
                            @include('records._fault_code', ['value' => $value, 'options' => $comboOptions[$name] ?? [], 'isReq' => $isReq, 'baseInput' => $baseInput])

                        @elseif($type === 'combo')
                            <input type="text" name="{{ $name }}" value="{{ $value }}"
                                   list="combo-{{ $name }}" autocomplete="off"
                                   @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                   placeholder="{{ $f['placeholder'] ?? '' }}"
                                   class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
                            <datalist id="combo-{{ $name }}">
                                @foreach($comboOptions[$name] ?? [] as $opt => $optLabel)
                                    <option value="{{ $opt }}">{{ $optLabel }}</option>
                                @endforeach
                            </datalist>

                        @elseif($hasLookup)
                            <div class="lookup-result mb-1.5">
                                @if($lookupData)
                                    @php
                                        $isGreen = ($lookupData['health'] ?? 'green') === 'green';
                                        $cls = $isGreen
                                            ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                                            : 'bg-red-50 border-red-200 text-red-800';
                                        $icon = $isGreen ? '✓' : '✗';
                                    @endphp
                                    <div class="inline-flex flex-wrap items-center gap-2 px-2.5 py-1 rounded-md border {{ $cls }} text-xs font-medium">
                                        <span class="font-bold">{{ $icon }}</span>
                                        @if(!empty($lookupData['caption']))<span class="opacity-70">{{ $lookupData['caption'] }}:</span>@endif
                                        <span>{{ $lookupData['workers'] ?? $lookupData['worker1'] ?? '—' }}</span>
                                        <span class="opacity-50">·</span>
                                        <span>{{ $lookupData['record_date'] ?? '—' }}</span>
                                        <span class="opacity-50">·</span>
                                        <span>{{ $lookupData['status_text'] ?? '' }}</span>
                                    </div>
                                    @foreach($lookupData['details'] ?? [] as $d)
                                        <div class="mt-1 text-xs" style="color: var(--ui-text-2)"><span class="opacity-70">{{ $d['label'] }}:</span> {{ $d['value'] }}</div>
                                    @endforeach
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
                            @php if (is_bool($value)) $value = (int) $value; // поле «Да/Нет» хранится как true/false @endphp
                            <select name="{{ $name }}" @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                                    class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
                                <option value="">— выберите —</option>
                                @foreach(($f['options'] ?? []) as $optKey => $optVal)
                                    @php
                                        // у поля «Да/Нет» ('bool'=>true) ключи 1/0 — PHP делает их числами, поэтому отдельно
                                        $optValue = (is_string($optKey) || !empty($f['bool'])) ? $optKey : $optVal;
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
                            @php
                                // 'default_now': пустое поле получает время открытия формы (время сервера)
                                if (!$value && !empty($f['default_now']) && !$isTimeLocked) {
                                    $value = now()->format('H:i');
                                }
                            @endphp
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

                        @if(!empty($f['hint']))
                            <p class="mt-1 text-xs text-gray-500">{{ $f['hint'] }}</p>
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

    {{-- Панель кнопок (на мобильных — в столбик, кнопки на всю ширину) --}}
    <div class="form-actions mt-8 pt-6 border-t border-gray-200">
        <a href="{{ route('records.index', $sheet) }}"
           class="tap-44 inline-flex items-center gap-1.5 px-4 py-2.5 text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg font-medium text-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Назад
        </a>
        @if(!$isTimeLocked)
            <button type="submit"
                    class="tap-44 inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-lg shadow transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                {{ isset($record) ? 'Сохранить изменения' : 'Создать запись' }}
            </button>
        @endif
    </div>
</div>

{{-- Скрипты --}}
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
                resultEl.innerHTML = '<div class="inline-flex flex-wrap items-center gap-2 px-2.5 py-1 rounded-md border bg-gray-50 border-gray-200 text-xs text-gray-500">Не найдено в таблице</div>';
                return;
            }
            var isGreen = data.health === 'green';
            var cls = isGreen ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800';
            var icon = isGreen ? '✓' : '✗';
            resultEl.innerHTML =
                '<div class="inline-flex flex-wrap items-center gap-2 px-2.5 py-1 rounded-md border ' + cls + ' text-xs font-medium">'
                + '<span class="font-bold">' + icon + '</span>'
                + (data.caption ? '<span class="opacity-70">' + esc(data.caption) + ':</span>' : '')
                + '<span>' + esc(data.workers || data.worker1 || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.record_date || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.status_text || '') + '</span>'
                + '</div>'
                + (data.details || []).map(function (d) {
                    return '<div class="mt-1 text-xs" style="color: var(--ui-text-2)"><span class="opacity-70">'
                        + esc(d.label) + ':</span> ' + esc(d.value) + '</div>';
                }).join('');
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

    // --- МАСКА IP: в поле последнего октета — только цифры ---
    document.querySelectorAll('input[data-ip-suffix]').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var clean = inp.value.replace(/\D+/g, '').slice(0, 3);
            if (clean !== inp.value) inp.value = clean;
        });
    });

    // --- ЗАВИСИМЫЕ ПОЛЯ: блок «показать после заполнения» и «обязательно, если …» ---
    (function () {
        var form = document.querySelector('.record-form-card') && document.querySelector('.record-form-card').closest('form');
        if (!form) return;

        function valueOf(field) {
            var el = form.querySelector('[name="' + field + '"]');
            return el ? String(el.value || '').trim() : '';
        }

        function sync() {
            form.querySelectorAll('[data-show-when-filled]').forEach(function (block) {
                block.hidden = valueOf(block.dataset.showWhenFilled) === '';
            });

            form.querySelectorAll('[data-required-when-field]').forEach(function (wrap) {
                var on = valueOf(wrap.dataset.requiredWhenField) === wrap.dataset.requiredWhenValue;
                if (wrap.dataset.requiredUnless) on = !on;   // «обязательно, кроме этого значения»
                var input = wrap.querySelector('input[name], select[name], textarea[name]');
                if (input && !input.disabled) input.required = on;
                var badge = wrap.querySelector('[data-required-badge]');
                if (badge) badge.hidden = !on;
            });
        }

        var watched = {};
        form.querySelectorAll('[data-show-when-filled]').forEach(function (b) { watched[b.dataset.showWhenFilled] = true; });
        form.querySelectorAll('[data-required-when-field]').forEach(function (w) { watched[w.dataset.requiredWhenField] = true; });
        Object.keys(watched).forEach(function (field) {
            var el = form.querySelector('[name="' + field + '"]');
            if (el) { el.addEventListener('change', sync); el.addEventListener('input', sync); }
        });
        sync();
    })();

    // --- ПРОВЕРКА НА ДУБЛИКАТЫ (для всех пользователей) ---
    var workerSelects = document.querySelectorAll('select[data-worker-field]');
    workerSelects.forEach(function (select) {
        select.dataset.prevValue = select.value;
        select.addEventListener('change', function () {
            var val = select.value;
            if (!val) {
                select.dataset.prevValue = '';
                return;
            }
            
            var isDup = false;
            workerSelects.forEach(function (other) {
                if (other !== select && other.value === val) {
                    isDup = true;
                }
            });

            if (isDup) {
                select.value = select.dataset.prevValue || '';
                var cell = select.closest('.worker-cell');
                var badge = cell ? cell.querySelector('.worker-pin-badge') : null;
                if (badge) {
                    badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">✗ Уже выбран</span>';
                    setTimeout(function(){ badge.innerHTML = ''; }, 2500);
                } else {
                    alert('Этот исполнитель уже выбран в другом поле.');
                }
            } else {
                select.dataset.prevValue = val;
            }
        });
    });
});
</script>
@php
    // Поля с автоопределением (например, «Тип борта» по номеру борта): правила — в config/sheets.php → 'autodetect'
    $autodetect = [];
    foreach ($cfg['fields'] as $adName => $adField) {
        $ad = $adField['autodetect'] ?? null;
        if (!$ad || empty($ad['using']) || !method_exists($ad['using'], 'patterns')) continue;
        $autodetect[] = [
            'field'      => $adName,
            'from'       => $ad['from'],
            'patterns'   => $ad['using']::patterns(),
            'homoglyphs' => method_exists($ad['using'], 'homoglyphs') ? $ad['using']::homoglyphs() : [],
        ];
    }
@endphp
@if($autodetect)
<script>
(function () {
    // Пока поле не выбрано вручную — подставляем значение по другому полю (тип борта по номеру)
    var rules = @json($autodetect);
    document.addEventListener('DOMContentLoaded', function () {
        rules.forEach(function (rule) {
            var target = document.querySelector('[name="' + rule.field + '"]');
            var source = document.querySelector('[name="' + rule.from + '"]');
            if (!target || !source || target.disabled) return;
            var auto = !target.value;               // пользователь сам выбрал — не трогаем
            target.addEventListener('change', function () { auto = !target.value; });
            var detect = function () {
                if (!auto) return;
                var v = String(source.value || '').toUpperCase().replace(/./g, function (c) { return rule.homoglyphs[c] || c; });
                var found = '';
                for (var i = 0; i < rule.patterns.length; i++) {
                    if (v.indexOf(rule.patterns[i][0]) !== -1) { found = rule.patterns[i][1]; break; }
                }
                if (target.value !== found) {
                    target.value = found;
                    target.dispatchEvent(new Event('change'));
                    auto = true;
                }
            };
            source.addEventListener('input', detect);
            source.addEventListener('change', detect);
        });
    });
})();
</script>
@endif
