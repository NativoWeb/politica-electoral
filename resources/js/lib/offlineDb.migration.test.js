import 'fake-indexeddb/auto';
import { describe, it, expect, vi } from 'vitest';
import Dexie from 'dexie';

// A phone that still has the v1 database (before Eventos) with unsynced edits opens the new version.
describe('migración del esquema offline v1 → v2', () => {
    it('conserva los cambios pendientes y los datos descargados, y añade las tablas de eventos', async () => {
        await Dexie.delete('ElectoralOffline');
        const old = new Dexie('ElectoralOffline');
        old.version(1).stores({
            municipios: 'id, name, provincia',
            downloads: 'municipioId, downloadedAt',
            personas: 'id, municipioId, nombre, tipo_registro, partido',
            lideres: 'id, municipioId, nombre, cargo, partido, barrio',
            nexos: 'id, personId',
            gobernador: 'id, nombre',
            pendingChanges: '++id, type, action, synced, createdAt',
        });
        await old.pendingChanges.bulkAdd([
            { type: 'lider', action: 'create', data: { nombre: 'Nuevo', client_id: 'lid-1' }, owner: 'owner-a', synced: 0, createdAt: '2026-10-30T10:00:00.000Z' },
            { type: 'persona', action: 'update', data: { id: 'p1', telefono: '3001112233' }, owner: 'owner-a', synced: 0, createdAt: '2026-10-30T10:01:00.000Z' },
            { type: 'nexo', action: 'create', data: { nombre: 'Hija', personId: 'lid-1', client_id: 'nex-1' }, owner: 'owner-b', synced: 0, hiddenSince: '2026-10-30T11:00:00.000Z', createdAt: '2026-10-30T10:02:00.000Z' },
        ]);
        await old.lideres.put({ id: 'lid-1', municipioId: 'm1', nombre: 'Nuevo', _offline: true });
        old.close();

        vi.resetModules();
        const offline = await import('./offlineDb');
        offline.setOfflineOwner('owner-a');

        await offline.db.open();
        expect(offline.db.verno).toBe(2);

        const all = await offline.db.pendingChanges.toArray();
        expect(all).toHaveLength(3);
        expect(all.map(c => c.type)).toEqual(['lider', 'persona', 'nexo']);
        expect(all[2].hiddenSince).toBe('2026-10-30T11:00:00.000Z');
        expect(await offline.getPendingCount()).toBe(2);
        expect(await offline.db.lideres.get('lid-1')).toMatchObject({ nombre: 'Nuevo' });

        await offline.queueEventAttendee('ev-1', { client_uuid: '00000000-0000-4000-8000-000000000001', nombres: 'Ana', apellidos: 'Pérez' });
        expect(await offline.getPendingCount()).toBe(3);
        expect((await offline.getEventAttendeeChanges('ev-1')).pending).toHaveLength(1);
        // Queue order is kept: the old edits go out before the new registration.
        expect((await offline.getPendingChanges()).map(c => c.type)).toEqual(['lider', 'persona', 'event_attendee']);
    });
});
