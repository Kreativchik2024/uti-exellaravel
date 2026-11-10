<x-app-layout>
    @php
        $workerFields = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

        // Раскладка: своя у вкладки (как в форме) или общая группировка
        $groupDefinitions = !empty($cfg['layout'])
            ? collect($cfg['layout'])->mapWithKeys(fn ($d, $i) => ['layout_' . $i => $d])->all()
            : [
                'main'      => ['title' => 'Основное', 'fields' => ['record_date', 'post_number', 'peripheral_number', 'board_number', 'ip_address', 'fuselage_number', 'frame_serial', 'fuselage_no', 'fuselage_serial', 'work_start', 'work_end', 'qty', 'seq']],
                'equipment' => ['title' => 'Оборудование', 'fields' => ['cross3_number', 'bracket_serial', 'manet_number', 'serial_number', 'pc_version', 'ahrs', 'compass_version', 'device_type', 'runs_count']],
                'status'    => ['title' => 'Статус и дефекты', 'fields' => ['status', 'camera_status', 'night_camera_status', 'speed_sensor_status', 'suspension_status', 'is_working', 'is_repaired', 'stand_test', 'barometer_status', 'defect', 'defect_reason', 'fault_code', 'reported_defect', 'found_defect', 'rejected_by', 'analysis_actions', 'result', 'actions_taken', 'work_done']],
                'notes'     => ['title' => 'Примечания', 'fields' => ['notes', 'comments']],
            ];

        $groupedFields = [];
        $usedFields = [];
        foreach ($groupDefinitions as $key => $def) {
            $found = array_values(array_filter($def['fields'], fn ($fn) => isset($cfg['fields'][$fn]) && !in_array($fn, $workerFields, true)));
            if ($found) {
                $groupedFields[$key] = ['title' => $def['title'], 'fields' => $found];
                $usedFields = array_merge($usedFields, $found);
            }
        }
        $otherFields = array_values(array_filter(array_keys($cfg['fields']), fn ($fn) => !in_array($fn, $workerFields, true) && !in_array($fn, $usedFields, true)));
        if ($otherFields) {
            $groupedFields['other'] = ['title' => 'Дополнительно', 'fields' => $otherFields];
        }

        // Итог проверки
        $healthFields = $cfg['health_fields'] ?? [];
        $goodValues   = $cfg['health_good_values'] ?? ['Работает'];
        $isGood = collect($healthFields)->every(fn ($f) => in_array($record->{$f} ?? null, $goodValues, true));

        $workers = array_values(array_filter(array_map(fn ($wf) => $record->{$wf} ?? null, $workerFields)));
        $recordDate = $record->record_date ? \Carbon\Carbon::parse($record->record_date)->format('d.m.Y') : ($record->created_at?->format('d.m.Y') ?? '—');

        $pillFields = ['status', 'camera_status', 'night_camera_status', 'speed_sensor_status', 'suspension_status', 'barometer_status', 'is_working', 'stand_test'];
    @endphp

    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('records.index', $sheet) }}" class="ui-iconbtn" style="border: 1px solid var(--ui-border); background: var(--ui-surface)" title="Назад к списку" aria-label="Назад к списку">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2>{{ $cfg['title'] }}</h2>
                        <span class="ui-badge font-mono">#{{ $record->id }}</span>
                    </div>
                    <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                        Запись от <span class="font-semibold" style="color: var(--ui-text-2)">{{ $recordDate }}</span>
                        @if($record->creator) · создал {{ $record->creator->name }} @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @can('update', $record)
                    @if(!$isTimeLocked)
                        <a href="{{ route('records.edit', [$sheet, $record->id]) }}" class="ui-btn ui-btn--primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Редактировать
                        </a>
                    @else
                        <span class="ui-pill ui-pill--warn">Только просмотр</span>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        @if($isTimeLocked)
            <div class="ui-card flex items-start gap-3 px-4 py-3 mb-5 text-sm" role="status" style="box-shadow: inset 3px 0 0 #f59e0b, var(--ui-shadow-sm)">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: #d97706" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <div style="color: var(--ui-text-2)">
                    <b class="block" style="color: var(--ui-text)">Редактирование заблокировано</b>
                    С момента создания записи прошло более 24 часов — изменить её может только мастер или администратор.
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

            {{-- Поля записи --}}
            <div class="lg:col-span-2 space-y-5">
                @foreach($groupedFields as $group)
                    <section class="ui-card overflow-hidden">
                        <h3 class="rec-section__title">{{ $group['title'] }}</h3>
                        <dl class="rec-grid">
                            @foreach($group['fields'] as $name)
                                @php
                                    $f = $cfg['fields'][$name];
                                    $value = $record->{$name} ?? null;
                                    $type = $f['type'] ?? 'text';
                                    $lookupData = !empty($f['lookup']) ? ($lookups[$name] ?? null) : null;

                                    $display = $value;
                                    if ($value !== null && $value !== '') {
                                        try {
                                            if ($type === 'date') $display = \Carbon\Carbon::parse($value)->format('d.m.Y');
                                            elseif ($type === 'time') $display = \Carbon\Carbon::parse($value)->format('H:i');
                                        } catch (\Throwable $e) {}
                                    }
                                    if ($name === 'is_repaired' && $value !== null) $display = $value ? 'Да' : 'Нет';
                                    $empty = $display === null || $display === '';
                                @endphp

                                <div class="rec-field {{ $type === 'textarea' ? 'rec-field--wide' : '' }}">
                                    <dt>{{ $f['label'] ?? $name }}</dt>
                                    <dd>
                                        @if($empty)
                                            <span style="color: var(--ui-faint)">—</span>
                                        @elseif(in_array($name, $pillFields, true) || $name === 'is_repaired')
                                            <x-status-pill :value="$display" />
                                        @elseif($type === 'textarea')
                                            <div class="rec-text">{{ $display }}</div>
                                        @elseif($name === 'fault_code')
                                            <span class="font-mono text-sm font-semibold px-1.5 py-0.5 rounded-md" style="background: var(--ui-surface-hover)">{{ $display }}</span>
                                        @else
                                            <span class="{{ in_array($name, ['ip_address', 'serial_number', 'manet_number', 'cross3_number', 'bracket_serial', 'frame_serial', 'fuselage_number']) ? 'font-mono' : '' }}">{{ $display }}</span>
                                        @endif

                                        @if($lookupData)
                                            @php $lookupGood = ($lookupData['health'] ?? 'green') === 'green'; @endphp
                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" style="color: var(--ui-muted)">
                                                <x-status-pill :value="$lookupGood ? 'В порядке' : 'Внимание'" :tone="$lookupGood ? 'good' : 'bad'" />
                                                <span>{{ $lookupData['workers'] ?? $lookupData['worker1'] ?? '—' }}</span>
                                                <span>· {{ $lookupData['record_date'] ?? '—' }}</span>
                                                @if(!$lookupGood && !empty($lookupData['status_text']))
                                                    <span class="basis-full" style="color: var(--ui-bad)">{{ $lookupData['status_text'] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>

            {{-- Боковая колонка --}}
            <aside class="space-y-5 lg:sticky" style="top: calc(var(--ui-nav-h) + 16px)">
                @if(!empty($healthFields))
                    <div class="ui-card p-4 flex items-center gap-3.5">
                        <span class="rec-health {{ $isGood ? 'rec-health--good' : 'rec-health--bad' }}" aria-hidden="true">
                            @if($isGood)
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v3.75m0 3.75h.008M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            @endif
                        </span>
                        <div>
                            <div class="font-semibold" style="color: {{ $isGood ? 'var(--ui-good)' : 'var(--ui-bad)' }}">{{ $isGood ? 'Работает' : 'Требует внимания' }}</div>
                            <div class="text-xs" style="color: var(--ui-muted)">{{ $isGood ? 'Все проверки пройдены' : 'Есть замечания — см. поля проверки' }}</div>
                        </div>
                    </div>
                @endif

                @if($workers)
                    <section class="ui-card overflow-hidden">
                        <h3 class="rec-section__title">Исполнители</h3>
                        <ul class="p-3 pt-0 space-y-1">
                            @foreach($workers as $i => $w)
                                <li class="flex items-center gap-2.5 px-1.5 py-1.5">
                                    <span class="ui-chat__avatar" style="width: 32px; height: 32px; font-size: .7rem">
                                        {{ mb_strtoupper(mb_substr($w, 0, 1)) }}{{ mb_strtoupper(mb_substr(trim(\Illuminate\Support\Str::after($w, ' ')), 0, 1)) }}
                                    </span>
                                    <span class="text-sm font-medium" style="color: var(--ui-text)">{{ $w }}</span>
                                    @if($i === 0)<span class="ml-auto text-[11px]" style="color: var(--ui-faint)">основной</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="ui-card overflow-hidden">
                    <h3 class="rec-section__title">Сведения</h3>
                    <dl class="rec-meta">
                        <div><dt>Создано</dt><dd>{{ $record->created_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                        <div><dt>Автор</dt><dd>{{ $record->creator?->name ?? '—' }}</dd></div>
                        <div><dt>Изменено</dt><dd>{{ $record->updated_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                        <div><dt>ID записи</dt><dd class="font-mono">#{{ $record->id }}</dd></div>
                    </dl>
                </section>

                @if(($history ?? collect())->isNotEmpty())
                    <section class="ui-card overflow-hidden">
                        <h3 class="rec-section__title">История изменений</h3>
                        <div class="px-1 pb-2">
                            @foreach($history as $log)
                                <div class="ui-audit ui-audit--compact">
                                    <div class="ui-audit__head">
                                        <span class="ui-audit__badge ui-audit__badge--{{ $log->action }}">{{ $log->actionLabel() }}</span>
                                    </div>
                                    <div class="ui-audit__meta">
                                        <span>{{ $log->created_at->format('d.m.Y H:i') }}</span>
                                        <span>{{ $log->user_name }}</span>
                                    </div>
                                    @if($log->action === 'updated')
                                        @include('audit._changes', ['log' => $log, 'labels' => $historyLabels])
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @can('delete', $record)
                    @unless($isTimeLocked)
                        <form method="POST" action="{{ route('records.destroy', [$sheet, $record->id]) }}"
                              onsubmit="return confirm('Удалить запись #{{ $record->id }}? Это действие нельзя отменить.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ui-btn ui-btn--ghost w-full" style="color: var(--ui-bad)">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Удалить запись
                            </button>
                        </form>
                    @endunless
                @endcan
            </aside>
        </div>
    </div>

    @push('styles')
    <style>
        .rec-section__title { padding: .9rem 1.25rem .4rem; font-size: .8rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--ui-muted); }
        .rec-grid { display: grid; grid-template-columns: 1fr; gap: 0; padding: 0 1.25rem 1rem; }
        @media (min-width: 640px) { .rec-grid { grid-template-columns: 1fr 1fr; column-gap: 1.5rem; } }
        .rec-field { padding: .65rem 0; border-top: 1px solid var(--ui-border); min-width: 0; }
        .rec-field--wide { grid-column: 1 / -1; }
        .rec-field dt { font-size: .75rem; color: var(--ui-muted); margin-bottom: .2rem; }
        .rec-field dd { font-size: .925rem; color: var(--ui-text); font-weight: 500; overflow-wrap: anywhere; }
        .rec-text { white-space: pre-line; font-weight: 400; line-height: 1.55; color: var(--ui-text-2); }
        .rec-health { width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
        .rec-health--good { background: var(--ui-good-soft); color: var(--ui-good); }
        .rec-health--bad  { background: var(--ui-bad-soft);  color: var(--ui-bad); }
        .rec-meta { padding: 0 1.25rem 1rem; }
        .rec-meta > div { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-top: 1px solid var(--ui-border); font-size: .85rem; }
        .rec-meta dt { color: var(--ui-muted); }
        .rec-meta dd { color: var(--ui-text); font-weight: 500; text-align: right; }
        @media (max-width: 1023px) { aside.lg\:sticky { position: static; } }
    </style>
    @endpush
</x-app-layout>
