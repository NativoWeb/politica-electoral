import { describe, it, expect, vi, beforeEach } from 'vitest';

const offline = vi.hoisted(() => ({ getPendingCount: vi.fn(), syncPendingChanges: vi.fn() }));
const session = vi.hoisted(() => ({ clearUserOfflineState: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn(), clearHistory: vi.fn() } }));
vi.mock('./offlineDb', () => offline);
vi.mock('./swSession', async (importOriginal) => ({ ...(await importOriginal()), ...session }));

import { logout, finishPendingLogout, handleLogoutNetworkError, resetPendingLogoutAttempts, withTimeout, OFFLINE_LOGOUT_MESSAGE } from './logout';
import { userKey } from './userKey';

const userA = { id: 'user-a' };

function deps(overrides = {}) {
    return {
        isOnline: () => true,
        confirm: vi.fn(() => true),
        alert: vi.fn(),
        goTo: vi.fn(),
        post: vi.fn(),
        clearHistory: vi.fn(),
        serverLogout: vi.fn().mockResolvedValue({ type: 'opaqueredirect' }),
        ...overrides,
    };
}

describe('logout', () => {
    beforeEach(() => {
        localStorage.clear();
        resetPendingLogoutAttempts();
        offline.getPendingCount.mockReset().mockResolvedValue(0);
        offline.syncPendingChanges.mockReset().mockResolvedValue({ synced: 0, failed: 0 });
        session.clearUserOfflineState.mockReset().mockResolvedValue(undefined);
    });

    it('sin cambios pendientes purga lo local y luego cierra en el servidor', async () => {
        const d = deps();

        await expect(logout(userA, d)).resolves.toBe(true);

        expect(d.confirm).not.toHaveBeenCalled();
        expect(session.clearUserOfflineState).toHaveBeenCalledTimes(1);
        expect(d.post).toHaveBeenCalledTimes(1);
        expect(session.clearUserOfflineState.mock.invocationCallOrder[0]).toBeLessThan(d.post.mock.invocationCallOrder[0]);
    });

    it('con red intenta sincronizar antes de preguntar', async () => {
        offline.getPendingCount.mockResolvedValueOnce(2).mockResolvedValueOnce(0);
        const d = deps();

        await logout(userA, d);

        expect(offline.syncPendingChanges).toHaveBeenCalledTimes(1);
        expect(d.confirm).not.toHaveBeenCalled();
        expect(d.post).toHaveBeenCalledTimes(1);
    });

    it('si quedan cambios sin sincronizar y el usuario cancela, no cierra ni borra nada', async () => {
        offline.getPendingCount.mockResolvedValue(3);
        offline.syncPendingChanges.mockRejectedValue(new Error('500'));
        const d = deps({ confirm: vi.fn(() => false) });

        await expect(logout(userA, d)).resolves.toBe(false);

        expect(d.confirm).toHaveBeenCalledWith(expect.stringContaining('3 cambio(s) sin sincronizar'));
        expect(session.clearUserOfflineState).not.toHaveBeenCalled();
        expect(d.post).not.toHaveBeenCalled();
    });

    it('sin red purga lo local, borra el historial cifrado, avisa y no manda el POST', async () => {
        const d = deps({ isOnline: () => false });

        await logout(userA, d);

        expect(session.clearUserOfflineState).toHaveBeenCalledTimes(1);
        expect(d.clearHistory).toHaveBeenCalledTimes(1);
        expect(d.alert).toHaveBeenCalledWith(OFFLINE_LOGOUT_MESSAGE);
        expect(d.goTo).toHaveBeenCalledWith('/login');
        expect(d.post).not.toHaveBeenCalled();
        expect(localStorage.getItem('electoral:pending-logout')).toBe(await userKey('user-a'));
    });

    it('con logout pendiente y red cierra en el servidor sin montar la app', async () => {
        const d = deps();

        await expect(finishPendingLogout(d)).resolves.toBe(true);

        expect(session.clearUserOfflineState).toHaveBeenCalledTimes(1);
        expect(d.serverLogout).toHaveBeenCalledTimes(1);
        expect(d.goTo).toHaveBeenCalledWith('/login');
    });

    it('con logout pendiente sin red no llama al servidor y conserva la marca', async () => {
        localStorage.setItem('electoral:pending-logout', 'k');
        const d = deps({ isOnline: () => false });

        await finishPendingLogout(d);

        expect(d.serverLogout).not.toHaveBeenCalled();
        expect(d.goTo).toHaveBeenCalledWith('/login');
        expect(localStorage.getItem('electoral:pending-logout')).toBe('k');
    });

    it('si el servidor sigue rechazando el cierre, al tercer intento deja de redirigir (sin bucle)', async () => {
        localStorage.setItem('electoral:pending-logout', 'k');
        const d = deps({ serverLogout: vi.fn().mockRejectedValue(new Error('down')) });

        await finishPendingLogout(d);
        await finishPendingLogout(d);
        await expect(finishPendingLogout(d)).resolves.toBe(false);

        expect(d.goTo).toHaveBeenCalledTimes(2);
        expect(localStorage.getItem('electoral:pending-logout')).toBeNull();
    });

    it('si el servidor no responde, a los 10 s manda al login igual (sin spinner infinito)', async () => {
        vi.useFakeTimers();
        localStorage.setItem('electoral:pending-logout', 'k');
        const d = deps({ serverLogout: vi.fn(() => new Promise(() => {})) });

        const done = finishPendingLogout(d);
        await vi.advanceTimersByTimeAsync(9999);
        expect(d.goTo).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);

        await expect(done).resolves.toBe(true);
        expect(d.goTo).toHaveBeenCalledWith('/login');
        expect(localStorage.getItem('electoral:pending-logout')).toBe('k');
        vi.useRealTimers();
    });

    it('si borrar los datos locales se cuelga, también sale al login al vencer el tiempo', async () => {
        session.clearUserOfflineState.mockImplementationOnce(() => new Promise(() => {}));
        localStorage.setItem('electoral:pending-logout', 'k');
        const d = deps({ timeoutMs: 20 });

        await expect(finishPendingLogout(d)).resolves.toBe(true);
        expect(d.goTo).toHaveBeenCalledWith('/login');
        expect(d.serverLogout).not.toHaveBeenCalled();
    });

    it('withTimeout devuelve el valor de respaldo si la promesa no termina', async () => {
        await expect(withTimeout(new Promise(() => {}), 10, true)).resolves.toBe(true);
        await expect(withTimeout(Promise.resolve(false), 10, true)).resolves.toBe(false);
    });

    it('si el POST de logout falla por red, borra el historial, avisa y sale de la página', () => {
        const d = deps();

        handleLogoutNetworkError(d);

        expect(d.clearHistory).toHaveBeenCalledTimes(1);
        expect(d.alert).toHaveBeenCalledWith(OFFLINE_LOGOUT_MESSAGE);
        expect(d.goTo).toHaveBeenCalledWith('/login');
    });
});
