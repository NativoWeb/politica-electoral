import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// Mock Inertia usePage
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            municipios: [
                { id: 'mun-1', name: 'Bucaramanga', provincia: 'Soto' },
                { id: 'mun-2', name: 'Floridablanca', provincia: 'Soto' },
                { id: 'mun-3', name: 'Málaga', provincia: 'García Rovira' },
            ],
        },
    }),
}));

// Mock offlineDb
const mockDownloadMunicipio = vi.fn().mockResolvedValue({});
const mockDownloadProvincia = vi.fn().mockResolvedValue({ completed: 2, failed: 0, total: 2, errors: [] });
const mockDeleteDownload = vi.fn().mockResolvedValue(undefined);
const mockSyncPendingChanges = vi.fn().mockResolvedValue({ synced: 0, failed: 0 });

vi.mock('@/lib/offlineDb', () => ({
    downloadMunicipio: (...args) => mockDownloadMunicipio(...args),
    downloadProvincia: (...args) => mockDownloadProvincia(...args),
    deleteDownload: (...args) => mockDeleteDownload(...args),
    syncPendingChanges: (...args) => mockSyncPendingChanges(...args),
}));

// Mock useOffline hooks
vi.mock('@/lib/useOffline', () => ({
    useOnlineStatus: () => true,
    usePendingCount: () => [0, vi.fn()],
    useDownloads: () => [[], vi.fn()],
    useAutoSync: vi.fn(),
}));

import { OfflinePanel, OfflineIndicator, DownloadRegionButton } from './OfflineManager';

describe('OfflineManager', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('OfflinePanel', () => {
        it('muestra botón "Toda la provincia" en cada grupo', () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            const buttons = screen.getAllByText('Toda la provincia');
            expect(buttons).toHaveLength(2); // Soto y García Rovira
        });

        it('muestra los headers de provincia con conteo 0/N', () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            expect(screen.getByText('(0/1)')).toBeInTheDocument(); // García Rovira: 1 mun
            expect(screen.getByText('(0/2)')).toBeInTheDocument(); // Soto: 2 muns
        });

        it('no se renderiza si open=false', () => {
            const { container } = render(<OfflinePanel open={false} onClose={vi.fn()} />);
            expect(container.innerHTML).toBe('');
        });

        it('agrupa municipios por provincia correctamente', () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            expect(screen.getByText('Soto')).toBeInTheDocument();
            expect(screen.getByText('García Rovira')).toBeInTheDocument();
        });

        it('muestra los municipios dentro de cada provincia', () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            expect(screen.getByText('Bucaramanga')).toBeInTheDocument();
            expect(screen.getByText('Floridablanca')).toBeInTheDocument();
            expect(screen.getByText('Málaga')).toBeInTheDocument();
        });

        it('llama downloadProvincia al hacer click en "Toda la provincia"', async () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            const buttons = screen.getAllByText('Toda la provincia');
            fireEvent.click(buttons[1]); // click "Soto" (alphabetically second)

            await waitFor(() => {
                expect(mockDownloadProvincia).toHaveBeenCalledTimes(1);
            });

            const call = mockDownloadProvincia.mock.calls[0];
            expect(call[0]).toHaveLength(2); // 2 municipios in Soto
            expect(call[0][0].name).toBe('Bucaramanga');
            expect(call[0][1].name).toBe('Floridablanca');
        });

        it('filtra municipios por búsqueda', () => {
            render(<OfflinePanel open={true} onClose={vi.fn()} />);

            const searchInput = screen.getByPlaceholderText('Buscar municipio...');
            fireEvent.change(searchInput, { target: { value: 'buca' } });

            expect(screen.getByText('Bucaramanga')).toBeInTheDocument();
            expect(screen.queryByText('Floridablanca')).not.toBeInTheDocument();
            expect(screen.queryByText('Málaga')).not.toBeInTheDocument();
        });
    });

    describe('OfflineIndicator', () => {
        it('muestra "En línea" cuando hay conexión', () => {
            render(<OfflineIndicator />);
            expect(screen.getByText('En línea')).toBeInTheDocument();
        });
    });

    describe('DownloadRegionButton', () => {
        it('renderiza el botón con texto "Offline"', () => {
            render(<DownloadRegionButton municipioId="mun-1" municipioName="Bucaramanga" />);
            expect(screen.getByText('Offline')).toBeInTheDocument();
        });

        it('llama downloadMunicipio al hacer click', async () => {
            render(<DownloadRegionButton municipioId="mun-1" municipioName="Bucaramanga" />);

            fireEvent.click(screen.getByText('Offline'));

            await waitFor(() => {
                expect(mockDownloadMunicipio).toHaveBeenCalledWith('mun-1');
            });
        });
    });
});
