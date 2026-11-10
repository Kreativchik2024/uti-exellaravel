{{-- Черновик формы: введённое сохраняется в браузере и восстанавливается, если страница
     закрылась, пропала сеть или устарела сессия. Удаляется при отправке формы и через 3 дня.
     Ключ — пользователь + вкладка + запись, поэтому за общим компьютером черновики не путаются. --}}
@php
    $draftKey = 'uti-draft:' . auth()->id() . ':' . $sheet . ':' . (isset($record) ? $record->id : 'new');
    $hasOld = session()->hasOldInput();   // после ошибки проверки поля уже заполнены сервером
@endphp
<div class="ui-draft" data-draft-banner hidden role="status">
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
    <span class="ui-draft__text" data-draft-text>Есть несохранённый черновик</span>
    <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-draft-restore>Восстановить</button>
    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-draft-discard>Удалить</button>
</div>
<script>
(function () {
    var KEY = @json($draftKey), HAS_OLD = @json($hasOld), MAX_AGE = 3 * 24 * 3600 * 1000;
    var SKIP = { _token: 1, _method: 1, pin: 1, intended: 1 };
    var banner = document.currentScript.previousElementSibling;
    var form = banner.closest('form');
    if (!form) return;
    form.insertBefore(banner, form.firstElementChild ? form.firstElementChild.nextSibling : null);

    function store(fn) { try { return fn(window.localStorage); } catch (e) { return null; } }

    // Старые черновики (любых форм) — удаляем
    store(function (ls) {
        for (var i = ls.length - 1; i >= 0; i--) {
            var k = ls.key(i);
            if (k && k.indexOf('uti-draft:') === 0) {
                try { if (Date.now() - JSON.parse(ls.getItem(k)).at > MAX_AGE) ls.removeItem(k); } catch (e) { ls.removeItem(k); }
            }
        }
    });

    function collect() {
        var data = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || SKIP[el.name] || el.type === 'file' || el.type === 'submit' || el.type === 'button' || el.disabled) return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            data[el.name] = el.value;
        });
        return data;
    }

    var initial = JSON.stringify(collect()), timer = null;
    function save() {
        var data = collect(), json = JSON.stringify(data);
        if (json === initial) { store(function (ls) { ls.removeItem(KEY); }); return; }
        store(function (ls) { ls.setItem(KEY, JSON.stringify({ at: Date.now(), data: data })); });
    }
    form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 600); });
    form.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(save, 300); });
    form.addEventListener('submit', function () { clearTimeout(timer); store(function (ls) { ls.removeItem(KEY); }); });

    if (HAS_OLD) return;   // сервер уже вернул введённое — черновик не предлагаем
    var draft = store(function (ls) { try { return JSON.parse(ls.getItem(KEY)); } catch (e) { return null; } });
    if (!draft || !draft.data || JSON.stringify(draft.data) === initial) return;

    var d = new Date(draft.at);
    banner.querySelector('[data-draft-text]').textContent = 'Есть несохранённый черновик от '
        + d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    banner.hidden = false;

    banner.querySelector('[data-draft-restore]').addEventListener('click', function () {
        Object.keys(draft.data).forEach(function (name) {
            var els = form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
            Array.prototype.forEach.call(els, function (el) {
                if (el.type === 'checkbox' || el.type === 'radio') el.checked = el.value === draft.data[name];
                else el.value = draft.data[name];
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
        banner.hidden = true;
    });
    banner.querySelector('[data-draft-discard]').addEventListener('click', function () {
        store(function (ls) { ls.removeItem(KEY); });
        banner.hidden = true;
    });
})();
</script>
