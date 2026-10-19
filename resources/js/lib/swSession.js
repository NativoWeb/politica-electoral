/**
 * Bridge between the Inertia session and the service worker / offline storage.
 *
 * The SW only keeps authenticated pages for the user it was told about, and only
 * precaches protected pages once the app has confirmed there is a logged-in user.
 * Browser storage only ever holds userKey() hashes, never the account UUID.
 */
import { clearDownloadedData, clearPendingChanges, discardForeignPendingChanges, setOfflineOwner } from './offlineDb';
import { userKey } from './userKey';

export const PRECACHE_URLS = ['/mapa-politico', '/gobernador'];
const LAST_USER_KEY = 'electoral:offline-user';

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

// Offline downloads and queued edits belong to whoever made them; never hand them to another account.
// `previous` may still be a raw UUID written by the first v18 build.
async function forgetPreviousUserData(userId, key) {
    const previous = readLastUser();
    const sameAccount = previous === key || previous === userId;

    try {
        if (previous && !sameAccount) await clearDownloadedData();
        // No previous owner at all = first run after the update: the queue can only be this device's user.
        await discardForeignPendingChanges(key, { adoptLegacy: sameAccount || !previous });
        writeLastUser(key);
    } catch (err) {
        console.warn('No se pudieron borrar los datos offline del usuario anterior', err);
    }
}

export async function syncServiceWorkerSession(user) {
    const id = user?.id ?? null;
    if (id === lastUserId) return;
    lastUserId = id;

    // The owner switches right away, so nothing is queued or synced under the previous user meanwhile.
    const pendingKey = userKey(id);
    setOfflineOwner(pendingKey);
    const key = await pendingKey;
    if (key) forgetPreviousUserData(id, key);

    const reply = await postToServiceWorker({ type: 'SESSION', user: key, precache: key ? PRECACHE_URLS : [] });
    // No active SW yet (first install): let the next call retry.
    if (reply === null && lastUserId === id) lastUserId = undefined;
}

// Purges everything this device keeps for the session. The device mark is only removed when the
// local data really went away, so a later login still detects and wipes leftovers.
export async function clearUserOfflineState() {
    lastUserId = undefined;
    setOfflineOwner(null);
    await postToServiceWorker({ type: 'LOGOUT' });
    try {
        await clearDownloadedData();
        await clearPendingChanges();
        writeLastUser(null);
    } catch (err) {
        console.warn('No se pudieron borrar los datos offline al cerrar sesión', err);
    }
}

export function resetSwSessionForTests() {
    lastUserId = undefined;
}
