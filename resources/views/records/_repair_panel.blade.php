{{--
    Панель «Ремонт» исследования: один или несколько пунктов — что сделано (ремонт / замена) и какой узел.
    Узел — из дерева блоков справочника кодов неисправностей (блок → подблок → …), подтверждённый свой вариант
    или новый свой вариант (уходит на проверку администратору). Есть хоть одна замена — технологу нужен акт.
    Править: ремонтник, мастер, админ. Акт: технолог, мастер, админ.
    Облегчения: тип — кнопками, пункт — одной строкой, частые узлы — одним касанием, несколько узлов за раз,
    новый пункт берёт тип предыдущего и сразу открывает выбор узла, Enter в поиске — первый найденный.
--}}
@php
    $actions   = \App\Models\ResearchRecord::REPAIR_ACTIONS;
    $short     = array_map(fn ($v) => \Illuminate\Support\Str::before($v, ' ('), $actions);   // «Ремонт», «Замена»
    $canRepair = auth()->user()->isRepair() || auth()->user()->canManageContent();
    $canAct    = auth()->user()->isTechnologist() || auth()->user()->canManageContent();
    $saved = $record->repairItems()->with('repairItem')->get();
    // Пункты; у старых записей без пунктов — из сводки
    $rows = $saved->map(fn ($it) => [
        'action'  => $it->action,
        'node'    => $it->item_id ? 'i' . $it->item_id : ($it->group_id ? 'g' . $it->group_id : ''),
        'label'   => (string) $it->label,
        'pending' => $it->item_id && ! ($it->repairItem?->is_approved ?? true),
    ])->values()->all();
    if (! $rows && $record->repair_action) {
        $rows[] = [
            'action'  => $record->repair_action,
            'node'    => $record->repair_item_id ? 'i' . $record->repair_item_id : ($record->repair_group_id ? 'g' . $record->repair_group_id : ''),
            'label'   => (string) $record->repair_item,
            'pending' => $record->repair_item_id && ! ($record->repairItemModel?->is_approved ?? true),
        ];
    }
