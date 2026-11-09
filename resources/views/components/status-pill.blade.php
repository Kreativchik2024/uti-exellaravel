@props(['value' => null, 'tone' => null])

@php
    // Тон по значению статуса (если не задан явно)
    $tone ??= match (true) {
        in_array($value, ['Сдан', 'Исправен', 'Проверен', 'Работает', 'Да'], true) => 'good',
        in_array($value, ['Не сдан', 'Неисправен', 'Не работает', 'Брак', 'Нет', 'Внимание'], true) => 'bad',
        in_array($value, ['В работе', 'В ремонт', 'Требует ремонта', 'Требует замены'], true) => 'warn',
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
