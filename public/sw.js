// Service Worker — Inteligencia Electoral PWA
const CACHE_NAME = 'electoral-v2';
const APP_SHELL = [
    '/',
    '/mapa-politico',
    '/gobernador',
    '/manifest.json',
    '/img/bandera-colombia.svg',
    '/offline.html',
];

// Install — cache app shell
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            // Use addAll for critical assets, but don't fail if some are unavailable
            return cache.addAll(APP_SHELL).catch(() => {
                // Fallback: cache what we can individually
                return Promise.allSettled(
                    APP_SHELL.map((url) => cache.add(url).catch(() => {}))
                );
            });
        })
    );
    self.skipWaiting();
});

// Activate — clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

// Fetch — network first for navigation, cache first for assets
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip chrome-extension and other non-http(s) requests
    if (!request.url.startsWith('http')) return;

    // For build assets (JS/CSS with hashes) — cache first (immutable)
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
                });
            })
        );
        return;
    }

    // For images and fonts — cache first with network fallback
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

    // For Google Fonts CSS — cache first
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

    // For navigation requests — network first, fallback to cache, then offline page
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() =>
                    caches.match(request)
                        .then((cached) => cached || caches.match('/offline.html'))
                        .then((fallback) => fallback || new Response('Sin conexion', {
                            status: 503,
                            headers: { 'Content-Type': 'text/html; charset=utf-8' },
                        }))
                )
        );
        return;
    }

    // For API calls — network only (offline data handled by IndexedDB)
    if (request.url.includes('/api/')) {
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

// Background Sync — sync pending changes when online
// Note: Safari/iOS does NOT support Background Sync, so we also use
// the 'online' event in the client-side code as a fallback
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-changes') {
        event.waitUntil(
            self.clients.matchAll().then((clients) => {
                clients.forEach((client) => client.postMessage({ type: 'SYNC_REQUESTED' }));
            })
        );
    }
});

// Listen for messages from the client
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
