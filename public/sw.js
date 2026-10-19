// Service Worker — Inteligencia Electoral PWA v18
const VERSION = 'v18';
// Static cache: build assets, images, fonts and offline.html (shared, no user data).
const STATIC_CACHE = `electoral-static-${VERSION}`;
// Pages cache: authenticated HTML, only ever served to the user it was stored for.
const PAGES_CACHE = `electoral-pages-${VERSION}`;
const SESSION_KEY = '/__sw/session';
const USER_HEADER = 'X-SW-User';

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

function isCacheablePage(request, response) {
    return response.status === 200
        && response.type === 'basic'
        && (response.headers.get('Content-Type') || '').includes('text/html')
        && isSamePathRedirect(request, response);
}

async function tagWithUser(response, userId) {
    const clean = await stripRedirect(response);
    const headers = new Headers(clean.headers);
    headers.set(USER_HEADER, userId);
    return new Response(await clean.blob(), { status: 200, statusText: 'OK', headers });
}

// ─── Session state (persisted, the SW can be stopped at any time) ───

async function getSessionUser() {
    const cache = await caches.open(STATIC_CACHE);
    const stored = await cache.match(SESSION_KEY);
    if (!stored) return null;
    try {
        return (await stored.json()).user || null;
    } catch {
        return null;
    }
}

async function setSessionUser(userId) {
    const cache = await caches.open(STATIC_CACHE);
    await cache.put(SESSION_KEY, new Response(JSON.stringify({ user: userId || null }), {
        headers: { 'Content-Type': 'application/json' },
    }));
}

const precacheControllers = new Set();

async function purgeUserPages() {
    precacheControllers.forEach(controller => controller.abort());
    precacheControllers.clear();
    await caches.delete(PAGES_CACHE);
}

// Precache runs only for a confirmed user. It never follows redirects and asks for JSON on
// auth failure, so a stale session yields a 401 that is discarded instead of a login redirect
// that would store url.intended.
async function precachePages(userId, urls) {
    if (!userId || !urls.length) return;
    const controller = new AbortController();
    precacheControllers.add(controller);

    await Promise.all(urls.map(async (pageUrl) => {
        try {
            const absolute = new URL(pageUrl, self.location.origin).href;
            const res = await fetch(absolute, {
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
                headers: { Accept: 'application/json, text/html;q=0.9' },
                signal: controller.signal,
            });
            if (!isCacheablePage({ url: absolute }, res)) return;
            if (controller.signal.aborted || (await getSessionUser()) !== userId) return;
            const cache = await caches.open(PAGES_CACHE);
            await cache.put(absolute, await tagWithUser(res, userId));
        } catch {
            // Offline or aborted by a logout: nothing to store.
        }
    }));

    precacheControllers.delete(controller);
}

async function handleMessage(data) {
    if (data?.type === 'SESSION') {
        const userId = data.user || null;
        if ((await getSessionUser()) !== userId) {
            await purgeUserPages();
            await setSessionUser(userId);
        }
        // Not awaited: a LOGOUT queued behind this message must be able to abort it.
        return { reply: { ok: true }, work: precachePages(userId, Array.isArray(data.precache) ? data.precache : []) };
    }
    if (data?.type === 'LOGOUT') {
        await purgeUserPages();
        await setSessionUser(null);
        return { reply: { ok: true } };
    }
    return { reply: null };
}

// Find a cached page by pathname (robust fallback when exact URL match fails)
async function matchByPathname(cache, pathname) {
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

async function cachedPageFor(request) {
    const userId = await getSessionUser();
    if (!userId || !(await caches.has(PAGES_CACHE))) return null;
    const cache = await caches.open(PAGES_CACHE);
    const cached = (await cache.match(request, { ignoreSearch: true }))
        || (await matchByPathname(cache, new URL(request.url).pathname));
    return cached && cached.headers.get(USER_HEADER) === userId ? cached : null;
}

async function storePage(request, response) {
    const userId = await getSessionUser();
    if (!userId) return;
    const cache = await caches.open(PAGES_CACHE);
    await cache.put(request, await tagWithUser(response, userId));
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(c => c.add('/offline.html'))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== STATIC_CACHE && k !== PAGES_CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Messages are processed in order so a LOGOUT can never be overtaken by an older SESSION.
let messageQueue = Promise.resolve();

self.addEventListener('message', (event) => {
    const port = event.ports && event.ports[0];
    let work = null;
    messageQueue = messageQueue
        .then(() => handleMessage(event.data))
        .catch(() => ({ reply: { ok: false } }))
        .then((result) => {
            work = result.work || null;
            if (port) port.postMessage(result.reply);
        });
    event.waitUntil(messageQueue.then(() => work));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.pathname === SESSION_KEY) return;

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
                        caches.open(STATIC_CACHE).then(c => c.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // 2. Inertia XHR requests: NEVER intercept
    if (request.headers.get('X-Inertia')) return;

    // 3. Page navigation: network first → this user's cached page → offline.html
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then((response) => {
                if (isCacheablePage(request, response)) {
                    // IMPORTANT: clone BEFORE returning to browser (avoids race condition)
                    event.waitUntil(storePage(request, response.clone()).catch(() => {}));
                }
                return response;
            }).catch(async () => {
                const cached = await cachedPageFor(request);
                if (cached) return cached;
                return (await caches.match('/offline.html'))
                    || new Response('Sin conexión', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
            })
        );
        return;
    }
});
