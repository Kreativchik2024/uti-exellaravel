/* ============================================================
   Плашка «обновление сервера» для всех, у кого открыт сайт.

   scripts/uti-update.sh пишет public/update-notice.json:
     {"state":"soon","at":<unix-время начала>}  — через N секунд начнётся обновление
     {"state":"updating"}                        — идёт обновление
     {"state":"done"}                            — готово (предложить обновить страницу)
     {"state":"cancelled"}                       — отменено / откат (плашку убрать)
   Файл статический — его отдаёт веб-сервер без PHP, поэтому частый опрос
   сервер не нагружает. Обратный отсчёт считается по часам сервера
   (заголовок Date), а не по часам компьютера.
   Настройка: <script src="update-notice.js" data-url="/update-notice.json">
   ============================================================ */
(function () {
    'use strict';

    var me = document.currentScript;
    var URL_ = (me && me.dataset.url) || '/update-notice.json';
    // MISSING — файла ещё нет (до первого запуска нового uti-update): проверяем почаще,
    // чтобы не пропустить 15-секундное предупреждение. Потом файл есть всегда.
    var FAST = 4000, SLOW = 30000, MISSING = 6000;

    var box = null, tick = null, timer = null;
    var skew = 0;          // серверное время − время компьютера, мс
    var lastState = null;  // что показывали; «готово» показываем, только если видели само обновление
    var sawUpdate = false;

    function css() {
        if (document.getElementById('ui-upd-style')) return;
        var st = document.createElement('style');
        st.id = 'ui-upd-style';
        st.textContent =
            '.ui-upd{position:fixed;left:50%;top:12px;transform:translateX(-50%);z-index:300;max-width:calc(100vw - 24px);' +
            'display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;border-radius:var(--sk-radius,12px);' +
            'background:var(--sk-popup,var(--ui-surface,#fff));color:var(--ui-text,#0f172a);' +
            'border:1px solid var(--ui-border-strong,#d4d8e3);box-shadow:var(--ui-shadow-lg,0 12px 32px -8px rgba(15,23,42,.25));' +
            'font-size:.9rem;line-height:1.35;animation:ui-upd-in .25s ease both}' +
            '.ui-upd__icon{flex-shrink:0;width:30px;height:30px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;' +
            'background:var(--ui-accent,#4f46e5);color:var(--sk-on-accent,#fff);font-weight:800}' +
            '.ui-upd__num{font-variant-numeric:tabular-nums;font-weight:800}' +
            '.ui-upd--updating .ui-upd__icon{animation:ui-upd-spin 1.2s linear infinite}' +
            '.ui-upd--done .ui-upd__icon{background:var(--ui-good,#059669);color:#fff}' +
            '.ui-upd__btn{flex-shrink:0;padding:.35rem .8rem;border-radius:var(--sk-radius,8px);font-weight:700;' +
            'background:var(--ui-accent,#4f46e5);color:var(--sk-on-accent,#fff);border:0;cursor:pointer}' +
            '.ui-upd__x{flex-shrink:0;opacity:.6;background:none;border:0;color:inherit;font-size:1.1rem;cursor:pointer}' +
            '@keyframes ui-upd-in{from{opacity:0;transform:translate(-50%,-8px)}to{opacity:1;transform:translate(-50%,0)}}' +
            '@keyframes ui-upd-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(st);
    }

    function show(kind, html) {
        css();
        if (!box) {
            box = document.createElement('div');
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            document.body.appendChild(box);
        }
        box.className = 'ui-upd ui-upd--' + kind;
        box.innerHTML = html;
        var reload = box.querySelector('[data-reload]');
        if (reload) reload.onclick = function () { location.reload(); };
        var close = box.querySelector('[data-close]');
        if (close) close.onclick = hide;
    }

    function hide() {
        clearInterval(tick); tick = null;
        if (box) { box.remove(); box = null; }
    }

    function render(d) {
        clearInterval(tick); tick = null;
        var state = d && d.state;

        if (state === 'soon') {
            sawUpdate = true;
            var draw = function () {
                var left = Math.max(0, Math.ceil(((d.at || 0) * 1000 - (Date.now() + skew)) / 1000));
                if (left > 0) {
                    show('soon', '<span class="ui-upd__icon">!</span><span>Через <span class="ui-upd__num">' + left +
                        '</span> с начнётся <b>обновление сервера</b>. Сохраните введённые данные.</span>');
                } else {
                    show('updating', '<span class="ui-upd__icon">⟳</span><span><b>Идёт обновление сервера.</b> Сайт может ненадолго стать недоступен — подождите.</span>');
                }
            };
            draw();
            tick = setInterval(draw, 1000);
        } else if (state === 'updating') {
            sawUpdate = true;
            show('updating', '<span class="ui-upd__icon">⟳</span><span><b>Идёт обновление сервера.</b> Сайт может ненадолго стать недоступен — подождите.</span>');
        } else if (state === 'done' && sawUpdate) {
            show('done', '<span class="ui-upd__icon">✓</span><span><b>Сервер обновлён.</b> Обновите страницу, чтобы увидеть изменения.</span>' +
                '<button type="button" class="ui-upd__btn" data-reload>Обновить</button><button type="button" class="ui-upd__x" data-close aria-label="Закрыть">×</button>');
        } else if (lastState !== 'done' || !sawUpdate) {
            hide();
        }
        lastState = state || null;
    }

    function schedule(ms) { clearTimeout(timer); timer = setTimeout(poll, ms); }

    function poll() {
        fetch(URL_ + '?t=' + Date.now(), { cache: 'no-store', credentials: 'omit' })
            .then(function (res) {
                var date = res.headers.get('Date');
                if (date) { var t = Date.parse(date); if (!isNaN(t)) skew = t - Date.now(); }
                if (!res.ok) { render(null); schedule(MISSING); return null; }
                return res.json().then(function (d) {
                    render(d);
                    var busy = d && (d.state === 'soon' || d.state === 'updating');
                    schedule(busy ? 2000 : (document.hidden ? SLOW : FAST));
                });
            })
            .catch(function () { schedule(FAST); });   // сервер перезапускается — пробуем снова
    }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poll);
    else poll();
})();
