// Service Worker — Inteligencia Electoral PWA v17
const CACHE_NAME = 'electoral-v17';

// Safari rejects responses with the redirected flag from SW cache.
// Create a clean 200 response without redirect metadata.
async function stripRedirect(response) {
    if (!response.redirected) return response;
    const body = await response.blob();
    return new Response(body, {
        status: 200,
        statusText: 'OK',
        headers: new Headers(response.headers),
    });
}

// Check if a response is safe to cache for a given request URL.
// Same-path redirects (HTTP→HTTPS, www) are safe; different-path (auth→/login) are not.
function isSamePathRedirect(request, response) {
    if (!response.redirected) return true;
    try {
        const reqPath = new URL(request.url).pathname;
        const resPath = new URL(response.url).pathname;
        return reqPath === resPath;
    } catch {
        return false;
    }
}

// Find a cached response by pathname (robust fallback when exact URL match fails)
async function matchByPathname(cacheName, pathname) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    for (const key of keys) {
        try {
            if (new URL(key.url).pathname === pathname) {
                return cache.match(key);
            }
        } catch {}
    }
    return null;
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
                const urls = event.data.urls || [];
                return Promise.all(urls.map(pageUrl =>
                    fetch(new Request(pageUrl, { credentials: 'same-origin' }))
                        .then(async (res) => {
                            if (res.ok && isSamePathRedirect({ url: new URL(pageUrl, self.location.origin).href }, res)) {
                                const clean = await stripRedirect(res);
                                await cache.put(pageUrl, clean);
                            }
                        })
                        .catch(() => {})
                ));
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
                if (response.ok && isSamePathRedirect(request, response)) {
                    // IMPORTANT: clone BEFORE returning to browser (avoids race condition)
                    const toCache = response.clone();
                    caches.open(CACHE_NAME).then(async (c) => {
                        c.put(request, await stripRedirect(toCache));
                    });
                }
                return response;
            }).catch(async () => {
                // Offline: try exact match first
                const cached = await caches.match(request, { ignoreSearch: true });
                if (cached) return cached;

                // Fallback: search by pathname (handles URL format mismatches)
                const byPath = await matchByPathname(CACHE_NAME, url.pathname);
                if (byPath) return byPath;

                return caches.match('/offline.html');
            })
        );
        return;
    }
});
