import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const offline = vi.hoisted(() => ({
    clearDownloadedData: vi.fn(),
    clearPendingChanges: vi.fn(),
    countLegacyPendingChanges: vi.fn(),
    reconcilePendingChanges: vi.fn(),
    getOfflineOwnerKey: vi.fn(),
    getPendingCount: vi.fn(),
    syncPendingChanges: vi.fn(),
    setOfflineOwner: vi.fn(),
}));
vi.mock('./offlineDb', () => offline);

import { syncServiceWorkerSession, clearUserOfflineState, resetSwSessionForTests, PRECACHE_URLS, precacheExtraPages, precacheExtraPagesOnce, precacheUrlsFor, writePendingLogout } from './swSession';
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
        offline.countLegacyPendingChanges.mockResolvedValue(0);
        offline.getPendingCount.mockResolvedValue(0);
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

        await syncServiceWorkerSession({ id: 'user-a', permissions: ['view.mapa', 'view.gobernador'] });
        await syncServiceWorkerSession({ id: 'user-a', permissions: ['view.mapa', 'view.gobernador'] });
        await flush();

        expect(key).toMatch(/^[0-9a-f]{64}$/);
        expect(worker.postMessage).toHaveBeenCalledTimes(1);
        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'SESSION', user: key, precache: PRECACHE_URLS });
        expect(localStorage.getItem('electoral:offline-user')).toBe(key);
        expect(JSON.stringify(localStorage)).not.toContain('user-a');
    });

    it('no pide precargar /gobernador a quien no tiene view.gobernador', async () => {
        const worker = installWorker();

        await syncServiceWorkerSession({ id: 'user-a', permissions: ['view.mapa', 'write.data'] });

        expect(worker.postMessage.mock.calls[0][0].precache).toEqual(['/mapa-politico']);
        expect(precacheUrlsFor({ permissions: ['*'] })).toEqual(PRECACHE_URLS);
        expect(precacheUrlsFor({ permissions: null })).toEqual([]);
    });

    it('no pide precargar /mapa-politico a quien solo tiene view.votantes', async () => {
        const worker = installWorker();

        await syncServiceWorkerSession({ id: 'user-a', permissions: ['view.votantes', 'write.data'] });

        expect(worker.postMessage.mock.calls[0][0].precache).toEqual([]);
        expect(precacheUrlsFor({ permissions: ['view.gobernador'] })).toEqual(['/gobernador']);
    });

    it('al entrar otro usuario borra descargas y oculta (no adopta) los cambios ajenos', async () => {
        installWorker();
        localStorage.setItem('electoral:offline-user', await userKey('user-a'));

        await syncServiceWorkerSession({ id: 'user-b' });
        await flush();

        const keyB = await userKey('user-b');
        expect(offline.clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(offline.reconcilePendingChanges).toHaveBeenCalledWith(keyB, { adoptLegacy: false });
        expect(localStorage.getItem('electoral:offline-user')).toBe(keyB);
    });

    it('el mismo usuario conserva sus datos y adopta sus cambios antiguos (marca UUID de v18)', async () => {
        installWorker();
        localStorage.setItem('electoral:offline-user', 'user-a');

        await syncServiceWorkerSession({ id: 'user-a' });
        await flush();

        expect(offline.clearDownloadedData).not.toHaveBeenCalled();
        expect(offline.reconcilePendingChanges).toHaveBeenCalledWith(await userKey('user-a'), { adoptLegacy: true });
    });

    it('sin marca previa y con cambios sin dueño pregunta si son suyos', async () => {
        installWorker();
        offline.countLegacyPendingChanges.mockResolvedValue(2);
        const ask = vi.fn(() => false);

        await syncServiceWorkerSession({ id: 'user-a' }, { confirm: ask });
        await flush();

        expect(ask).toHaveBeenCalledWith(expect.stringContaining('Hay 2 cambio(s) guardado(s) antes en este equipo'));
        expect(offline.reconcilePendingChanges).toHaveBeenCalledWith(await userKey('user-a'), { adoptLegacy: false });

        resetSwSessionForTests();
        localStorage.clear();
        await syncServiceWorkerSession({ id: 'user-a' }, { confirm: () => true });
        await flush();
        expect(offline.reconcilePendingChanges).toHaveBeenLastCalledWith(await userKey('user-a'), { adoptLegacy: true });
    });

    it('cuando el dueño vuelve a entrar con red sincroniza su cola recuperada', async () => {
        installWorker();
        offline.getPendingCount.mockResolvedValue(1);

        await syncServiceWorkerSession({ id: 'user-a' }, { confirm: () => false });
        await flush();

        expect(offline.reconcilePendingChanges).toHaveBeenCalled();
        expect(offline.syncPendingChanges).toHaveBeenCalledTimes(1);
    });

    it('sin cambios sin dueño no pregunta nada', async () => {
        installWorker();
        const ask = vi.fn(() => true);

        await syncServiceWorkerSession({ id: 'user-a' }, { confirm: ask });
        await flush();

        expect(ask).not.toHaveBeenCalled();
    });

    it('una página de un usuario con logout pendiente no lo vuelve a registrar', async () => {
        const worker = installWorker();
        writePendingLogout(await userKey('user-a'));

        await syncServiceWorkerSession({ id: 'user-a' });
        await flush();

        expect(worker.postMessage).not.toHaveBeenCalled();
        expect(offline.setOfflineOwner).not.toHaveBeenCalled();
        expect(localStorage.getItem('electoral:offline-user')).toBeNull();
    });

    it('una página sin sesión borra la marca de logout pendiente', async () => {
        installWorker();
        writePendingLogout('k');

        await syncServiceWorkerSession(null);

        expect(localStorage.getItem('electoral:pending-logout')).toBeNull();
    });

    it('en el login reintenta la purga de un cierre de sesión que no alcanzó a terminar', async () => {
        installWorker();
        const keyA = await userKey('user-a');
        localStorage.setItem('electoral:offline-user', keyA);
        writePendingLogout(keyA);

        await syncServiceWorkerSession(null);
        await flush();

        expect(offline.clearDownloadedData).toHaveBeenCalled();
        expect(offline.clearPendingChanges).toHaveBeenCalledWith(keyA);
        expect(localStorage.getItem('electoral:offline-user')).toBeNull();
        expect(localStorage.getItem('electoral:pending-logout')).toBeNull();
    });

    it('una sesión vencida (sin logout pendiente) no borra la cola sin sincronizar al llegar al login', async () => {
        installWorker();
        const keyA = await userKey('user-a');
        localStorage.setItem('electoral:offline-user', keyA);

        await syncServiceWorkerSession(null);
        await flush();

        expect(offline.clearPendingChanges).not.toHaveBeenCalled();
        expect(offline.clearDownloadedData).not.toHaveBeenCalled();
        expect(localStorage.getItem('electoral:offline-user')).toBe(keyA);
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

    it('al cerrar sesión purga el SW, las descargas y la cola del propio usuario', async () => {
        const worker = installWorker();
        offline.getOfflineOwnerKey.mockResolvedValue('owner-key');
        localStorage.setItem('electoral:offline-user', 'x');

        await clearUserOfflineState();

        expect(worker.postMessage.mock.calls[0][0]).toEqual({ type: 'LOGOUT' });
        expect(offline.setOfflineOwner).toHaveBeenCalledWith(null);
        expect(offline.clearDownloadedData).toHaveBeenCalledTimes(1);
        expect(offline.clearPendingChanges).toHaveBeenCalledWith('owner-key');
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

    it('pide guardar páginas del modo operador con el hash de la sesión, después del mensaje SESSION', async () => {
        const worker = installWorker();
        const key = await userKey('user-a');
        offline.getOfflineOwnerKey.mockResolvedValue(key);

        await syncServiceWorkerSession({ id: 'user-a', permissions: ['register.asistentes'] });
        await precacheExtraPages(['/eventos/e1/operador']);

        expect(worker.postMessage.mock.calls.map(([m]) => m.type)).toEqual(['SESSION', 'PRECACHE']);
        expect(worker.postMessage.mock.calls[1][0]).toEqual({ type: 'PRECACHE', user: key, urls: ['/eventos/e1/operador'] });
    });

    it('primera instalación: las páginas pedidas sin SW activo se envían cuando el SW confirma la sesión', async () => {
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { controller: null, getRegistration: vi.fn().mockResolvedValue(undefined) },
        });
        const key = await userKey('user-a');
        offline.getOfflineOwnerKey.mockResolvedValue(key);
        await syncServiceWorkerSession({ id: 'user-a', permissions: ['register.asistentes'] });
        expect(await precacheExtraPages(['/eventos/e1/operador'])).toBeNull();

        const worker = installWorker();
        await syncServiceWorkerSession({ id: 'user-a', permissions: ['register.asistentes'] });
        await flush();

        expect(worker.postMessage.mock.calls.map(([m]) => m.type)).toEqual(['SESSION', 'PRECACHE']);
        expect(worker.postMessage.mock.calls[1][0].urls).toEqual(['/eventos/e1/operador']);
    });

    it('el listado pide cada página de operador una sola vez por sesión, y solo la marca si el SW la aceptó', async () => {
        sessionStorage.clear();
        const worker = installWorker();
        offline.getOfflineOwnerKey.mockResolvedValue('owner-key');

        await precacheExtraPagesOnce(['/eventos/e1/operador', '/eventos/e2/operador']);
        await precacheExtraPagesOnce(['/eventos/e1/operador', '/eventos/e2/operador']);
        await precacheExtraPagesOnce(['/eventos/e2/operador', '/eventos/e3/operador']);

        expect(worker.postMessage.mock.calls.map(([m]) => m.urls)).toEqual([
            ['/eventos/e1/operador', '/eventos/e2/operador'],
            ['/eventos/e3/operador'],
        ]);

        // Another account in the same tab has its own list.
        offline.getOfflineOwnerKey.mockResolvedValue('other-key');
        await precacheExtraPagesOnce(['/eventos/e1/operador']);
        expect(worker.postMessage).toHaveBeenCalledTimes(3);
        sessionStorage.clear();
    });

    it('si el SW rechaza la precarga, la vuelve a pedir en la próxima visita', async () => {
        sessionStorage.clear();
        const worker = installWorker({ reply: { ok: false } });
        offline.getOfflineOwnerKey.mockResolvedValue('owner-key');

        await precacheExtraPagesOnce(['/eventos/e1/operador']);
        await precacheExtraPagesOnce(['/eventos/e1/operador']);

        expect(worker.postMessage).toHaveBeenCalledTimes(2);
        sessionStorage.clear();
    });

    it('sin sesión no pide guardar páginas', async () => {
        const worker = installWorker();
        offline.getOfflineOwnerKey.mockResolvedValue(null);

        expect(await precacheExtraPages(['/eventos/e1/operador'])).toBeNull();
        expect(worker.postMessage).not.toHaveBeenCalled();
    });
});
