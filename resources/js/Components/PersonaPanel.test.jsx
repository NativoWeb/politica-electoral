import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const page = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn() },
}));

vi.mock('@/lib/offlineDb', () => ({
    getOfflinePersona: vi.fn().mockResolvedValue(null),
    offlineUpdatePersona: vi.fn(),
    offlineCreateNexo: vi.fn(),
    offlineDeleteNexo: vi.fn(),
}));

import PersonaPanel from './PersonaPanel';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };
const VISIBLE = [{ id: 'cd', name: 'Centro Democrático' }, { id: 'x', name: 'Alejandro' }];
const READ_ONLY_X = 'Este registro es de la campaña Alejandro. Puedes verlo, pero solo su equipo puede modificarlo.';

function persona(campaignId) {
    return {
        id: 'l1', source: 'lider', campaign_id: campaignId, nombre: 'Beto Pérez', municipio: 'Girón', cargo: 'Líder',
        nexos: [{ id: 'n1', nombre: 'Hermana de Beto', parentesco: 'Hermano/a' }],
        contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [],
    };
}

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

// GET /persona/{id} answers the record, posibles-duplicados an empty list, any write the given response.
function mockFetch(record, write = () => json({ success: true })) {
    globalThis.fetch = vi.fn((url, options = {}) => {
        if ((options.method || 'GET') !== 'GET') return write(url, options);
        if (url.includes('posibles-duplicados')) return json({ data: [] });
        return json(record);
    });
}

describe('PersonaPanel campaign read-only', () => {
    const originalFetch = globalThis.fetch;

    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD } }, visibleCampaigns: VISIBLE };
        // jsdom has no layout: TabBar scrolls the active tab into view.
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    it('opens a subcampaign lider read-only with the campaign notice', async () => {
        mockFetch(persona('x'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        expect(await screen.findByRole('note')).toHaveTextContent(READ_ONLY_X);
        expect(screen.getByText('Campaña Alejandro')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.queryByTitle('Desactivar persona')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Nexos/i }));
        expect(screen.getByText('Hermana de Beto')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Agregar familiar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).not.toBeInTheDocument();
    });

    it('keeps an own lider editable', async () => {
        mockFetch(persona('cd'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        expect(await screen.findByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.queryByText(/^Campaña /)).not.toBeInTheDocument();
    });

    it('shows the read-only notice instead of a generic error when a save answers 403', async () => {
        mockFetch(persona('cd'), () => json({ message: 'Este registro pertenece a otra campaña: solo se puede consultar.' }, 403));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        await waitFor(() => expect(screen.getAllByText('Este registro es de otra campaña. Puedes verlo, pero solo su equipo puede modificarlo.').length).toBeGreaterThan(0));
        expect(screen.queryByText(/Error al guardar/)).not.toBeInTheDocument();
    });

    it('never reports a nexo as added when the server rejects it (422)', async () => {
        mockFetch(persona('cd'), () => json({ message: 'El nombre es obligatorio.', errors: { nombre: ['El nombre es obligatorio.'] } }, 422));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: /Nexos/i }));
        fireEvent.click(screen.getByRole('button', { name: '+ Agregar familiar' }));
        fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Primo' } });
        fireEvent.submit(screen.getByText('Nuevo nexo familiar').closest('form'));

        await waitFor(() => expect(screen.getAllByText('El nombre es obligatorio.').length).toBeGreaterThan(0));
        expect(screen.queryByText('Nexo agregado')).not.toBeInTheDocument();
    });
});
