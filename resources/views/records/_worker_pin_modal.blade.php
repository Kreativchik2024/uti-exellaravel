@php
    $canManageWorkers      = $canManageWorkers      ?? false;
    $verifiedWorkersMap    = $verifiedWorkersMap    ?? [];
    $currentEmployeeName   = $currentEmployeeName   ?? null;
    $employeesRequiringPin = $employeesRequiringPin ?? [];
    $employeesWithoutPin   = $employeesWithoutPin   ?? [];
    $serverNow             = $serverNow             ?? now()->timestamp;

    $verifiedWorkersMap    = is_array($verifiedWorkersMap)    ? $verifiedWorkersMap    : [];
    $employeesRequiringPin = is_array($employeesRequiringPin) ? $employeesRequiringPin : [];
    $employeesWithoutPin   = is_array($employeesWithoutPin)   ? $employeesWithoutPin   : [];

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG;
@endphp

{{-- Убрали @if(!$canManageWorkers), чтобы скрипт работал и для админов --}}
<div id="workerPinConfig"
     class="hidden"
     data-pin-check-url="{{ route('records.pinCheck') }}"
     data-csrf="{{ csrf_token() }}"
     data-current-employee="{{ trim($currentEmployeeName ?? '') }}"
     data-requiring="{{ json_encode($employeesRequiringPin, $jsonFlags) }}"
     data-without-pin="{{ json_encode($employeesWithoutPin, $jsonFlags) }}"
     data-verified="{{ json_encode($verifiedWorkersMap, $jsonFlags) }}"
     data-server-now="{{ (int) $serverNow }}"
     data-can-manage="{{ $canManageWorkers ? '1' : '0' }}"></div>

<div id="workerPinModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="pin-modal-card bg-white rounded-2xl shadow-2xl p-5 space-y-3">
        <h3 class="font-semibold text-base text-gray-900">Подтверждение ПИН</h3>
        <p class="text-xs text-gray-500">
            Сотрудник: <b id="workerPinEmployee" class="text-gray-900"></b><br>
            Введите его личный ПИН (4–6 цифр).
        </p>
        <input type="password" id="workerPinInput" inputmode="numeric" autocomplete="off"
               class="tap-44 block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500">
        <div id="workerPinError" class="text-red-600 text-xs hidden"></div>
        <div class="flex justify-end gap-2 pt-1">
            <button type="button" id="workerPinCancelBtn"
                    class="tap-44 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 rounded-md transition">Отмена</button>
            <button type="button" id="workerPinSubmitBtn"
                    class="tap-44 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 text-xs rounded-md transition">Подтвердить</button>
        </div>
    </div>
</div>

