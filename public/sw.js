// Service Worker — Inteligencia Electoral PWA v14
const CACHE_NAME = 'electoral-v14';

// Safari rejects responses with redirected flag from SW cache.
// This creates a clean 200 response without redirect metadata.
async function cleanResponse(response) {
    if (!response.redirected) return response.clone();
    const body = await response.clone().blob();
    return new Response(body, {
        status: 200,
        statusText: 'OK',
        headers: new Headers(response.headers),
    });
}

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
                        .then(async (res) => {
                            if (res.ok) cache.put(url, await cleanResponse(res));
                        })
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

    // 3. Page navigation: network first → cache fallback → offline.html
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then(async (response) => {
                if (response.ok && response.type === 'basic') {
                    caches.open(CACHE_NAME).then(async (c) => {
                        c.put(request, await cleanResponse(response));
                    });
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
