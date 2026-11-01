/**
 * Bridge between the Inertia session and the service worker / offline storage.
 *
 * The SW only keeps authenticated pages for the user it was told about, and only
 * precaches protected pages once the app has confirmed there is a logged-in user.
 * Browser storage only ever holds userKey() hashes, never the account UUID.
 */
import {
    clearDownloadedData, clearPendingChanges, countLegacyPendingChanges, getOfflineOwnerKey, getPendingCount,
    reconcilePendingChanges, setOfflineOwner, syncPendingChanges,
} from './offlineDb';
import { userKey } from './userKey';

export const PRECACHE_URLS = ['/mapa-politico', '/gobernador'];
// A page behind a permission the user lacks only redirects home, so it is not requested.
const PRECACHE_PERMISSIONS = { '/mapa-politico': 'view.mapa', '/gobernador': 'view.gobernador' };
// Same key as SHARED_OWNER_KEY in offlineDb: the sync reads it to know which account this browser holds.
const LAST_USER_KEY = 'electoral:offline-user';
// Set before every logout attempt and removed by the first page without a session. If the server
// never got the logout (no network), the next online page of that same user finishes it.
const PENDING_LOGOUT_KEY = 'electoral:pending-logout';

let lastUserId;
// The last SESSION message: an extra precache waits for it, so the SW already knows the user.
let sessionPosted = Promise.resolve(null);
// Pages asked for before the SW knew the session (first install): sent once it confirms one.
const waitingExtra = new Set();

async function getWorker() {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return null;
    if (navigator.serviceWorker.controller) return navigator.serviceWorker.controller;
    const registration = await navigator.serviceWorker.getRegistration();
    return registration?.active ?? null;
}

// Resolves with the SW reply, or with null when there is no SW or it does not answer in time.
export async function postToServiceWorker(message, timeoutMs = 1500) {
    const worker = await getWorker();
    if (!worker) return null;

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => resolve(null), timeoutMs);
        channel.port1.onmessage = (event) => {
            clearTimeout(timer);
            resolve(event.data);
        };
        worker.postMessage(message, [channel.port2]);
    });
}

function readLastUser() {
    try {
        return localStorage.getItem(LAST_USER_KEY);
    } catch {
        return null;
    }
}

function writeLastUser(key) {
    try {
        if (key) localStorage.setItem(LAST_USER_KEY, key);
        else localStorage.removeItem(LAST_USER_KEY);
    } catch {
        // Private mode / blocked storage: the SW still purges pages on its own.
    }
}

export function readPendingLogout() {
    try {
        return localStorage.getItem(PENDING_LOGOUT_KEY);
    } catch {
        return null;
    }
}

export function writePendingLogout(key) {
    try {
        if (key) localStorage.setItem(PENDING_LOGOUT_KEY, key);
        else localStorage.removeItem(PENDING_LOGOUT_KEY);
    } catch {
        // Blocked storage: the logout itself still runs, only the offline retry is lost.
    }
}

// Downloads go away when another account logs in; queued edits of other accounts stay hidden
// (see reconcilePendingChanges). `previous` may still be a raw UUID written by the first v18 build.
async function forgetPreviousUserData(userId, key, confirmFn) {
    const previous = readLastUser();
    const sameAccount = previous === key || previous === userId;

    try {
        if (previous && !sameAccount) await clearDownloadedData();

        let adoptLegacy = sameAccount;
        if (!previous) {
            // First run after the update: edits saved before owners existed may belong to anyone.
            const legacy = await countLegacyPendingChanges();
            adoptLegacy = legacy > 0 && confirmFn(`Hay ${legacy} cambio(s) guardado(s) antes en este equipo sin sincronizar. ¿Son tuyos?

Si eliges Cancelar se conservarán ocultos 7 días y luego se borrarán.`);
        }
        await reconcilePendingChanges(key, { adoptLegacy });
        writeLastUser(key);
        // The owner's queue (restored, adopted or left from a previous session) goes out now.
        if (navigator.onLine && await getPendingCount() > 0) await syncPendingChanges();
    } catch (err) {
        console.warn('No se pudieron revisar los datos offline del usuario anterior', err);
    }
}

// True when this page belongs to a user whose logout is still pending on the server.
export async function isLogoutPendingFor(user) {
    const pendingFor = readPendingLogout();
    return Boolean(pendingFor && user?.id && pendingFor === (await userKey(user.id)));
}

