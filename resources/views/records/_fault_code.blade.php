{{--
    Поле «Код неисправности»: вписать код или описать неисправность словами.
    Подсказки — только коды этой вкладки. Пустое поле — выбор по дереву: блок → подблок → коды
    (уровень с одним вариантом проходится сам). Вписали текст — поиск по всем кодам вкладки.
    В списке — одни коды; описание, разъяснение и раздел выделенного кода — рядом в отдельном окошке
    (если описание есть). Выбранный код — карточка с расшифровкой.
    Новой неисправности при сохранении присваивается код автоматически (App\Services\FaultCodes::resolve).
--}}
<div class="relative" x-data="faultCode(@js($options), @js((string) $value), @js(route('records.faultCodes', $sheet)))"
     @click.outside="open = false" @keydown.escape="open = false">
    <input type="text" name="fault_code" x-model="value" autocomplete="off"
           @focus="open = true; search()" @input="open = true; search()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.backspace="if (open && browsing() && trail.length) { $event.preventDefault(); up(); }"
           @keydown.enter="if (open && list().length) { $event.preventDefault(); pick(list()[active]); }"
           @if($isReq) required @endif @if($isTimeLocked) disabled @endif
           placeholder="Код или опишите неисправность словами"
           :class="value && !/\s/.test(value) ? 'font-mono' : ''"
           class="{{ $baseInput }} @error('fault_code') !border-red-400 !ring-red-500/15 @enderror">

    {{-- Выпадающий список кодов + окошко с описанием выделенного --}}
    <div x-show="open && list().length" x-cloak x-ref="pop" class="ui-fc-pop" :class="ancestors().length && 'has-stairs'">
        {{-- Лесенка: пройденные уровни — колонками, каждая ниже предыдущей; клик — перейти в другой блок этого уровня.
             Окошко с описанием кода — справа, а при лесенке — под колонками (сбоку ему не хватает места) --}}
        <div class="ui-fc-cols">
        <template x-for="(col, n) in ancestors()" :key="'col' + n + col.open">
            <div class="ui-fc-menu ui-stairs-col" :style="'--i:' + n">
                <template x-for="g in col.groups" :key="g.key">
                    <button type="button" class="ui-fc-item is-group" :class="g.name === col.open && 'is-open'" @mousedown.prevent="jump(col.depth, g.name)">
                        <span class="flex items-center justify-between gap-2 w-full min-w-0">
                            <span class="truncate font-medium" x-text="g.name"></span>
                            <span class="ui-fc-count"><span x-text="g.count"></span> ›</span>
                        </span>
                    </button>
                </template>
                <template x-for="c in col.here" :key="'h' + c.code">
                    <button type="button" class="ui-fc-item" @mousedown.prevent="pick(c)"><span class="ui-fc-code" x-text="c.code"></span></button>
                </template>
            </div>
        </template>
        <div class="ui-fc-menu ui-stairs-col" role="listbox" :style="'--i:' + ancestors().length">
            <div x-show="browsing() && trail.length && !ancestors().length" class="ui-fc-trail" x-text="trail.join(' › ')"></div>
            <template x-for="(it, i) in list()" :key="it.key">
                <button type="button" class="ui-fc-item" :class="{ 'is-active': i === active, 'is-new': it.isNew, 'is-back': it.isBack, 'is-group': it.isGroup }"
                        @mousedown.prevent="pick(it)" @mousemove="active !== i && (active = i)">
                    <template x-if="it.isBack"><span>← Назад</span></template>
                    <template x-if="it.isGroup">
                        <span class="flex items-center justify-between gap-2 w-full min-w-0">
                            <span class="truncate font-medium" x-text="it.name"></span>
                            <span class="ui-fc-count"><span x-text="it.count"></span> ›</span>
                        </span>
                    </template>
                    <template x-if="!it.isNew && !it.isGroup && !it.isBack"><span class="ui-fc-code" x-text="it.code"></span></template>
                    <template x-if="it.isNew"><span class="font-semibold">+ Новая неисправность</span></template>
                </button>
            </template>
        </div>
        </div>
        <div class="ui-fc-detail" x-show="current() && !current().isGroup && !current().isBack && (current().description || current().isNew)" x-transition.opacity.duration.100ms>
            <template x-if="current() && !current().isNew && !current().isGroup && !current().isBack">
                <div>
                    <div class="ui-fc-detail__code" x-text="current().code"></div>
                    <div class="ui-fc-detail__desc" x-text="current().description"></div>
                    <div x-show="current().hint" class="ui-fc-detail__hint" x-text="current().hint"></div>
                    <div x-show="current().group" class="ui-fc-detail__group" x-text="current().group"></div>
                </div>
            </template>
            <template x-if="current() && current().isNew">
                <div>
                    <div class="ui-fc-detail__desc">«<span x-text="current().description"></span>»</div>
                    <div class="ui-fc-detail__hint">При сохранении получит код <b x-text="current().code"></b>, администратор проверит.</div>
                    <div class="ui-fc-detail__group" x-text="current().group"></div>
                </div>
            </template>
        </div>
    </div>

    {{-- Расшифровка выбранного кода — отдельной карточкой, только если описание есть --}}
    <div x-show="!open && known() && known().description" x-cloak class="ui-fc-card">
        <div class="ui-fc-detail__desc" x-text="known() && known().description"></div>
        <div x-show="known() && known().hint" class="ui-fc-detail__hint" x-text="known() && known().hint"></div>
        <div x-show="known() && known().group" class="ui-fc-detail__group" x-text="known() && known().group"></div>
    </div>
    {{-- Что будет при сохранении, если вписан текст, а не код --}}
    <p class="mt-1.5 text-xs ui-fc-note" x-show="!open && note()" x-html="note()"></p>
    {{-- Подсказка по тексту причины (если поле кода ещё пустое) --}}
    <p x-show="hint && !value" x-cloak class="mt-1.5 text-xs" style="color: var(--ui-accent-text)">
        По описанию причины похоже на <b x-text="hint && hint.code"></b> — <span x-text="hint && hint.description"></span>
        <button type="button" class="underline font-semibold ml-1" @click="pick(hint)">подставить</button>
    </p>
