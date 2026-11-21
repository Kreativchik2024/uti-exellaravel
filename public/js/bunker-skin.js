/* ============================================================
   Тема «Бункер» (themes/bunker.css) — живые мелочи оформления:
     • бегущая полоса развёртки по экрану;
     • командная строка внизу рамки: дата, время, связь, кнопка звука;
     • «прогрев кинескопа» — заставка при первом открытии сайта за сеанс
       и при выборе темы (щелчок/клавиша — пропустить);
     • звуки клавиш и щелчков (синтез Web Audio, без файлов).
       По умолчанию ВЫКЛЮЧЕНЫ, включаются кнопкой «Звук» в командной строке.
   Только интерфейс: данные не читает и не меняет. В других темах ничего не делает.
   ============================================================ */
(function () {
    'use strict';

    var NAME = 'bunker';
    var BOOT_KEY = 'bunker-booted';
    var SOUND_KEY = 'bunker-sound';
    var els = null, timer = null, audio = null;

    function active() { return document.documentElement.getAttribute('data-ui-theme') === NAME; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function reducedMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
    function soundOn() { try { return localStorage.getItem(SOUND_KEY) === '1'; } catch (e) { return false; } }

    /* ---------- Звук: короткие щелчки, синтез на лету ---------- */
    function ctx() {
        if (!audio) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            audio = new AC();
        }
        if (audio.state === 'suspended') audio.resume();
        return audio;
    }
    function blip(freq, dur, vol, type) {
        var a = ctx(); if (!a) return;
        var o = a.createOscillator(), g = a.createGain(), t = a.currentTime;
        o.type = type || 'square';
        o.frequency.setValueAtTime(freq, t);
        g.gain.setValueAtTime(vol, t);
        g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
        o.connect(g); g.connect(a.destination);
        o.start(t); o.stop(t + dur + 0.02);
    }
    function onKey(e) {
        if (!active() || !soundOn() || e.repeat) return;
        if (e.key && e.key.length === 1 || e.key === 'Backspace' || e.key === 'Enter') {
            blip(1400 + Math.random() * 500, 0.025, 0.025);
        }
    }
    function onPress(e) {
        if (!active() || !soundOn()) return;
        if (e.target.closest && e.target.closest('a, button, [role="button"], [role="menuitem"], [role="menuitemradio"], select, label')) {
            blip(620, 0.05, 0.05);
            setTimeout(function () { blip(940, 0.04, 0.035); }, 45);
        }
    }

    /* ---------- Командная строка и полоса развёртки ---------- */
    function renderBar() {
        if (!els) return;
        var d = new Date();
        var online = navigator.onLine !== false;
        els.clock.textContent = pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + '  ' +
            pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        els.led.className = 'bk-led' + (online ? '' : ' bk-led--off');
        els.net.textContent = online ? 'связь есть' : 'нет связи';
    }
    function syncSoundBtn() {
        if (!els) return;
        var on = soundOn();
        els.sound.setAttribute('aria-pressed', on ? 'true' : 'false');
        els.sound.textContent = on ? 'Звук: вкл' : 'Звук: выкл';
    }

    function mount() {
        if (els) return;
        var roll = document.createElement('div');
        roll.className = 'bk-roll';
        roll.setAttribute('aria-hidden', 'true');

        var bar = document.createElement('div');
        bar.className = 'bk-bar';
        bar.innerHTML =
            '<span class="bk-hide-sm"><b>Бункер-терминал</b> · ' + (document.title || 'УТИ').replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>' +
            '<span><span class="bk-led"></span><span data-net></span></span>' +
            '<span class="bk-spacer"></span>' +
            '<b data-clock></b>' +
            '<button type="button" data-sound title="Звуки клавиш и щелчков"></button>';

        document.body.appendChild(roll);
        document.body.appendChild(bar);
        els = {
            roll: roll, bar: bar,
            clock: bar.querySelector('[data-clock]'),
            led: bar.querySelector('.bk-led'),
            net: bar.querySelector('[data-net]'),
            sound: bar.querySelector('[data-sound]')
        };
        els.sound.addEventListener('click', function () {
            var on = !soundOn();
            try { localStorage.setItem(SOUND_KEY, on ? '1' : '0'); } catch (e) {}
            syncSoundBtn();
            if (on) { blip(520, 0.08, 0.05); setTimeout(function () { blip(1040, 0.08, 0.04); }, 90); }
        });
        syncSoundBtn();
        renderBar();
        timer = setInterval(renderBar, 1000);
    }
    function unmount() {
        if (timer) clearInterval(timer);
        if (els) { els.roll.remove(); els.bar.remove(); }
        els = null; timer = null;
    }

    /* ---------- «Прогрев кинескопа» ---------- */
    function boot() {
        if (reducedMotion() || document.querySelector('.bk-boot')) return;
        var online = navigator.onLine !== false;
        var text = [
            'БУНКЕР-ТЕРМИНАЛ «АТОМ-7» · ' + (document.title || 'УТИ').toUpperCase(),
            '',
            '> ПРОГРЕВ КИНЕСКОПА ............ [ОК]',
            '> ЛАМПОВАЯ ПАМЯТЬ .............. [ОК]',
            '> СВЯЗЬ С СЕРВЕРОМ ............. ' + (online ? '[ОК]' : '[НЕТ]'),
            '',
            '> ДОБРО ПОЖАЛОВАТЬ, ОПЕРАТОР.'
        ].join('\n');

        var box = document.createElement('div');
        box.className = 'bk-boot';
        box.setAttribute('aria-hidden', 'true');
        box.innerHTML = '<div class="bk-boot__logo"></div><div data-out></div><div class="bk-boot__hint">ЛЮБАЯ КЛАВИША — ПРОПУСТИТЬ</div>';
        document.body.appendChild(box);
        var out = box.querySelector('[data-out]');

        if (soundOn()) { blip(55, 0.9, 0.06, 'sawtooth'); }   // гул прогрева

        var i = 0, closed = false, typer = null;
        function paint() {
            // [ОК] — бирюзовым, [НЕТ] — красным
            out.innerHTML = text.slice(0, i).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/\[ОК\]/g, '<span class="bk-ok">[ОК]</span>').replace(/\[НЕТ\]/g, '<span class="bk-bad">[НЕТ]</span>') + '█';
        }
        function close() {
            if (closed) return;
            closed = true;
            clearInterval(typer);
            document.removeEventListener('keydown', close, true);
            box.classList.add('is-out');
            setTimeout(function () { box.remove(); }, 450);
        }
        setTimeout(function () {
            typer = setInterval(function () {
                i = Math.min(text.length, i + 3);
                paint();
                if (soundOn() && text.charAt(i) !== ' ') blip(1500 + Math.random() * 400, 0.015, 0.015);
                if (i >= text.length) { clearInterval(typer); setTimeout(close, 700); }
            }, 22);
        }, 700);
        box.addEventListener('click', close);
        document.addEventListener('keydown', close, true);
        setTimeout(close, 5000);   // страховка: заставка не задерживается
    }
    function firstBootThisSession() {
        try {
            if (sessionStorage.getItem(BOOT_KEY)) return false;
            sessionStorage.setItem(BOOT_KEY, '1');
            return true;
        } catch (e) { return false; }
    }

    /* ---------- Включение / выключение вместе с темой ---------- */
    function update(switched) {
        if (active()) {
            mount();
            if (switched || firstBootThisSession()) {
                try { sessionStorage.setItem(BOOT_KEY, '1'); } catch (e) {}
                boot();
            }
        } else {
            unmount();
        }
    }

    function start() {
        var was = active();
        try { update(false); } catch (e) {}
        document.addEventListener('themechange', function () {
            var now = active();
            try { update(now && !was); } catch (e) {}
            was = now;
        });
        document.addEventListener('keydown', function (e) { try { onKey(e); } catch (x) {} }, true);
        document.addEventListener('pointerdown', function (e) { try { onPress(e); } catch (x) {} }, true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
