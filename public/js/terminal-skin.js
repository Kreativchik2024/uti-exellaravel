/* ============================================================
   Тема «Терминал» (themes/terminal.css) — живые мелочи оформления:
     • строка состояния внизу боковой панели: дата, время, связь,
       время сеанса на странице;
     • «загрузка терминала» — короткая заставка при первом открытии
       сайта за сеанс браузера и при выборе темы (щелчок/клавиша — пропустить).
   Только интерфейс: данные не читает и не меняет. В других темах ничего не делает.
   ============================================================ */
(function () {
    'use strict';

    var NAME = 'terminal';
    var BOOT_KEY = 'terminal-booted';
    var status = null, timer = null, started = Date.now();

    function active() { return document.documentElement.getAttribute('data-ui-theme') === NAME; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /* ---------- Строка состояния ---------- */
    function renderStatus() {
        if (!status) return;
        var d = new Date();
        var up = Math.floor((Date.now() - started) / 1000);
        var online = navigator.onLine !== false;
        status.innerHTML =
            '<div><b>' + pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + '</b> ' +
            '<b>' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + '</b></div>' +
            '<div>Связь: ' + (online ? '<span class="t-ok">■ есть</span>' : '<span class="t-bad">■ нет</span>') + '</div>' +
            '<div>На странице: ' + pad(Math.floor(up / 3600)) + ':' + pad(Math.floor(up / 60) % 60) + ':' + pad(up % 60) + '</div>';
    }

    function startStatus() {
        // строка состояния — только при боковой панели (на страницах с меню)
        if (status || !document.querySelector('.ui-nav')) return;
        status = document.createElement('div');
        status.className = 't-status';
        status.setAttribute('aria-hidden', 'true');
        document.body.appendChild(status);
        renderStatus();
        timer = setInterval(renderStatus, 1000);
    }

    function stopStatus() {
        if (timer) clearInterval(timer);
        if (status && status.parentNode) status.parentNode.removeChild(status);
        status = null; timer = null;
    }

    /* ---------- «Загрузка терминала» ---------- */
    function boot() {
        if (reducedMotion() || document.querySelector('.t-boot')) return;

        var title = (document.title || 'УТИ').toUpperCase();
        var online = navigator.onLine !== false;
        var lines = [
            'АСУП «' + title + '» · ТЕРМИНАЛ УЧЁТА ПРОИЗВОДСТВА',
            '',
            'ПРОВЕРКА ЭКРАНА .............. <span class="t-ok">ОК</span>',
            'ЗАГРУЗКА ИНТЕРФЕЙСА .......... <span class="t-ok">ОК</span>',
            'СВЯЗЬ С СЕРВЕРОМ ............. ' + (online ? '<span class="t-ok">ОК</span>' : '<span class="t-bad">НЕТ</span>'),
            '',
            'ГОТОВ К РАБОТЕ.'
        ];

        var box = document.createElement('div');
        box.className = 't-boot';
        box.setAttribute('aria-hidden', 'true');
        var text = document.createElement('div');
        box.appendChild(text);
        var hint = document.createElement('div');
        hint.className = 't-boot__hint';
        hint.textContent = 'ЛЮБАЯ КЛАВИША — ПРОПУСТИТЬ';
        box.appendChild(hint);
        document.body.appendChild(box);

        var shown = 0, closed = false, step = null;
        function close() {
            if (closed) return;
            closed = true;
            clearInterval(step);
            document.removeEventListener('keydown', close, true);
            box.classList.add('is-out');
            setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 400);
        }
        // после «включения» кинескопа — строки по одной
        setTimeout(function () {
            step = setInterval(function () {
                shown++;
                text.innerHTML = lines.slice(0, shown).join('\n') + (shown < lines.length ? '\n█' : ' █');
                if (shown >= lines.length) { clearInterval(step); setTimeout(close, 550); }
            }, 120);
        }, 380);
        box.addEventListener('click', close);
        document.addEventListener('keydown', close, true);
        setTimeout(close, 4000);   // страховка: заставка не задерживается
    }

    function firstBootThisSession() {
        try {
            if (sessionStorage.getItem(BOOT_KEY)) return false;
            sessionStorage.setItem(BOOT_KEY, '1');
            return true;
        } catch (e) { return false; }
    }

    /* ---------- Включение / выключение вместе с темой ---------- */
    function update(fromSwitch) {
        if (active()) {
            startStatus();
            if (fromSwitch || firstBootThisSession()) {
                try { sessionStorage.setItem(BOOT_KEY, '1'); } catch (e) {}
                boot();
            }
        } else {
            stopStatus();
        }
    }

    function start() {
        var was = active();
        try { update(false); } catch (e) {}
        document.addEventListener('themechange', function () {
            var now = active();
            try { update(now && !was); } catch (e) {}   // заставка — только при переходе на эту тему
            was = now;
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
