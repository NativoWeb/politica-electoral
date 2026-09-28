// Service Worker — Inteligencia Electoral PWA — Build 20260928v5
const CACHE_NAME = 'electoral-v8';
const PRECACHE = ['/manifest.json', '/offline.html'];
const PAGE_PATHS = ['/', '/mapa-politico', '/gobernador', '/admin/', '/login'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE).catch(() => {}))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // Don't delete old caches immediately — keep build assets available
    // Old caches get cleaned up gradually as new assets replace them
    event.waitUntil(
        caches.keys().then(async (keys) => {
            const newCache = await caches.open(CACHE_NAME);
            // Copy build assets from old caches to new
            for (const key of keys) {
                if (key === CACHE_NAME) continue;
                const old = await caches.open(key);
                const reqs = await old.keys();
                for (const req of reqs) {
                    if (req.url.includes('/build/') || req.url.match(/\.(js|css|png|svg|woff2?)(\?|$)/)) {
                        const res = await old.match(req);
                        if (res) await newCache.put(req, res);
                    }
                }
                await caches.delete(key);
            }
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;
    if (!request.url.startsWith('http')) return;

    const url = new URL(request.url);

    // Build assets — cache first (immutable)
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((c) => c || fetch(request).then((r) => {
                if (r.ok) { const cl = r.clone(); caches.open(CACHE_NAME).then((cache) => cache.put(request, cl)); }
                return r;
            }).catch(() => new Response('', { status: 404 })))
        );
        return;
    }

    // Static assets — cache first
    if (url.pathname.match(/\.(png|jpg|jpeg|svg|webp|ico|woff2?|ttf|eot|css)(\?.*)?$/) || url.host.includes('fonts.g')) {
        event.respondWith(
            caches.match(request).then((c) => c || fetch(request).then((r) => {
                if (r.ok) { const cl = r.clone(); caches.open(CACHE_NAME).then((cache) => cache.put(request, cl)); }
                return r;
            }).catch(() => new Response('', { status: 404 })))
        );
        return;
    }

    // API/data endpoints — network only
    if (url.pathname.includes('/api/') || url.pathname.includes('/persona/') || url.pathname.includes('/nexos/') || url.pathname.includes('/crear-lider') || url.pathname.includes('/exportar/')) {
        return;
    }

    // Page requests (navigation OR fetch to page URLs) — network first, cache aggressively
    const isPage = request.mode === 'navigate' || PAGE_PATHS.some((p) => url.pathname === p || url.pathname.startsWith(p));

    if (isPage) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response.ok && response.headers.get('content-type')?.includes('text/html')) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, clone.clone());
                        // Also cache without query params
                        if (url.search) {
                            const baseReq = new Request(url.origin + url.pathname);
                            cache.put(baseReq, clone);
                        }
                    });
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

    // Everything else — network first, cache fallback
    event.respondWith(
        fetch(request).then((r) => {
            if (r.ok) { const cl = r.clone(); caches.open(CACHE_NAME).then((cache) => cache.put(request, cl)); }
            return r;
        }).catch(() => caches.match(request))
    );
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-changes') {
        event.waitUntil(
            self.clients.matchAll().then((clients) => {
                clients.forEach((client) => client.postMessage({ type: 'SYNC_REQUESTED' }));
            })
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
