import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const clearDownloadedData = vi.fn();
vi.mock('./offlineDb', () => ({ clearDownloadedData: (...args) => clearDownloadedData(...args) }));

import { syncServiceWorkerSession, clearUserOfflineState, resetSwSessionForTests, PRECACHE_URLS } from './swSession';

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

describe('swSession', () => {
    beforeEach(() => {
        resetSwSessionForTests();
        clearDownloadedData.mockReset().mockResolvedValue(undefined);
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

    it('un usuario confirmado precarga una sola vez por sesión', async () => {
        const worker = installWorker();

        await syncServiceWorkerSession({ id: 'user-a' });
        await syncServiceWorkerSession({ id: 'user-a' });

        expect(worker.postMessage).toHaveBeenCalledTimes(1);
        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'SESSION', user: 'user-a', precache: PRECACHE_URLS });
    });

    it('borra las descargas offline cuando entra otro usuario', async () => {
        installWorker();
        localStorage.setItem('electoral:offline-user', 'user-a');

        await syncServiceWorkerSession({ id: 'user-b' });

        expect(clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(localStorage.getItem('electoral:offline-user')).toBe('user-b');
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

    it('al cerrar sesión purga el SW y las descargas', async () => {
        const worker = installWorker();
        localStorage.setItem('electoral:offline-user', 'user-a');

        await clearUserOfflineState();

        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'LOGOUT' });
        expect(clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(localStorage.getItem('electoral:offline-user')).toBeNull();
    });

    it('el cierre de sesión no se bloquea si el SW no responde ni se puede borrar IndexedDB', async () => {
        vi.useFakeTimers();
        installWorker({ reply: undefined });
        clearDownloadedData.mockRejectedValue(new Error('blocked'));
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const done = clearUserOfflineState();
        await vi.advanceTimersByTimeAsync(1600);
        await expect(done).resolves.toBeUndefined();

        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
        vi.useRealTimers();
    });
});
