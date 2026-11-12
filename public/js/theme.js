/* ============================================================
   Тема оформления: «auto» (как в системе) / «light» / «dark» /
   «peaky» («Острые козырьки»), «blueprint» («Конструкторское бюро»), «minions» («Миньоны», светлая) —
   основа тёмная (или светлая: SKINS[имя] = false) + public/css/themes/*.css (атрибут html[data-ui-theme]).

   Подключается СИНХРОННО в <head> обоих макетов — тема применяется
   до отрисовки страницы, без мигания белым.

   API:
     window.getThemeMode()        → 'auto' | 'light' | 'dark' | 'peaky' | 'blueprint' | 'minions'
     window.setThemeMode(mode)    — выбрать режим (сохраняется в браузере)
     window.toggleDark()          — совместимость: переключить светлая/тёмная

   При каждом изменении — событие document 'themechange'
   (detail: { mode, dark }); на него подписаны графики статистики.
   ============================================================ */
(function () {
    'use strict';

    var KEY = 'theme';
    var MODES = ['auto', 'light', 'dark', 'peaky', 'blueprint', 'minions'];
    // Оформленные темы: имя режима → тёмная ли основа
    var SKINS = { peaky: true, blueprint: true, minions: false };
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function readMode() {
        try {
            // ?theme=dark|light|auto — ручное переключение (в т.ч. для проверки)
            var forced = new URLSearchParams(window.location.search).get('theme');
            if (forced && MODES.indexOf(forced) !== -1) return forced;
            var stored = localStorage.getItem(KEY);
            return (stored && stored !== 'auto' && MODES.indexOf(stored) !== -1) ? stored : 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    var mode = readMode();

    function systemDark() { return !!(media && media.matches); }
    function isDark() {
        if (SKINS.hasOwnProperty(mode)) return SKINS[mode];
        return mode === 'dark' || (mode === 'auto' && systemDark());
    }

    function apply(notify) {
        var dark = isDark();
        var root = document.documentElement;
        root.classList.toggle('dark', dark);
        root.setAttribute('data-theme-mode', mode);
        if (SKINS.hasOwnProperty(mode)) root.setAttribute('data-ui-theme', mode);
        else root.removeAttribute('data-ui-theme');
        if (notify) {
            document.dispatchEvent(new CustomEvent('themechange', { detail: { mode: mode, dark: dark } }));
        }
    }

    window.getThemeMode = function () { return mode; };

    window.setThemeMode = function (next) {
        if (MODES.indexOf(next) === -1) next = 'auto';
        mode = next;
        try {
            if (mode === 'auto') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, mode);
        } catch (e) {}
        apply(true);
    };

    // Совместимость со старыми кнопками: светлая ↔ тёмная
    window.toggleDark = function () {
        window.setThemeMode(isDark() ? 'light' : 'dark');
    };

    // В режиме «Авто» следуем за системой на лету
    if (media) {
        var onSystemChange = function () { if (mode === 'auto') apply(true); };
        if (media.addEventListener) media.addEventListener('change', onSystemChange);
        else if (media.addListener) media.addListener(onSystemChange);
    }

    // Выбор в другой вкладке — применяем и здесь
    window.addEventListener('storage', function (e) {
        if (e.key === KEY) { mode = readMode(); apply(true); }
    });

    apply(false);
})();
