import { router } from '@inertiajs/react';
import { getPendingCount, syncPendingChanges } from './offlineDb';
import { clearUserOfflineState } from './swSession';
import { userKey } from './userKey';

// Set before every logout attempt and removed by the first page without a session. If the server
// never got the logout (no network), the next online page of that same user retries it.
const PENDING_LOGOUT_KEY = 'electoral:pending-logout';

export const OFFLINE_LOGOUT_MESSAGE = 'No se pudo cerrar la sesión en el servidor porque no hay conexión. '
    + 'Los datos guardados en este equipo ya se borraron; conéctate para cerrarla (se cerrará sola al abrir la app con internet).';

function readPendingLogout() {
    try {
        return localStorage.getItem(PENDING_LOGOUT_KEY);
    } catch {
        return null;
    }
}

function writePendingLogout(key) {
    try {
        if (key) localStorage.setItem(PENDING_LOGOUT_KEY, key);
        else localStorage.removeItem(PENDING_LOGOUT_KEY);
    } catch {
        // Blocked storage: the logout itself still runs, only the offline retry is lost.
    }
}

let resuming = false;

const defaults = {
    isOnline: () => navigator.onLine,
    confirm: (message) => window.confirm(message),
    alert: (message) => window.alert(message),
    goTo: (url) => window.location.assign(url),
    post: () => router.post('/logout'),
};

// Returns false when the user cancels because of unsynced changes.
export async function logout(user, deps = {}) {
    const { isOnline, confirm, alert, goTo, post } = { ...defaults, ...deps };

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
        alert(OFFLINE_LOGOUT_MESSAGE);
        goTo('/login');
        return true;
    }

    post();
    return true;
}

// Called on every Inertia navigation.
export async function resumePendingLogout(user, deps = {}) {
    const { isOnline, post } = { ...defaults, ...deps };
    const pendingFor = readPendingLogout();
    if (!pendingFor) return false;

    if (!user) {
        writePendingLogout(null);
        return false;
    }
    if (pendingFor !== (await userKey(user.id))) {
        writePendingLogout(null);
        return false;
    }
    if (!isOnline() || resuming) return false;

    resuming = true;
    try {
        await clearUserOfflineState();
        post();
    } finally {
        resuming = false;
    }
    return true;
}

// A logout POST that never reached the server: keep the local purge and leave the page.
export function handleLogoutNetworkError(deps = {}) {
    const { alert, goTo } = { ...defaults, ...deps };
    alert(OFFLINE_LOGOUT_MESSAGE);
    goTo('/login');
}
