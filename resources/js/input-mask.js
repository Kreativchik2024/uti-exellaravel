// Маски ввода (настраивает админ: Админ → Маски полей). Разбор — как в App\Support\InputMask:
// 9 — цифра, A — буква, * — буква или цифра, \X — символ X как есть, остальное — постоянные символы (подставляются сами).
// Поле с data-masks='["УМН-9999", …]': при вводе подставляются постоянные символы и не пускаются лишние;
// если введено не по маске — поле подсвечивается и форма не отправится, пока не исправят.
const tokens = (pattern) => {
    const out = [];
    const chars = [...pattern];
    for (let i = 0; i < chars.length; i++) {
        const c = chars[i];
        if (c === '\\' && i + 1 < chars.length) { out.push({ t: 'lit', ch: chars[++i] }); continue; }
        out.push(['9', 'A', '*'].includes(c) ? { t: c, ch: c } : { t: 'lit', ch: c });
    }
    return out;
};

const fits = (tok, c) => tok.t === '9' ? /\d/.test(c) : tok.t === 'A' ? /\p{L}/u.test(c) : /[\p{L}\d]/u.test(c);

/** Подогнать введённое под маску: {out, complete} или null, если не подходит. */
const fit = (raw, pattern, deleting = false) => {
    const tk = tokens(pattern);
    const src = [...raw];
    let out = '', i = 0, k = 0, guessed = 0;   // guessed — сколько постоянных символов подставлено вместо введённых
    while (k < tk.length && i < src.length) {
        const tok = tk[k], c = src[i];
        if (tok.t === 'lit') {
            out += tok.ch;
            if (c.toLowerCase() === tok.ch.toLowerCase()) i++; else guessed++;
            k++;
            continue;
        }
        if (!fits(tok, c)) return null;
        out += c; i++; k++;
    }
    if (i < src.length) return null;   // длиннее маски
    // Следующие постоянные символы — сразу (кроме стирания, иначе их не удалить)
    if (!deleting && out.length) while (k < tk.length && tk[k].t === 'lit') out += tk[k++].ch;
    return { out, complete: k === tk.length, guessed };
};

const placeholder = (pattern) => tokens(pattern).map(t => t.t === '9' ? '0' : t.t === 'A' ? 'Я' : t.t === '*' ? '?' : t.ch).join('');

/** Лучшее совпадение среди масок: {out, complete, mask} или null. */
const best = (raw, masks, deleting) => {
    const all = masks.map(m => ({ r: fit(raw, m, deleting), m })).filter(x => x.r)
        .map(x => ({ ...x.r, mask: x.m }));
    if (!all.length) return null;
    // Сначала полностью введённые, потом — где меньше пришлось додумывать
    all.sort((a, b) => (b.complete - a.complete) || (a.guessed - b.guessed));
    return all[0];
};

const bind = (el) => {
    if (el.dataset.maskBound) return;
    el.dataset.maskBound = '1';
    let masks = [];
    try { masks = JSON.parse(el.dataset.masks || '[]'); } catch (e) { return; }
    if (!masks.length) return;
    const msg = 'Формат: ' + masks.map(placeholder).join(' или ');

    const check = (final) => {
        const v = el.value;
        const r = v ? best(v, masks, true) : null;
        const bad = v !== '' && (!r || (final && !r.complete));
        el.setCustomValidity(bad && final ? msg : '');
        el.classList.toggle('mask-bad', bad);
    };
    el.addEventListener('input', (e) => {
        const deleting = (e.inputType || '').startsWith('delete');
        const r = best(el.value, masks, deleting);
        if (r && r.out !== el.value) el.value = r.out;
        check(false);
    });
    el.addEventListener('blur', () => check(true));
    el.form?.addEventListener('submit', () => check(true), true);
    if (el.value) check(true);
};

const init = (root = document) => root.querySelectorAll('[data-masks]').forEach(bind);

window.InputMask = { tokens, fit, best, placeholder, init };
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => init()) : init();
