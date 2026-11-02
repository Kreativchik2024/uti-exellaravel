<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4">
            <div class="flex items-start gap-3">
                <a href="{{ route('records.index', $sheet) }}"
                   class="flex-shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95"
                   title="Назад к списку">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="font-bold text-2xl text-gray-900 tracking-tight">
                            {{ $cfg['title'] }}
                        </h2>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                            #{{ $record->id }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">
                        Запись от
                        <span class="font-semibold text-gray-700">
                            {{ $record->record_date ? \Carbon\Carbon::parse($record->record_date)->format('d.m.Y') : ($record->created_at?->format('d.m.Y') ?? '—') }}
                        </span>
                        @if($record->creator)
                            · создал <span class="font-medium text-gray-700">{{ $record->creator->name }}</span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('records.index', $sheet) }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2.5 text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-xl font-medium text-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    К списку
                </a>

                @can('delete', $record)
                    <form method="POST" action="{{ route('records.destroy', [$sheet, $record->id]) }}"
                          onsubmit="return confirm('Удалить запись?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-semibold px-5 py-2.5 rounded-xl shadow-md transition-all active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Удалить
                        </button>
                    </form>
                @endcan

                @can('update', $record)
                    @if(!$isTimeLocked)
                        <a href="{{ route('records.edit', [$sheet, $record->id]) }}"
                           class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-xl shadow-md shadow-blue-500/20 hover:shadow-lg hover:shadow-blue-500/30 transition-all active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            Редактировать
                        </a>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 font-medium text-sm">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                            </svg>
                            Только просмотр
                        </span>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

        @if($isTimeLocked)
            <div class="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-xl flex items-start gap-3">
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

        @php
            $workerFields = ['worker1','worker2','worker3','worker4','worker5'];

            $groupDefinitions = [
                'main' => [
                    'title'    => 'Основное',
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
                    'icon'     => 'chip',
                    'fields'   => [
                        'cross3_number', 'bracket_serial', 'manet_number',
                        'serial_number', 'pc_version', 'ahrs', 'compass_version',
                        'device_type', 'runs_count',
                    ],
                ],
                'status' => [
                    'title'    => 'Статус и дефекты',
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
                    'title'  => 'Дополнительно',
                    'icon'   => 'dot',
                    'fields' => $otherFields,
                ];
            }

            $healthFields = $cfg['health_fields'] ?? [];
            $goodValues   = $cfg['health_good_values'] ?? ['Работает'];
            $isGood       = true;
            if (!empty($healthFields)) {
                foreach ($healthFields as $f) {
                    $val = $record->{$f} ?? null;
                    if ($val === null || $val === '' || !in_array($val, $goodValues, true)) {
                        $isGood = false;
                        break;
                    }
                }
            }
        @endphp

        {{-- Верхняя плашка «Проверка» --}}
        @if(!empty($healthFields))
            <div class="bg-white rounded-xl shadow-sm border border-gray-200/70 p-4 flex items-center gap-4">
                @if($isGood)
                    <div class="flex-shrink-0 w-12 h-12 rounded-full bg-emerald-500 flex items-center justify-center shadow-md shadow-emerald-500/30">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-base font-bold text-emerald-700">Работает</p>
                        <p class="text-xs text-gray-500">Все проверки пройдены</p>
                    </div>
                @else
                    <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-500 flex items-center justify-center shadow-md shadow-red-500/30">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-base font-bold text-red-700">Внимание</p>
                        <p class="text-xs text-gray-500">Есть проблемы — см. поля проверки</p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Секции полей --}}
        @foreach($groupedFields as $key => $group)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200/70 overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-gray-50/80 to-white flex items-center gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
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
                            @default
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                        @endswitch
                    </div>
                    <h3 class="font-semibold text-base text-gray-900">{{ $group['title'] }}</h3>
                </div>

                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                    @foreach($group['fields'] as $name)
                        @php
                            $f      = $cfg['fields'][$name];
                            $value  = $record->{$name} ?? null;
                            $type   = $f['type'] ?? 'text';
                            $label  = $f['label'] ?? $name;
                            $hasLookup = !empty($f['lookup']);
                            $lookupData = $lookups[$name] ?? null;

                            $display = $value;
                            if ($type === 'date' && $value) {
                                try { $display = \Carbon\Carbon::parse($value)->format('d.m.Y'); }
                                catch (\Throwable $e) { $display = $value; }
                            } elseif ($type === 'time' && $value) {
                                try { $display = \Carbon\Carbon::parse($value)->format('H:i'); }
                                catch (\Throwable $e) { $display = $value; }
                            }
                        @endphp

                        <div class="{{ in_array($type, ['textarea']) ? 'md:col-span-2' : '' }}">
                            <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                                {{ $label }}
                            </dt>

                            @if($hasLookup && $lookupData)
                                @php
                                    $isGreen = ($lookupData['health'] ?? 'green') === 'green';
                                    $cls = $isGreen
                                        ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                                        : 'bg-red-50 border-red-200 text-red-800';
                                    $icon = $isGreen ? '✓' : '✗';
                                @endphp
                                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border {{ $cls }} text-xs font-medium mb-1.5">
                                    <span class="font-bold">{{ $icon }}</span>
                                    <span>{{ $lookupData['workers'] ?? $lookupData['worker1'] ?? '—' }}</span>
                                    <span class="opacity-50">·</span>
                                    <span>{{ $lookupData['record_date'] ?? '—' }}</span>
                                    <span class="opacity-50">·</span>
                                    <span>{{ $lookupData['status_text'] ?? '' }}</span>
                                </div>
                                <br>
                            @endif

                            @if($type === 'textarea')
                                @if($display)
                                    <dd class="text-sm text-gray-900 whitespace-pre-line break-words leading-relaxed bg-gray-50/60 rounded-lg px-3 py-2.5 border border-gray-100">
                                        {{ $display }}
                                    </dd>
                                @else
                                    <dd class="text-sm text-gray-400 italic">— не заполнено —</dd>
                                @endif

                            @elseif($name === 'status' && $display)
                                @php
                                    $isGoodStatus = in_array($display, ['Сдан','Исправен','Проверен','Работает','Да'], true);
                                    $isBadStatus  = in_array($display, ['Не сдан','Неисправен','Не работает','Брак','Нет'], true);
                                    $isWarnStatus = in_array($display, ['В работе','В ремонт','Требует ремонта','Требует замены'], true);
                                    $statusCls = match (true) {
                                        $isGoodStatus => 'bg-emerald-500 text-white',
                                        $isBadStatus  => 'bg-red-500 text-white',
                                        $isWarnStatus => 'bg-amber-500 text-white',
                                        default       => 'bg-gray-200 text-gray-700',
                                    };
                                @endphp
                                <dd>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold whitespace-nowrap shadow-sm {{ $statusCls }}">
                                        <span class="w-2 h-2 rounded-full bg-white"></span>
                                        {{ $display }}
                                    </span>
                                </dd>

                            @elseif(in_array($name, ['is_working', 'is_repaired']) && $display !== null)
                                @php
                                    $isPositive = in_array($display, ['1','Исправен','Да','Работает'], true);
                                @endphp
                                <dd>
                                    @if($isPositive)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-emerald-500 text-white whitespace-nowrap shadow-sm">
                                            <span class="w-2 h-2 rounded-full bg-white"></span>
                                            {{ $display === '1' ? 'Да' : $display }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-red-500 text-white whitespace-nowrap shadow-sm">
                                            <span class="w-2 h-2 rounded-full bg-white"></span>
                                            {{ $display === '0' ? 'Нет' : $display }}
                                        </span>
                                    @endif
                                </dd>

                            @elseif(in_array($name, ['camera_status','night_camera_status','speed_sensor_status','suspension_status','barometer_status']) && $display)
                                @php
                                    $isWorking = $display === 'Работает';
                                @endphp
                                <dd>
                                    @if($isWorking)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-emerald-500 text-white whitespace-nowrap shadow-sm">
                                            <span class="w-2 h-2 rounded-full bg-white"></span>
                                            {{ $display }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold bg-red-500 text-white whitespace-nowrap shadow-sm">
                                            <span class="w-2 h-2 rounded-full bg-white"></span>
                                            {{ $display }}
                                        </span>
                                    @endif
                                </dd>

                            @elseif($display)
                                <dd class="text-sm text-gray-900 font-medium break-words">
                                    {{ $display }}
                                </dd>
                            @else
                                <dd class="text-sm text-gray-400 italic">—</dd>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Исполнители --}}
        @php
            $workers = [];
            foreach ($workerFields as $wf) {
                $v = $record->{$wf} ?? null;
                if (!empty($v)) $workers[] = $v;
            }
        @endphp
        @if(!empty($workers))
            <div class="bg-white rounded-xl shadow-sm border border-gray-200/70 overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-gray-50/80 to-white flex items-center gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-base text-gray-900">Исполнители</h3>
                </div>
                <div class="p-5 flex flex-wrap gap-2">
                    @foreach($workers as $w)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm font-medium">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                            </svg>
                            {{ $w }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Служебная информация --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200/70 p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Создано</dt>
                    <dd class="text-gray-900 font-medium">
                        {{ $record->created_at?->format('d.m.Y H:i') ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Автор</dt>
                    <dd class="text-gray-900 font-medium">
                        {{ $record->creator?->name ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Изменено</dt>
                    <dd class="text-gray-900 font-medium">
                        {{ $record->updated_at?->format('d.m.Y H:i') ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">ID записи</dt>
                    <dd class="text-gray-900 font-mono font-medium">#{{ $record->id }}</dd>
                </div>
            </div>
        </div>

        {{-- Нижние кнопки --}}
        <div class="pt-2 pb-8 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('records.index', $sheet) }}"
               class="inline-flex items-center gap-1.5 px-4 py-2.5 text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg font-medium text-sm transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                К списку
            </a>
            
            <div class="flex items-center gap-2">
                @can('delete', $record)
                    <form method="POST" action="{{ route('records.destroy', [$sheet, $record->id]) }}"
                          onsubmit="return confirm('Удалить запись?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-lg shadow transition active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Удалить запись
                        </button>
                    </form>
                @endcan

                @can('update', $record)
                    @if(!$isTimeLocked)
                        <a href="{{ route('records.edit', [$sheet, $record->id]) }}"
                           class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-lg shadow transition active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            Редактировать запись
                        </a>
                    @endif
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>