{{--
    Админ → Заготовки полей (App\Http\Controllers\FieldPrefillController): текст, который уже стоит в пустом поле формы.
    Строгой проверки нет — заготовку можно стереть или изменить.
--}}
@php
    $typeLabels = ['date' => 'дата', 'time' => 'время', 'select' => 'выбор из списка', 'number' => 'число',
                   'textarea' => 'текст в несколько строк', 'checkbox' => 'галочка', 'combo' => 'справочник кодов'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h10M4 17h6"/></svg>
            </span>
            <div>
                <h2>Заготовки полей · {{ $cfg['title'] ?? $sheet }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Начало текста, которое уже стоит в пустом поле формы. Дальше пишут как угодно — заготовку можно стереть или исправить</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('field-prefills.save', $sheet) }}" class="ui-card overflow-hidden">
            @csrf @method('PUT')
            <div class="dr-head">
                <h3 class="dr-title">Поля · {{ count($fields) }}</h3>
                <span class="dr-hint">например «УМН-» — в новой записи поле начнётся с него; если ничего не дописать, поле останется пустым</span>
            </div>

            @foreach($fields as $fd)
                <div class="fp-row" @if($fd['kind'] === 'text') x-data="{ v: @js($fd['value']) }" @endif>
                    <div class="min-w-0">
                        <div class="text-sm font-semibold" style="color: var(--ui-text)">{{ $fd['label'] }}</div>
                        <div class="text-xs font-mono" style="color: var(--ui-faint)">{{ $fd['name'] }}</div>
                    </div>

                    @if($fd['kind'] === 'text')
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" name="prefill[{{ $fd['name'] }}]" x-model="v" maxlength="120" placeholder="без заготовки"
                                   class="ui-input !py-1.5 text-sm font-mono flex-1 min-w-[10rem]" aria-label="Заготовка: {{ $fd['label'] }}">
                            <button type="button" x-show="v" class="text-xs underline" style="color: var(--ui-muted)" @click="v = ''">убрать</button>
                            <span x-show="v" class="fp-preview" title="Так будет выглядеть поле в новой записи"><span x-text="v"></span><span class="fp-caret"></span></span>
                        </div>
                    @elseif($fd['kind'] === 'prefix')
                        <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--ui-muted)">
                            Постоянная часть адреса:
                            <input type="text" name="prefix[{{ $fd['name'] }}]" value="{{ $fd['value'] }}" maxlength="20" placeholder="{{ $fd['defaultPrefix'] }}"
                                   class="ui-input !py-1.5 !w-40 text-sm font-mono" aria-label="Постоянная часть IP">
                            + последнее число 1–254
                        </div>
                    @else
                        <div class="text-xs" style="color: var(--ui-muted)">
                            Заготовка не нужна — {{ $fd['name'] === 'fault_code' ? 'выбор из справочника кодов' : ($typeLabels[$fd['type']] ?? $fd['type']) }}
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="px-4 py-3 flex justify-end" style="border-top: 1px solid var(--ui-border)">
                <button class="ui-btn ui-btn--primary">Сохранить</button>
            </div>
        </form>
    </div>

    @push('styles')
    <style>
        .fp-row { display: grid; grid-template-columns: minmax(0, 13rem) minmax(0, 1fr); gap: .5rem 1rem; padding: .7rem 1rem; border-top: 1px solid var(--ui-border); align-items: center; }
        .fp-preview { display: inline-flex; align-items: center; padding: .2rem .55rem; border-radius: 8px; font: .8rem ui-monospace, monospace; white-space: pre;
            background: var(--ui-surface-2); color: var(--ui-text); border: 1px solid var(--ui-border); }
        .fp-caret { width: 1px; height: 1rem; margin-left: 1px; background: var(--ui-accent); animation: fp-blink 1s steps(1) infinite; }
        @keyframes fp-blink { 50% { opacity: 0; } }
        @media (max-width: 640px) { .fp-row { grid-template-columns: 1fr; } }
    </style>
    @endpush
</x-app-layout>
