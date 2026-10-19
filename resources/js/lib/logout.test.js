import { describe, it, expect, vi, beforeEach } from 'vitest';

const offline = vi.hoisted(() => ({ getPendingCount: vi.fn(), syncPendingChanges: vi.fn() }));
const session = vi.hoisted(() => ({ clearUserOfflineState: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));
vi.mock('./offlineDb', () => offline);
vi.mock('./swSession', () => session);

import { logout, resumePendingLogout, handleLogoutNetworkError, OFFLINE_LOGOUT_MESSAGE } from './logout';
import { userKey } from './userKey';

const userA = { id: 'user-a' };

function deps(overrides = {}) {
    return {
        isOnline: () => true,
        confirm: vi.fn(() => true),
        alert: vi.fn(),
        goTo: vi.fn(),
        post: vi.fn(),
        ...overrides,
    };
}

describe('logout', () => {
    beforeEach(() => {
        localStorage.clear();
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

    it('sin red purga lo local, avisa y no manda el POST', async () => {
        const d = deps({ isOnline: () => false });

        await logout(userA, d);

        expect(session.clearUserOfflineState).toHaveBeenCalledTimes(1);
        expect(d.alert).toHaveBeenCalledWith(OFFLINE_LOGOUT_MESSAGE);
        expect(d.goTo).toHaveBeenCalledWith('/login');
        expect(d.post).not.toHaveBeenCalled();
        expect(localStorage.getItem('electoral:pending-logout')).toBe(await userKey('user-a'));
    });

    it('al volver la red con la sesión de A aún viva, la cierra sola', async () => {
        await logout(userA, deps({ isOnline: () => false }));
        const d = deps();

        await expect(resumePendingLogout(userA, d)).resolves.toBe(true);

        expect(d.post).toHaveBeenCalledTimes(1);
    });

    it('una página sin sesión o de otro usuario descarta el cierre pendiente', async () => {
        await logout(userA, deps({ isOnline: () => false }));
        await expect(resumePendingLogout(null, deps())).resolves.toBe(false);
        expect(localStorage.getItem('electoral:pending-logout')).toBeNull();

        await logout(userA, deps({ isOnline: () => false }));
        const d = deps();
        await expect(resumePendingLogout({ id: 'user-b' }, d)).resolves.toBe(false);
        expect(d.post).not.toHaveBeenCalled();
        expect(localStorage.getItem('electoral:pending-logout')).toBeNull();
    });

    it('si el POST de logout falla por red, avisa y sale de la página', () => {
        const d = deps();

        handleLogoutNetworkError(d);

        expect(d.alert).toHaveBeenCalledWith(OFFLINE_LOGOUT_MESSAGE);
        expect(d.goTo).toHaveBeenCalledWith('/login');
    });
});
