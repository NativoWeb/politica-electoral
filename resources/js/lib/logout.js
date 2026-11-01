import { router } from '@inertiajs/react';
import { askLogoutWithPending } from '@/Components/PendingLogoutDialog';
import { showGlobalToast } from './api';
import { getPendingCount, syncPendingChanges } from './offlineDb';
import { clearUserOfflineState, writePendingLogout } from './swSession';
import { userKey } from './userKey';

export const OFFLINE_LOGOUT_MESSAGE = 'No se pudo cerrar la sesión en el servidor porque no hay conexión. '
    + 'Los datos guardados en este equipo ya se borraron; conéctate para cerrarla (se cerrará sola al abrir la app con internet).';

function xsrfToken() {
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

const defaults = {
    isOnline: () => navigator.onLine,
    confirmPending: (count) => askLogoutWithPending(count),
    notify: (message) => showGlobalToast(message, 'info'),
    alert: (message) => window.alert(message),
    goTo: (url) => window.location.replace(url),
    post: (onFinish) => router.post('/logout', {}, { onFinish }),
    clearHistory: () => router.clearHistory(),
    serverLogout: () => fetch('/logout', {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'manual',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    }),
};

export const SYNC_BEFORE_LOGOUT_MS = 8000;

// The user's unsynced changes, or null when the queue cannot be read (then it is not assumed empty).
async function countPending() {
    try {
        return await getPendingCount();
    } catch {
        return null;
    }
}

// Repeated taps while a logout is running are ignored. The flag goes back down when the user stays,
// something fails or the logout request finishes (Inertia keeps the app alive for the next login).
let logoutInFlight = false;

export function resetLogoutForTests() {
    logoutInFlight = false;
}

// Returns false when the user stays because of unsynced changes (logging out clears this device's queue),
// or when another logout is already running.
export async function logout(user, deps = {}) {
    if (logoutInFlight) return false;
    logoutInFlight = true;
    const release = () => { logoutInFlight = false; };
    try {
        const leaving = await runLogout(user, deps, release);
        if (!leaving) release();
        return leaving;
    } catch (err) {
        release();
        throw err;
    }
}

async function runLogout(user, deps, release) {
    const { isOnline, confirmPending, notify, alert, goTo, post, clearHistory, syncTimeoutMs = SYNC_BEFORE_LOGOUT_MS } = { ...defaults, ...deps };

    let pending = await countPending();
    if (pending > 0 && isOnline()) {
        notify(`Enviando ${pending === 1 ? '1 registro pendiente' : `${pending} registros pendientes`} antes de cerrar sesión...`);
        // A slow or hung sync never blocks the logout: whatever is left is asked about below.
        await withTimeout(syncPendingChanges().catch(() => null), syncTimeoutMs, null);
        pending = await countPending();
    }
    if (pending !== 0 && !(await confirmPending(pending))) {
        return false;
    }

    writePendingLogout(await userKey(user?.id));
    await clearUserOfflineState();

    if (!isOnline()) {
        clearHistory();
        alert(OFFLINE_LOGOUT_MESSAGE);
        goTo('/login');
        return true;
    }

    post(release);
    return true;
}

export const PENDING_LOGOUT_TIMEOUT_MS = 10000;

// Resolves with `fallback` if `promise` has not settled after `ms` (a hung IndexedDB or fetch never blocks the user).
export function withTimeout(promise, ms, fallback) {
    let timer;
    const timeout = new Promise(resolve => { timer = setTimeout(() => resolve(fallback), ms); });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

const ATTEMPTS_KEY = 'electoral:pending-logout-attempts';

export function resetPendingLogoutAttempts() {
    try {
        sessionStorage.removeItem(ATTEMPTS_KEY);
    } catch {
        // Blocked storage: the counter just starts over.
    }
}

function bumpAttempts() {
    try {
        const n = Number(sessionStorage.getItem(ATTEMPTS_KEY) || 0) + 1;
        sessionStorage.setItem(ATTEMPTS_KEY, String(n));
        return n;
    } catch {
        return 1;
    }
}

// Used instead of mounting the app when the page belongs to a user whose logout is still pending.
// With network the server session is closed right away; without it the user lands on the offline
// page and the next online page retries. Returns false (mount the app) after 2 failed attempts in
// this tab, so a server that keeps rejecting the logout cannot cause a redirect loop.
export async function finishPendingLogout(deps = {}) {
    const { isOnline, goTo, serverLogout, timeoutMs = PENDING_LOGOUT_TIMEOUT_MS } = { ...defaults, ...deps };

    if (bumpAttempts() > 2) {
        writePendingLogout(null);
        return false;
    }

    // After the timeout the user goes to the login anyway; the mark stays and the attempt counter prevents a loop.
    await withTimeout((async () => {
        await clearUserOfflineState();
        if (isOnline()) {
            try {
                await serverLogout();
            } catch {
                // Server unreachable: the mark stays, the next online page retries.
            }
        }
    })(), timeoutMs);
    goTo('/login');
    return true;
}

// A logout POST that never reached the server: keep the local purge and leave the page.
export function handleLogoutNetworkError(deps = {}) {
    const { alert, goTo, clearHistory } = { ...defaults, ...deps };
    clearHistory();
    alert(OFFLINE_LOGOUT_MESSAGE);
    goTo('/login');
}
