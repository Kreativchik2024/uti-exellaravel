/* Форма записи: поиск по таблице, подсказки, выбор исполнителей (resources/views/records/_form.blade.php). */
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    document.querySelectorAll('.lookup-input:not([disabled])').forEach(function (input) {
        var timer = null;
        var resultEl = input.parentElement.querySelector('.lookup-result');
        if (!resultEl) return;

        function render(data) {
            if (!data || !data.found) {
                resultEl.innerHTML = '<div class="inline-flex flex-wrap items-center gap-2 px-2.5 py-1 rounded-md border bg-gray-50 border-gray-200 text-xs text-gray-500">Не найдено в таблице</div>';
                return;
            }
            var isGreen = data.health === 'green';
            var cls = isGreen ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800';
            var icon = isGreen ? '✓' : '✗';
            resultEl.innerHTML =
                '<div class="inline-flex flex-wrap items-center gap-2 px-2.5 py-1 rounded-md border ' + cls + ' text-xs font-medium">'
                + '<span class="font-bold">' + icon + '</span>'
                + (data.caption ? '<span class="opacity-70">' + esc(data.caption) + ':</span>' : '')
                + '<span>' + esc(data.workers || data.worker1 || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.record_date || '—') + '</span>'
                + '<span class="opacity-50">·</span>'
                + '<span>' + esc(data.status_text || '') + '</span>'
                + '</div>'
                + (data.details || []).map(function (d) {
                    return '<div class="mt-1 text-xs" style="color: var(--ui-text-2)"><span class="opacity-70">'
                        + esc(d.label) + ':</span> ' + esc(d.value) + '</div>';
                }).join('');
        }

        function doLookup() {
            var val = input.value.trim();
            if (val === '') { resultEl.innerHTML = ''; return; }
            var url = input.dataset.lookupUrl + '?field=' + encodeURIComponent(input.dataset.lookupField) + '&value=' + encodeURIComponent(val);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { render(data); })
                .catch(function () { resultEl.innerHTML = ''; });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(doLookup, 400);
        });
    });

    // --- ПГП: «Что известно об этом борте» — при вводе моторамы, фюзеляжа или номеров периферии ---
    // Модуль связи не учитывается: он переходит с номером борта на другой борт, к самому борту не относится
    (function () {
        var box = document.querySelector('[data-board-journal]');
        if (!box) return;
        var form = box.closest('form');
        var names = ['frame_serial', 'fuselage_number', 'cross3_number', 'bracket_serial'];
        var timer = null, last = null;

        function load() {
            var params = new URLSearchParams();
            names.forEach(function (n) {
                var el = form && form.querySelector('[name="' + n + '"]');
                var v = el ? String(el.value || '').trim() : '';
                if (v) params.set(n, v);
            });
            var query = params.toString();
            if (query === last) return;
            last = query;
            if (!query) { box.innerHTML = ''; return; }
            fetch(box.dataset.url + '?' + query + (box.dataset.except ? '&except=' + encodeURIComponent(box.dataset.except) : ''), { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (html) { if (params.toString() === last) { box.innerHTML = html; prefill(); } })
                .catch(function () {});
        }

        // Борт вернулся после ремонта — данные борта из прошлой записи в ПУСТЫЕ поля (введённое не трогаем)
        function prefill() {
            var src = box.querySelector('[data-board-prefill]');
            if (!src || !form) return;
            var data;
            try { data = JSON.parse(src.getAttribute('data-board-prefill') || '{}'); } catch (e) { return; }
            Object.keys(data).forEach(function (name) {
                var el = form.querySelector('[name="' + name + '"]:not([type=hidden])');
                if (!el || el.disabled || el.readOnly || String(el.value || '').trim() !== '') return;
                if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === String(data[name]); })) return;
                el.value = data[name];
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        names.forEach(function (n) {
            var el = form && form.querySelector('[name="' + n + '"]');
            if (!el) return;
            el.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 500); });
            el.addEventListener('change', load);
        });
        load();   // при правке записи — сразу по уже введённым номерам
    })();

    // --- МАСКА IP: в поле последнего октета — только цифры ---
    document.querySelectorAll('input[data-ip-suffix]').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var clean = inp.value.replace(/\D+/g, '').slice(0, 3);
            if (clean !== inp.value) inp.value = clean;
        });
    });

    // --- ЗАВИСИМЫЕ ПОЛЯ: блок «показать после заполнения» и «обязательно, если …» ---
    (function () {
        var form = document.querySelector('.record-form-card') && document.querySelector('.record-form-card').closest('form');
        if (!form) return;

        function valueOf(field) {
            var el = form.querySelector('[name="' + field + '"]');
            return el ? String(el.value || '').trim() : '';
        }

        function sync() {
            form.querySelectorAll('[data-show-when-filled]').forEach(function (block) {
                block.hidden = valueOf(block.dataset.showWhenFilled) === '';
            });

            form.querySelectorAll('[data-required-when-field]').forEach(function (wrap) {
                var on = valueOf(wrap.dataset.requiredWhenField) === wrap.dataset.requiredWhenValue;
                if (wrap.dataset.requiredUnless) on = !on;   // «обязательно, кроме этого значения»
                var input = wrap.querySelector('input[name], select[name], textarea[name]');
                if (input && !input.disabled) input.required = on;
                var badge = wrap.querySelector('[data-required-badge]');
                if (badge) badge.hidden = !on;
            });
        }

        var watched = {};
        form.querySelectorAll('[data-show-when-filled]').forEach(function (b) { watched[b.dataset.showWhenFilled] = true; });
        form.querySelectorAll('[data-required-when-field]').forEach(function (w) { watched[w.dataset.requiredWhenField] = true; });
        Object.keys(watched).forEach(function (field) {
            var el = form.querySelector('[name="' + field + '"]');
            if (el) { el.addEventListener('change', sync); el.addEventListener('input', sync); }
        });
        sync();
    })();

    // --- ПРОВЕРКА НА ДУБЛИКАТЫ (для всех пользователей) ---
    var workerSelects = document.querySelectorAll('select[data-worker-field]');
    workerSelects.forEach(function (select) {
        select.dataset.prevValue = select.value;
        select.addEventListener('change', function () {
            var val = select.value;
            if (!val) {
                select.dataset.prevValue = '';
                return;
            }
            
            var isDup = false;
            workerSelects.forEach(function (other) {
                if (other !== select && other.value === val) {
                    isDup = true;
                }
            });

            if (isDup) {
                select.value = select.dataset.prevValue || '';
                var cell = select.closest('.worker-cell');
                var badge = cell ? cell.querySelector('.worker-pin-badge') : null;
                if (badge) {
                    badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">✗ Уже выбран</span>';
                    setTimeout(function(){ badge.innerHTML = ''; }, 2500);
                } else {
                    alert('Этот исполнитель уже выбран в другом поле.');
                }
            } else {
                select.dataset.prevValue = val;
            }
        });
    });
});
