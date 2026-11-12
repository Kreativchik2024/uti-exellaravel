/* ============================================================
   Фон оформленных тем: картинки из пула сменяются сами.

   Пул — window.UI_SKIN_POOLS = { peaky: [url, …], … } (собирает
   resources/views/partials/theme-skins.blade.php из папок
   public/img/themes/<тема>/). Картинка выбирается по времени:
   каждые PERIOD минут — следующая, одинаково на всех страницах
   (при переходе между страницами фон не прыгает). Пока страница
   открыта — смена плавная. Слой .ui-skin-bg, стили — themes/base.css.
   ============================================================ */
(function () {
    'use strict';

    var PERIOD = 5 * 60 * 1000;   // как часто менять картинку
    var pools = window.UI_SKIN_POOLS || {};
    var box = null, layers = [], front = 0, shownUrl = null;

    function skin() { return document.documentElement.getAttribute('data-ui-theme'); }

    // Курсоры темы — загрузить заранее: иначе при первом наведении/прокрутке курсор может мигнуть
    var me = document.currentScript;
    var imgBase = me ? me.src.replace(/\/js\/[^\/]*$/, '/img/cursors/') : '/img/cursors/';
    var preloaded = {};
    function preloadCursors(name) {
        if (!name || preloaded[name]) return;
        preloaded[name] = true;
        var hi = (window.devicePixelRatio || 1) > 1.25 ? '@2x' : '';
        [name, name + '-pointer'].forEach(function (f) { new Image().src = imgBase + f + hi + '.png'; });
    }

    function ensureBox() {
        if (box) return;
        box = document.createElement('div');
        box.className = 'ui-skin-bg no-anim';
        box.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < 2; i++) { layers.push(box.appendChild(document.createElement('i'))); }
        document.body.insertBefore(box, document.body.firstChild);
    }

    function show(url, instant) {
        var put = function () {
            var next = layers[1 - front];
            next.style.backgroundImage = 'url("' + url + '")';
            if (instant) box.classList.add('no-anim');
            next.classList.add('is-on');
            layers[front].classList.remove('is-on');
            front = 1 - front;
            if (instant) requestAnimationFrame(function () { requestAnimationFrame(function () { box.classList.remove('no-anim'); }); });
        };
        if (instant) { put(); return; }
        // плавная смена — только когда картинка уже загружена
        var img = new Image();
        img.onload = put;
        img.onerror = function () {};
        img.src = url;
    }

    function update() {
        preloadCursors(skin());
        var list = pools[skin()] || [];
        if (!list.length) {
            if (box) box.hidden = true;
            shownUrl = null;
            return;
        }
        ensureBox();
        box.hidden = false;
        var url = list[Math.floor(Date.now() / PERIOD) % list.length];
        if (url === shownUrl) return;
        var first = shownUrl === null;
        shownUrl = url;
        show(url, first);
    }

    function start() {
        update();
        setInterval(update, 30 * 1000);
        document.addEventListener('themechange', function () { update(); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
