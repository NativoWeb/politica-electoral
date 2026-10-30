import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock Dexie before importing the module
vi.mock('dexie', () => {
    const mockTable = () => ({
        where: vi.fn().mockReturnThis(),
        equals: vi.fn().mockReturnThis(),
        delete: vi.fn().mockResolvedValue(undefined),
        bulkPut: vi.fn().mockResolvedValue(undefined),
        put: vi.fn().mockResolvedValue(undefined),
        clear: vi.fn().mockResolvedValue(undefined),
        get: vi.fn().mockResolvedValue(null),
        toArray: vi.fn().mockResolvedValue([]),
        add: vi.fn().mockResolvedValue(1),
        update: vi.fn().mockResolvedValue(1),
        and: vi.fn().mockReturnThis(),
        count: vi.fn().mockResolvedValue(0),
    });

    class MockDexie {
        constructor() {
            this.municipios = mockTable();
            this.downloads = mockTable();
            this.personas = mockTable();
            this.lideres = mockTable();
            this.nexos = mockTable();
            this.gobernador = mockTable();
            this.pendingChanges = mockTable();
        }
        version() { return { stores: vi.fn() }; }
        transaction(_mode, _tables, fn) { return fn(); }
    }

    return { default: MockDexie };
});

// Mock fetch globally
global.fetch = vi.fn();

