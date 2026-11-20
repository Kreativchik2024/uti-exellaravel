<x-app-layout>
    @php
        $workerFields = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

        // Раскладка: своя у вкладки (как в форме) или общая группировка
        $groupDefinitions = !empty($cfg['layout'])
            ? collect($cfg['layout'])->mapWithKeys(fn ($d, $i) => ['layout_' . $i => $d])->all()
            : [
                'main'      => ['title' => 'Основное', 'fields' => ['record_date', 'post_number', 'peripheral_number', 'board_number', 'ip_address', 'fuselage_number', 'frame_serial', 'fuselage_no', 'fuselage_serial', 'work_start', 'work_end', 'qty', 'seq', 'category']],
                'equipment' => ['title' => 'Оборудование', 'fields' => ['cross3_number', 'bracket_serial', 'manet_number', 'serial_number', 'module_serial', 'pc_version', 'ahrs', 'compass_version', 'device_type', 'runs_count']],
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
        // Брак уже отремонтирован (ПГП → исследование «Отремонтировано: Да») — зелёным, а не красным
        $isRepaired = $isRepaired ?? false;
        if ($isRepaired) $isGood = true;

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
                                    // ПГП: кто прошил / проверил / собрал — в журнале борта ниже, а не у поля
                                    $lookupData = !empty($f['lookup']) && $sheet !== 'pgp' ? ($lookups[$name] ?? null) : null;

                                    $display = $value;
                                    if ($value !== null && $value !== '') {
                                        try {
                                            if ($type === 'date') $display = \Carbon\Carbon::parse($value)->format('d.m.Y');
                                            elseif ($type === 'time') $display = \Carbon\Carbon::parse($value)->format('H:i');
                                        } catch (\Throwable $e) {}
                                    }
                                    if (($name === 'is_repaired' || ($type === 'checkbox' || !empty($f['bool']))) && $value !== null) $display = $value ? 'Да' : 'Нет';
                                    $empty = $display === null || $display === '';
                                @endphp

                                <div class="rec-field {{ $type === 'textarea' ? 'rec-field--wide' : '' }}">
                                    <dt>{{ $f['label'] ?? $name }}</dt>
                                    <dd>
                                        @if($empty)
                                            <span style="color: var(--ui-faint)">—</span>
                                        @elseif($name === 'repair_status')
                                            <x-research-status :value="$value" />
                                        @elseif($name === 'status' && $isRepaired)
                                            <x-status-pill value="Отремонтировано" tone="good" />
                                            <span class="ml-1.5 text-xs" style="color: var(--ui-muted)">было: {{ $display }}</span>
                                        @elseif(in_array($name, $pillFields, true) || $name === 'is_repaired' || ($type === 'checkbox' || !empty($f['bool'])))
                                            <x-status-pill :value="$display" />
                                        @elseif($type === 'textarea')
                                            <div class="rec-text">{{ $display }}</div>
                                        @elseif($name === 'fault_code')
                                            @php $faultMap = \App\Models\FaultCode::mapFor(app(\App\Services\SectionContext::class)->current()); @endphp
                                            <span class="flex flex-col gap-1">
                                                @foreach(\App\Services\FaultCodes::split($display) as $fc)
                                                    <span>
                                                        <span class="font-mono text-sm font-semibold px-1.5 py-0.5 rounded-md" style="background: var(--ui-surface-hover)">{{ $fc }}</span>
                                                        @if($faultMap[$fc] ?? null)<span class="ml-1.5 text-sm" style="color: var(--ui-text-2)">{{ $faultMap[$fc] }}</span>@endif
                                                    </span>
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="{{ in_array($name, ['ip_address', 'serial_number', 'manet_number', 'cross3_number', 'bracket_serial', 'frame_serial', 'fuselage_number']) ? 'font-mono' : '' }}">{{ $display }}</span>
                                        @endif

                                        @if($lookupData)
                                            @php $lookupGood = ($lookupData['health'] ?? 'green') === 'green'; @endphp
                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" style="color: var(--ui-muted)">
                                                <x-status-pill :value="$lookupGood ? 'В порядке' : 'Внимание'" :tone="$lookupGood ? 'good' : 'bad'" />
                                                <span>@if(!empty($lookupData['caption'])){{ $lookupData['caption'] }}: @endif{{ $lookupData['workers'] ?? $lookupData['worker1'] ?? '—' }}</span>
                                                <span>· {{ $lookupData['record_date'] ?? '—' }}</span>
                                                @if(!$lookupGood && !empty($lookupData['status_text']))
                                                    <span class="basis-full" style="color: var(--ui-bad)">{{ $lookupData['status_text'] }}</span>
                                                @endif
                                                @foreach($lookupData['details'] ?? [] as $d)
                                                    <span class="basis-full">{{ $d['label'] }}: {{ $d['value'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach

                {{-- ПГП: журнал борта по этапам — прошивка и настройка → сборка → ПГП → ремонт → снова ПГП → ОТК --}}
                @if($sheet === 'pgp' && $boardHistory)
                    @include('records._board_journal', ['boardHistory' => $boardHistory])
                @endif

                {{-- Исследование: комментарии и вся история по детали (деталь может возвращаться несколько раз) --}}
                @if($sheet === 'research')
                    <section id="comments" class="ui-card overflow-hidden">
                        <h3 class="rec-section__title">Комментарии и история детали</h3>
                        <div class="px-4 pb-4 space-y-4">
                            @foreach($partHistory as $item)
                                @php $isCurrent = $item->id === $record->id; @endphp
                                <div class="rounded-lg p-3" style="background: var(--ui-surface-2); {{ $isCurrent ? 'border: 1px solid var(--ui-accent-text)' : 'border: 1px solid var(--ui-border)' }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs" style="color: var(--ui-muted)">
                                        <span>
                                            @if($isCurrent)
                                                <b style="color: var(--ui-text)">Это исследование</b>
                                            @else
                                                <a href="{{ route('records.show', ['research', $item->id]) }}" class="underline">Исследование №{{ $item->id }}</a>
                                            @endif
                                            · {{ $item->record_date?->format('d.m.Y') }}
                                        </span>
                                        <span class="flex items-center gap-1.5">
                                            @if($item->category)<span>{{ $item->category }}</span>@endif
                                            <x-research-status :value="$item->repair_status" />
                                        </span>
                                    </div>
                                    @if($item->reported_defect)
                                        <div class="mt-1.5 text-sm" style="color: var(--ui-text-2)">{{ $item->reported_defect }}</div>
                                    @endif
                                    @forelse($item->comments as $c)
                                        <div class="mt-2 pl-3 text-sm" style="border-left: 2px solid var(--ui-border)">
                                            <div class="text-xs" style="color: var(--ui-muted)">{{ $c->user?->name ?? 'Удалённый пользователь' }} · {{ $c->created_at?->format('d.m.Y H:i') }}</div>
                                            <div class="whitespace-pre-line" style="color: var(--ui-text)">{{ $c->body }}</div>
                                        </div>
                                    @empty
                                        <div class="mt-2 text-xs" style="color: var(--ui-faint)">Комментариев нет</div>
                                    @endforelse
                                </div>
                            @endforeach

                            <form method="POST" action="{{ route('records.comment', [$sheet, $record->id]) }}" class="space-y-2">
                                @csrf
                                {{-- Черновик хранится в браузере: «Акт оформлен», смена статуса и т.п. обновляют страницу — текст не пропадёт --}}
                                <textarea name="body" rows="3" maxlength="2000" required placeholder="Новый комментарий к этому исследованию"
                                          data-draft-key="research-comment-{{ $record->id }}"
                                          class="w-full rounded-lg text-sm" style="background: var(--ui-surface-2); color: var(--ui-text); border: 1px solid var(--ui-border)">{{ old('body') }}</textarea>
                                @error('body')<p class="text-xs" style="color: var(--ui-bad)">{{ $message }}</p>@enderror
                                <button type="submit" class="ui-btn">Добавить комментарий</button>
                            </form>
                        </div>
                    </section>
                @endif
            </div>

            {{-- Боковая колонка --}}
            <aside class="space-y-5 lg:sticky" style="top: calc(var(--ui-nav-h) + 16px)">
                {{-- ПГП: цепочка документов борта — проверка «Не сдан» → ремонт → повторная проверка (новая запись) → «Сдан» --}}
                @if($sheet === 'pgp')
                    @php
                        $pendingResearch = $record->pendingRecheckResearch();
                        $chainResearches = $record->researches()->with('recheck')->orderBy('id')->get();
                        $prevResearch = $record->afterResearch;
                    @endphp
                    @if($chainResearches->isEmpty() && app(\App\Services\PgpDefectResearch::class)->isDefect($record))
                        <section class="ui-card p-4 space-y-2" style="border-color: var(--ui-danger, #dc2626)">
                            <h3 class="text-sm font-semibold" style="color: var(--ui-text)">Исследование не заведено</h3>
                            <p class="text-sm" style="color: var(--ui-text-2)">
                                Борт с браком. Чтобы он ушёл в ремонт и потом вернулся на повторную проверку, заведите по нему исследование.
                            </p>
                            <form method="POST" action="{{ route('records.startResearch', ['pgp', $record->id]) }}">
                                @csrf
                                <button type="submit" class="ui-btn w-full">Завести исследование</button>
                            </form>
                        </section>
                    @endif
                    @if($pendingResearch)
                        <section class="ui-card p-4 space-y-2" style="border-color: var(--ui-accent)">
                            <h3 class="text-sm font-semibold" style="color: var(--ui-accent-text)">Вернулся после ремонта</h3>
                            <p class="text-sm" style="color: var(--ui-text-2)">
                                Проверьте борт на ПГП новой записью — она свяжется с исследованием №{{ $pendingResearch->id }}, а эта запись останется как была.
                            </p>
                            <a href="{{ route('records.create', ['pgp', 'from' => $record->id]) }}" class="ui-btn w-full">Повторная проверка на ПГП</a>
                        </section>
                    @endif
                    @if($prevResearch || $chainResearches->isNotEmpty())
                        <section class="ui-card p-4 space-y-1.5 text-sm">
                            <h3 class="font-semibold" style="color: var(--ui-text)">Документы борта</h3>
                            @if($prevResearch)
                                <p style="color: var(--ui-text-2)">Повторная проверка после ремонта:
                                    <a href="{{ route('records.show', ['research', $prevResearch->id]) }}" class="underline">исследование №{{ $prevResearch->id }}</a>@if($prevResearch->pgp_record_id),
                                    первая проверка — <a href="{{ route('records.show', ['pgp', $prevResearch->pgp_record_id]) }}" class="underline">запись №{{ $prevResearch->pgp_record_id }}</a>@endif
                                </p>
                            @endif
                            @foreach($chainResearches as $cr)
                                <p style="color: var(--ui-text-2)">
                                    Ремонт: <a href="{{ route('records.show', ['research', $cr->id]) }}" class="underline">исследование №{{ $cr->id }}</a>
                                    @if($cr->recheck)
                                        → повторная проверка <a href="{{ route('records.show', ['pgp', $cr->recheck->id]) }}" class="underline">№{{ $cr->recheck->id }}</a>: <b>{{ $cr->recheck->status ?: 'без статуса' }}</b>
                                    @endif
                                </p>
                            @endforeach
                        </section>
                    @endif
                @endif
                {{-- Исследование модуля: где его делали по S/N — вкладка, дата, кто шил / собирал (App\Services\ModuleTrail) --}}
                @if($sheet === 'research' && ($moduleMade ?? collect())->isNotEmpty())
                    <section class="ui-card p-4 space-y-2 text-sm">
                        <h3 class="font-semibold" style="color: var(--ui-text)">Где делали модуль</h3>
                        @foreach($moduleMade as $m)
                            <div class="rounded-lg px-3 py-2" style="background: var(--ui-surface-2); border: 1px solid var(--ui-border)">
                                <a href="{{ route('records.show', [$m['sheet'], $m['record']->id]) }}" class="underline font-semibold">{{ $m['title'] }} №{{ $m['record']->id }}</a>
                                <span style="color: var(--ui-muted)">· {{ $m['record']->record_date?->format('d.m.Y') }}</span>
                                <div style="color: var(--ui-text-2)">{{ $m['workers'] !== '' ? $m['workers'] : 'исполнитель не указан' }}</div>
                                <div class="text-xs" style="color: var(--ui-muted)">{{ $m['field'] }}: <span class="font-mono">{{ $m['serial'] }}</span></div>
                            </div>
                        @endforeach
                    </section>
                @endif
                {{-- Запись вкладки: исследования (ремонты) этого модуля по S/N --}}
                @if(($moduleResearch ?? collect())->isNotEmpty())
                    <section class="ui-card p-4 space-y-2 text-sm">
                        <h3 class="font-semibold" style="color: var(--ui-text)">Исследования модуля</h3>
                        @foreach($moduleResearch as $mr)
                            <div class="rounded-lg px-3 py-2" style="background: var(--ui-surface-2); border: 1px solid var(--ui-border)">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span><a href="{{ route('records.show', ['research', $mr->id]) }}" class="underline font-semibold">Исследование №{{ $mr->id }}</a>
                                        <span style="color: var(--ui-muted)">· {{ $mr->record_date?->format('d.m.Y') }}</span></span>
                                    <x-research-status :value="$mr->repair_status" />
                                </div>
                                @if($mr->reported_defect)<div class="mt-1" style="color: var(--ui-text-2)">{{ \Illuminate\Support\Str::limit($mr->reported_defect, 140) }}</div>@endif
                            </div>
                        @endforeach
                    </section>
                @endif
                {{-- Исследование борта: первая проверка и повторная проверка после ремонта (цепочка документов) --}}
                @if($sheet === 'research' && ($record->pgp_record_id || $record->recheck))
                    <section class="ui-card p-4 space-y-1.5 text-sm">
                        <h3 class="font-semibold" style="color: var(--ui-text)">Документы борта</h3>
                        @if($record->pgp_record_id)
                            <p style="color: var(--ui-text-2)">Проверка на ПГП: <a href="{{ route('records.show', ['pgp', $record->pgp_record_id]) }}" class="underline">запись №{{ $record->pgp_record_id }}</a></p>
                        @endif
                        @if($record->recheck)
                            <p style="color: var(--ui-text-2)">Повторная проверка: <a href="{{ route('records.show', ['pgp', $record->recheck->id]) }}" class="underline">запись №{{ $record->recheck->id }}</a> — <b>{{ $record->recheck->status ?: 'без статуса' }}</b></p>
                        @elseif($record->repair_status === 'conveyor' && $record->pgp_record_id)
                            <p style="color: var(--ui-muted)">Повторной проверки на ПГП ещё не было.</p>
                        @endif
                    </section>
                @endif
                {{-- Исследование: статус цветными кнопками (ремонтник, мастер, админ), остальным — только метка --}}
                @if($sheet === 'research')
                    @php
                        $currentStatus = $record->repair_status ?: 'new';
                        $canSetStatus = auth()->user()->isRepair() || auth()->user()->canManageContent();
                        $nextStatus = $record->nextStatus();
                    @endphp
                    <section class="ui-card p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold" style="color: var(--ui-text)">Статус</h3>
                            <x-research-status :value="$currentStatus" />
                        </div>
                        @if($canSetStatus)
                            <form method="POST" action="{{ route('records.researchStatus', [$sheet, $record->id]) }}" class="grid grid-cols-1 gap-2">
                                @csrf @method('PATCH')
                                @foreach(\App\Models\ResearchRecord::STATUSES as $key => $s)
                                    <button type="submit" name="repair_status" value="{{ $key }}"
                                            class="ui-pill ui-pill--{{ $s['tone'] }} rs-btn {{ $key === $currentStatus ? 'rs-btn--current' : '' }} {{ $key === $nextStatus ? 'rs-btn--next' : '' }}"
                                            @if($key === $currentStatus) aria-pressed="true" @endif
                                            @if($key === $nextStatus) title="Следующий шаг" @endif>
                                        <span class="ui-pill__dot" aria-hidden="true"></span>{{ $s['label'] }}
                                        @if($key === $nextStatus)<span class="rs-btn__next">дальше</span>@endif
                                    </button>
                                @endforeach
                            </form>
                        @endif
                        @if($record->pgp_record_id)
                            <a href="{{ route('records.show', ['pgp', $record->pgp_record_id]) }}" class="block text-sm underline" style="color: var(--ui-accent-text)">Брак ПГП: открыть запись №{{ $record->pgp_record_id }}</a>
                            <a href="{{ route('records.show', ['pgp', $record->pgp_record_id]) }}#board-history" class="block text-sm underline" style="color: var(--ui-accent-text)">Журнал борта по этапам</a>
                        @endif
                    </section>

                    {{-- Ремонт / замена и узел; акт на замену --}}
                    @include('records._repair_panel')
                @endif

                @if($sheet === 'pgp')
                    <section class="ui-card overflow-hidden">
                        <div class="flex items-center justify-between gap-2 px-4 pt-3 pb-2">
                            <h3 class="rec-section__title !p-0">Пометка технолога</h3>
                            @if($record->tech_processed)
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background: var(--ui-surface-2); color: var(--ui-good)">Обработан</span>
                            @else
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background: var(--ui-surface-2); color: var(--ui-bad)">Не обработан</span>
                            @endif
                        </div>
                        <div class="px-4 pb-4 space-y-3">
                            @if(auth()->user()->isTechnologist())
                                <form method="POST" action="{{ route('records.tech', [$sheet, $record->id]) }}" class="space-y-3">
                                    @csrf @method('PATCH')
                                    <textarea name="tech_note" rows="3" maxlength="2000" placeholder="Что сделано или что нужно учесть"
                                              class="w-full rounded-lg text-sm" style="background: var(--ui-surface-2); color: var(--ui-text); border: 1px solid var(--ui-border)">{{ old('tech_note', $record->tech_note) }}</textarea>
                                    <label class="flex items-center justify-between gap-2 text-sm" style="color: var(--ui-text-2)">
                                        <span>Решён вопрос</span>
                                        <select name="tech_processed" class="rounded-lg text-sm" style="background: var(--ui-surface-2); color: var(--ui-text); border: 1px solid var(--ui-border)">
                                            <option value="0" @selected(! old('tech_processed', $record->tech_processed))>Нет</option>
                                            <option value="1" @selected(old('tech_processed', $record->tech_processed))>Да</option>
                                        </select>
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="submit" class="ui-btn">Сохранить пометку</button>
                                        {{-- Отмена: возвращает значения, сохранённые в записи (без запроса к серверу) --}}
                                        <button type="reset" class="ui-btn ui-btn--ghost">Отмена</button>
                                    </div>
                                </form>
                            @else
                                <p class="text-sm whitespace-pre-line" style="color: var(--ui-text-2)">{{ $record->tech_note ?: 'Пометки нет' }}</p>
                            @endif
                            @if($record->tech_updated_at)
                                <p class="text-xs" style="color: var(--ui-muted)">Изменено {{ $record->tech_updated_at->format('d.m.Y H:i') }}</p>
                            @endif

                            {{-- Ремонты и замены по этому борту (из исследований) и проверка технолога --}}
                            @php $repairs = $record->researches()->whereNotNull('repair_action')->with('repairItems')->orderBy('id')->get(); @endphp
                            @if($repairs->isNotEmpty())
                                <div class="pt-3 space-y-2" style="border-top: 1px solid var(--ui-border)">
                                    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--ui-muted)">Ремонт и замена</div>
                                    @foreach($repairs as $rep)
                                        @php
                                            $kind = $rep->techCheckKind();
                                            [$pill, $tone] = match (true) {
                                                $kind === null       => ['Отправлен в сборку', 'neutral'],
                                                (bool) $rep->act_done_at => [$kind === 'act' ? 'Акт оформлен' : 'Принято', 'good'],
                                                default              => [$kind === 'act' ? 'Нужен акт' : 'Ждёт приёмки', 'bad'],
                                            };
                                        @endphp
                                        <a href="{{ route('records.show', ['research', $rep->id]) }}" class="block rounded-lg px-2.5 py-2 hover:opacity-80" style="background: var(--ui-surface-2)">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-sm font-medium" style="color: var(--ui-text)">
                                                    {{ $rep->repairActionLabel() }} · №{{ $rep->id }}
                                                </span>
                                                <x-status-pill :value="$pill" :tone="$tone" />
                                            </div>
                                            @forelse($rep->repairItems as $it)
                                                <div class="text-xs mt-0.5" style="color: var(--ui-text-2)">{{ $it->actionLabel() }}: {{ $it->label }}</div>
                                            @empty
                                                <div class="text-xs mt-0.5" style="color: var(--ui-text-2)">{{ $rep->repair_item }}</div>
                                            @endforelse
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

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
                            <div class="font-semibold" style="color: {{ $isGood ? 'var(--ui-good)' : 'var(--ui-bad)' }}">{{ $isRepaired ? 'Отремонтировано' : ($isGood ? 'Работает' : 'Требует внимания') }}</div>
                            <div class="text-xs" style="color: var(--ui-muted)">{{ $isRepaired ? 'Брак устранён — см. исследование' : ($isGood ? 'Все проверки пройдены' : 'Есть замечания — см. поля проверки') }}</div>
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
