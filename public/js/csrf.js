/* ============================================================
   Защита от ошибки 419 (Page Expired / CSRF token mismatch).

   Токен вшивается в страницу при загрузке. Если вкладка долго
   открыта (сон компьютера, фон, истёк срок сессии) или в другой
   вкладке был вход/выход — токен устаревает и отправка падает с 419.

   Здесь:
   1) window.csrfToken() — всегда актуальный токен (из <meta>);
   2) токен обновляется при возврате на вкладку и раз в 10 минут;
   3) перед отправкой формы со «старой» страницы токен обновляется;
   4) fetch(), получивший 419, повторяется один раз со свежим токеном.

   Подключается из layouts/app.blade.php и layouts/guest.blade.php.
   ============================================================ */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var refreshUrl = (meta && meta.getAttribute('data-refresh-url')) || '/csrf-token';

    var REFRESH_EVERY_MS = 10 * 60 * 1000; // фоновое обновление
    var STALE_AFTER_MS   = 5 * 60 * 1000;  // перед отправкой формы — если старше
    var lastRefresh = Date.now();
    var inflight = null;

    var nativeFetch = window.fetch ? window.fetch.bind(window) : null;

    function getToken() {
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function setToken(token) {
        if (!token) return;
        if (meta) meta.setAttribute('content', token);
        document.querySelectorAll('input[name="_token"]').forEach(function (el) { el.value = token; });
        document.querySelectorAll('[data-csrf]').forEach(function (el) { el.setAttribute('data-csrf', token); });
    }

    function refresh() {
        if (!nativeFetch) return Promise.resolve(getToken());
        if (inflight) return inflight;

        inflight = nativeFetch(refreshUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.token) {
                    setToken(data.token);
                    lastRefresh = Date.now();
                }
                return getToken();
            })
            .catch(function () { return getToken(); })
            .then(function (t) { inflight = null; return t; });

        return inflight;
    }

    window.csrfToken = getToken;
    window.refreshCsrfToken = refresh;

    // ---- 2) Фоновое обновление ----
    function refreshIfOld(maxAge) {
        if (Date.now() - lastRefresh > maxAge) refresh();
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refreshIfOld(60 * 1000);
    });
    window.addEventListener('focus', function () { refreshIfOld(60 * 1000); });
    window.addEventListener('online', function () { refresh(); });
    // Возврат на страницу кнопкой «Назад» (bfcache) — токен в ней старый
    window.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
    setInterval(function () { refreshIfOld(REFRESH_EVERY_MS - 1000); }, REFRESH_EVERY_MS);

    // ---- 3) Отправка форм ----
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (!form.querySelector('input[name="_token"]')) return;

        if (form.dataset.csrfFresh === '1') { delete form.dataset.csrfFresh; return; }
        if (Date.now() - lastRefresh <= STALE_AFTER_MS) return;

        // Страница «старая»: сначала обновляем токен, потом отправляем заново
        e.preventDefault();
        e.stopImmediatePropagation();
        var submitter = e.submitter || null;

        refresh().then(function () {
            form.dataset.csrfFresh = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                form.submit();
            }
        });
    }, true);

    // ---- 4) Повтор fetch() после 419 ----
    if (!nativeFetch) return;

    function isSameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; }
        catch (_) { return false; }
    }

    function withToken(init, token) {
        var next = Object.assign({}, init);
        var body = init.body;

        if (typeof body === 'string') {
            next.body = /(^|&)_token=/.test(body)
                ? body.replace(/(^|&)_token=[^&]*/, '$1_token=' + encodeURIComponent(token))
                : body;
        } else if (body instanceof URLSearchParams) {
            var p = new URLSearchParams(body);
            if (p.has('_token')) p.set('_token', token);
            next.body = p;
        } else if (typeof FormData !== 'undefined' && body instanceof FormData) {
            if (body.has('_token')) body.set('_token', token);
            next.body = body;
        }

        var headers = new Headers(init.headers || {});
        headers.set('X-CSRF-TOKEN', token);
        next.headers = headers;
        return next;
    }

    window.fetch = function (input, init) {
        init = init || {};
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var method = (init.method || (input && input.method) || 'GET').toUpperCase();

        if (method === 'GET' || method === 'HEAD' || !isSameOrigin(url) || typeof input !== 'string') {
            return nativeFetch(input, init);
        }

        // Заголовок с актуальным токеном — на случай, если в теле его нет
        var headers = new Headers(init.headers || {});
        if (!headers.has('X-CSRF-TOKEN')) headers.set('X-CSRF-TOKEN', getToken());
        var first = Object.assign({}, init, { headers: headers });

        return nativeFetch(input, first).then(function (res) {
            if (res.status !== 419) return res;
            return refresh().then(function (token) {
                return nativeFetch(input, withToken(init, token));
            });
        });
    };
})();
