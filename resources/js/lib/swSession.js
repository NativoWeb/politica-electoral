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
const LAST_USER_KEY = 'electoral:offline-user';
// Set before every logout attempt and removed by the first page without a session. If the server
// never got the logout (no network), the next online page of that same user finishes it.
const PENDING_LOGOUT_KEY = 'electoral:pending-logout';

let lastUserId;

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

export async function syncServiceWorkerSession(user, { confirm: confirmFn = (m) => window.confirm(m) } = {}) {
    const id = user?.id ?? null;
    if (id === lastUserId) return;

    // A page of a user who already logged out (restored from cache) must not register them again.
    if (id && readPendingLogout() && await isLogoutPendingFor(user)) return;
    if (!id) writePendingLogout(null);
    lastUserId = id;

    // The owner switches right away, so nothing is queued or synced under the previous user meanwhile.
    const pendingKey = userKey(id);
    setOfflineOwner(pendingKey);
    const key = await pendingKey;
    if (key) forgetPreviousUserData(id, key, confirmFn);

    const reply = await postToServiceWorker({ type: 'SESSION', user: key, precache: key ? PRECACHE_URLS : [] });
    // No active SW yet (first install): let the next call retry.
    if (reply === null && lastUserId === id) lastUserId = undefined;
}

// Purges everything this device keeps for the session. The device mark is only removed when the
// local data really went away, so a later login still detects and wipes leftovers.
export async function clearUserOfflineState() {
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

export function resetSwSessionForTests() {
    lastUserId = undefined;
}
