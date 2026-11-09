@props(['value' => null, 'tone' => null])

@php
    // Тон по значению статуса (если не задан явно)
    $tone ??= match (true) {
        in_array(mb_strtolower((string) $value), ['сдан', 'исправен', 'проверен', 'работает', 'да', 'пройден', 'ок', 'норма', 'в порядке', 'выполнена', 'устранено'], true) => 'good',
        in_array(mb_strtolower((string) $value), ['не сдан', 'неисправен', 'не работает', 'брак', 'нет', 'внимание', 'не пройден', 'не ок', 'не выполнена'], true) => 'bad',
        in_array(mb_strtolower((string) $value), ['в работе', 'в ремонт', 'требует ремонта', 'требует замены', 'замечание', 'не подтверждено'], true) => 'warn',
        default => 'neutral',
    };
@endphp

@if($value !== null && $value !== '')
    <span {{ $attributes->merge(['class' => "ui-pill ui-pill--{$tone}"]) }}>
        <span class="ui-pill__dot" aria-hidden="true"></span>{{ $value }}
    </span>
@else
    <span style="color: var(--ui-faint)">—</span>
@endif