@endphp
<section class="ui-card p-4 space-y-3" @if($canRepair) x-data="repairRows(@js($repairPicker), @js($rows))" @endif>
    <div class="flex items-center justify-between gap-2">
        <h3 class="text-sm font-semibold" style="color: var(--ui-text)">Ремонт</h3>
        @if($canRepair && $rows)
            <button type="button" x-show="!editing" class="text-xs font-semibold" style="color: var(--ui-accent-text)" @click="edit()">Изменить</button>
        @endif
    </div>

    {{-- Что уже записано (при правке — скрыто, чтобы не дублировать форму) --}}
    <div @if($canRepair) x-show="!editing" @endif>
        @if($rows)
            <ul class="space-y-1.5">
                @foreach($rows as $row)
                    <li class="rp-saved">
                        <span class="rp-tag ui-pill--{{ $row['action'] === 'replacement' ? 'warn' : 'neutral' }}" title="{{ $actions[$row['action']] ?? '' }}">{{ $short[$row['action']] ?? $row['action'] }}</span>
                        <span class="min-w-0" style="color: var(--ui-text-2); overflow-wrap: anywhere">{{ $row['label'] ?: '—' }}</span>
                        @if($row['pending'])<span class="ui-badge-warn" title="Свой вариант ждёт проверки администратора">на проверке</span>@endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm" style="color: var(--ui-muted)">Ремонт или замена ещё не указаны</p>
        @endif

        {{-- Следующий шаг: ремонт записан — отправить на испытания; потом — технолог --}}
        @if($rows)
            @php $hasReplacement = collect($rows)->contains('action', 'replacement'); @endphp
            @if(($record->repair_status ?: 'new') !== 'conveyor')
                @if($canRepair)
                    <form method="POST" action="{{ route('records.researchStatus', ['research', $record->id]) }}" class="rp-next mt-2.5">
                        @csrf @method('PATCH')
                        <span class="rp-next__text">
                            <b>Дальше:</b> отправить борт на испытания{{ $hasReplacement ? '' : ' — после этого технолог примет ремонт' }}
                        </span>
                        <button type="submit" name="repair_status" value="conveyor" class="ui-btn ui-btn--primary ui-btn--sm">Отправить на испытания</button>
                    </form>
                @endif
            @elseif($record->needsTechnologist())
                <p class="rp-next mt-2.5"><span class="rp-next__text"><b>Дальше:</b> {{ $hasReplacement ? 'технолог оформляет акт на замену' : 'технолог принимает ремонт' }}</span></p>
            @endif
        @endif
    </div>

    @if($canRepair)
        <form method="POST" action="{{ route('records.researchRepair', ['research', $record->id]) }}" class="space-y-2" x-show="editing" x-cloak>
            @csrf @method('PATCH')
            <p class="text-xs" style="color: var(--ui-muted)">
                <b>Ремонт</b> — без использования комплектующих, <b>Замена</b> — с использованием комплектующих
            </p>

            {{-- «Как в прошлый раз»: пункты прошлого исследования этой же детали --}}
            <template x-if="picker.previous">
                <button type="button" class="rp-prev" @click="usePrevious()">
                    <span class="rp-prev__title">↺ Как в прошлый раз</span>
                    <span class="rp-prev__meta" x-text="'исследование №' + picker.previous.id + (picker.previous.date ? ' от ' + picker.previous.date : '')"></span>
                    <span class="rp-prev__list" x-text="picker.previous.rows.map(r => (r.action === 'replacement' ? 'Замена' : 'Ремонт') + ': ' + r.label).join('; ')"></span>
                </button>
            </template>

            <template x-for="(row, idx) in rows" :key="row.uid">
                <div class="rp-line" x-data="repairPicker(picker, row)" x-effect="row.action = action; row.ready = !!action && (!!node || !!custom.trim())">
                    {{-- Тип — двумя кнопками --}}
                    <div class="rp-seg" role="group" aria-label="Что сделано">
                        @foreach($actions as $k => $v)
                            <button type="button" title="{{ $v }}" class="{{ $k === 'replacement' ? 'ui-pill--warn is-warn' : '' }}"
                                    :class="action === '{{ $k }}' && 'is-on'" :aria-pressed="action === '{{ $k }}'" @click="action = '{{ $k }}'">{{ $short[$k] }}</button>
                        @endforeach
                    </div>
                    <input type="hidden" :name="'items[' + idx + '][repair_action]'" :value="action">

                    {{-- Узел --}}
                    <div class="rp-line__node" @click.outside="open = false">
                        <button type="button" @click="toggle()" class="ui-input w-full text-left flex items-center justify-between gap-2 !py-1.5 text-sm">
                            <span class="min-w-0 truncate" :style="label ? '' : 'color: var(--ui-faint)'" x-text="label || 'Выберите узел…'" :title="label"></span>
                            <span x-show="pending" class="ui-badge-warn shrink-0">на проверке</span>
                        </button>

                        <div x-show="open" x-cloak class="rp-pop" @keydown.escape.stop="open = false">
                            <input type="text" x-ref="search" x-model="q" placeholder="Поиск по блокам и узлам — Enter выберет первый"
                                   class="ui-input w-full !py-1.5 text-sm" @keydown.enter.prevent="pickFirst()">

                            {{-- Подсказка по отказу: что указывали в исследованиях с тем же кодом / отказом --}}
                            <template x-if="!q.trim() && suggested().length">
                                <div class="mt-1.5">
                                    <div class="text-[11px] font-semibold uppercase tracking-wide mb-1" style="color: var(--ui-accent-text)" x-text="'Подходит по ' + picker.suggested.for"></div>
                                    <div class="rp-chips">
                                        <template x-for="f in suggested()" :key="'s' + f.key">
                                            <button type="button" class="rp-chip rp-chip--hint" :class="isPicked(f.key) && 'is-on'" @click="pick(f.key, f.label, f.pending)" x-text="f.label"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Частые узлы — одним касанием (для выбранного типа: ремонт или замена) --}}
                            <template x-if="!q.trim() && frequent().length">
                                <div class="mt-1.5">
                                    <div class="text-[11px] font-semibold uppercase tracking-wide mb-1" style="color: var(--ui-muted)"
                                         x-text="action === 'replacement' ? 'Часто меняют' : (action === 'repair' ? 'Часто ремонтируют' : 'Часто выбирают')"></div>
                                    <div class="rp-chips">
                                        <template x-for="f in frequent()" :key="f.key">
                                            <button type="button" class="rp-chip" :class="isPicked(f.key) && 'is-on'" @click="pick(f.key, f.label, f.pending)" x-text="f.label"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <label class="mt-1.5 flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--ui-text-2)">
                                <input type="checkbox" x-model="multi" class="rounded"> Выбрать несколько узлов — каждый станет отдельным пунктом
                            </label>

                            <template x-if="results() !== null">
                                <div class="ui-fc-menu !w-full mt-1.5" style="max-height: 320px">
                                    <template x-for="r in results()" :key="r.key">
                                        <button type="button" class="ui-fc-item" :class="isPicked(r.key) && 'is-active'" @click="pick(r.key, r.label, !!r.pending)">
                                            <span class="text-left"><span x-show="multi" x-text="isPicked(r.key) ? '☑ ' : '☐ '"></span><span x-text="r.label"></span></span>
                                            <span x-show="r.pending" class="ui-badge-warn">на проверке</span>
                                        </button>
                                    </template>
                                    <p x-show="!results().length" class="px-2 py-1.5 text-xs" style="color: var(--ui-muted)">Ничего не нашлось — добавьте свой вариант ниже</p>
                                </div>
                            </template>

                            {{-- Лесенка: колонка на каждый открытый уровень, каждая ниже предыдущей --}}
                            <template x-if="results() === null">
                                <div class="ui-stairs mt-1.5">
                                    <template x-for="(parent, n) in [null, ...trail]" :key="'col' + n + '-' + parent">
                                        <div class="ui-fc-menu ui-stairs-col" :style="'--i:' + n">
                                            <div x-show="parent !== null" class="ui-fc-trail" x-text="groupById(parent)?.name"></div>
                                            <button type="button" x-show="parent !== null" class="ui-fc-item is-new" :class="isPicked('g' + parent) && 'is-active'"
                                                    @click="pick('g' + parent, pathOf(parent).join(' · '), false)">
                                                <span x-text="(multi ? (isPicked('g' + parent) ? '☑ ' : '☐ ') : '✓ ') + 'Весь блок целиком'"></span>
                                            </button>
                                            <template x-for="g in childGroups(parent)" :key="'g' + g.id">
                                                <button type="button" class="ui-fc-item"
                                                        :class="{ 'is-group': hasChildren(g.id), 'is-open': trail[n] === g.id, 'is-active': node === 'g' + g.id || isPicked('g' + g.id) }"
                                                        @click="hasChildren(g.id) ? openAt(n, g.id) : pick('g' + g.id, pathOf(g.id).join(' · '), false)">
                                                    <span class="text-left"><span x-show="multi && !hasChildren(g.id)" x-text="isPicked('g' + g.id) ? '☑ ' : '☐ '"></span><span x-text="g.name"></span></span>
                                                    <span x-show="hasChildren(g.id)" class="ui-fc-count">›</span>
                                                </button>
                                            </template>
                                            <template x-for="i in childItems(parent)" :key="'i' + i.id">
                                                <button type="button" class="ui-fc-item" :class="(node === 'i' + i.id || isPicked('i' + i.id)) && 'is-active'" @click="pick('i' + i.id, itemLabel(i), !i.approved)">
                                                    <span class="text-left"><span x-show="multi" x-text="isPicked('i' + i.id) ? '☑ ' : '☐ '"></span><span x-text="i.name"></span></span>
                                                    <span x-show="!i.approved" class="ui-badge-warn">на проверке</span>
                                                </button>
                                            </template>
                                            <p x-show="parent === null && !childGroups(null).length && !childItems(null).length" class="px-2 py-1.5 text-xs" style="color: var(--ui-muted)">
                                                Блоков нет — заполните справочник кодов неисправностей или добавьте свой вариант
                                            </p>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Несколько узлов: первый — в этот пункт, остальные — новыми пунктами с тем же типом --}}
                            <div x-show="multi" class="mt-1.5 flex flex-wrap items-center gap-2">
                                <button type="button" class="ui-btn ui-btn--sm" :disabled="!picked.length" @click="applyMulti(idx)">
                                    Добавить выбранные (<span x-text="picked.length"></span>)
                                </button>
                                <button type="button" x-show="picked.length" class="text-xs underline" style="color: var(--ui-muted)" @click="picked = []">сбросить</button>
                            </div>

                            {{-- Свой вариант — в текущий блок, на проверку администратору --}}
                            <div x-show="!multi" class="mt-1 pt-1.5" style="border-top: 1px dashed var(--ui-border)">
                                <button type="button" x-show="!customOpen" class="ui-fc-item is-new" @click="customOpen = true; $nextTick(() => $refs.custom.focus())">
                                    <span>+ Нужного нет — предложить свой вариант</span>
                                </button>
                                <div x-show="customOpen" class="space-y-1.5 px-1">
                                    <input type="text" x-ref="custom" x-model="custom" maxlength="150" placeholder="Название узла" class="ui-input w-full !py-1.5 text-sm"
                                           @keydown.enter.prevent="useCustom()">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="ui-btn ui-btn--sm" @click="useCustom()" :disabled="!custom.trim()">
                                            Добавить в <span x-text="current() ? '«' + groupById(current())?.name + '»' : 'верхний уровень'"></span>
                                        </button>
                                        <button type="button" class="text-xs underline" style="color: var(--ui-muted)" @click="customOpen = false; custom = ''">отмена</button>
                                    </div>
                                    <p class="text-xs" style="color: var(--ui-muted)">Сохранится сразу, но в общем списке появится после проверки администратором</p>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" :name="'items[' + idx + '][repair_node]'" :value="node">
                        <input type="hidden" :name="'items[' + idx + '][custom_name]'" :value="node ? '' : custom">
                        <input type="hidden" :name="'items[' + idx + '][custom_group]'" :value="node ? '' : (customGroup ?? '')">
                    </div>

                    <button type="button" x-show="rows.length > 1" class="rp-x" title="Убрать пункт" aria-label="Убрать пункт" @click="remove(idx)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2.2" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
            </template>

            <button type="button" class="ui-btn ui-btn--ghost w-full !py-1.5" @click="add()" :disabled="rows.length >= 20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2.2" d="M12 5v14M5 12h14"/></svg>
                Добавить пункт ремонта или замены
            </button>
            @error('repair_node')<p class="text-xs" style="color: var(--ui-bad)">{{ $message }}</p>@enderror
            @error('items')<p class="text-xs" style="color: var(--ui-bad)">{{ $message }}</p>@enderror

            <p x-show="rows.some(r => r.action === 'replacement')" class="text-xs" style="color: var(--ui-text-2)">
                Есть замена — технологу придёт задача оформить акт
            </p>
            <div class="flex gap-2">
                <button type="submit" class="ui-btn flex-1" :disabled="!rows.every(r => r.ready)">Сохранить</button>
                <button type="button" x-show="hadRows" class="ui-btn ui-btn--ghost" @click="cancel()">Отмена</button>
            </div>
        </form>
    @endif

    {{-- Технолог: есть замена — акт; только ремонт, отправленный на испытания, — приёмка --}}
    @if($techKind = $record->techCheckKind())
        @php
            $isAct = $techKind === 'act';
            $t = $isAct
                ? ['title' => 'Акт на замену', 'done' => 'Оформлен', 'todo' => 'Нужен акт', 'button' => 'Акт оформлен', 'who' => 'Оформляет технолог']
                : ['title' => 'Приёмка после ремонта', 'done' => 'Принято', 'todo' => 'Ждёт технолога', 'button' => 'Принято технологом', 'who' => 'Принимает технолог'];
        @endphp
        <div class="pt-3 space-y-2" style="border-top: 1px solid var(--ui-border)">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold" style="color: var(--ui-text)">{{ $t['title'] }}</span>
                @if($record->act_done_at)
                    <x-status-pill :value="$t['done']" tone="good" />
                @else
                    <x-status-pill :value="$t['todo']" tone="bad" />
                @endif
            </div>
            @if($record->act_done_at)
                <p class="text-xs" style="color: var(--ui-muted)">{{ $record->act_done_at->format('d.m.Y H:i') }}</p>
            @elseif($canAct)
                <form method="POST" action="{{ route('records.researchAct', ['research', $record->id]) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="ui-btn w-full">{{ $t['button'] }}</button>
                </form>
            @else
                <p class="text-xs" style="color: var(--ui-muted)">{{ $t['who'] }}</p>
            @endif
        </div>
    @elseif($record->repair_action === 'repair')
        <p class="pt-3 text-xs" style="border-top: 1px solid var(--ui-border); color: var(--ui-muted)">
            После статуса «Отправлен на испытания» борт уйдёт технологу на приёмку
        </p>
    @endif