<script>
(function () {
    var cfgEl = document.getElementById('workerPinConfig');
    if (!cfgEl) return;

    var pinCheckUrl = cfgEl.dataset.pinCheckUrl || '';
    var csrf        = cfgEl.dataset.csrf || '';
    // Актуальный токен (public/js/csrf.js обновляет его на долго открытых страницах)
    function token() { return window.csrfToken ? (window.csrfToken() || csrf) : csrf; }
    var currentEmployee = (cfgEl.dataset.currentEmployee || '').trim();
    var canManageWorkers = cfgEl.dataset.canManage === '1';

    var requiringArr = [];
    var withoutPinArr = [];
    var initialMap   = {};
    var occupiedMap  = {};

    try { requiringArr = JSON.parse(cfgEl.dataset.requiring || '[]'); } catch (e) { requiringArr = []; }
    try { withoutPinArr = JSON.parse(cfgEl.dataset.withoutPin || '[]'); } catch (e) { withoutPinArr = []; }
    try { initialMap   = JSON.parse(cfgEl.dataset.verified   || '{}'); } catch (e) { initialMap   = {}; }

    var clockOffsetMs = 0;
    var serverNowSec  = parseInt(cfgEl.dataset.serverNow || '0', 10);
    if (serverNowSec > 0) clockOffsetMs = (serverNowSec * 1000) - Date.now();

    function nowSeconds() { return (Date.now() + clockOffsetMs) / 1000; }
    function syncClockFromServer(serverNow) {
        if (serverNow && parseInt(serverNow, 10) > 0) clockOffsetMs = (parseInt(serverNow, 10) * 1000) - Date.now();
    }

    fetch('{{ route('worker-sessions.occupied') }}', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    }).then(function(r){ return r.ok ? r.json() : null; }).then(function(data){
        if (data && data.ok && data.occupied) occupiedMap = data.occupied;
    }).catch(function(){});

    var approved = {};
    Object.keys(initialMap || {}).forEach(function (name) {
        var v = parseInt(initialMap[name], 10);
        if (!isNaN(v)) approved[name.trim()] = v;
    });

    var requiringSet = new Set((Array.isArray(requiringArr) ? requiringArr : []).map(function(s){ return s.trim(); }));
    var withoutPinSet = new Set((Array.isArray(withoutPinArr) ? withoutPinArr : []).map(function(s){ return s.trim(); }));
    
    var selfNames = new Set();
    if (currentEmployee) selfNames.add(currentEmployee);

    var pendingEmployee = null;
    var pendingResolve  = null;

    function requiresPinStrict(name) {
        if (!name) return false;
        var n = name.trim();
        if (selfNames.has(n)) return false;
        return requiringSet.has(n);
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function formatTime(sec) {
        if (sec < 0) sec = 0;
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = Math.floor(sec % 60);
        function p(n){return n<10?'0'+n:String(n);}
        return p(h)+':'+p(m)+':'+p(s);
    }

    function pinColorClass(remainingSec) {
        if (remainingSec <= 0) return 'text-red-600';
        if (remainingSec <= 15 * 60) return 'text-amber-600';
        return 'text-emerald-600';
    }

    function renderBadge(select) {
        var cell = select.closest('.worker-cell');
        if (!cell) return;
        var badge = cell.querySelector('.worker-pin-badge');
        if (!badge) return;

        var value = (select.value || '').trim();
        if (!value) { badge.innerHTML = ''; return; }

        // Владелец записи (текущий пользователь)
        if (selfNames.has(value)) {
            badge.innerHTML = '<span class="text-[11px] text-emerald-600 font-medium">✓ вы — ПИН не нужен</span>';
            return;
        }
        if (withoutPinSet.has(value) && !canManageWorkers) {
            badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">✗ нет ПИН</span>';
            return;
        }
        if (approved[value]) {
            var remaining = Math.floor(approved[value] - nowSeconds());
            var cls = pinColorClass(remaining);
            if (remaining > 0) {
                badge.innerHTML = '<span class="text-[11px] font-medium ' + cls + '">'
                    + '🔒 ' + esc(value) + ' · <span class="font-mono tabular-nums">' + formatTime(remaining) + '</span></span>';
            } else {
                badge.innerHTML = '<button type="button" class="text-[11px] text-red-600 font-medium hover:underline" '
                    + 'data-confirm-pin="' + esc(value) + '">⏱ ' + esc(value) + ' — истёк</button>';
            }
            return;
        }
        badge.innerHTML = '<button type="button" class="text-[11px] text-amber-600 font-medium hover:underline" '
            + 'data-confirm-pin="' + esc(value) + '">⚠ Подтвердить ПИН</button>';
    }

    function renderAllBadges() {
        document.querySelectorAll('select[data-worker-field]').forEach(renderBadge);
    }

    function isDuplicate(select) {
        var value = select.value;
        if (!value) return false;
        var found = false;
        document.querySelectorAll('select[data-worker-field]').forEach(function (o) {
            if (o !== select && o.value === value) found = true;
        });
        return found;
    }

    function openPinModal(employeeName) {
        return new Promise(function (resolve) {
            var modal   = document.getElementById('workerPinModal');
            if (!modal) { resolve({ ok: false }); return; }
            var label   = document.getElementById('workerPinEmployee');
            var inputEl = document.getElementById('workerPinInput');
            var errorEl = document.getElementById('workerPinError');

            pendingEmployee = employeeName;
            pendingResolve  = resolve;

            if (label)   label.textContent = employeeName;
            if (inputEl) inputEl.value = '';
            if (errorEl) { errorEl.textContent = ''; errorEl.classList.add('hidden'); }

            modal.classList.remove('hidden');
            setTimeout(function(){ if (inputEl) inputEl.focus(); }, 50);
        });
    }

    function closePinModal(result) {
        var modal = document.getElementById('workerPinModal');
        if (modal) modal.classList.add('hidden');
        if (pendingResolve) {
            pendingResolve(result);
            pendingResolve  = null;
            pendingEmployee = null;
        }
    }

    function submitPin() {
        var input = document.getElementById('workerPinInput');
        var err   = document.getElementById('workerPinError');
        if (!input || !err) { closePinModal({ ok: false }); return; }

        var pin = (input.value || '').trim();
        if (!/^\d{4,6}$/.test(pin)) {
            err.textContent = 'ПИН — 4–6 цифр.';
            err.classList.remove('hidden');
            return;
        }
        err.classList.add('hidden');

        var body = 'short_name=' + encodeURIComponent(pendingEmployee || '')
                 + '&pin=' + encodeURIComponent(pin)
                 + '&_token=' + encodeURIComponent(token());

        fetch(pinCheckUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body,
            credentials: 'same-origin',
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
                if (res.data.employee_name && res.data.expires_at) {
                    approved[res.data.employee_name.trim()] = parseInt(res.data.expires_at, 10);
                }
                syncClockFromServer(res.data.server_now);
                closePinModal({ ok: true });
            } else {
                err.textContent = (res.data && res.data.error) || ('Ошибка ' + (res.status || ''));
                err.classList.remove('hidden');
            }
        }).catch(function () {
            err.textContent = 'Ошибка соединения.';
            err.classList.remove('hidden');
        });
    }

    var cancelBtn = document.getElementById('workerPinCancelBtn');
    var submitBtn = document.getElementById('workerPinSubmitBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', function () { closePinModal({ ok: false }); });
    if (submitBtn) submitBtn.addEventListener('click', submitPin);

    var pinInput = document.getElementById('workerPinInput');
    if (pinInput) {
        pinInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitPin(); }
        });
    }

    function claimWorker(name) {
        if (!name) return;
        var body = new URLSearchParams();
        body.set('_token', token());
        body.set('short_name', name);
        body.set('sheet', '{{ $sheet ?? '' }}');
        body.set('record_id', '{{ $record->id ?? '' }}');

        fetch('{{ route('worker-sessions.claim') }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        }).catch(function(){});
    }

    document.querySelectorAll('select[data-worker-field]').forEach(function (select) {
        renderBadge(select);
        select.dataset.lastApproved = select.value;

        if (select.value) claimWorker(select.value);

        select.addEventListener('change', function () {
            var value = select.value;

            if (!value) {
                select.dataset.lastApproved = '';
                renderBadge(select);
                return;
            }

            if (isDuplicate(select)) {
                select.value = select.dataset.lastApproved || '';
                renderBadge(select);
                var cell  = select.closest('.worker-cell');
                var badge = cell ? cell.querySelector('.worker-pin-badge') : null;
                if (badge) {
                    badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">✗ Уже выбран</span>';
                    setTimeout(function(){ renderBadge(select); }, 2500);
                }
                return;
            }

            if (withoutPinSet.has(value.trim()) && !canManageWorkers) {
                select.value = select.dataset.lastApproved || '';
                renderBadge(select);
                var cell  = select.closest('.worker-cell');
                var badge = cell ? cell.querySelector('.worker-pin-badge') : null;
                if (badge) {
                    badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">✗ У сотрудника нет ПИН</span>';
                    setTimeout(function(){ renderBadge(select); }, 2500);
                }
                return;
            }

            if (occupiedMap[value]) {
                select.value = select.dataset.lastApproved || '';
                renderBadge(select);
                var cell  = select.closest('.worker-cell');
                var badge = cell ? cell.querySelector('.worker-pin-badge') : null;
                if (badge) {
                    var info = occupiedMap[value];
                    var text = '✗ Занят (' + info.sheet + (info.record_id ? '#' + info.record_id : '') + ')';
                    badge.innerHTML = '<span class="text-[11px] text-red-600 font-medium">' + text + '</span>';
                    setTimeout(function(){ renderBadge(select); }, 3500);
                }
                return;
            }

            if (canManageWorkers || selfNames.has(value.trim()) || !requiresPinStrict(value)) {
                select.dataset.lastApproved = value;
                renderBadge(select);
                claimWorker(value);
                return;
            }
            if (approved[value]) {
                var remaining = Math.floor(approved[value] - nowSeconds());
                if (remaining > 0) {
                    select.dataset.lastApproved = value;
                    renderBadge(select);
                    claimWorker(value);
                    return;
                }
            }

            var prev = select.dataset.lastApproved || '';
            select.value = prev;
            renderBadge(select);

            openPinModal(value).then(function (result) {
                if (result && result.ok) {
                select.value = value;
                select.dataset.lastApproved = value;
                renderBadge(select);
                claimWorker(value);
                }
            });
        });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-pin]');
        if (!btn) return;
        e.preventDefault();
        var employeeName = btn.getAttribute('data-confirm-pin');
        openPinModal(employeeName).then(function (result) {
            if (result && result.ok) {
                renderAllBadges();
                claimWorker(employeeName);
            }
        });
    });

    setInterval(renderAllBadges, 1000);
})();
</script>