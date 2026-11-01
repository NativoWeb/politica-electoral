import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    db, discardEventAttendeeChange, getEventAttendeeChanges, getEventSnapshot, getPendingCount, isEventUuidUsed,
    queueEventAttendee, rememberEventUuid, saveEventSnapshot, setOfflineOwner, syncPendingChanges,
} from './offlineDb';

const EVENT = 'ev-1';
const A = 'owner-a';

// What a logged-in tab has: its owner in memory and the same owner shared by the browser's tabs.
function login(owner) {
    setOfflineOwner(owner);
    localStorage.setItem('electoral:offline-user', owner);
}

function payload(n, extra = {}) {
    return {
        client_uuid: `00000000-0000-4000-8000-00000000000${n}`, consent_text_id: 'c1', autoriza_datos: true,
        nombres: `Persona${n}`, apellidos: 'Prueba', cedula: `10980000${n}`, celular: '3001234567', barrio: 'Centro',
        fijo: null, fecha_nacimiento: null, direccion: null, ...extra,
    };
}

const respond = (status, body = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

// A server that keeps one row per client_uuid, like POST /eventos/{id}/asistentes.
function fakeServer() {
    const rows = new Map();
    const handler = vi.fn(async (url, init) => {
        const body = JSON.parse(init.body);
        if (rows.has(body.client_uuid)) return respond(200, { success: true, data: { client_uuid: body.client_uuid, duplicate: true } });
        rows.set(body.client_uuid, { url, body });
        return respond(201, { success: true, data: { client_uuid: body.client_uuid, duplicate: false } });
    });
    return { rows, handler };
}

describe('modo operador sin señal: cola de asistentes (IndexedDB real)', () => {
    beforeEach(async () => {
        await Promise.all([db.pendingChanges.clear(), db.eventos.clear(), db.eventUuids.clear()]);
        login(A);
        document.getElementById('global-offline-toast')?.remove();
        global.fetch = vi.fn();
    });

    it('encola sin red con su client_uuid y lo cuenta como pendiente del evento', async () => {
        await queueEventAttendee(EVENT, payload(1));

        const { pending, rejected } = await getEventAttendeeChanges(EVENT);
        expect(pending).toHaveLength(1);
        expect(rejected).toHaveLength(0);
        expect(pending[0]).toMatchObject({ type: 'event_attendee', action: 'create', owner: A, synced: 0, data: { eventId: EVENT, payload: payload(1) } });
        expect(await isEventUuidUsed(payload(1).client_uuid)).toBe(true);
        expect(await getPendingCount()).toBe(1);
        expect((await getEventAttendeeChanges('otro-evento')).pending).toHaveLength(0);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('al reconectar envía los 3 al endpoint del evento, solo con el payload, y la cola queda vacía', async () => {
        const server = fakeServer();
        global.fetch = server.handler;
        for (const n of [1, 2, 3]) await queueEventAttendee(EVENT, payload(n));

        const result = await syncPendingChanges();

        expect(result).toMatchObject({ synced: 3, rejected: 0, failed: 0 });
        expect(server.handler).toHaveBeenCalledTimes(3);
        expect(server.handler.mock.calls[0][0]).toBe(`/eventos/${EVENT}/asistentes`);
        expect(server.handler.mock.calls[0][1].method).toBe('POST');
        expect(JSON.parse(server.handler.mock.calls[0][1].body)).toEqual(payload(1));
        expect(server.handler.mock.calls[0][1].headers['X-Offline-Owner']).toBe(A);
        expect((await getEventAttendeeChanges(EVENT)).pending).toHaveLength(0);
        expect(await getPendingCount()).toBe(0);
    });

    it('no duplica: un registro que el servidor ya tenía (POST perdido) y un reintento forzado responden duplicate', async () => {
        const server = fakeServer();
        global.fetch = server.handler;
        // The online POST reached the server but the answer was lost: the phone queued it with the same client_uuid.
        server.rows.set(payload(1).client_uuid, { body: payload(1) });
        await queueEventAttendee(EVENT, payload(1));
        await queueEventAttendee(EVENT, payload(2));

        const [first, second] = await Promise.all([syncPendingChanges(), syncPendingChanges()]);
        expect(first).toBe(second);
        expect(first.synced).toBe(2);
        expect(server.rows.size).toBe(2);

        // Forced retry: the same changes queued again (e.g. restored from a backup) are answered as duplicates.
        await queueEventAttendee(EVENT, payload(1));
        await queueEventAttendee(EVENT, payload(2));
        const retry = await syncPendingChanges();
        expect(retry.synced).toBe(2);
        expect(server.rows.size).toBe(2);
        expect(await syncPendingChanges()).toEqual({ synced: 0, failed: 0 });
    });

    it('un 422 queda rechazado con su motivo visible y los demás siguen enviándose', async () => {
        await queueEventAttendee(EVENT, payload(1, { celular: '12' }));
        await queueEventAttendee(EVENT, payload(2));
        global.fetch = vi.fn()
            .mockResolvedValueOnce(respond(422, { message: 'x', errors: { celular: ['El celular debe tener 10 dígitos y empezar por 3.'] } }))
            .mockResolvedValueOnce(respond(201, { success: true, data: { duplicate: false } }));

        const result = await syncPendingChanges();

        expect(result).toMatchObject({ synced: 1, rejected: 1 });
        const { pending, rejected } = await getEventAttendeeChanges(EVENT);
        expect(pending).toHaveLength(0);
        expect(rejected).toHaveLength(1);
        expect(rejected[0]).toMatchObject({
            rejected: true, rejectedStatus: 422, rejectedReason: 'El celular debe tener 10 dígitos y empezar por 3.',
            data: { payload: { nombres: 'Persona1' } },
        });
        expect(rejected[0].rejectedAt).toEqual(expect.any(String));
    });

    it('un 409 (client_uuid de otro evento) también queda rechazado con el mensaje del servidor', async () => {
        await queueEventAttendee(EVENT, payload(1));
        global.fetch = vi.fn().mockResolvedValueOnce(respond(409, { success: false, message: 'Ese registro pertenece a otro evento.' }));

        await syncPendingChanges();

        const { rejected } = await getEventAttendeeChanges(EVENT);
        expect(rejected[0]).toMatchObject({ rejectedStatus: 409, rejectedReason: 'Ese registro pertenece a otro evento.' });
    });

    it.each([401, 419])('un %i (sesión) conserva todo para reintentar tras iniciar sesión', async (status) => {
        await queueEventAttendee(EVENT, payload(1));
        await queueEventAttendee(EVENT, payload(2));
        global.fetch = vi.fn().mockResolvedValueOnce(respond(status, { message: 'Unauthenticated.' }));

        const result = await syncPendingChanges();

        expect(result).toMatchObject({ synced: 0, rejected: 0, failed: 1, stoppedStatus: status });
        expect(global.fetch).toHaveBeenCalledTimes(1);
        const { pending, rejected } = await getEventAttendeeChanges(EVENT);
        expect(pending).toHaveLength(2);
        expect(rejected).toHaveLength(0);

        const server = fakeServer();
        global.fetch = server.handler;
        expect((await syncPendingChanges()).synced).toBe(2);
    });

    it('sin red durante la sincronización se detiene y conserva la cola', async () => {
        await queueEventAttendee(EVENT, payload(1));
        global.fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

        const result = await syncPendingChanges();

        expect(result).toMatchObject({ synced: 0, failed: 1, stoppedStatus: null });
        expect((await getEventAttendeeChanges(EVENT)).pending).toHaveLength(1);
    });

    it('otro usuario del teléfono no ve, no descarta ni envía los pendientes de A', async () => {
        await queueEventAttendee(EVENT, payload(1));
        const [change] = (await getEventAttendeeChanges(EVENT)).pending;

        login('owner-b');
        expect(await getEventAttendeeChanges(EVENT)).toEqual({ pending: [], rejected: [] });
        expect(await discardEventAttendeeChange(change.id)).toBe(false);
        expect(await syncPendingChanges()).toEqual({ synced: 0, failed: 0 });
        expect(global.fetch).not.toHaveBeenCalled();

        login(A);
        expect(await discardEventAttendeeChange(change.id)).toBe(true);
        expect(await getPendingCount()).toBe(0);
    });

    it('guarda el evento y el texto de autorización del dueño para usarlos sin señal', async () => {
        const evento = { id: EVENT, nombre: 'Del Senado al barrio', barrios: ['Centro'] };
        const consent = { id: 'c1', version: 'provisional-1', cuerpo: 'Texto', approved: false };

        await saveEventSnapshot(evento, consent);

        expect(await getEventSnapshot(EVENT)).toMatchObject({ id: EVENT, owner: A, evento, consent, savedAt: expect.any(String) });
        login('owner-b');
        expect(await getEventSnapshot(EVENT)).toBeNull();
    });

    it('anota los client_uuid guardados con señal para no reutilizarlos', async () => {
        expect(await isEventUuidUsed('11111111-1111-4111-8111-111111111111')).toBe(false);
        await rememberEventUuid(EVENT, '11111111-1111-4111-8111-111111111111');
        expect(await isEventUuidUsed('11111111-1111-4111-8111-111111111111')).toBe(true);
    });
});