describe('offlineDb', () => {
    let offlineDb;

    beforeEach(async () => {
        vi.clearAllMocks();
        global.fetch.mockReset();
        offlineDb = await import('./offlineDb.js');
        offlineDb.setOfflineOwner('owner-test');
    });

    describe('downloadProvincia', () => {
        it('descarga todos los municipios de la provincia secuencialmente', async () => {
            const municipios = [
                { id: 'uuid-1', name: 'Bucaramanga' },
                { id: 'uuid-2', name: 'Floridablanca' },
                { id: 'uuid-3', name: 'Piedecuesta' },
            ];

            // Mock fetch for each municipio download
            global.fetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    municipioId: 'test',
                    municipioName: 'Test',
                    personas: [],
                    lideres: [],
                    nexos: [],
                    gobernador: [],
                }),
            });

            const progressCalls = [];
            const onProgress = (p) => progressCalls.push({ ...p });

            const result = await offlineDb.downloadProvincia(municipios, onProgress);

            expect(result.completed).toBe(3);
            expect(result.failed).toBe(0);
            expect(result.total).toBe(3);
            expect(result.errors).toHaveLength(0);

            // Verify progress was called for each municipio
            expect(progressCalls).toHaveLength(3);
            expect(progressCalls[0]).toEqual({ completed: 1, failed: 0, total: 3, current: 'Bucaramanga' });
            expect(progressCalls[1]).toEqual({ completed: 2, failed: 0, total: 3, current: 'Floridablanca' });
            expect(progressCalls[2]).toEqual({ completed: 3, failed: 0, total: 3, current: 'Piedecuesta' });
        });

        it('maneja errores parciales sin detener la descarga', async () => {
            const municipios = [
                { id: 'uuid-1', name: 'Bucaramanga' },
                { id: 'uuid-2', name: 'Floridablanca' },
                { id: 'uuid-3', name: 'Piedecuesta' },
            ];

            let callCount = 0;
            global.fetch.mockImplementation(() => {
                callCount++;
                if (callCount === 2) {
                    return Promise.resolve({ ok: false, status: 500 });
                }
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({
                        municipioId: 'test',
                        municipioName: 'Test',
                        personas: [],
                        lideres: [],
                        nexos: [],
                        gobernador: [],
                    }),
                });
            });

            const result = await offlineDb.downloadProvincia(municipios, vi.fn());

            expect(result.completed).toBe(2);
            expect(result.failed).toBe(1);
            expect(result.total).toBe(3);
            expect(result.errors).toHaveLength(1);
            expect(result.errors[0].name).toBe('Floridablanca');
        });

        it('devuelve resultado vacío para array vacío', async () => {
            const result = await offlineDb.downloadProvincia([], vi.fn());

            expect(result.completed).toBe(0);
            expect(result.failed).toBe(0);
            expect(result.total).toBe(0);
            expect(global.fetch).not.toHaveBeenCalled();
        });

        it('funciona sin callback de progreso', async () => {
            global.fetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    municipioId: 'test',
                    municipioName: 'Test',
                    personas: [],
                    lideres: [],
                    nexos: [],
                    gobernador: [],
                }),
            });

            const result = await offlineDb.downloadProvincia(
                [{ id: 'uuid-1', name: 'Test' }],
            );

            expect(result.completed).toBe(1);
            expect(result.failed).toBe(0);
        });

        it('continúa descargando tras error de red en un municipio', async () => {
            const municipios = [
                { id: 'uuid-1', name: 'Municipio1' },
                { id: 'uuid-2', name: 'Municipio2' },
            ];

            let callCount = 0;
            global.fetch.mockImplementation(() => {
                callCount++;
                if (callCount === 1) {
                    return Promise.reject(new Error('Network error'));
                }
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({
                        municipioId: 'test',
                        municipioName: 'Test',
                        personas: [],
                        lideres: [],
                        nexos: [],
                        gobernador: [],
                    }),
                });
            });

            const progressCalls = [];
            const result = await offlineDb.downloadProvincia(municipios, (p) => progressCalls.push({ ...p }));

            expect(result.failed).toBe(1);
            expect(result.completed).toBe(1);
            // Progress still called for both
            expect(progressCalls).toHaveLength(2);
            expect(progressCalls[0].failed).toBe(1);
            expect(progressCalls[1].completed).toBe(1);
        });
    });

    describe('downloadMunicipio (regresión)', () => {
        it('llama al endpoint correcto con el municipioId', async () => {
            global.fetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    municipioId: 'abc-123',
                    municipioName: 'Bucaramanga',
                    personas: [{ id: 'p1', full_name: 'Juan' }],
                    lideres: [{ id: 'l1', nombre: 'Pedro' }],
                    nexos: [],
                    gobernador: [],
                }),
            });

            await offlineDb.downloadMunicipio('abc-123');

            expect(global.fetch).toHaveBeenCalledWith(
                '/api/offline/download/abc-123',
                expect.objectContaining({
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                }),
            );
        });

        it('lanza error si el servidor responde con error', async () => {
            global.fetch.mockResolvedValue({ ok: false, status: 404 });

            await expect(offlineDb.downloadMunicipio('bad-id')).rejects.toThrow('Download failed: 404');
        });
    });

    describe('offlineCreateLider (regresión)', () => {
        it('crea un líder con UUID y lo marca como offline', async () => {
            const liderData = { nombre: 'Carlos', municipio: 'Bucaramanga', cargo: 'Líder' };

            // Mock crypto.randomUUID
            const mockUUID = '550e8400-e29b-41d4-a716-446655440000';
            vi.stubGlobal('crypto', { randomUUID: () => mockUUID });

            const result = await offlineDb.offlineCreateLider(liderData);

            expect(result.id).toBe(mockUUID);
            expect(result._offline).toBe(true);
            expect(result.nombre).toBe('Carlos');

            vi.unstubAllGlobals();
        });

        it('encola el id del dispositivo como client_id para que el servidor no lo duplique', async () => {
            const { db } = offlineDb;
            const mockUUID = '550e8400-e29b-41d4-a716-446655440001';
            vi.stubGlobal('crypto', { randomUUID: () => mockUUID });

            await offlineDb.offlineCreateLider({ nombre: 'Pedro Gómez', municipio_id: 'mun-1' });

            expect(db.pendingChanges.add).toHaveBeenCalledWith(expect.objectContaining({
                type: 'lider',
                action: 'create',
                data: { nombre: 'Pedro Gómez', municipio_id: 'mun-1', client_id: mockUUID },
            }));
            vi.unstubAllGlobals();
        });

        it('el nexo creado sin internet también lleva su client_id', async () => {
            const { db } = offlineDb;
            const mockUUID = '550e8400-e29b-41d4-a716-446655440002';
            vi.stubGlobal('crypto', { randomUUID: () => mockUUID });

            await offlineDb.offlineCreateNexo('lid-9', { nombre: 'Ana', parentesco: 'Hija' });

            expect(db.pendingChanges.add).toHaveBeenCalledWith(expect.objectContaining({
                type: 'nexo',
                action: 'create',
                data: { nombre: 'Ana', parentesco: 'Hija', personId: 'lid-9', client_id: mockUUID },
            }));
            vi.unstubAllGlobals();
        });
    });

    describe('offlineUpdatePersona (regresión)', () => {
        it('solo encola los campos que cambiaron', async () => {
            const { db } = offlineDb;
            db.lideres.get.mockResolvedValue({
                id: 'lid-1', nombre: 'Juan', telefono: '123', cargo: 'Líder',
            });
            db.personas.get.mockResolvedValue(null);

            await offlineDb.offlineUpdatePersona('lid-1', {
                nombre: 'Juan', // sin cambio
                telefono: '456', // cambió
            });

            // pendingChanges.add should have been called with only id + telefono
            expect(db.pendingChanges.add).toHaveBeenCalledWith(
                expect.objectContaining({
                    type: 'persona',
                    action: 'update',
                    data: { id: 'lid-1', telefono: '456' },
                }),
            );
        });

        it('no encola si no hay campos que cambiaron', async () => {
            const { db } = offlineDb;
            db.lideres.get.mockResolvedValue({
                id: 'lid-1', nombre: 'Juan', telefono: '123',
            });
            db.personas.get.mockResolvedValue(null);

            await offlineDb.offlineUpdatePersona('lid-1', {
                nombre: 'Juan',
                telefono: '123',
            });

            expect(db.pendingChanges.add).not.toHaveBeenCalled();
        });
    });

    describe('addPendingChange (regresión)', () => {
        it('registra el cambio con synced=0 y timestamp', async () => {
            const { db } = offlineDb;

            await offlineDb.addPendingChange('lider', 'create', { nombre: 'Test' });

            expect(db.pendingChanges.add).toHaveBeenCalledWith(
                expect.objectContaining({
                    type: 'lider',
                    action: 'create',
                    data: { nombre: 'Test' },
                    synced: 0,
                }),
            );
        });
    });

    describe('syncPendingChanges ante rechazos del servidor', () => {
        const update = { id: 7, type: 'lider', action: 'update', data: { id: 'lid-x', telefono: '1' }, owner: 'owner-test', synced: 0 };
        const createLider = { id: 1, type: 'lider', action: 'create', data: { nombre: 'Nuevo', client_id: 'lid-new' }, owner: 'owner-test', synced: 0 };
        const createNexo = { id: 2, type: 'nexo', action: 'create', data: { nombre: 'Hija', personId: 'lid-new', client_id: 'nex-new' }, owner: 'owner-test', synced: 0 };
        const deleteNexo = { id: 3, type: 'nexo', action: 'delete', data: { id: 'nex-new' }, owner: 'owner-test', synced: 0 };
        const respond = (status, body = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
        const READ_ONLY = 'Este registro es de otra campaña. Puedes verlo, pero solo su equipo puede modificarlo.';

        beforeEach(() => {
            document.getElementById('global-offline-toast')?.remove();
        });

        it('un 403 de otra campaña sale de la cola, guarda cuándo y avisa con el mensaje de solo lectura', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([update]);
            global.fetch.mockResolvedValueOnce(respond(403, { message: 'Este registro pertenece a otra campaña: solo se puede consultar.' }));

            const result = await offlineDb.syncPendingChanges();

            expect(result).toMatchObject({ synced: 0, failed: 0, rejected: 1, messages: [READ_ONLY] });
            expect(db.pendingChanges.update).toHaveBeenCalledWith(7, expect.objectContaining({
                synced: 1, rejected: true, rejectedStatus: 403, rejectedReason: READ_ONLY, rejectedAt: expect.any(String),
            }));
            expect(document.getElementById('global-offline-toast').textContent).toContain(READ_ONLY);
        });

        it('un 422 también se descarta con el motivo del servidor', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([update]);
            global.fetch.mockResolvedValueOnce(respond(422, { message: 'Datos inválidos', errors: { cedula: ['Ya existe un líder con cédula 1'] } }));

            const result = await offlineDb.syncPendingChanges();

            expect(result.rejected).toBe(1);
            expect(db.pendingChanges.update).toHaveBeenCalledWith(7, expect.objectContaining({ rejected: true, rejectedReason: 'Ya existe un líder con cédula 1' }));
        });

        it('tras un error temporal se detiene: los cambios siguientes no se envían ni se descartan', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([update, { ...update, id: 8 }]);
            global.fetch.mockResolvedValueOnce(respond(500));

            const result = await offlineDb.syncPendingChanges();

            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(result).toMatchObject({ synced: 0, failed: 1, rejected: 0 });
            expect(db.pendingChanges.update).not.toHaveBeenCalled();
            expect(document.getElementById('global-offline-toast')).toBeNull();
        });

        it('(a) create de líder con 500 y nexo del mismo líder después: ambos siguen en la cola', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([createLider, createNexo]);
            global.fetch.mockResolvedValueOnce(respond(500)).mockResolvedValueOnce(respond(404, { message: 'No encontrado.' }));

            const result = await offlineDb.syncPendingChanges();

            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(result).toMatchObject({ synced: 0, rejected: 0 });
            expect(db.pendingChanges.update).not.toHaveBeenCalled();
            expect(db.lideres.delete).not.toHaveBeenCalled();
            expect(db.nexos.delete).not.toHaveBeenCalled();
        });

        it('(b) borrar un nexo cuyo create sigue pendiente: un 404 no lo descarta', async () => {
            const { db } = offlineDb;
            // The delete comes first in the queue; its create is still waiting further down.
            db.pendingChanges.toArray.mockResolvedValueOnce([deleteNexo, createNexo]);
            global.fetch.mockResolvedValueOnce(respond(404, { message: 'No encontrado.' }));

            const result = await offlineDb.syncPendingChanges();

            expect(result).toMatchObject({ synced: 0, rejected: 0, failed: 1 });
            expect(db.pendingChanges.update).not.toHaveBeenCalled();
        });

        it('un 404 sin create pendiente de esa entidad sí es definitivo', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([deleteNexo]);
            global.fetch.mockResolvedValueOnce(respond(404, { message: 'No encontrado.' }));

            const result = await offlineDb.syncPendingChanges();

            expect(result.rejected).toBe(1);
            expect(db.pendingChanges.update).toHaveBeenCalledWith(3, expect.objectContaining({ rejected: true, rejectedStatus: 404 }));
        });

        it('un create rechazado se quita de la vista sin internet (líder y nexo)', async () => {
            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([createLider, createNexo]);
            global.fetch
                .mockResolvedValueOnce(respond(422, { message: 'El municipio es obligatorio.' }))
                .mockResolvedValueOnce(respond(404, { message: 'No encontrado.' }));

            const result = await offlineDb.syncPendingChanges();

            expect(result.rejected).toBe(2);
            expect(db.lideres.delete).toHaveBeenCalledWith('lid-new');
            expect(db.nexos.delete).toHaveBeenCalledWith('nex-new');
        });

        it('limpieza: los rechazados se miden desde rejectedAt y duran una semana; los sincronizados un día', async () => {
            const now = Date.parse('2026-10-30T12:00:00Z');
            const iso = (msAgo) => new Date(now - msAgo).toISOString();
            const day = 86400000;

            expect(offlineDb.isExpiredResolvedChange({ synced: 1, createdAt: iso(2 * day) }, now)).toBe(true);
            expect(offlineDb.isExpiredResolvedChange({ synced: 1, createdAt: iso(day / 2) }, now)).toBe(false);
            // Created long ago but refused just now: kept.
            expect(offlineDb.isExpiredResolvedChange({ synced: 1, rejected: true, createdAt: iso(30 * day), rejectedAt: iso(day) }, now)).toBe(false);
            expect(offlineDb.isExpiredResolvedChange({ synced: 1, rejected: true, createdAt: iso(30 * day), rejectedAt: iso(8 * day) }, now)).toBe(true);

            const { db } = offlineDb;
            db.pendingChanges.toArray.mockResolvedValueOnce([update]);
            global.fetch.mockResolvedValueOnce(respond(200, {}));
            await offlineDb.syncPendingChanges();
            const cleanup = db.pendingChanges.and.mock.calls.at(-1)[0];
            expect(cleanup({ synced: 1, rejected: true, createdAt: iso(30 * day), rejectedAt: new Date().toISOString() })).toBe(false);
        });
    });

    describe('clearDownloadedData', () => {
        it('vacía las regiones descargadas y conserva la cola de cambios pendientes', async () => {
            const { db } = offlineDb;

            await offlineDb.clearDownloadedData();

            for (const table of ['municipios', 'downloads', 'personas', 'lideres', 'nexos', 'gobernador']) {
                expect(db[table].clear).toHaveBeenCalledTimes(1);
            }
            expect(db.pendingChanges.clear).not.toHaveBeenCalled();
        });

        it('propaga el error si IndexedDB falla', async () => {
            offlineDb.db.personas.clear.mockRejectedValueOnce(new Error('QuotaExceeded'));

            await expect(offlineDb.clearDownloadedData()).rejects.toThrow('QuotaExceeded');
        });
    });
});
