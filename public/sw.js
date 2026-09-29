// Service Worker — Inteligencia Electoral PWA — Build 20260929
const CACHE_NAME = 'electoral-v11';
const PRECACHE = ['/manifest.json', '/offline.html'];
const PAGE_PATHS = ['/', '/mapa-politico', '/gobernador', '/admin/', '/login'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE).catch(() => {}))
    );
    self.skipWaiting();
});

// Don't delete old caches — build assets might be there
self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET' || !request.url.startsWith('http')) return;

    const url = new URL(request.url);

    // Build assets + static files — cache first, search ALL caches
    if (url.pathname.startsWith('/build/') || url.pathname.match(/\.(png|jpg|svg|webp|ico|woff2?|css)(\?.*)?$/) || url.host.includes('fonts.g')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((c) => c.put(request, clone));
                    }
                    return response;
                }).catch(() => new Response('', { status: 404 }));
            })
        );
        return;
    }

    // API/data — network only
    if (url.pathname.includes('/api/') || url.pathname.includes('/persona/') || url.pathname.includes('/nexos/') || url.pathname.includes('/crear-lider') || url.pathname.includes('/exportar/')) {
        return;
    }

    // Pages — network first, cache ONLY text/html responses (not Inertia JSON)
    const isPage = request.mode === 'navigate' || PAGE_PATHS.some((p) => url.pathname === p || url.pathname.startsWith(p));
    if (isPage) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response.ok) {
                    const ct = response.headers.get('content-type') || '';
                    // Only cache actual HTML pages, never JSON
                    if (ct.includes('text/html')) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, clone.clone());
                            if (url.search) cache.put(new Request(url.origin + url.pathname), clone);
                        });
                    }
                }
                return response;
            }).catch(() =>
                caches.match(request)
                    .then((c) => c || caches.match(url.origin + url.pathname))
                    .then((c) => c || caches.match('/mapa-politico'))
                    .then((c) => c || caches.match('/'))
                    .then((c) => c || caches.match('/offline.html'))
                    .then((c) => c || new Response('<h1>Sin conexión</h1>', { headers: { 'Content-Type': 'text/html' } }))
            )
        );
        return;
    }

    // Everything else — network first
    event.respondWith(
        fetch(request).then((r) => {
            if (r.ok) { const cl = r.clone(); caches.open(CACHE_NAME).then((c) => c.put(request, cl)); }
            return r;
        }).catch(() => caches.match(request))
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
