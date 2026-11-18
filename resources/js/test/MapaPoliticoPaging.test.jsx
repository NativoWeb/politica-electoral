import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';

const page = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Head: () => null,
    Link: ({ children }) => children,
}));
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/PersonaPanel', () => ({ default: () => null }));
vi.mock('@/Components/CrearLiderModal', () => ({ default: () => null }));
vi.mock('@/lib/offlineDb', () => ({
    downloadMunicipio: vi.fn(), downloadProvincia: vi.fn(),
    isDownloaded: vi.fn().mockResolvedValue(false), getAllOfflineData: vi.fn().mockResolvedValue(null),
}));

import MapaPolitico from '@/Pages/MapaPolitico';

const lider = (id, nombre, tipo = 'Líderes') => ({ id, nombre, tipo_registro: tipo, municipio: 'Girón', campaign_id: 'cd', cargo: 'Líder' });

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function renderPage(props = {}) {
    return render(
        <MapaPolitico
            data={[lider('l1', 'Ana Líder')]}
            municipios={[{ id: 'm1', name: 'Girón', provincia: 'Soto' }]}
            filters={{ municipio: 'm1' }}
            sectionPaging={{ Votantes: { key: 'votantes', loaded: 0, total: 3 } }}
            {...props}
        />,
    );
}

describe('Mapa Político: secciones por páginas', () => {
    const originalFetch = globalThis.fetch;

    beforeEach(() => {
        page.props = { auth: { user: { campaign: { id: 'cd', name: 'CD' }, permissions: [] } }, visibleCampaigns: [{ id: 'cd', name: 'CD' }] };
        globalThis.fetch = vi.fn();
        window.matchMedia = window.matchMedia || (() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
    });

    afterEach(() => {
        cleanup();
        globalThis.fetch = originalFetch;
    });

    it('muestra Votantes con su total sin filas y las trae al abrir la sección', async () => {
        globalThis.fetch.mockReturnValueOnce(json({ success: true, has_more: false, data: [
            lider('v1', 'Beto Votante', 'Votantes'), lider('v2', 'Caro Votante', 'Votantes'), lider('v3', 'Dora Votante', 'Votantes'),
        ] }));
        renderPage();

        expect(screen.getByRole('heading', { name: 'Votantes' })).toBeInTheDocument();
        expect(screen.getByText('0 de 3 registros')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('heading', { name: 'Votantes' }));

        await waitFor(() => expect(screen.getByText('Caro Votante')).toBeInTheDocument());
        const url = globalThis.fetch.mock.calls[0][0];
        expect(url).toContain('/mapa-politico/seccion?');
        expect(url).toContain('municipio=m1');
        expect(url).toContain('seccion=votantes');
        expect(url).toContain('offset=0');
        expect(screen.queryByRole('button', { name: 'Ver más' })).not.toBeInTheDocument();
    });

    it('"Ver más" pide la siguiente página de una sección recortada', async () => {
        const first = Array.from({ length: 2 }, (_, i) => lider(`l${i}`, `Lider ${i}`));
        globalThis.fetch.mockReturnValueOnce(json({ success: true, has_more: false, data: [lider('l9', 'Lider Nueve')] }));
        renderPage({ data: first, sectionPaging: { 'Líderes': { key: 'lideres', loaded: 2, total: 3 } } });

        fireEvent.click(screen.getByRole('heading', { name: 'Líderes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));

        await waitFor(() => expect(screen.getByText('Lider Nueve')).toBeInTheDocument());
        expect(globalThis.fetch.mock.calls[0][0]).toContain('offset=2');
        expect(screen.queryByRole('button', { name: 'Ver más' })).not.toBeInTheDocument();
        expect(screen.queryByText(/de 3 registros/)).not.toBeInTheDocument();
    });

    it('la siguiente página empieza en next_offset y el botón se va con has_more=false', async () => {
        // The server read 3 rows but returned 2 (one was already merged into a candidate's row).
        globalThis.fetch
            .mockReturnValueOnce(json({ success: true, has_more: true, next_offset: 4, data: [lider('l2', 'Lider Dos'), lider('l3', 'Lider Tres')] }))
            .mockReturnValueOnce(json({ success: true, has_more: false, next_offset: 6, data: [lider('l3', 'Lider Tres'), lider('l5', 'Lider Cinco')] }));
        renderPage({ data: [lider('l0', 'Lider Cero')], sectionPaging: { 'Líderes': { key: 'lideres', loaded: 1, total: 50 } } });

        fireEvent.click(screen.getByRole('heading', { name: 'Líderes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));
        await waitFor(() => expect(screen.getByText('Lider Tres')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));
        await waitFor(() => expect(screen.getByText('Lider Cinco')).toBeInTheDocument());

        expect(globalThis.fetch.mock.calls[0][0]).toContain('offset=1');
        expect(globalThis.fetch.mock.calls[1][0]).toContain('offset=4');
        expect(screen.getAllByText('Lider Tres')).toHaveLength(1);
        expect(screen.queryByRole('button', { name: 'Ver más' })).not.toBeInTheDocument();
    });

    it('si la página falla lo dice y deja reintentar', async () => {
        globalThis.fetch.mockReturnValueOnce(json({ success: false, message: 'error' }, 500));
        renderPage({ data: [lider('l1', 'Ana Líder')], sectionPaging: { 'Líderes': { key: 'lideres', loaded: 1, total: 5 } } });

        fireEvent.click(screen.getByRole('heading', { name: 'Líderes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));

        await waitFor(() => expect(screen.getByText(/No se pudieron cargar/)).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Ver más' })).toBeEnabled();
    });
});
