import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    db, addPendingChange, getPendingChanges, getPendingCount, syncPendingChanges,
    reconcilePendingChanges, countLegacyPendingChanges, clearPendingChanges, setOfflineOwner, FOREIGN_RETENTION_MS,
} from './offlineDb';

const A = 'owner-a';
const B = 'owner-b';

describe('pendingChanges por usuario (IndexedDB real)', () => {
    beforeEach(async () => {
        await db.pendingChanges.clear();
        setOfflineOwner(null);
        global.fetch = vi.fn().mockResolvedValue({ ok: true });
    });

    it('sin sesión no se puede encolar un cambio offline', async () => {
        await expect(addPendingChange('persona', 'update', { id: 'p1' })).rejects.toThrow('No hay una sesión activa');
        expect(await db.pendingChanges.count()).toBe(0);
    });

    it('guarda el dueño y solo él ve y cuenta sus cambios', async () => {
        setOfflineOwner(A);
        await addPendingChange('persona', 'update', { id: 'p1', telefono: '3001112233' });

        expect((await db.pendingChanges.toArray())[0].owner).toBe(A);
        expect(await getPendingCount()).toBe(1);

        setOfflineOwner(B);
        expect(await getPendingCount()).toBe(0);
        expect(await getPendingChanges()).toEqual([]);
    });

    it('otro usuario nunca envía los cambios de A con su cookie', async () => {
        setOfflineOwner(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        setOfflineOwner(B);
        const result = await syncPendingChanges();

        expect(result).toEqual({ synced: 0, failed: 0 });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('el dueño sí sincroniza sus cambios', async () => {
        setOfflineOwner(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        const result = await syncPendingChanges();

        expect(result.synced).toBe(1);
        expect(global.fetch).toHaveBeenCalledWith('/mapa-politico/persona/p1', expect.objectContaining({ method: 'PUT' }));
    });

    it('dos sincronizaciones a la vez comparten la misma corrida (no se envía dos veces)', async () => {
        setOfflineOwner(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });

        const [first, second] = await Promise.all([syncPendingChanges(), syncPendingChanges()]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(first).toBe(second);
    });

    it('se detiene si la sesión cambia a mitad de la sincronización', async () => {
        setOfflineOwner(A);
        await addPendingChange('persona', 'update', { id: 'p1', nombre: 'X' });
        await addPendingChange('persona', 'update', { id: 'p2', nombre: 'Y' });
        global.fetch = vi.fn().mockImplementation(async () => {
            setOfflineOwner(B);
            return { ok: true };
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
        setOfflineOwner(B);
        expect(await getPendingCount()).toBe(1);
        expect((await db.pendingChanges.toArray()).find(c => c.owner === A).hiddenSince).toBeTruthy();

        expect(await reconcilePendingChanges(A)).toMatchObject({ restored: 1 });
        setOfflineOwner(A);
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
});
