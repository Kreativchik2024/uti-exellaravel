{{--
    Статус записи ПГП с учётом ремонта ($mark из RecordController::index):
    returned — борт вернулся после ремонта, ждёт перепроверки; after — сдан после ремонта; in_repair — сейчас в ремонте.
--}}
@switch($mark ?? null)
    @case('returned')
        <x-status-pill value="После ремонта: проверить" tone="warn" title="Борт вернулся на ПГП после ремонта — перепроверьте и поставьте «Сдан»" />
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
