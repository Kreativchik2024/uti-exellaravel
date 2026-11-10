/*
 * Автовыход при бездействии (сервер: App\Http\Middleware\LogoutWhenIdle).
 *
 *  - Пока человек двигает мышью, печатает, нажимает или листает, раз в минуту сообщаем серверу,
 *    что он работает (/session/ping), — иначе долгое заполнение формы закончилось бы выходом.
 *  - Активность общая для всех вкладок: игра в одной вкладке продлевает и остальные
 *    (иначе забытая вкладка через 30 минут выкидывала бы из системы посреди игры).
 *  - Перед выходом спрашиваем сервер (/session/status): если человек был активен где-то ещё —
 *    в другой вкладке или на другом устройстве — не выходим.
 *  - За минуту до выхода — предупреждение с кнопкой «Я здесь».
 *
 * Настройка: <meta name="idle-logout" content="минуты" data-ping="url" data-status="url" data-logout="url">
 */
(function () {
    var meta = document.querySelector('meta[name="idle-logout"]');
    if (!meta) return;
    var minutes = parseInt(meta.content, 10);
    if (!(minutes > 0)) return;

    var LIMIT = minutes * 60 * 1000, WARN = Math.min(60 * 1000, LIMIT / 2), PING_EVERY = 60 * 1000, SHARE_KEY = 'uti-last-activity';
    var lastActivity = Date.now(), lastPing = Date.now(), lastShare = 0, dialog = null, countdown = null, checking = false;

    function token() { return window.csrfToken ? window.csrfToken() : ''; }
    function json(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    }

    function ping() {
        lastPing = Date.now();
        json(meta.dataset.ping).then(function (r) { if (r.status === 401) location.reload(); }).catch(function () {});
    }

    // Активность в любой вкладке — общая
    function share(now) {
        if (now - lastShare < 5000) return;
        lastShare = now;
        try { localStorage.setItem(SHARE_KEY, String(now)); } catch (e) {}
    }
    window.addEventListener('storage', function (e) {
        if (e.key !== SHARE_KEY) return;
        var t = parseInt(e.newValue, 10);
        if (t > lastActivity) { lastActivity = t; if (dialog) hideWarning(); }
    });

    function activity() {
        var now = Date.now();
        lastActivity = now;
        share(now);
        if (dialog) hideWarning();
        if (now - lastPing > PING_EVERY) ping();
    }

    ['mousemove', 'mousedown', 'pointerdown', 'click', 'keydown', 'keyup', 'wheel', 'touchstart', 'touchmove', 'scroll', 'input', 'focus'].forEach(function (ev) {
        window.addEventListener(ev, activity, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) activity(); });

    function logout() {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = meta.dataset.logout;
        var t = document.createElement('input');
        t.type = 'hidden'; t.name = '_token'; t.value = token();
        f.appendChild(t);
        var why = document.createElement('input');   // в журнале — «автовыход», а не обычный выход
        why.type = 'hidden'; why.name = 'reason'; why.value = 'idle';
        f.appendChild(why);
        document.body.appendChild(f);
        f.submit();
    }

    // Сервер знает про активность во всех вкладках и на всех устройствах
    function checkServer(onIdle) {
        if (checking) return;
        checking = true;
        json(meta.dataset.status)
            .then(function (r) {
                if (r.status === 401) { location.reload(); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function (d) {
                checking = false;
                if (!d) return;
                var serverLast = Date.now() - d.idle_seconds * 1000;
                if (serverLast > lastActivity + 1000) { lastActivity = serverLast; if (dialog) hideWarning(); return; }
                onIdle();
            })
            .catch(function () { checking = false; });
    }

    function showWarning() {
        if (dialog) return;
        dialog = document.createElement('div');
        dialog.className = 'ui-idle';
        dialog.setAttribute('role', 'alertdialog');
        dialog.innerHTML = '<div class="ui-idle__card"><div class="ui-idle__title">Вы ещё здесь?</div>'
            + '<div class="ui-idle__text">Нет действий ' + minutes + ' мин. Через <b data-left>60</b> с выполнится выход из системы.</div>'
            + '<button type="button" class="ui-btn ui-btn--primary">Я здесь</button></div>';
        dialog.querySelector('button').addEventListener('click', function () { activity(); ping(); });
        document.body.appendChild(dialog);
        countdown = setInterval(function () {
            var left = Math.max(0, Math.ceil((lastActivity + LIMIT - Date.now()) / 1000));
            var b = dialog && dialog.querySelector('[data-left]');
            if (b) b.textContent = left;
        }, 500);
    }

    function hideWarning() {
        clearInterval(countdown);
        if (dialog) dialog.remove();
        dialog = null;
    }

    setInterval(function () {
        var idle = Date.now() - lastActivity;
        if (idle >= LIMIT) { checkServer(logout); return; }
        if (idle >= LIMIT - WARN && !dialog) checkServer(showWarning);
    }, 1000);
})();