</section>

@once
<script>
document.addEventListener('alpine:init', () => {
    // Пункты «ремонт / замена»: у каждого свой выбор узла (repairPicker)
    Alpine.data('repairRows', (picker, saved) => ({
        picker, seq: 0, rows: [], hadRows: saved.length > 0, editing: saved.length === 0,
        init() { this.reset(); },
        make(r) { return { uid: ++this.seq, action: r.action || '', node: r.node || '', label: r.label || '', pending: !!r.pending, autoOpen: !!r.autoOpen, ready: false }; },
        reset() { this.rows = (saved.length ? saved : [{}]).map(r => this.make(r)); },
        edit() { this.reset(); this.editing = true; },
        cancel() { this.reset(); this.editing = false; },
        // Новый пункт — с типом предыдущего, выбор узла открывается сразу
        add() {
            if (this.rows.length >= 20) return;
            const last = this.rows[this.rows.length - 1];
            this.rows.push(this.make({ action: last?.action || '', autoOpen: true }));
        },
        remove(idx) { this.rows.splice(idx, 1); },
        // Подставить пункты прошлого исследования этой детали (потом можно поправить)
        usePrevious() { if (this.picker.previous) this.rows = this.picker.previous.rows.map(r => this.make(r)); },
        // Несколько узлов из одного выбора — новыми пунктами после idx
        addAfter(idx, action, picks) {
            const room = Math.max(0, 20 - this.rows.length);
            this.rows.splice(idx + 1, 0, ...picks.slice(0, room).map(p => this.make({ action, node: p.key, label: p.label, pending: p.pending })));
        },
    }));

    Alpine.data('repairPicker', (data, init) => ({
        groups: data.groups || [], items: data.items || [], freq: data.frequent || {}, hint: data.suggested?.keys || [],
        action: init.action || '', node: init.node || '', label: init.label || '', pending: !!init.pending,
        open: false, q: '', trail: [], custom: '', customOpen: false, customGroup: null, multi: false, picked: [],
        init() { if (init.autoOpen) { init.autoOpen = false; this.toggle(true); } },
        toggle(force) {
            this.open = force ?? !this.open;
            if (this.open) this.$nextTick(() => this.$refs.search?.focus());
        },
        current() { return this.trail.length ? this.trail[this.trail.length - 1] : null; },
        groupById(id) { return this.groups.find(g => g.id === id); },
        pathOf(id) {
            const p = []; let g = this.groupById(id);
            for (let guard = 0; g && guard < 20; guard++) { p.unshift(g.name); g = g.parent ? this.groupById(g.parent) : null; }
            return p;
        },
        childGroups(id) { return this.groups.filter(g => g.parent === id); },
        childItems(id) { return this.items.filter(i => i.group === id); },
        hasChildren(id) { return this.childGroups(id).length > 0 || this.childItems(id).length > 0; },
        itemLabel(i) { return (i.group ? this.pathOf(i.group).join(' · ') + ' · ' : '') + i.name; },
        // «g12» / «i5» → подпись
        chips(keys) {
            return keys.map(k => {
                const id = +k.slice(1);
                if (k[0] === 'g') { const g = this.groupById(id); return g ? { key: k, label: this.pathOf(id).join(' · '), pending: false } : null; }
                const i = this.items.find(x => x.id === id); return i ? { key: k, label: this.itemLabel(i), pending: !i.approved } : null;
            }).filter(Boolean);
        },
        suggested() { return this.chips(this.hint); },
        // Частые для выбранного типа (если по нему ещё мало данных — общие); без повторов подсказки
        frequent() {
            const own = this.freq[this.action] || [];
            return this.chips((own.length ? own : (this.freq.all || [])).filter(k => !this.hint.includes(k)));
        },
        // Поиск: блоки и узлы, у которых путь содержит текст
        results() {
            const q = this.q.trim().toLowerCase();
            if (!q) return null;
            const r = [];
            for (const g of this.groups) { const l = this.pathOf(g.id).join(' · '); if (l.toLowerCase().includes(q)) r.push({ key: 'g' + g.id, label: l }); }
            for (const i of this.items) { const l = this.itemLabel(i); if (l.toLowerCase().includes(q)) r.push({ key: 'i' + i.id, label: l, pending: !i.approved }); }
            return r.slice(0, 40);
        },
        pickFirst() { const r = this.results(); if (r && r.length) this.pick(r[0].key, r[0].label, !!r[0].pending); },
        // Лесенка: в колонке n открыт блок id — колонки правее него заменяются его содержимым
        openAt(n, id) { this.trail = this.trail.slice(0, n).concat([id]); },
        isPicked(key) { return this.multi && this.picked.some(p => p.key === key); },
        // Обычный режим — выбрать и закрыть; «несколько» — отметить/снять
        pick(key, label, pending) {
            if (!this.multi) return this.choose(key, label, pending);
            const at = this.picked.findIndex(p => p.key === key);
            at >= 0 ? this.picked.splice(at, 1) : this.picked.push({ key, label, pending });
        },
        applyMulti(idx) {
            if (!this.picked.length) return;
            const [first, ...rest] = this.picked;
            this.choose(first.key, first.label, first.pending);
            if (rest.length) this.addAfter(idx, this.action, rest);
            this.picked = []; this.multi = false;
        },
        choose(node, label, pending) { this.node = node; this.label = label; this.pending = pending; this.custom = ''; this.customOpen = false; this.open = false; this.q = ''; },
        useCustom() {
            const n = this.custom.trim();
            if (!n) return;
            this.customGroup = this.current();
            this.node = '';
            this.label = (this.customGroup ? this.pathOf(this.customGroup).join(' · ') + ' · ' : '') + n;
            this.pending = true; this.open = false; this.customOpen = false;
        },
    }));
});
</script>
@endonce
