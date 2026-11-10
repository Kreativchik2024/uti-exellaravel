/*
 * Service worker УТИ.
 *
 *  - Оформление (css, js, иконки, шрифты) кешируется: страницы открываются быстрее.
 *  - Страницы и данные НЕ кешируются (в них личные данные и защитные токены) —
 *    всегда берутся с сервера.
 *  - Нет связи с сервером — показывается страница /offline.html вместо ошибки браузера.
 *    Введённое в форму при этом не теряется: черновик хранится в браузере (records/_draft).
 */
const VERSION = 'uti-v1';
const STATIC = VERSION + '-static';
const OFFLINE_URL = '/offline.html';
const STATIC_PATHS = /^\/(build|css|js|icons|images|fonts|vendor)\//;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png'])).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;                       // формы и действия — только напрямую на сервер
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Страница: всегда с сервера; при обрыве связи — страница «нет связи»
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Оформление: из кеша сразу, обновление в фоне
    if (STATIC_PATHS.test(url.pathname)) {
        event.respondWith(
            caches.open(STATIC).then((cache) =>
                cache.match(req).then((cached) => {
                    const fresh = fetch(req).then((res) => {
                        if (res.ok) cache.put(req, res.clone());
                        return res;
                    }).catch(() => cached);
                    return cached || fresh;
                })
            )
        );
    }
    // Остальное (данные, файлы чата, картинки новостей) — без кеша
});
