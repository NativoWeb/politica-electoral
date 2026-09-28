// Service Worker — Inteligencia Electoral PWA — Build 20260928v3
const CACHE_NAME = 'electoral-v6';
const APP_SHELL = [
    '/manifest.json',
    '/offline.html',
];

// Install — cache app shell
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(APP_SHELL).catch(() => {
                return Promise.allSettled(
                    APP_SHELL.map((url) => cache.add(url).catch(() => {}))
                );
            });
        })
    );
    self.skipWaiting();
});

// Activate — migrate old cache entries to new cache, then delete old
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(async (keys) => {
            const oldKeys = keys.filter((key) => key !== CACHE_NAME && key.startsWith('electoral-'));
            // Copy navigation entries from old cache to new
            const newCache = await caches.open(CACHE_NAME);
            for (const oldKey of oldKeys) {
                const oldCache = await caches.open(oldKey);
                const requests = await oldCache.keys();
                for (const req of requests) {
                    // Only migrate HTML pages and build assets, not stale API responses
                    const url = new URL(req.url);
                    if (req.mode === 'navigate' || url.pathname.startsWith('/build/') || url.pathname.match(/\.(js|css|png|svg|woff2?)$/)) {
                        const response = await oldCache.match(req);
                        if (response) await newCache.put(req, response);
                    }
                }
                await caches.delete(oldKey);
            }
        })
    );
    self.clients.claim();
});

// Fetch handler
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip non-http(s)
    if (!request.url.startsWith('http')) return;

    // Build assets (JS/CSS with hashes) — cache first (immutable)
    if (request.url.includes('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached || new Response('', { status: 404 }));
            })
        );
        return;
    }

    // Images and fonts — cache first
    if (request.url.match(/\.(png|jpg|jpeg|svg|webp|ico|woff2?|ttf|eot)(\?.*)?$/)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => new Response('', { status: 404 }));
            })
        );
        return;
    }

    // Google Fonts — cache first
    if (request.url.includes('fonts.googleapis.com') || request.url.includes('fonts.gstatic.com')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached || new Response('', { status: 404 }));
            })
        );
        return;
    }

    // API calls and data endpoints — network only (bypass SW)
    if (request.url.includes('/api/') || request.url.includes('/persona/') || request.url.includes('/nexos/') || request.url.includes('/crear-lider') || request.url.includes('/exportar/')) {
        return;
    }

    // Navigation requests — network first, cache the base URL (without query), fallback to offline
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            // Cache both the exact URL and the base path (without query)
                            cache.put(request, clone.clone());
                            const baseUrl = new URL(request.url);
                            baseUrl.search = '';
                            cache.put(new Request(baseUrl.toString()), clone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Try exact URL first
                    return caches.match(request)
                        .then((cached) => {
                            if (cached) return cached;
                            // Try base URL without query params
                            const baseUrl = new URL(request.url);
                            baseUrl.search = '';
                            return caches.match(new Request(baseUrl.toString()));
                        })
                        .then((cached) => {
                            if (cached) return cached;
                            // Try root page
                            return caches.match('/mapa-politico') || caches.match('/');
                        })
                        .then((cached) => cached || caches.match('/offline.html'))
                        .then((fallback) => fallback || new Response('Sin conexión', {
                            status: 503,
                            headers: { 'Content-Type': 'text/html; charset=utf-8' },
                        }));
                })
        );
        return;
    }

    // Everything else — network first with cache fallback
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                }
                return response;
            })
            .catch(() => caches.match(request))
    );
});

// Background Sync
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-changes') {
        event.waitUntil(
            self.clients.matchAll().then((clients) => {
                clients.forEach((client) => client.postMessage({ type: 'SYNC_REQUESTED' }));
            })
        );
    }
});

// Messages
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    // Pre-cache pages on demand from the client
    if (event.data && event.data.type === 'PRECACHE_PAGES' && event.data.urls) {
        event.waitUntil(
            caches.open(CACHE_NAME).then(async (cache) => {
                for (const url of event.data.urls) {
                    try {
                        const response = await fetch(url, { credentials: 'same-origin' });
                        if (response.ok) {
                            await cache.put(new Request(url), response);
                        }
                    } catch {}
                }
            })
        );
    }
});
