// Service Worker — Inteligencia Electoral PWA v13
const CACHE_NAME = 'electoral-v13';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(c => c.add('/offline.html'))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'PRECACHE_PAGES') {
        event.waitUntil(
            caches.open(CACHE_NAME).then(cache => {
                (event.data.urls || []).forEach(url => {
                    fetch(url, { credentials: 'same-origin' })
                        .then(res => { if (res.ok) cache.put(url, res); })
                        .catch(() => {});
                });
            })
        );
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // 1. Static assets: cache first, network fallback
    const isAsset = url.pathname.startsWith('/build/')
        || url.pathname.match(/\.(png|jpg|jpeg|svg|webp|ico|woff2?|ttf|eot|css|js)(\?.*)?$/)
        || url.host.includes('fonts.googleapis.com')
        || url.host.includes('fonts.gstatic.com');

    if (isAsset) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(c => c.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // 2. Inertia XHR requests: NEVER intercept
    if (request.headers.get('X-Inertia')) return;

    // 3. Page navigation (reload, URL bar, hard link):
    //    Network first → cache fallback → offline.html
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then(async (response) => {
                if (response.ok && response.type === 'basic') {
                    let toCache;
                    if (response.redirected) {
                        // Safari rejects redirected responses from SW cache —
                        // create a clean 200 response without redirect metadata
                        const body = await response.clone().blob();
                        toCache = new Response(body, {
                            status: 200,
                            statusText: 'OK',
                            headers: new Headers(response.headers),
                        });
                    } else {
                        toCache = response.clone();
                    }
                    caches.open(CACHE_NAME).then(c => c.put(request, toCache));
                }
                return response;
            }).catch(() =>
                caches.match(request, { ignoreSearch: true })
                    .then(cached => cached || caches.match('/offline.html'))
            )
        );
        return;
    }

    // 4. Everything else: let browser handle normally
});
