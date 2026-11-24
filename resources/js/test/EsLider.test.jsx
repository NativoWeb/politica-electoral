import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';

const { page } = vi.hoisted(() => ({ page: { props: {} } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn(), get: vi.fn(), on: () => () => {} },
}));
vi.mock('@/lib/offlineDb', () => ({
    getOfflinePersona: vi.fn().mockResolvedValue(null),
    offlineUpdatePersona: vi.fn(),
    offlineCreateNexo: vi.fn(),
    offlineDeleteNexo: vi.fn(),
}));

import PersonaPanel from '@/Components/PersonaPanel';
import VotanteDetail from '@/Components/VotanteDetail';
import VotanteFilters from '@/Components/VotanteFilters';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };
const ETIQUETA = 'Es líder (cuenta para las metas)';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function persona(extra = {}) {
    return {
        id: 'l1', source: 'lider', campaign_id: 'cd', nombre: 'Beto Pérez', municipio: 'Girón', cargo: 'Líder',
        nexos: [], contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [], ...extra,
    };
}

const originalFetch = globalThis.fetch;

beforeEach(() => {
    page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: [] };
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
    cleanup();
    globalThis.fetch = originalFetch;
});

describe('«Es líder» in the Mapa ficha (PersonaPanel)', () => {
    function mockFetch(record) {
        globalThis.fetch = vi.fn((url, options = {}) => {
            if ((options.method || 'GET') !== 'GET') return json({ success: true });
            if (url.includes('posibles-duplicados')) return json({ data: [] });
            return json(record);
        });
    }
    const puts = () => globalThis.fetch.mock.calls.filter(([, o = {}]) => o.method === 'PUT').map(([, o]) => JSON.parse(o.body));

    it('is hidden when the module is off (the server sends no es_lider)', async () => {
        mockFetch(persona());
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: /Opciones/i }));
        expect(screen.queryByLabelText(ETIQUETA)).not.toBeInTheDocument();
    });

    it('saving another field never sends es_lider (a leader is not unmarked)', async () => {
        mockFetch(persona({ es_lider: true }));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: /Opciones/i }));
        expect(screen.getByLabelText(ETIQUETA)).toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]).not.toHaveProperty('es_lider');
    });

    it('ticking the box sends es_lider true', async () => {
        mockFetch(persona({ es_lider: false }));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: /Opciones/i }));
        fireEvent.click(screen.getByLabelText(ETIQUETA));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0].es_lider).toBe(true);
    });

    it('outside edit mode the box only shows the value', async () => {
        mockFetch(persona({ es_lider: true }));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: /Opciones/i }));
        expect(screen.getByLabelText(ETIQUETA)).toBeDisabled();
    });
});

describe('«Es líder» in the Votantes ficha (VotanteDetail)', () => {
    function renderDetail(data, { creating = false, campanasActivas = false } = {}) {
        const apiFetch = vi.fn().mockResolvedValue({ id: 'v1' });
        render(
            <VotanteDetail
                data={data} loading={false} creating={creating} onClose={vi.fn()} onSaved={vi.fn()} onDeleted={vi.fn()}
                apiFetch={apiFetch} municipios={[]} showToast={vi.fn()} campanasActivas={campanasActivas}
            />,
        );
        return apiFetch;
    }
    const votante = (extra = {}) => ({ id: 'v1', nombre: 'Beto Pérez', municipio: 'Girón', campaign_id: 'cd', contactos: [], info_politica: [], nexos: [], referidos: [], ...extra });
    const abrirOpciones = () => fireEvent.click(screen.getByRole('button', { name: 'Opciones' }));

    it('hidden with the module off', () => {
        renderDetail(votante());
        abrirOpciones();
        expect(screen.queryByLabelText(ETIQUETA)).not.toBeInTheDocument();
    });

    it('unticking sends es_lider false; saving without touching it sends no key', async () => {
        const apiFetch = renderDetail(votante({ es_lider: true }));
        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        abrirOpciones();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));
        await waitFor(() => expect(apiFetch).toHaveBeenCalledTimes(1));
        expect(JSON.parse(apiFetch.mock.calls[0][1].body)).not.toHaveProperty('es_lider');

        // A saved ficha leaves the edit mode: edit it again to untick.
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByLabelText(ETIQUETA));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));
        await waitFor(() => expect(apiFetch).toHaveBeenCalledTimes(2));
        expect(JSON.parse(apiFetch.mock.calls[1][1].body).es_lider).toBe(false);
    });

    it('creating a votante with the module on offers the box', () => {
        renderDetail(null, { creating: true, campanasActivas: true });
        abrirOpciones();
        expect(screen.getByLabelText(ETIQUETA)).not.toBeChecked();
    });
});

describe('«Marcados como líder» filter (VotanteFilters)', () => {
    function renderFilters(props) {
        const onApply = vi.fn();
        render(<VotanteFilters filters={{}} municipios={[]} onApply={onApply} onClear={vi.fn()} {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Filtros/ }));
        return onApply;
    }

    it('only with the module on, and it filters with es_lider=1', () => {
        const onApply = renderFilters({ campanasActivas: true });
        fireEvent.click(screen.getByLabelText('Marcados como líder'));
        fireEvent.click(screen.getByRole('button', { name: /^Aplicar/ }));
        expect(onApply).toHaveBeenCalledWith(expect.objectContaining({ es_lider: '1' }));
    });

    it('module off: no filter', () => {
        renderFilters({ campanasActivas: false });
        expect(screen.queryByLabelText('Marcados como líder')).not.toBeInTheDocument();
    });
});
