{{-- Установка сайта как приложения (PWA) и страница «нет связи» (public/sw.js) --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#4f46e5">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<script>
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
    }
</script>
