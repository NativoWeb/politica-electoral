import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    db, addPendingChange, getPendingChanges, getPendingCount, syncPendingChanges,
    reconcilePendingChanges, countLegacyPendingChanges, clearPendingChanges, setOfflineOwner, FOREIGN_RETENTION_MS,
} from './offlineDb';

const A = 'owner-a';
const B = 'owner-b';

const okJson = () => new Response(JSON.stringify({ success: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });

// What a logged-in tab has: its owner in memory and the same owner shared by the browser's tabs.
function login(owner) {
    setOfflineOwner(owner);
    localStorage.setItem('electoral:offline-user', owner);
}

describe('pendingChanges por usuario (IndexedDB real)', () => {
    beforeEach(async () => {
        await db.pendingChanges.clear();
        setOfflineOwner(null);
        localStorage.clear();
        global.fetch = vi.fn().mockImplementation(async () => okJson());
    });

    it('sin sesión no se puede encolar un cambio offline', async () => {
        await expect(addPendingChange('persona', 'update', { id: 'p1' })).rejects.toThrow('No hay una sesión activa');
        expect(await db.pendingChanges.count()).toBe(0);
    });

    it('guarda el dueño y solo él ve y cuenta sus cambios', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', telefono: '3001112233' });

        expect((await db.pendingChanges.toArray())[0].owner).toBe(A);
        expect(await getPendingCount()).toBe(1);

        login(B);
        expect(await getPendingCount()).toBe(0);
        expect(await getPendingChanges()).toEqual([]);
    });

    it('otro usuario nunca envía los cambios de A con su cookie', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        login(B);
        const result = await syncPendingChanges();

        expect(result).toEqual({ synced: 0, failed: 0 });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('el dueño sí sincroniza sus cambios', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        const result = await syncPendingChanges();

        expect(result.synced).toBe(1);
        expect(global.fetch).toHaveBeenCalledWith('/mapa-politico/persona/p1', expect.objectContaining({ method: 'PUT' }));
    });

    it('dos sincronizaciones a la vez comparten la misma corrida (no se envía dos veces)', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        const [first, second] = await Promise.all([syncPendingChanges(), syncPendingChanges()]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(first).toBe(second);
    });

    it('se detiene si la sesión cambia a mitad de la sincronización', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });
        global.fetch = vi.fn().mockImplementation(async () => {
            login(B);
            return okJson();
        });

        const result = await syncPendingChanges();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(result.synced).toBe(1);
    });

    it('al entrar B los cambios de A quedan ocultos (no se borran) y A los recupera al volver', async () => {
        await db.pendingChanges.bulkAdd([
            { type: 'persona', action: 'update', data: { id: 'p1' }, owner: A, synced: 0, createdAt: 'x' },
            { type: 'persona', action: 'update', data: { id: 'p3' }, owner: B, synced: 0, createdAt: 'x' },
        ]);

        expect(await reconcilePendingChanges(B)).toMatchObject({ hidden: 1, expired: 0 });
        login(B);
        expect(await getPendingCount()).toBe(1);
        expect((await db.pendingChanges.toArray()).find(c => c.owner === A).hiddenSince).toBeTruthy();

        expect(await reconcilePendingChanges(A)).toMatchObject({ restored: 1 });
        login(A);
        expect(await getPendingCount()).toBe(1);
        await syncPendingChanges();
        expect(global.fetch).toHaveBeenCalledWith('/mapa-politico/persona/p1', expect.anything());
        expect(global.fetch).not.toHaveBeenCalledWith('/mapa-politico/persona/p3', expect.anything());
    });

    it('los cambios ocultos caducan a los 7 días', async () => {
        const hiddenAt = Date.parse('2026-10-01T00:00:00Z');
        await db.pendingChanges.add({ type: 'persona', action: 'update', data: { id: 'p1' }, owner: A, synced: 0, createdAt: 'x' });
        await reconcilePendingChanges(B, { now: hiddenAt });

        expect(await reconcilePendingChanges(B, { now: hiddenAt + FOREIGN_RETENTION_MS - 1000 })).toMatchObject({ expired: 0 });
        expect(await db.pendingChanges.count()).toBe(1);
        expect(await reconcilePendingChanges(B, { now: hiddenAt + FOREIGN_RETENTION_MS + 1000 })).toMatchObject({ expired: 1 });
        expect(await db.pendingChanges.count()).toBe(0);
    });

    it('cambios sin dueño: se adoptan si el usuario lo confirma, si no quedan ocultos y nunca se adoptan después', async () => {
        await db.pendingChanges.add({ type: 'persona', action: 'update', data: { id: 'p2' }, synced: 0, createdAt: 'x' });
        expect(await countLegacyPendingChanges()).toBe(1);

        expect(await reconcilePendingChanges(A, { adoptLegacy: false })).toMatchObject({ hidden: 1, adopted: 0 });
        expect(await countLegacyPendingChanges()).toBe(0);
        expect(await reconcilePendingChanges(A, { adoptLegacy: true })).toMatchObject({ adopted: 0 });

        await db.pendingChanges.clear();
        await db.pendingChanges.add({ type: 'persona', action: 'update', data: { id: 'p2' }, synced: 0, createdAt: 'x' });
        expect(await reconcilePendingChanges(A, { adoptLegacy: true })).toMatchObject({ adopted: 1 });
        expect((await db.pendingChanges.toArray())[0].owner).toBe(A);
    });

    it('al cerrar sesión solo se borra la cola del propio usuario', async () => {
        await db.pendingChanges.bulkAdd([
            { type: 'persona', action: 'update', data: { id: 'p1' }, owner: A, synced: 0, createdAt: 'x' },
            { type: 'persona', action: 'update', data: { id: 'p3' }, owner: B, synced: 0, createdAt: 'x', hiddenSince: new Date().toISOString() },
        ]);

        await clearPendingChanges(A);

        expect((await db.pendingChanges.toArray()).map(c => c.owner)).toEqual([B]);
    });

    it('dos pestañas: si otra pestaña entró con B, la de A no envía los cambios de A con la sesión de B', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });

        // Tab 2 logs in as B: the browser (cookie and shared owner) is now B's; tab 1 still holds A in memory.
        localStorage.setItem('electoral:offline-user', B);
        const result = await syncPendingChanges();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(result).toMatchObject({ synced: 0, rejected: 0, stoppedReason: 'owner' });
        expect(await getPendingCount()).toBe(2);
        expect((await db.pendingChanges.toArray()).every(c => c.synced === 0 && !c.rejected)).toBe(true);

        // A logs in again: the same changes go out, with A as owner.
        localStorage.setItem('electoral:offline-user', A);
        expect((await syncPendingChanges()).synced).toBe(2);
        expect(global.fetch.mock.calls[0][1].headers['X-Offline-Owner']).toBe(A);
    });

    it('si otra pestaña cambia de cuenta a mitad de la sincronización, se detiene antes del siguiente envío', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });
        global.fetch = vi.fn().mockImplementation(async () => {
            localStorage.setItem('electoral:offline-user', B);
            return okJson();
        });

        const result = await syncPendingChanges();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(result).toMatchObject({ synced: 1, stoppedReason: 'owner' });
        expect(await getPendingCount()).toBe(1);
    });

    it('tras cerrar sesión en otra pestaña (sin dueño compartido) no se envía nada', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        localStorage.removeItem('electoral:offline-user');

        expect(await syncPendingChanges()).toMatchObject({ synced: 0, stoppedReason: 'signed_out' });
        expect(global.fetch).not.toHaveBeenCalled();
        expect(await getPendingCount()).toBe(1);
    });

    it('un 409 owner_mismatch del servidor es temporal: se detiene sin descartar nada', async () => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });
        global.fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ success: false, code: 'owner_mismatch' }), { status: 409 }));

        const result = await syncPendingChanges();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(result).toMatchObject({ synced: 0, rejected: 0, failed: 1, stoppedStatus: 409, stoppedReason: 'owner' });
        expect(await getPendingCount()).toBe(2);
        expect((await db.pendingChanges.toArray()).some(c => c.rejected)).toBe(false);
    });

    it('los cambios ocultos no se cuentan, no se listan y no se envían aunque sean del dueño', async () => {
        login(A);
        await db.pendingChanges.bulkAdd([
            { type: 'persona', action: 'update', data: { id: 'p1' }, owner: A, synced: 0, createdAt: 'x', hiddenSince: new Date().toISOString() },
            { type: 'persona', action: 'update', data: { id: 'p2' }, owner: A, synced: 0, createdAt: 'x' },
        ]);

        expect(await getPendingCount()).toBe(1);
        expect((await getPendingChanges()).map(c => c.data.id)).toEqual(['p2']);
        await syncPendingChanges();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch).toHaveBeenCalledWith('/mapa-politico/persona/p2', expect.anything());
    });

    it.each([
        ['un redirect manual (opaqueredirect, status 0)', () => ({ ok: false, status: 0, type: 'opaqueredirect', headers: new Headers() })],
        ['un 302', () => new Response(null, { status: 302, headers: { Location: '/password/change' } })],
        ['un 200 en HTML (redirect seguido hasta otra página)', () => new Response('<html>Cambiar contraseña</html>', { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } })],
    ])('%s es temporal: no marca sincronizado ni rechazado y no sigue con los demás', async (_name, response) => {
        login(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });
        global.fetch = vi.fn().mockImplementation(async () => response());

        const result = await syncPendingChanges();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][1].redirect).toBe('manual');
        expect(result).toMatchObject({ synced: 0, rejected: 0, failed: 1, stoppedReason: 'unexpected' });
        const all = await db.pendingChanges.toArray();
        expect(all.every(c => c.synced === 0 && !c.rejected)).toBe(true);
        expect(await getPendingCount()).toBe(2);
    });
});
