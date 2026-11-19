// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within, cleanup, waitFor, act } from '@testing-library/react';

const mockRouterPost = vi.fn();
const mockListeners = [];
const mockApiFetch = vi.fn();
const mockPage = { props: {}, url: '/admin/territorio/divisiones' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('@/lib/api', async (importOriginal) => ({ ...(await importOriginal()), apiFetch: (...args) => mockApiFetch(...args) }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    function useForm(initial) {
        const [data, setDataState] = useState(initial);
        const setData = (key, value) => setDataState(prev => ({ ...prev, [key]: value }));
        return { data, setData, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() };
    }

    return {
        Head: () => null,
        Link: ({ children, href, prefetch: _prefetch, ...rest }) => <a href={href} {...rest}>{children}</a>,
        usePage: () => mockPage,
        useForm,
        router: {
            post: (...args) => mockRouterPost(...args),
            on: (event, cb) => { mockListeners.push([event, cb]); return () => mockListeners.splice(mockListeners.findIndex(l => l[1] === cb), 1); },
            get: vi.fn(),
            reload: vi.fn(),
        },
    };
});

import TerritoryDivisiones from '@/Pages/Admin/TerritoryDivisiones';
import Territory from '@/Pages/Admin/Territory';
import { displayName } from '@/Components/Territorio/TerritorioControls';

const SUPERADMIN = { id: 'u1', name: 'Marly', role: 'Superadministrador', permissions: ['*'], canEditElectoral: true };

const PRINCIPAL = {
    id: 'stder', name: 'SANTANDER', code: '68', is_active: true, totalProvincias: 2, totalMunicipios: 3,
    provincias: [
        { id: 'met', name: 'METROPOLITANA', is_active: true, count: 2, municipios: [
            { id: 'bga', parent_id: 'met', name: 'BUCARAMANGA', code: '68001', is_active: true },
            { id: 'giron', parent_id: 'met', name: 'GIRÓN', code: '68307', is_active: true },
        ] },
        { id: 'soto', name: 'SOTO NORTE', is_active: true, count: 1, municipios: [
            { id: 'matanza', parent_id: 'soto', name: 'MATANZA', code: '68444', is_active: true },
        ] },
    ],
    municipios: [],
};

const OTROS = [
    { id: 'ant', name: 'ANTIOQUIA', code: '05', is_active: true, totalProvincias: 0, totalMunicipios: 2, nombresMunicipios: ['Medellín', 'Envigado'] },
    { id: 'nds', name: 'NORTE DE SANTANDER', code: '54', is_active: true, totalProvincias: 0, totalMunicipios: 2, nombresMunicipios: ['Cúcuta', 'Ocaña'] },
];

const PROVINCIAS = [{ id: 'met', name: 'METROPOLITANA', dept: 'SANTANDER' }, { id: 'soto', name: 'SOTO NORTE', dept: 'SANTANDER' }];

function jsonResponse(body, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(body) });
}

function renderDivisiones(props = {}) {
    return render(<TerritoryDivisiones principal={PRINCIPAL} departamentos={OTROS} provincias={PROVINCIAS} totalMunicipios={7} {...props} />);
}

function otrosToggle() {
    return screen.getByRole('button', { name: /Otros departamentos de Colombia \(2\)/ });
}

describe('displayName', () => {
    it('shows names in title case, keeping the small words and the initials', () => {
        expect(displayName('NORTE DE SANTANDER')).toBe('Norte de Santander');
        expect(displayName('BOGOTÁ, D.C.')).toBe('Bogotá, D.C.');
        expect(displayName('cúcuta (norte de santander)')).toBe('Cúcuta (Norte de Santander)');
    });
});

