/* ============================================================
   Тема «Аниме» (themes/anime.css) — живые мелочи оформления:
     • падающие лепестки сакуры (анимация CSS, двигается transform — дёшево);
     • искорки за курсором мыши (не чаще раза в 45 мс, не больше 18 сразу).
   Только интерфейс: события не перехватываются. В других темах,
   на сенсорных экранах (искорки) и при отключённой анимации — ничего.
   ============================================================ */
(function () {
    'use strict';

    var PETALS = 16;
    var box = null, alive = 0, last = 0;

    function active() { return document.documentElement.getAttribute('data-ui-theme') === 'anime'; }
    function reducedMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
    function rnd(a, b) { return a + Math.random() * (b - a); }

    function mountPetals() {
        if (box || reducedMotion()) return;
        box = document.createElement('div');
        box.className = 'an-petals';
        box.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < PETALS; i++) {
            var p = document.createElement('i');
            p.className = 'an-petal';
            p.style.left = rnd(0, 100) + 'vw';
            p.style.setProperty('--s', rnd(9, 18).toFixed(1) + 'px');
            p.style.setProperty('--d', rnd(10, 20).toFixed(1) + 's');
            p.style.setProperty('--delay', (-rnd(0, 20)).toFixed(1) + 's');   // сразу по всему экрану
            p.style.setProperty('--dx', rnd(-220, 60).toFixed(0) + 'px');
            p.style.setProperty('--r', rnd(240, 720).toFixed(0) + 'deg');
            p.style.setProperty('--sw', rnd(2, 4).toFixed(1) + 's');
            box.appendChild(p);
        }
        document.body.appendChild(box);
    }
    function unmountPetals() { if (box) { box.remove(); box = null; } }

    function spark(e) {
        if (!active() || e.pointerType !== 'mouse' || reducedMotion()) return;
        var now = Date.now();
        if (now - last < 45 || alive >= 18) return;
        last = now;
        var s = document.createElement('span');
        s.className = 'an-spark';
        s.setAttribute('aria-hidden', 'true');
        s.style.left = (e.clientX + rnd(-6, 6)) + 'px';
        s.style.top = (e.clientY + rnd(4, 12)) + 'px';
        document.body.appendChild(s);
        alive++;
        setTimeout(function () { s.remove(); alive--; }, 720);
    }

    function update() { if (active()) mountPetals(); else unmountPetals(); }

    function start() {
        try { update(); } catch (x) {}
        document.addEventListener('themechange', function () { try { update(); } catch (x) {} });
        document.addEventListener('pointermove', function (e) { try { spark(e); } catch (x) {} }, { passive: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
