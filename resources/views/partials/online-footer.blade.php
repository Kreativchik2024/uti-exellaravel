@auth
<div id="onlineFooter" class="online-bar fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur border-t border-gray-200 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 py-2 flex items-center gap-3 text-xs min-w-0">
        <div class="flex items-center gap-1.5 flex-shrink-0">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span class="online-title font-semibold text-gray-700">Онлайн:</span>
            <span id="onlineCount" class="font-bold text-emerald-600">0</span>
        </div>
        <div id="onlineList" class="online-list flex gap-1.5 flex-1 min-w-0">
            <span class="text-gray-400 italic">загрузка…</span>
        </div>
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
                list.innerHTML = '<span class="text-gray-400 italic">никого</span>';
                return;
            }
            list.innerHTML = data.online.map(function (u) {
                var title = u.sheet ? ('работает с ' + u.sheet + (u.record_id ? ' #' + u.record_id : '')) : 'в системе';
                return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-[11px] font-medium" title="' + esc(title) + '">'
                    + '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>'
                    + esc(u.employee_name || '—')
                    + '</span>';
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