describe('Admin → Divisiones territoriales', () => {
    beforeEach(() => {
        mockRouterPost.mockReset();
        mockApiFetch.mockReset();
        mockPage.props = { auth: { user: SUPERADMIN } };
    });

    afterEach(cleanup);

    it('puts Santander first, open, with its provincias and correct counts', () => {
        renderDivisiones();
        const santander = screen.getByRole('region', { name: 'Departamento de Santander' });
        const otros = screen.getByRole('region', { name: 'Otros departamentos de Colombia' });
        expect(santander.compareDocumentPosition(otros) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

        expect(within(santander).getByRole('heading', { name: 'Santander' })).toBeTruthy();
        expect(within(santander).getByText(/2 provincias · 3 municipios/)).toBeTruthy();
        expect(within(santander).getByText('Metropolitana')).toBeTruthy();
        expect(within(santander).getByText('Soto Norte')).toBeTruthy();
        expect(screen.getByText(/3 departamentos · 2 provincias · 7 municipios/)).toBeTruthy();
    });

    it('keeps the other departamentos closed and does not ask for their municipios until one is opened', async () => {
        mockApiFetch.mockReturnValue(jsonResponse({ success: true, provincias: [], municipios: [
            { id: 'cuc', parent_id: 'nds', name: 'Cúcuta', code: '54001', is_active: true },
            { id: 'oca', parent_id: 'nds', name: 'Ocaña', code: '54498', is_active: false },
        ] }));
        renderDivisiones();

        expect(otrosToggle().getAttribute('aria-expanded')).toBe('false');
        expect(screen.queryByText('Norte de Santander')).toBeNull();
        expect(mockApiFetch).not.toHaveBeenCalled();

        fireEvent.click(otrosToggle());
        const row = screen.getByText('Norte de Santander').closest('li');
        expect(within(row).getByText(/2 municipios/)).toBeTruthy();
        expect(within(row).getByText('Cód. DANE 54')).toBeTruthy();
        expect(mockApiFetch).not.toHaveBeenCalled();

        fireEvent.click(within(row).getByRole('button', { expanded: false }));
        expect(await within(row).findByText('Cúcuta')).toBeTruthy();
        expect(within(row).getByText('Cód. DANE 54001')).toBeTruthy();
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/territorio/departamento/nds/municipios', { method: 'GET' });
    });

    it('searches the other departamentos by departamento or by municipio, ignoring accents', () => {
        renderDivisiones();
        fireEvent.click(otrosToggle());
        const search = screen.getByRole('searchbox', { name: 'Buscar departamento o municipio' });

        fireEvent.change(search, { target: { value: 'cucuta' } });
        let rows = screen.getAllByTestId('otro-departamento');
        expect(rows).toHaveLength(1);
        expect(within(rows[0]).getByText('Norte de Santander')).toBeTruthy();
        expect(within(rows[0]).getByText(/Coincide: Cúcuta/)).toBeTruthy();

        fireEvent.change(search, { target: { value: 'antio' } });
        rows = screen.getAllByTestId('otro-departamento');
        expect(rows).toHaveLength(1);
        expect(within(rows[0]).getByText('Antioquia')).toBeTruthy();

        fireEvent.change(search, { target: { value: 'zzz' } });
        expect(screen.queryAllByTestId('otro-departamento')).toHaveLength(0);
        expect(screen.getByText(/No hay departamentos ni municipios que coincidan/)).toBeTruthy();
    });

    it('searches inside Santander without touching the other departamentos', () => {
        renderDivisiones();
        const santander = screen.getByRole('region', { name: 'Departamento de Santander' });
        fireEvent.change(within(santander).getByRole('searchbox'), { target: { value: 'giron' } });
        expect(within(santander).getByText('Girón')).toBeTruthy();
        expect(within(santander).queryByText('Bucaramanga')).toBeNull();
        expect(within(santander).queryByText('Soto Norte')).toBeNull();
    });

    it('shows the error with a retry when the municipios cannot be loaded', async () => {
        mockApiFetch.mockReturnValueOnce(jsonResponse({ success: false, message: 'Departamento no encontrado.' }, false));
        renderDivisiones();
        fireEvent.click(otrosToggle());
        const row = screen.getByText('Antioquia').closest('li');
        fireEvent.click(within(row).getByRole('button', { expanded: false }));

        expect(await within(row).findByRole('alert')).toBeTruthy();
        expect(within(row).getByText('Departamento no encontrado.')).toBeTruthy();

        mockApiFetch.mockReturnValueOnce(jsonResponse({ success: true, provincias: [], municipios: [
            { id: 'med', parent_id: 'ant', name: 'Medellín', code: '05001', is_active: true },
        ] }));
        fireEvent.click(within(row).getByRole('button', { name: 'Reintentar' }));
        expect(await within(row).findByText('Medellín')).toBeTruthy();
    });

    it('keeps the toggle action of a municipio of another departamento', async () => {
        mockApiFetch.mockReturnValue(jsonResponse({ success: true, provincias: [], municipios: [
            { id: 'cuc', parent_id: 'nds', name: 'Cúcuta', code: '54001', is_active: true },
        ] }));
        renderDivisiones();
        fireEvent.click(otrosToggle());
        const row = screen.getByText('Norte de Santander').closest('li');
        fireEvent.click(within(row).getByRole('button', { expanded: false }));
        const mun = (await within(row).findByText('Cúcuta')).closest('li');

        fireEvent.click(within(mun).getByTitle('Desactivar'));
        expect(mockRouterPost).toHaveBeenCalledWith('/admin/territorio/cuc/toggle', {}, expect.objectContaining({ preserveScroll: true }));

        mockApiFetch.mockReturnValue(jsonResponse({ success: true, provincias: [], municipios: [
            { id: 'cuc', parent_id: 'nds', name: 'Cúcuta', code: '54001', is_active: false },
        ] }));
        act(() => mockListeners.filter(([e]) => e === 'success').forEach(([, cb]) => cb({ detail: { page: { component: 'Admin/TerritoryDivisiones' } } })));
        expect(await within(row).findByText('INACTIVO')).toBeTruthy();
        expect(mockApiFetch).toHaveBeenCalledTimes(2);
    });

    it('opens the other departamentos when there is no Santander', () => {
        render(<TerritoryDivisiones principal={null} departamentos={OTROS} provincias={[]} totalMunicipios={4} />);
        expect(otrosToggle().getAttribute('aria-expanded')).toBe('true');
        expect(screen.getAllByTestId('otro-departamento')).toHaveLength(2);
    });
});

describe('Admin home (Territorio)', () => {
    beforeEach(() => {
        mockApiFetch.mockReset();
        mockPage.props = { auth: { user: SUPERADMIN } };
    });

    afterEach(cleanup);

    it('shows Divisiones and Barrios as cards with their subtitles, without tabs', async () => {
        mockApiFetch.mockReturnValue(jsonResponse({ success: true, municipio: { id: 'bga', nombre: 'Bucaramanga' }, total_textos: 12 }));
        render(<Territory resumen={{ departamentos: 33, municipios: 1122, principal: { name: 'SANTANDER', provincias: 8, municipios: 87 } }} />);

        const divisiones = screen.getByText('Divisiones territoriales').closest('a');
        expect(divisiones.getAttribute('href')).toBe('/admin/territorio/divisiones');
        expect(within(divisiones).getByText('Departamentos, provincias y municipios · Santander: 8 provincias, 87 municipios')).toBeTruthy();

        const barrios = screen.getByText('Barrios y comunas').closest('a');
        expect(barrios.getAttribute('href')).toBe('/admin/territorio/barrios');
        expect(await within(barrios).findByText('Asignar barrios a su comuna · Bucaramanga: 12 pendientes')).toBeTruthy();
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/territorio/barrios-sin-comuna?solo_total=1', { method: 'GET' });

        expect(screen.queryByRole('button', { name: 'Divisiones' })).toBeNull();
        expect(screen.getByText('Partidos').closest('a').getAttribute('href')).toBe('/admin/partidos');
    });

    it('keeps the plain Barrios subtitle when the pending list cannot be read', async () => {
        mockApiFetch.mockReturnValue(Promise.reject(new Error('offline')));
        render(<Territory resumen={{ departamentos: 1, municipios: 87, principal: null }} />);
        await waitFor(() => expect(mockApiFetch).toHaveBeenCalled());
        expect(screen.getByText('Asignar barrios a su comuna')).toBeTruthy();
        expect(screen.getByText('Departamentos, provincias y municipios')).toBeTruthy();
    });
});
