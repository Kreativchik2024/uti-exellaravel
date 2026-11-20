{{--
    Статус записи ПГП с учётом ремонта ($mark из RecordController::index):
    returned — вернулся после ремонта, ждёт повторной проверки; rechecked — повторная проверка уже заведена (новой записью);
    after — сдан после ремонта; in_repair — сейчас в ремонте. См. App\Services\RecordListFilters::repairMarks.
--}}
@switch($mark ?? null)
    @case('returned')
        <x-status-pill value="После ремонта: проверить" tone="warn" title="Борт вернулся на ПГП после ремонта — откройте запись и нажмите «Повторная проверка на ПГП»" />
        @break
    @case('rechecked')
        <span class="inline-flex flex-wrap items-center gap-1">
            <x-status-pill :value="$record->status ?? null" />
            <span class="ui-badge-warn" style="background: var(--ui-surface-hover); color: var(--ui-text-2)" title="После ремонта борт проверен новой записью">перепроверен</span>
        </span>
        @break
    @case('after')
        <span class="inline-flex flex-wrap items-center gap-1">
            <x-status-pill :value="$record->status" />
            <span class="ui-badge-warn" style="background: var(--ui-accent-soft); color: var(--ui-accent-text)" title="Сдан после ремонта">после ремонта</span>
        </span>
        @break
    @case('in_repair')
        <span class="inline-flex flex-wrap items-center gap-1">
            <x-status-pill :value="$record->status ?? null" />
            <span class="ui-badge-warn">отправлен в сборку</span>
        </span>
        @break
    @default
        <x-status-pill :value="$record->status ?? null" />
@endswitch
