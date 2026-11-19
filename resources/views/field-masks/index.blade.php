{{--
    Админ → Маски полей: маски у каждого поля ПГП и библиотека масок (App\Http\Controllers\FieldMaskController).
    Маска: 9 — цифра, A — буква, * — буква или цифра, \X — символ X как есть; остальное подставляется само.
--}}
@php
    $lib = $masks->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'pattern' => $m->pattern])->values();
    $typeLabels = ['date' => 'дата', 'time' => 'время', 'select' => 'выбор из списка', 'number' => 'число',
                   'textarea' => 'текст в несколько строк', 'checkbox' => 'галочка', 'combo' => 'справочник кодов'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h10M4 17h6m9-3l-3 3m0 0l-3-3m3 3V10"/></svg>
            </span>
            <div>
                <h2>Маски полей · {{ $cfg['title'] ?? $sheet }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Маска подсказывает формат при вводе и не даёт сохранить значение не по формату</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5" x-data="{ lib: @js($lib) }">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        {{-- Как писать маску --}}
        <section class="ui-card p-4 text-sm space-y-2" style="color: var(--ui-text-2)">
            <div class="font-semibold" style="color: var(--ui-text)">Как писать маску</div>
            <div class="fm-legend">
                <span><code>9</code> — цифра</span>
                <span><code>A</code> — буква (латинская A)</span>
                <span><code>*</code> — буква или цифра</span>
                <span><code>\9</code> — символ «9» как есть</span>
                <span>остальное (<code>-</code>, <code>УМН</code>, <code>/</code>…) — подставляется само</span>
            </div>
            <div class="text-xs" style="color: var(--ui-muted)">
                Примеры: <code>УМН-9999</code> → УМН-0421 · <code>AA-999999</code> → MR-004512 · у поля может быть несколько масок — подойдёт любая.
                Уже внесённые значения при правке записи не проверяются.
            </div>
        </section>

        {{-- Поля вкладки --}}
        <section class="ui-card overflow-hidden">
            <div class="dr-head"><h3 class="dr-title">Поля · {{ count($fields) }}</h3><span class="dr-hint">маски выбираются из библиотеки ниже или создаются тут же</span></div>
            @foreach($fields as $fd)
                <div class="fm-row">
                    <div class="fm-row__name">
                        <div class="text-sm font-semibold" style="color: var(--ui-text)">{{ $fd['label'] }}</div>
                        <div class="text-xs font-mono" style="color: var(--ui-faint)">{{ $fd['name'] }}</div>
                    </div>

                    @if($fd['kind'] === 'mask')
                      <div class="fm-row__body space-y-2" x-data="{ creating: false }">
                        <form method="POST" action="{{ route('field-masks.field', [$sheet, $fd['name']]) }}"
                              x-data="{ sel: @js($fd['masks']), add: '', sample: '' }">
                            @csrf @method('PUT')
                            <template x-for="id in sel" :key="id"><input type="hidden" name="masks[]" :value="id"></template>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <template x-for="(id, n) in sel" :key="'c' + id">
                                    <span class="fm-chip">
                                        <code x-text="lib.find(m => m.id === id)?.pattern"></code>
                                        <span class="text-xs" style="color: var(--ui-muted)" x-text="lib.find(m => m.id === id)?.name"></span>
                                        <button type="button" class="fm-chip__x" title="Убрать" @click="sel.splice(n, 1)">×</button>
                                    </span>
                                </template>
                                <span x-show="!sel.length" class="text-xs" style="color: var(--ui-muted)">без маски — вводится как угодно</span>
                                <select x-model="add" @change="if (add && !sel.includes(+add)) sel.push(+add); add = ''" class="ui-input !py-1 !w-auto text-xs" aria-label="Добавить маску">
                                    <option value="">+ маска из библиотеки</option>
                                    <template x-for="m in lib" :key="m.id"><option :value="m.id" x-text="m.pattern + ' — ' + m.name" :disabled="sel.includes(m.id)"></option></template>
                                </select>
                                <button type="button" class="text-xs font-semibold" style="color: var(--ui-accent-text)" @click="creating = !creating">+ новая</button>
                                <button type="submit" class="ui-btn ui-btn--sm ml-auto">Сохранить</button>
                            </div>

                            {{-- Проверить, как будет вводиться --}}
                            <div x-show="sel.length" class="mt-1.5 flex flex-wrap items-center gap-2 text-xs" style="color: var(--ui-muted)">
                                Проверить:
                                <input type="text" x-model="sample" class="ui-input !py-1 !w-48 text-xs" placeholder="наберите значение"
                                       @input="const r = InputMask.best(sample, sel.map(id => lib.find(m => m.id === id)?.pattern).filter(Boolean), $event.inputType?.startsWith('delete')); if (r) sample = r.out">
                                <span x-show="sample" x-text="(() => { const r = InputMask.best(sample, sel.map(id => lib.find(m => m.id === id)?.pattern).filter(Boolean), true); return r && r.complete ? '✓ подходит' : (r ? '… вводится' : '✗ не по формату'); })()"></span>
                            </div>
                        </form>

                        {{-- Новая маска — сразу в библиотеку и к этому полю --}}
                        <form method="POST" action="{{ route('field-masks.store') }}" x-show="creating" x-cloak class="fm-row__new" x-data="{ np: '' }">
                            @csrf
                            <input type="hidden" name="sheet" value="{{ $sheet }}">
                            <input type="hidden" name="attach_field" value="{{ $fd['name'] }}">
                            <input type="text" name="pattern" x-model="np" required maxlength="120" placeholder="Маска, напр. УМН-9999" class="ui-input !py-1.5 text-sm font-mono">
                            <input type="text" name="name" required maxlength="100" placeholder="Название, напр. Номер УМН" class="ui-input !py-1.5 text-sm">
                            <input type="text" name="hint" maxlength="255" placeholder="Пояснение (необязательно)" class="ui-input !py-1.5 text-sm">
                            <span class="text-xs" style="color: var(--ui-muted)" x-show="np" x-text="'Будет выглядеть: ' + InputMask.placeholder(np)"></span>
                            <button class="ui-btn ui-btn--primary ui-btn--sm">Создать и добавить</button>
                        </form>
                      </div>

                    @elseif($fd['kind'] === 'prefix')
                        <form method="POST" action="{{ route('field-masks.field', [$sheet, $fd['name']]) }}" class="fm-row__body flex flex-wrap items-center gap-2">
                            @csrf @method('PUT')
                            <span class="text-xs" style="color: var(--ui-muted)">Постоянная часть адреса:</span>
                            <input type="text" name="prefix" value="{{ $fd['prefix'] }}" maxlength="20" class="ui-input !py-1.5 !w-40 text-sm font-mono" placeholder="{{ $fd['defaultPrefix'] }}">
                            <span class="text-xs" style="color: var(--ui-muted)">+ последнее число 1–254</span>
                            <button type="submit" class="ui-btn ui-btn--sm ml-auto">Сохранить</button>
                        </form>

                    @else
                        <div class="fm-row__body text-xs" style="color: var(--ui-muted)">
                            Маска не применяется — {{ $fd['name'] === 'fault_code' ? 'выбор из справочника кодов' : ($typeLabels[$fd['type']] ?? $fd['type']) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- Библиотека --}}
        <section class="ui-card overflow-hidden">
            <div class="dr-head"><h3 class="dr-title">Библиотека масок · {{ $masks->count() }}</h3><span class="dr-hint">одну маску можно дать нескольким полям</span></div>
            @forelse($masks as $m)
                <form method="POST" action="{{ route('field-masks.update', $m) }}" class="fm-lib" x-data="{ p: @js($m->pattern) }">
                    @csrf @method('PUT')
                    <input type="text" name="pattern" x-model="p" required maxlength="120" class="ui-input !py-1.5 text-sm font-mono" aria-label="Маска">
                    <input type="text" name="name" value="{{ $m->name }}" required maxlength="100" class="ui-input !py-1.5 text-sm" aria-label="Название">
                    <input type="text" name="hint" value="{{ $m->hint }}" maxlength="255" placeholder="Пояснение" class="ui-input !py-1.5 text-sm" aria-label="Пояснение">
                    <span class="text-xs whitespace-nowrap" style="color: var(--ui-muted)">
                        <span x-text="InputMask.placeholder(p)"></span> · полей: {{ $m->assignments_count }}
                    </span>
                    <div class="flex gap-1.5">
                        <button class="ui-btn ui-btn--sm">Сохранить</button>
                        <button type="submit" form="del-mask-{{ $m->id }}" class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)">Удалить</button>
                    </div>
                </form>
                <form id="del-mask-{{ $m->id }}" method="POST" action="{{ route('field-masks.destroy', $m) }}" class="hidden"
                      onsubmit="return confirm('Удалить маску «{{ $m->name }}»?{{ $m->assignments_count ? ' Она снимется с полей: ' . $m->assignments_count : '' }}')">
                    @csrf @method('DELETE')
                </form>
            @empty
                <p class="dr-empty">Масок пока нет — создайте первую ниже или прямо у поля</p>
            @endforelse

            <form method="POST" action="{{ route('field-masks.store') }}" class="fm-lib" style="background: var(--ui-surface-2)" x-data="{ p: '' }">
                @csrf
                <input type="hidden" name="sheet" value="{{ $sheet }}">
                <input type="text" name="pattern" x-model="p" required maxlength="120" placeholder="Новая маска, напр. AA-999999" class="ui-input !py-1.5 text-sm font-mono">
                <input type="text" name="name" required maxlength="100" placeholder="Название" class="ui-input !py-1.5 text-sm">
                <input type="text" name="hint" maxlength="255" placeholder="Пояснение (необязательно)" class="ui-input !py-1.5 text-sm">
                <span class="text-xs" style="color: var(--ui-muted)" x-text="p ? InputMask.placeholder(p) : ''"></span>
                <button class="ui-btn ui-btn--primary ui-btn--sm">Создать</button>
            </form>
        </section>
    </div>

    @push('styles')
    <style>
        .fm-legend { display: flex; flex-wrap: wrap; gap: .35rem 1rem; }
        .fm-legend code, .fm-chip code, .fm-row code { padding: .05rem .35rem; border-radius: 5px; background: var(--ui-surface-2); color: var(--ui-text); font-size: .8rem; }
        .fm-row { display: grid; grid-template-columns: minmax(0, 13rem) minmax(0, 1fr); gap: .5rem 1rem; padding: .75rem 1rem; border-top: 1px solid var(--ui-border); align-items: start; }
        .fm-row__new { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
        .fm-row__new input { flex: 1 1 10rem; }
        .fm-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .25rem .15rem .4rem; border-radius: 8px; background: var(--ui-accent-soft); }
        .fm-chip__x { width: 1.3rem; height: 1.3rem; border-radius: 6px; color: var(--ui-muted); }
        .fm-chip__x:hover { background: var(--ui-bad-soft); color: var(--ui-bad); }
        .fm-lib { display: grid; grid-template-columns: 11rem 1fr 1fr auto auto; gap: .5rem; align-items: center; padding: .6rem 1rem; border-top: 1px solid var(--ui-border); }
        @media (max-width: 760px) {
            .fm-row { grid-template-columns: 1fr; }
            .fm-lib { grid-template-columns: 1fr; }
        }
    </style>
    @endpush
</x-app-layout>
