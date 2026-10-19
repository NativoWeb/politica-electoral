/**
 * Bridge between the Inertia session and the service worker.
 *
 * The SW only keeps authenticated pages for the user it was told about, and only
 * precaches protected pages once the app has confirmed there is a logged-in user.
 */
import { clearDownloadedData } from './offlineDb';

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

function writeLastUser(id) {
    try {
        if (id) localStorage.setItem(LAST_USER_KEY, id);
        else localStorage.removeItem(LAST_USER_KEY);
    } catch {
        // Private mode / blocked storage: the SW still purges pages on its own.
    }
}

// Offline downloads belong to whoever downloaded them; never hand them to another account.
async function forgetPreviousUserData(currentId) {
    const previous = readLastUser();
    if (currentId && previous && previous !== currentId) {
        await clearDownloadedData().catch((err) => console.warn('No se pudieron borrar los datos offline del usuario anterior', err));
    }
    if (currentId) writeLastUser(currentId);
}

export async function syncServiceWorkerSession(user) {
    const id = user?.id ?? null;
    if (id === lastUserId) return;
    lastUserId = id;

    forgetPreviousUserData(id);
    const reply = await postToServiceWorker({ type: 'SESSION', user: id, precache: id ? PRECACHE_URLS : [] });
    // No active SW yet (first install): let the next call retry.
    if (reply === null && lastUserId === id) lastUserId = undefined;
}

export async function clearUserOfflineState() {
    lastUserId = undefined;
    await postToServiceWorker({ type: 'LOGOUT' });
    await clearDownloadedData().catch((err) => console.warn('No se pudieron borrar los datos offline al cerrar sesión', err));
    writeLastUser(null);
}

export function resetSwSessionForTests() {
    lastUserId = undefined;
}
