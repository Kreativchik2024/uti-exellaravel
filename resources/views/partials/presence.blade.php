@auth
{{-- Невидимая отметка «я на сайте» раз в минуту. На ней держатся: «в сети» в чате,
     защита «сотрудник занят» в формах, «Сейчас работают» на странице состояния сервера. --}}
<script>
(function () {
    var url = @json(route('worker-sessions.heartbeat'));
    function beat() {
        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_token=' + encodeURIComponent(window.csrfToken ? window.csrfToken() : @json(csrf_token())),
            credentials: 'same-origin',
        }).catch(function () {});
    }
    beat();
    setInterval(beat, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) beat(); });
})();
</script>
@endauth
