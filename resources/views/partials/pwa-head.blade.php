{{-- Установка сайта как приложения (PWA) и страница «нет связи» (public/sw.js) --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#4f46e5">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<script>
    // Печать запрещена: Ctrl+P / ⌘+P не открывает печать, при печати из меню браузера — только надпись (app.css)
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P' || e.key === 'з' || e.key === 'З')) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('DOMContentLoaded', function () {
        var note = document.createElement('div');
        note.className = 'ui-print-blocked';
        note.textContent = 'Печать страниц системы запрещена';
        document.body.appendChild(note);
    });
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
    }
</script>
