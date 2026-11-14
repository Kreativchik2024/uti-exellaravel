/*
 * Страница не прыгает наверх после сохранения.
 *
 * Почти все действия на сайте — обычная отправка формы, после которой сервер возвращает
 * на ту же страницу (back()), и браузер открывает её с самого верха. Перед отправкой
 * запоминаем, где был человек; если после ответа открылась та же страница — возвращаем
 * прокрутку. На другую страницу (создал запись → список) — ничего не делаем.
 *
 * Сообщения «Сохранено» / ошибки на страницах выводятся вверху — раз мы остались внизу,
 * то же сообщение на несколько секунд всплывает внизу экрана (#keep-scroll-flash в шаблоне).
 *
 * Отключить для формы: атрибут data-no-keep-scroll.
 */
(function () {
    'use strict';
    var KEY = 'keep-scroll';
    var MAX_AGE = 60 * 1000;          // ответ сервера дольше минуты — уже не «та же» отправка

    function read() {
        try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
    }
    function clear() {
        try { sessionStorage.removeItem(KEY); } catch (e) {}
    }

    // ---- восстановление ----
    var saved = read();
    clear();
    var restore = saved && saved.path === location.pathname && !location.hash
        && Date.now() - saved.t < MAX_AGE && saved.y > 0;

    if (restore) {
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        var go = function () { window.scrollTo(0, saved.y); };
        go();
        document.addEventListener('DOMContentLoaded', function () { go(); showFlash(); });
        window.addEventListener('load', go);             // картинки/шрифты могли сдвинуть страницу
    }

    function showFlash() {
        if (saved.y < 150) return;                       // сообщение и так видно вверху
        var src = document.getElementById('keep-scroll-flash');
        if (!src) return;
        var text = src.dataset.error || src.dataset.ok;
        if (!text) return;
        var bad = !!src.dataset.error;
        var el = document.createElement('div');
        el.setAttribute('role', bad ? 'alert' : 'status');
        el.textContent = text;
        el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9999;'
            + 'max-width:min(92vw,560px);padding:10px 16px;border-radius:10px;font-size:14px;font-weight:500;'
            + 'box-shadow:0 8px 24px rgba(0,0,0,.18);transition:opacity .4s;cursor:pointer;'
            + (bad ? 'background:var(--ui-bad-soft,#fee2e2);color:var(--ui-bad,#b91c1c);'
                   : 'background:var(--ui-good-soft,#dcfce7);color:var(--ui-good,#15803d);');
        el.addEventListener('click', function () { el.remove(); });
        document.body.appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; }, bad ? 7000 : 3500);
        setTimeout(function () { el.remove(); }, bad ? 7500 : 4000);
    }

    // ---- запоминание перед отправкой формы ----
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || e.defaultPrevented || f.hasAttribute('data-no-keep-scroll')) return;
        if ((f.getAttribute('target') || '').toLowerCase() === '_blank') return;   // ответ в новой вкладке
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, y: window.scrollY, t: Date.now() }));
        } catch (err) {}
    });

    // Переход по ссылке — новая страница, не «возврат после сохранения»
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest && e.target.closest('a[href]');
        if (a && !a.hasAttribute('download') && (a.getAttribute('target') || '') !== '_blank') clear();
    }, true);
})();
