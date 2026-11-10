/*
 * Автовыход при бездействии (сервер: App\Http\Middleware\LogoutWhenIdle).
 *
 *  - Пока человек двигает мышью, печатает или листает, раз в минуту сообщаем серверу,
 *    что он работает (/session/ping), — иначе долгое заполнение формы закончилось бы выходом.
 *  - За минуту до выхода показываем предупреждение с кнопкой «Я здесь».
 *  - По истечении времени — выход (форма logout), страница входа объяснит причину.
 *
 * Настройка: <meta name="idle-logout" content="минуты" data-ping="url" data-logout="url">
 */
(function () {
    var meta = document.querySelector('meta[name="idle-logout"]');
    if (!meta) return;
    var minutes = parseInt(meta.content, 10);
    if (!(minutes > 0)) return;

    var LIMIT = minutes * 60 * 1000, WARN = Math.min(60 * 1000, LIMIT / 2), PING_EVERY = 60 * 1000;
    var lastActivity = Date.now(), lastPing = Date.now(), dialog = null, countdown = null;

    function token() { return window.csrfToken ? window.csrfToken() : ''; }

    function ping() {
        lastPing = Date.now();
        fetch(meta.dataset.ping, { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (r.status === 401) location.reload(); })
            .catch(function () {});
    }

    function activity() {
        lastActivity = Date.now();
        if (dialog) hideWarning();
        if (Date.now() - lastPing > PING_EVERY) ping();
    }

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll', 'input'].forEach(function (ev) {
        window.addEventListener(ev, activity, { passive: true, capture: true });
    });

    function logout() {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = meta.dataset.logout;
        var t = document.createElement('input');
        t.type = 'hidden'; t.name = '_token'; t.value = token();
        f.appendChild(t);
        document.body.appendChild(f);
        f.submit();
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
        if (idle >= LIMIT) { logout(); return; }
        if (idle >= LIMIT - WARN) showWarning();
    }, 1000);
})();
