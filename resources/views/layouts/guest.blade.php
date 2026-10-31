        {{-- Плавающая кнопка смены темы --}}
        <button type="button" onclick="toggleDark()"
                style="position:fixed; bottom:24px; right:24px; z-index:9999;
                       width:48px; height:48px; border-radius:50%;
                       background:#4f46e5; color:#fff; border:none; cursor:pointer;
                       box-shadow:0 4px 12px rgba(0,0,0,0.3);
                       display:flex; align-items:center; justify-content:center;"
                title="Сменить тему">
            <svg data-theme-icon="moon" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg data-theme-icon="sun" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
        </button>

        <script>
            (function () {
                if (!window.toggleDark) {
                    window.toggleDark = function () {
                        var html = document.documentElement;
                        var nowDark = !html.classList.contains('dark');
                        html.classList.toggle('dark', nowDark);
                        try { localStorage.setItem('theme', nowDark ? 'dark' : 'light'); } catch (e) {}
                        syncIcons();
                    };
                }
                function syncIcons() {
                    var isDark = document.documentElement.classList.contains('dark');
                    document.querySelectorAll('[data-theme-icon="moon"]').forEach(function (el) {
                        el.style.display = isDark ? 'none' : 'block';
                    });
                    document.querySelectorAll('[data-theme-icon="sun"]').forEach(function (el) {
                        el.style.display = isDark ? 'block' : 'none';
                    });
                }
                document.addEventListener('DOMContentLoaded', syncIcons);
            })();
        </script>