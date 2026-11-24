import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';

const { page } = vi.hoisted(() => ({ page: { props: {} } }));

vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() }, usePage: () => page }));
vi.mock('@/lib/offlineDb', () => ({ offlineCreateLider: vi.fn() }));
vi.mock('@/lib/useAllMunicipios', () => ({ useAllMunicipios: list => list }));
vi.mock('@/Components/SearchableDropdown', () => ({
    default: ({ label, options, onChange }) => (
        <select aria-label={label} onChange={e => onChange(e.target.value || undefined)}>
            <option value="" />
            {options.map(o => <option key={o} value={o}>{o}</option>)}
        </select>
    ),
}));

import CrearLiderModal from '@/Components/CrearLiderModal';

const ETIQUETA = 'Es líder (cuenta para las metas)';

function crear(marcar) {
    render(<CrearLiderModal open onClose={vi.fn()} municipios={[{ id: 'm1', name: 'Girón', provincia: 'Soto' }]} provincias={['Soto']} />);
    fireEvent.change(screen.getByPlaceholderText('Nombre del lider'), { target: { value: 'Ana Diaz' } });
    fireEvent.change(screen.getByLabelText('Municipio *'), { target: { value: 'Girón (Soto)' } });
    if (marcar) fireEvent.click(screen.getByLabelText(ETIQUETA));
    fireEvent.click(screen.getByRole('button', { name: 'Crear Lider' }));
}

describe('«Es líder» when creating a líder from the Mapa (CrearLiderModal)', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ success: true }), { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('module off: no box and no key', async () => {
        page.props = { features: { campanas: false } };
        render(<CrearLiderModal open onClose={vi.fn()} municipios={[]} provincias={[]} />);
        expect(screen.queryByLabelText(ETIQUETA)).not.toBeInTheDocument();
    });

    it('module on and ticked: sends es_lider true', async () => {
        page.props = { features: { campanas: true } };
        crear(true);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body).es_lider).toBe(true);
    });

    it('module on but not ticked: sends no es_lider', async () => {
        page.props = { features: { esLider: true } };
        crear(false);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).not.toHaveProperty('es_lider');
    });
});
