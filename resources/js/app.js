// Inter с кириллицей. Наборы символов описаны через unicode-range —
// браузер скачивает только нужные (кириллицу и латиницу).
import '@fontsource-variable/inter';



import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Черновик поля с атрибутом data-draft-key: текст хранится в браузере, пока его не отправили.
// Другие кнопки на странице (акт, статус…) обновляют её — набранный текст возвращается на место.
(() => {
    const store = {
        get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { v ? localStorage.setItem(k, v) : localStorage.removeItem(k); } catch (e) {} },
    };
    const init = () => document.querySelectorAll('[data-draft-key]').forEach((el) => {
        const key = 'draft:' + el.dataset.draftKey;
        const saved = store.get(key);
        if (saved && !el.value.trim()) el.value = saved;
        el.addEventListener('input', () => store.set(key, el.value.trim() ? el.value : ''));
        // Отправили именно эту форму — черновик больше не нужен (при ошибке Laravel вернёт текст сам через old())
        el.form?.addEventListener('submit', () => store.set(key, ''));
    });
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
})();

// Подсказка с расшифровкой для любого элемента с атрибутом data-tip.
// Одно окошко на странице, положение считается по экрану: не обрезается таблицами и не уходит за край.
(() => {
    let bubble = null;
    let anchor = null;

    const place = () => {
        if (!bubble || !anchor || !anchor.isConnected) return hide();
        const r = anchor.getBoundingClientRect();
        const gap = 8;
        const bw = bubble.offsetWidth;
        const bh = bubble.offsetHeight;
        let left = r.left + r.width / 2 - bw / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - bw - 8));
        let top = r.top - bh - gap;
        if (top < 8) top = Math.min(r.bottom + gap, window.innerHeight - bh - 8);
        bubble.style.left = `${left}px`;
        bubble.style.top = `${Math.max(8, top)}px`;
    };

    const show = (el) => {
        const text = el.getAttribute('data-tip');
        if (!text) return;
        if (!bubble) {
            bubble = document.createElement('div');
            bubble.className = 'ui-tip-bubble';
            bubble.setAttribute('role', 'tooltip');
            document.body.appendChild(bubble);
        }
        anchor = el;
        bubble.textContent = text;
        bubble.style.display = 'block';
        place();
    };

    const hide = () => {
        if (bubble) bubble.style.display = 'none';
        anchor = null;
    };

    const tipOf = (target) => (target instanceof Element ? target.closest('[data-tip]') : null);

    document.addEventListener('mouseover', (e) => {
        const el = tipOf(e.target);
        if (el && el !== anchor) show(el);
    });
    document.addEventListener('mouseout', (e) => {
        const el = tipOf(e.target);
        if (el && !el.contains(e.relatedTarget)) hide();
    });
    document.addEventListener('focusin', (e) => {
        const el = tipOf(e.target);
        if (el) show(el);
    });
    document.addEventListener('focusout', hide);
    window.addEventListener('scroll', () => { if (anchor) place(); }, true);
    window.addEventListener('resize', () => { if (anchor) place(); });
})();
