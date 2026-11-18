{{--
    Панель «Ремонт» исследования: что сделал ремонтник (ремонт / замена) и какой узел.
    Узел — из дерева блоков справочника кодов неисправностей (блок → подблок → …), подтверждённый свой вариант
    или новый свой вариант (уходит на проверку администратору). При замене по браку ПГП технологу (ОТК) нужен акт.
    Править: ремонтник, мастер, админ. Акт: ОТК, мастер, админ.
--}}
@php
    $actions   = \App\Models\ResearchRecord::REPAIR_ACTIONS;
    $canRepair = auth()->user()->isRepair() || auth()->user()->canManageContent();
    $canAct    = auth()->user()->isOtk() || auth()->user()->canManageContent();
    $itemPending = $record->repair_item_id && ! ($record->repairItemModel?->is_approved ?? true);
    $init = [
        'action'  => old('repair_action', $record->repair_action ?? ''),
        'node'    => $record->repair_item_id ? 'i' . $record->repair_item_id : ($record->repair_group_id ? 'g' . $record->repair_group_id : ''),
        'label'   => (string) $record->repair_item,
        'pending' => $itemPending,
    ];
@endphp
<section class="ui-card p-4 space-y-3" x-data="repairPicker(@js($repairPicker), @js($init))">
    <h3 class="text-sm font-semibold" style="color: var(--ui-text)">Ремонт</h3>

    {{-- Что уже записано --}}
    @if($record->repair_action)
        <div class="text-sm">
            <span class="font-semibold" style="color: var(--ui-text)">{{ $actions[$record->repair_action] ?? $record->repair_action }}:</span>
            <span style="color: var(--ui-text-2)">{{ $record->repair_item ?: '—' }}</span>
            @if($itemPending)<span class="ui-badge-warn" title="Свой вариант ждёт проверки администратора">на проверке</span>@endif
        </div>
    @else
        <p class="text-sm" style="color: var(--ui-muted)">Ремонт или замена ещё не указаны</p>
    @endif

    @if($canRepair)
        <form method="POST" action="{{ route('records.researchRepair', ['research', $record->id]) }}" class="space-y-2.5">
            @csrf @method('PATCH')
            <label class="block">
                <span class="ui-label">Что сделано</span>
                <select name="repair_action" x-model="action" required class="ui-input w-full">
                    <option value="">— выберите —</option>
                    @foreach($actions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>

            <div x-show="action" x-cloak class="relative" @click.outside="open = false">
                <span class="ui-label">Узел</span>
                <button type="button" @click="open = !open" class="ui-input w-full text-left flex items-center justify-between gap-2">
                    <span class="min-w-0" :style="label ? '' : 'color: var(--ui-faint)'" x-text="label || 'Выберите узел…'"></span>
                    <span x-show="pending" class="ui-badge-warn shrink-0">на проверке</span>
                </button>

                {{-- Выбор: поиск или лесенка блоков (блок → подблок → узел), как в кодах неисправностей --}}
                <div x-show="open" x-cloak class="rp-pop" @keydown.escape.stop="open = false">
                    <input type="text" x-model="q" placeholder="Поиск по блокам и узлам" class="ui-input w-full !py-1.5 text-sm">

                    <template x-if="results() !== null">
                        <div class="ui-fc-menu !w-full mt-1.5" style="max-height: 320px">
                            <template x-for="r in results()" :key="r.key">
                                <button type="button" class="ui-fc-item" @click="pickResult(r)">
                                    <span class="text-left" x-text="r.label"></span>
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
                                    <button type="button" x-show="parent !== null" class="ui-fc-item is-new" @click="pickGroup(parent)">
                                        <span>✓ Весь блок целиком</span>
                                    </button>
                                    <template x-for="g in childGroups(parent)" :key="'g' + g.id">
                                        <button type="button" class="ui-fc-item"
                                                :class="{ 'is-group': hasChildren(g.id), 'is-open': trail[n] === g.id, 'is-active': node === 'g' + g.id }"
                                                @click="hasChildren(g.id) ? openAt(n, g.id) : pickGroup(g.id)">
                                            <span class="text-left" x-text="g.name"></span>
                                            <span x-show="hasChildren(g.id)" class="ui-fc-count">›</span>
                                        </button>
                                    </template>
                                    <template x-for="i in childItems(parent)" :key="'i' + i.id">
                                        <button type="button" class="ui-fc-item" :class="node === 'i' + i.id && 'is-active'" @click="pickItem(i)">
                                            <span class="text-left" x-text="i.name"></span>
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

                    {{-- Свой вариант — в текущий блок, на проверку администратору --}}
                    <div class="mt-1 pt-1.5" style="border-top: 1px dashed var(--ui-border)">
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

                <input type="hidden" name="repair_node" :value="node">
                <input type="hidden" name="custom_name" :value="node ? '' : custom">
                <input type="hidden" name="custom_group" :value="node ? '' : (customGroup ?? '')">
                @error('repair_node')<p class="text-xs mt-1" style="color: var(--ui-bad)">{{ $message }}</p>@enderror
            </div>

            <p x-show="action === 'replacement'" x-cloak class="text-xs" style="color: var(--ui-text-2)">
                При замене технологу (ОТК) придёт задача оформить акт
            </p>
            <button type="submit" class="ui-btn w-full" :disabled="!action || (!node && !custom.trim())">Сохранить</button>
        </form>
    @endif

    {{-- Акт на замену: нужен при любой замене --}}
    @if($record->repair_action === 'replacement')
        <div class="pt-3 space-y-2" style="border-top: 1px solid var(--ui-border)">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold" style="color: var(--ui-text)">Акт на замену</span>
                @if($record->act_done_at)
                    <x-status-pill value="Оформлен" tone="good" />
                @else
                    <x-status-pill value="Нужен акт" tone="bad" />
                @endif
            </div>
            @if($record->act_done_at)
                <p class="text-xs" style="color: var(--ui-muted)">{{ $record->act_done_at->format('d.m.Y H:i') }}</p>
            @elseif($canAct)
                <form method="POST" action="{{ route('records.researchAct', ['research', $record->id]) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="ui-btn w-full">Акт оформлен</button>
                </form>
            @else
                <p class="text-xs" style="color: var(--ui-muted)">Оформляет технолог (ОТК)</p>
            @endif
        </div>
    @endif
</section>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('repairPicker', (data, init) => ({
        groups: data.groups || [], items: data.items || [],
        action: init.action || '', node: init.node || '', label: init.label || '', pending: !!init.pending,
        open: false, q: '', trail: [], custom: '', customOpen: false, customGroup: null,
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
        // Поиск: блоки и узлы, у которых путь содержит текст
        results() {
            const q = this.q.trim().toLowerCase();
            if (!q) return null;
            const r = [];
            for (const g of this.groups) { const l = this.pathOf(g.id).join(' · '); if (l.toLowerCase().includes(q)) r.push({ key: 'g' + g.id, label: l }); }
            for (const i of this.items) { const l = this.itemLabel(i); if (l.toLowerCase().includes(q)) r.push({ key: 'i' + i.id, label: l, pending: !i.approved }); }
            return r.slice(0, 40);
        },
        enter(id) { this.trail.push(id); },
        // Лесенка: в колонке n открыт блок id — колонки правее него заменяются его содержимым
        openAt(n, id) { this.trail = this.trail.slice(0, n).concat([id]); },
        choose(node, label, pending) { this.node = node; this.label = label; this.pending = pending; this.custom = ''; this.customOpen = false; this.open = false; this.q = ''; },
        pickGroup(id) { this.choose('g' + id, this.pathOf(id).join(' · '), false); },
        pickItem(i) { this.choose('i' + i.id, this.itemLabel(i), !i.approved); },
        pickResult(r) { this.choose(r.key, r.label, !!r.pending); },
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
