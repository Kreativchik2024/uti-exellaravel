@auth
<div id="onlineFooter" class="ui-online" role="status" aria-live="polite">
    <span class="ui-online__pulse" aria-hidden="true"></span>
    <span class="font-semibold whitespace-nowrap">Онлайн <span id="onlineCount" style="color: var(--ui-good)">0</span></span>
    <div id="onlineList" class="ui-online__list">
        <span style="color: var(--ui-faint)">загрузка…</span>
    </div>
</div>

<script>
(function () {
    var heartbeatUrl = '{{ route('worker-sessions.heartbeat') }}';
    var onlineUrl    = '{{ route('worker-sessions.online') }}';
    var csrf         = '{{ csrf_token() }}';

    function heartbeat() {
        fetch(heartbeatUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_token=' + encodeURIComponent(window.csrfToken ? window.csrfToken() : csrf),
            credentials: 'same-origin',
        }).catch(function () {});
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function loadOnline() {
        fetch(onlineUrl, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.ok) return;
            document.getElementById('onlineCount').textContent = data.count || 0;

            var list = document.getElementById('onlineList');
            if (!data.online || !data.online.length) {
                list.innerHTML = '<span style="color: var(--ui-faint)">никого</span>';
                return;
            }
            list.innerHTML = data.online.map(function (u) {
                var title = u.sheet ? ('работает с ' + u.sheet + (u.record_id ? ' #' + u.record_id : '')) : 'в системе';
                return '<span class="ui-online__chip" title="' + esc(title) + '">' + esc(u.employee_name || '—') + '</span>';
            }).join('');
        }).catch(function () {});
    }

    heartbeat();
    loadOnline();
    setInterval(heartbeat, 60000);
    setInterval(loadOnline, 30000);
})();
</script>
@endauth