export function precacheUrlsFor(user) {
    const perms = user?.permissions ?? [];
    return PRECACHE_URLS.filter((url) => {
        const needed = PRECACHE_PERMISSIONS[url];
        return !needed || perms.includes('*') || perms.includes(needed);
    });
}

export async function syncServiceWorkerSession(user, { confirm: confirmFn = (m) => window.confirm(m) } = {}) {
    const id = user?.id ?? null;
    if (id === lastUserId) return;

    // A page of a user who already logged out (restored from cache) must not register them again.
    if (id && readPendingLogout() && await isLogoutPendingFor(user)) return;
    if (!id) {
        // A logout whose local purge timed out: retry it now. Only for that logout, so an expired
        // session never loses its unsynced queue.
        const pendingFor = readPendingLogout();
        writePendingLogout(null);
        if (pendingFor && pendingFor === readLastUser()) await purgeLeftovers(pendingFor);
    }
    lastUserId = id;

    // The owner switches right away, so nothing is queued or synced under the previous user meanwhile.
    const pendingKey = userKey(id);
    setOfflineOwner(pendingKey);
    const key = await pendingKey;
    if (key) forgetPreviousUserData(id, key, confirmFn);

    sessionPosted = postToServiceWorker({ type: 'SESSION', user: key, precache: key ? precacheUrlsFor(user) : [] });
    const reply = await sessionPosted;
    // No active SW yet (first install): let the next call retry.
    if (reply === null && lastUserId === id) lastUserId = undefined;
    if (key && reply?.ok && waitingExtra.size) precacheExtraPages([]);
}

// Asks the SW to keep these pages of the current user for offline use (operator mode of events).
// Resolves with the SW reply, or null without a SW or a session (the pages then wait for the next session).
export async function precacheExtraPages(urls) {
    (urls ?? []).forEach(url => waitingExtra.add(url));
    await sessionPosted;
    const key = await getOfflineOwnerKey();
    if (!key || !waitingExtra.size) return null;
    const batch = [...waitingExtra];
    const reply = await postToServiceWorker({ type: 'PRECACHE', user: key, urls: batch });
    if (reply?.ok) batch.forEach(url => waitingExtra.delete(url));
    return reply;
}

const PRECACHED_KEY = 'electoral:precached-pages';

function readPrecached(storageKey) {
    try {
        return new Set(JSON.parse(sessionStorage.getItem(storageKey) || '[]'));
    } catch {
        return new Set();
    }
}

function writePrecached(storageKey, urls) {
    try {
        sessionStorage.setItem(storageKey, JSON.stringify([...urls]));
    } catch {
        // Blocked storage: the pages are just asked for again on the next visit.
    }
}

// Like precacheExtraPages, but each page only once per tab session and account (the list of events is
// visited often; the operator page refreshes its own copy every time it is opened).
export async function precacheExtraPagesOnce(urls) {
    const key = await getOfflineOwnerKey();
    if (!key) return null;
    const storageKey = `${PRECACHED_KEY}:${key}`;
    const done = readPrecached(storageKey);
    const fresh = (urls ?? []).filter(url => !done.has(url));
    if (!fresh.length) return null;
    const reply = await precacheExtraPages(fresh);
    if (reply?.ok) writePrecached(storageKey, [...done, ...fresh]);
    return reply;
}

// Purges everything this device keeps for the session. The device mark is only removed when the
// local data really went away, so a later login still detects and wipes leftovers.
export async function clearUserOfflineState() {
    waitingExtra.clear();
    lastUserId = undefined;
    const owner = await getOfflineOwnerKey();
    setOfflineOwner(null);
    await postToServiceWorker({ type: 'LOGOUT' });
    try {
        await clearDownloadedData();
        await clearPendingChanges(owner);
        writeLastUser(null);
    } catch (err) {
        console.warn('No se pudieron borrar los datos offline al cerrar sesión', err);
    }
}

async function purgeLeftovers(owner) {
    try {
        await clearDownloadedData();
        await clearPendingChanges(owner);
        writeLastUser(null);
    } catch (err) {
        console.warn('No se pudieron borrar los datos offline pendientes del cierre de sesión', err);
    }
}

export function resetSwSessionForTests() {
    lastUserId = undefined;
    sessionPosted = Promise.resolve(null);
    waitingExtra.clear();
}
