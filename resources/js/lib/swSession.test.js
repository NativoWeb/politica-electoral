import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const offline = vi.hoisted(() => ({
    clearDownloadedData: vi.fn(),
    clearPendingChanges: vi.fn(),
    discardForeignPendingChanges: vi.fn(),
    setOfflineOwner: vi.fn(),
}));
vi.mock('./offlineDb', () => offline);

import { syncServiceWorkerSession, clearUserOfflineState, resetSwSessionForTests, PRECACHE_URLS } from './swSession';
import { userKey } from './userKey';

function installWorker({ reply = { ok: true } } = {}) {
    const worker = {
        postMessage: vi.fn((message, [port]) => {
            if (reply !== undefined) port.postMessage(reply);
        }),
    };
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { controller: worker, getRegistration: vi.fn().mockResolvedValue(null) },
    });
    return worker;
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));

describe('swSession', () => {
    beforeEach(() => {
        resetSwSessionForTests();
        Object.values(offline).forEach(fn => fn.mockReset().mockResolvedValue(undefined));
        localStorage.clear();
    });

    afterEach(() => {
        delete navigator.serviceWorker;
    });

    it('un invitado nunca pide precargar páginas protegidas', async () => {
        const worker = installWorker();

        await syncServiceWorkerSession(null);

        expect(worker.postMessage).toHaveBeenCalledTimes(1);
        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'SESSION', user: null, precache: [] });
    });

    it('envía al SW un hash, nunca el UUID, y precarga una sola vez por sesión', async () => {
        const worker = installWorker();
        const key = await userKey('user-a');

        await syncServiceWorkerSession({ id: 'user-a' });
        await syncServiceWorkerSession({ id: 'user-a' });
        await flush();

        expect(key).toMatch(/^[0-9a-f]{64}$/);
        expect(worker.postMessage).toHaveBeenCalledTimes(1);
        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'SESSION', user: key, precache: PRECACHE_URLS });
        expect(localStorage.getItem('electoral:offline-user')).toBe(key);
        expect(JSON.stringify(localStorage)).not.toContain('user-a');
    });

    it('al entrar otro usuario borra descargas y cambios ajenos', async () => {
        installWorker();
        localStorage.setItem('electoral:offline-user', await userKey('user-a'));

        await syncServiceWorkerSession({ id: 'user-b' });
        await flush();

        const keyB = await userKey('user-b');
        expect(offline.clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(offline.discardForeignPendingChanges).toHaveBeenCalledWith(keyB, { adoptLegacy: false });
        expect(localStorage.getItem('electoral:offline-user')).toBe(keyB);
    });

    it('el mismo usuario conserva sus datos y adopta sus cambios antiguos (marca UUID de v18)', async () => {
        installWorker();
        localStorage.setItem('electoral:offline-user', 'user-a');

        await syncServiceWorkerSession({ id: 'user-a' });
        await flush();

        expect(offline.clearDownloadedData).not.toHaveBeenCalled();
        expect(offline.discardForeignPendingChanges).toHaveBeenCalledWith(await userKey('user-a'), { adoptLegacy: true });
    });

    it('si falla el borrado conserva la marca del usuario anterior para reintentar', async () => {
        installWorker();
        const keyA = await userKey('user-a');
        localStorage.setItem('electoral:offline-user', keyA);
        offline.clearDownloadedData.mockRejectedValue(new Error('blocked'));
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        await syncServiceWorkerSession({ id: 'user-b' });
        await flush();

        expect(localStorage.getItem('electoral:offline-user')).toBe(keyA);
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });

    it('reintenta la sesión si todavía no hay service worker activo', async () => {
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { controller: null, getRegistration: vi.fn().mockResolvedValue(undefined) },
        });
        await syncServiceWorkerSession({ id: 'user-a' });

        const worker = installWorker();
        await syncServiceWorkerSession({ id: 'user-a' });

        expect(worker.postMessage).toHaveBeenCalledTimes(1);
    });

    it('al cerrar sesión purga el SW, las descargas y la cola', async () => {
        const worker = installWorker();
        localStorage.setItem('electoral:offline-user', 'x');

        await clearUserOfflineState();

        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'LOGOUT' });
        expect(offline.setOfflineOwner).toHaveBeenCalledWith(null);
        expect(offline.clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(offline.clearPendingChanges).toHaveBeenCalledTimes(1);
        expect(localStorage.getItem('electoral:offline-user')).toBeNull();
    });

    it('el cierre no se bloquea si el SW no responde; si IndexedDB falla conserva la marca', async () => {
        vi.useFakeTimers();
        installWorker({ reply: undefined });
        localStorage.setItem('electoral:offline-user', 'x');
        offline.clearDownloadedData.mockRejectedValue(new Error('blocked'));
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const done = clearUserOfflineState();
        await vi.advanceTimersByTimeAsync(1600);
        await expect(done).resolves.toBeUndefined();

        expect(localStorage.getItem('electoral:offline-user')).toBe('x');
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
        vi.useRealTimers();
    });
});
