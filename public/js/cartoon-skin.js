/* ============================================================
   Тема «Мультик» (themes/cartoon.css): по щелчку на кнопке
   рядом с курсором выскакивает комиксная «звёздочка» с надписью
   («БАЦ!», «ХЛОП!»…) и тает за полсекунды.
   Только интерфейс: щелчок не перехватывается и не отменяется.
   В других темах и при отключённой анимации ничего не делает.
   ============================================================ */
(function () {
    'use strict';

    var WORDS = ['БАЦ!', 'ХЛОП!', 'ВЖУХ!', 'ТЫЦ!', 'БУМ!', 'ОП!', 'ДЗЫНЬ!'];
    var alive = 0;

    function active() { return document.documentElement.getAttribute('data-ui-theme') === 'cartoon'; }
    function reducedMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }

    function pop(x, y) {
        if (alive >= 3) return;
        var el = document.createElement('span');
        el.className = 'ct-pop';
        el.setAttribute('aria-hidden', 'true');
        el.textContent = WORDS[Math.floor(Math.random() * WORDS.length)];
        el.style.left = x + 'px';
        el.style.top = (y - 26) + 'px';
        document.body.appendChild(el);
        alive++;
        setTimeout(function () { el.remove(); alive--; }, 650);
    }

    document.addEventListener('pointerdown', function (e) {
        try {
            if (!active() || reducedMotion() || e.button !== 0) return;
            var t = e.target.closest && e.target.closest('.ui-btn, button[type="submit"], .ui-chat__fab');
            if (t && !t.disabled) pop(e.clientX, e.clientY);
        } catch (x) {}
    }, { passive: true, capture: true });
})();
