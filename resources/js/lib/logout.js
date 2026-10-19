import { router } from '@inertiajs/react';
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
    confirm: (message) => window.confirm(message),
    alert: (message) => window.alert(message),
    goTo: (url) => window.location.replace(url),
    post: () => router.post('/logout'),
    clearHistory: () => router.clearHistory(),
    serverLogout: () => fetch('/logout', {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'manual',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    }),
};

// Returns false when the user cancels because of unsynced changes.
export async function logout(user, deps = {}) {
    const { isOnline, confirm, alert, goTo, post, clearHistory } = { ...defaults, ...deps };

    let pending = await getPendingCount().catch(() => 0);
    if (pending > 0 && isOnline()) {
        await syncPendingChanges().catch(() => {});
        pending = await getPendingCount().catch(() => 0);
    }
    if (pending > 0 && !confirm(`Tienes ${pending} cambio(s) sin sincronizar. Si cierras sesión se descartarán de este equipo.\n\n¿Cerrar sesión de todas formas?`)) {
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

    post();
    return true;
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
    const { isOnline, goTo, serverLogout } = { ...defaults, ...deps };

    if (bumpAttempts() > 2) {
        writePendingLogout(null);
        return false;
    }

    await clearUserOfflineState();
    if (isOnline()) {
        try {
            await serverLogout();
        } catch {
            // Server unreachable: the mark stays, the next online page retries.
        }
    }
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
