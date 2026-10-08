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
});
