// Маски ввода (настраивает админ: Админ → Маски полей). Разбор — как в App\Support\InputMask:
// цифра — 0/9/#, буква — Я/А/A, буква или цифра — ?/_/*, \X — символ X как есть, остальное — постоянные символы.
// Поле с data-masks='["УМН-9999", …]':
//  - шаблон виден сразу (серым), постоянная часть уже стоит в поле — вводятся только цифры/буквы на свои места;
//  - неподходящий символ просто не вводится (ничего не сдвигается и не теряется);
//  - если набрали постоянную часть сами («УМН-») — повтор пропускается;
//  - не до конца введённое — подсвечивается, форма не отправится, пока не исправят.
const tokens = (pattern) => {
    const out = [];
    const chars = [...pattern];
    for (let i = 0; i < chars.length; i++) {
        const c = chars[i];
        if (c === '\\' && i + 1 < chars.length) { out.push({ t: 'lit', ch: chars[++i] }); continue; }
        out.push(['9', '0', '#'].includes(c) ? { t: '9', ch: c }
            : ['A', 'А', 'Я'].includes(c) ? { t: 'A', ch: c }
            : ['*', '?', '_'].includes(c) ? { t: '*', ch: c }
            : { t: 'lit', ch: c });
    }
    return out;
};

const fits = (tok, c) => tok.t === '9' ? /\d/.test(c) : tok.t === 'A' ? /\p{L}/u.test(c) : /[\p{L}\d]/u.test(c);
const same = (a, b) => a.toLowerCase() === b.toLowerCase();
const ghostOf = (t) => t.t === '9' ? '0' : t.t === 'A' ? 'Я' : t.t === '*' ? '?' : t.ch;

/** Постоянные символы в начале маски — их поле получает сразу. */
const lead = (pattern) => {
    let s = '';
    for (const t of tokens(pattern)) { if (t.t !== 'lit') break; s += t.ch; }
    return s;
};

/**
 * Разложить введённое по маске. Постоянные символы ставятся сами (набранные вручную — пропускаются),
 * неподходящие символы отбрасываются. {out, complete, k, skipped, guessed, filled}
 */
const fit = (raw, pattern, deleting = false) => {
    const tk = tokens(pattern);
    const src = [...raw];
    let out = '', i = 0, k = 0, skipped = 0, guessed = 0, filled = 0;
    while (k < tk.length && i < src.length) {
        const tok = tk[k], c = src[i];
        if (tok.t === 'lit') {
            out += tok.ch; k++;
            if (same(c, tok.ch)) i++; else guessed++;
            continue;
        }
        if (fits(tok, c)) { out += c; i++; k++; filled++; continue; }
        i++; skipped++;   // символ не для этого места — не вводится
    }
    skipped += src.length - i;   // длиннее маски — лишнее не вводится
    // Следующие постоянные символы — сразу (кроме стирания, иначе их не удалить)
    if (!deleting && filled) while (k < tk.length && tk[k].t === 'lit') out += tk[k++].ch;
    return { out, complete: k === tk.length, k, skipped, guessed, filled };
};

const placeholder = (pattern) => tokens(pattern).map(ghostOf).join('');

/** Лучшая маска: полностью введённая, потом — где меньше отброшено и додумано. {…fit, mask} или null */
const best = (raw, masks, deleting) => {
    const all = masks.map(m => ({ ...fit(raw, m, deleting), mask: m }));
    if (!all.length) return null;
    all.sort((a, b) => (b.complete - a.complete) || (a.skipped - b.skipped) || (a.guessed - b.guessed) || (b.filled - a.filled));
    return all[0];
};

/** Общая постоянная часть в начале у всех масок поля. */
const commonLead = (masks) => {
    const leads = masks.map(lead);
    let s = leads[0] || '';
    for (const l of leads) while (s && !l.startsWith(s)) s = s.slice(0, -1);
    return s;
};

const bind = (el) => {
    if (el.dataset.maskBound) return;
    let masks = [];
    try { masks = JSON.parse(el.dataset.masks || '[]'); } catch (e) { return; }
    if (!masks.length) return;
    el.dataset.maskBound = '1';
    const msg = 'Формат: ' + masks.map(placeholder).join(' или ');
    const start = commonLead(masks);
    const initial = el.value;   // значение, внесённое до маски, — сервер его пропустит, форму не блокируем

    // Шаблон серым под полем: введённая часть — прозрачная (ровно под текстом), остальное — серым
    const wrap = document.createElement('span');
    wrap.className = 'mask-wrap';
    el.parentNode.insertBefore(wrap, el);
    wrap.appendChild(el);
    const ghost = document.createElement('span');
    ghost.className = 'mask-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    wrap.appendChild(ghost);
    el.removeAttribute('placeholder');
    el.title = msg;

    const syncStyle = () => {
        const cs = getComputedStyle(el);
        for (const p of ['fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'lineHeight',
            'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
            'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'textAlign']) ghost.style[p] = cs[p];
    };
    const draw = () => {
        const v = el.value;
        let shown;
        if (v === '') shown = placeholder(masks[0]);   // пусто — весь шаблон
        else {
            const r = best(v, masks, true);
            const tk = tokens(r.mask);
            shown = r.complete ? '' : tk.slice(r.k).map(ghostOf).join('');
        }
        const typed = document.createElement('span');
        typed.className = 'mask-ghost__typed';
        typed.textContent = v;
        const left = document.createElement('span');
        left.textContent = shown;
        ghost.replaceChildren(typed, left);
        ghost.scrollLeft = el.scrollLeft;
    };
    const check = (final) => {
        const v = el.value;
        const r = v ? best(v, masks, true) : null;
        const bad = v !== '' && v !== initial && (!r || !r.complete || r.skipped > 0);
        el.setCustomValidity(bad && final ? msg : '');
        el.classList.toggle('mask-bad', bad && final);
    };
    const caretToEnd = () => { try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {} };

    el.addEventListener('focus', () => {
        // Постоянная часть уже стоит — вводятся только цифры/буквы
        if (el.value === '' && start) { el.value = start; requestAnimationFrame(caretToEnd); }
        syncStyle(); draw();
    });
    el.addEventListener('input', (e) => {
        const deleting = (e.inputType || '').startsWith('delete');
        const r = best(el.value, masks, deleting);
        if (r && r.out !== el.value) { el.value = r.out; caretToEnd(); }
        draw(); check(false);
    });
    el.addEventListener('scroll', () => { ghost.scrollLeft = el.scrollLeft; });
    el.addEventListener('blur', () => {
        if (start && el.value === start) el.value = '';   // осталась только постоянная часть — поле пустое
        draw(); check(true);
    });
    el.form?.addEventListener('submit', () => { if (start && el.value === start) el.value = ''; check(true); }, true);
    window.addEventListener('resize', syncStyle);
    syncStyle(); draw();
    if (el.value) check(true);
};

const init = (root = document) => root.querySelectorAll('[data-masks]').forEach(bind);

window.InputMask = { tokens, fit, best, placeholder, init };
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => init()) : init();