</div>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('faultCode', (options, initial, url) => ({
        value: initial, open: false, active: 0, remote: null, newItem: null, hint: null, timer: null, pendingNew: null,
        trail: [],
        local: options.map(o => ({ key: 'c' + o.code, path: [], ...o })),
        init() {
            // Причина неисправности (ПГП): по её тексту подсказываем код
            const reason = this.$el.closest('form')?.querySelector('[name="defect_reason"]');
            if (reason) {
                let t = null;
                reason.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => this.hintFrom(reason.value), 500); });
                if (reason.value && !this.value) this.hintFrom(reason.value);
            }
            // Меню-лесенка привязано к окну браузера (fixed), а не к форме: карточка формы обрезает края
            // (overflow: hidden), из-за этого колонки и описание уходили за её границу. Пересчёт — при каждом шаге и прокрутке.
            const refit = () => this.$nextTick(() => this.fit());
            this.$watch('open', refit);
            this.$watch('trail', refit);
            this.$watch('active', refit);
            this.$watch('value', refit);
            window.addEventListener('resize', refit);
            window.addEventListener('scroll', () => { if (this.open) this.fit(); }, true);
        },
        fit() {
            const pop = this.$refs.pop;
            const input = this.$el.querySelector('input[name="fault_code"]');
            if (!pop || !input || !this.open || !pop.getClientRects().length) return;
            const r = input.getBoundingClientRect();
            const vw = document.documentElement.clientWidth, vh = window.innerHeight;
            const narrow = vw <= 900;   // узкий экран — ступеньки вниз: ширина поля, но не уже 360px и в пределах окна
            const w = Math.min(vw - 16, Math.max(r.width, 360));
            Object.assign(pop.style, { position: 'fixed', right: 'auto', transform: '', maxHeight: 'none', overflowY: '',
                left: (narrow ? Math.max(8, Math.min(r.left, vw - 8 - w)) : r.left) + 'px', top: (r.bottom + 4) + 'px',
                width: narrow ? w + 'px' : '' });

            const p = pop.getBoundingClientRect();
            // по горизонтали — в пределах окна
            if (!narrow && p.right > vw - 8) pop.style.left = Math.max(8, r.left - (p.right - (vw - 8))) + 'px';
            // по вертикали: не влезает вниз, а сверху места больше — открыть вверх; иначе — прокрутка внутри
            const below = vh - r.bottom - 12, above = r.top - 12;
            if (p.height > below && above > below) {
                const h = Math.min(p.height, above);
                pop.style.top = (r.top - 4 - h) + 'px';
                if (p.height > above) Object.assign(pop.style, { maxHeight: above + 'px', overflowY: 'auto' });
            } else if (p.height > below) {
                Object.assign(pop.style, { maxHeight: Math.max(160, below) + 'px', overflowY: 'auto' });
            }
        },
        esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
        known() { return this.local.find(x => x.code.toLowerCase() === String(this.value).trim().toLowerCase()); },
        current() { return this.list()[this.active] || null; },
        // Пустое поле — выбор по дереву
        browsing() { return String(this.value).trim() === ''; },
        under(trail) { return this.local.filter(x => trail.every((n, i) => x.path[i] === n)); },
        level(trail) {
            const codes = this.under(trail);
            const groups = new Map();
            const here = [];
            for (const x of codes) {
                if (x.used) continue;   // старые значения из записей, которых нет в справочнике, — только в поиске
                const n = x.path[trail.length];
                if (n === undefined) here.push(x);
                else groups.set(n, (groups.get(n) || 0) + 1);
            }
            return { groups: [...groups].map(([name, count]) => ({ key: 'g' + name, isGroup: true, name, count })), here };
        },
        // Уровень с единственным подблоком и без своих кодов проходится сам
        settle() {
            for (let guard = 0; guard < 10; guard++) {
                const l = this.level(this.trail);
                if (l.groups.length === 1 && !l.here.length) this.trail.push(l.groups[0].name); else break;
            }
        },
        up() {
            this.trail.pop();
            // поднимаемся и через уровни, пройденные автоматически
            while (this.trail.length) {
                const l = this.level(this.trail);
                if (l.groups.length === 1 && !l.here.length) this.trail.pop(); else break;
            }
            this.active = 0;
        },
        // Пройденные уровни для лесенки (без пройденных автоматически): [{depth, open, groups, here}]
        ancestors() {
            if (!this.browsing() || this.remote !== null) return [];
            const cols = [];
            for (let d = this.autoDepth(); d < this.trail.length; d++) {
                const l = this.level(this.trail.slice(0, d));
                cols.push({ depth: d, open: this.trail[d], groups: l.groups, here: l.here });
            }
            return cols;
        },
        // Клик по блоку в колонке-ступеньке: открыть его вместо прежнего на этом уровне
        jump(depth, name) {
            this.trail = this.trail.slice(0, depth).concat([name]);
            this.settle();
            this.active = 0;
        },
        list() {
            if (this.browsing() && this.remote === null) {
                if (!this.trail.length) this.settle();
                const l = this.level(this.trail);
                // «Назад» не нужен: пройденные уровни видны ступеньками слева (Backspace — на уровень выше)
                return [...l.groups, ...l.here];
            }
            const q = String(this.value).trim().toLowerCase();
            let items = this.remote !== null ? this.remote
                : this.local.filter(x => !q || x.code.toLowerCase().startsWith(q) || (x.description || '').toLowerCase().includes(q)).slice(0, 30);
            if (this.known()) items = items.filter(x => x.code !== this.known().code || q !== x.code.toLowerCase());
            return this.newItem ? [...items, this.newItem] : items;
        },
        search() {
            this.active = 0;
            clearTimeout(this.timer);
            const q = String(this.value).trim();
            if (q.length < 2) { this.remote = null; this.newItem = null; if (!q) this.trail = []; return; }
            this.timer = setTimeout(async () => {
                try {
                    const d = await (await fetch(url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
                    if (String(this.value).trim() !== q) return;
                    this.remote = d.items.map(x => ({ ...x, key: 'r' + x.code }));
                    this.newItem = d.new ? { ...d.new, key: 'new', isNew: true } : null;
                } catch (e) {}
            }, 220);
        },
        async hintFrom(text) {
            if (String(text).trim().length < 4 || this.value) { this.hint = null; return; }
            try {
                const d = await (await fetch(url + '?q=' + encodeURIComponent(text), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
                const best = d.items.find(x => x.score >= 0.5);
                this.hint = best || null;
            } catch (e) {}
        },
        // Сколько уровней пройдено автоматически от корня (туда «Назад» не ведёт)
        autoDepth() {
            const t = [];
            for (let guard = 0; guard < 10; guard++) {
                const l = this.level(t);
                if (l.groups.length === 1 && !l.here.length) t.push(l.groups[0].name); else break;
            }
            return t.length;
        },
        pick(it) {
            if (!it) return;
            if (it.isGroup) { this.trail.push(it.name); this.settle(); this.active = 0; return; }
            if (it.isBack) { this.up(); return; }
            this.value = it.isNew ? it.description : it.code;
            this.pendingNew = it.isNew ? it : null;
            this.open = false; this.remote = null; this.newItem = null; this.hint = null;
            this.trail = [];
        },
        move(d) { const n = this.list().length; if (n) this.active = (this.active + d + n) % n; },
        note() {
            if (this.known()) return '';
            if (this.pendingNew && this.pendingNew.description === this.value)
                return 'Новая неисправность: при сохранении получит код <b>' + this.esc(this.pendingNew.code) + '</b> («' + this.esc(this.pendingNew.group) + '»)';
            if (String(this.value).trim().length >= 4 && /\s/.test(this.value))
                return 'При сохранении подберётся код похожей неисправности или будет добавлен новый';
            return '';
        },
    }));
});
</script>
@endonce
