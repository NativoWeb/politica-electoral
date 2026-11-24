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
const READ_ONLY_X = 'Este registro es del grupo político Alejandro. Puedes verlo, pero solo su equipo puede modificarlo.';

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
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
        // jsdom has no layout: TabBar scrolls the active tab into view.
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    it('without write.data shows the ficha read-only: no edit, toggle, contact, referido or nexo controls', async () => {
        page.props = { auth: { user: { campaign: CD, canWrite: false } }, visibleCampaigns: VISIBLE };
        mockFetch(persona('cd'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        expect(await screen.findByRole('note')).toHaveTextContent('Puedes ver esta ficha, pero no tienes permiso para editarla.');
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.queryByTitle('Desactivar persona')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Unificar con otra persona' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Nexos/i }));
        expect(screen.getByText('Hermana de Beto')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Agregar familiar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Contactos/i }));
        expect(screen.queryByRole('button', { name: /Agregar/i })).not.toBeInTheDocument();
    });

    it('calls onSaved after the server saves the ficha', async () => {
        mockFetch(persona('cd'));
        const onSaved = vi.fn();
        render(<PersonaPanel personId="l1" onClose={vi.fn()} onSaved={onSaved} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: /Guardar/ }));
        await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
    });

    it('opens a subcampaign lider read-only with the campaign notice', async () => {
        mockFetch(persona('x'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        expect(await screen.findByRole('note')).toHaveTextContent(READ_ONLY_X);
        expect(screen.getByText('Grupo político Alejandro')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.queryByTitle('Desactivar persona')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Nexos/i }));
        expect(screen.getByText('Hermana de Beto')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Agregar familiar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).not.toBeInTheDocument();
    });

    it('keeps what is being typed when the ficha reloads after a nexo change', async () => {
        mockFetch(persona('cd'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByPlaceholderText('Información clave del registro'), { target: { value: 'Es cafetero y gallero.' } });

        fireEvent.click(screen.getByRole('button', { name: /Nexos/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Eliminar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }));
        await waitFor(() => expect(globalThis.fetch.mock.calls.filter(([, o = {}]) => (o.method || 'GET') === 'GET').length).toBeGreaterThan(2));

        fireEvent.click(screen.getByRole('button', { name: /Basicos/i }));
        expect(screen.getByPlaceholderText('Información clave del registro')).toHaveValue('Es cafetero y gallero.');
    });

    it('keeps an own lider editable', async () => {
        mockFetch(persona('cd'));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        expect(await screen.findByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.queryByText(/^Grupo político /)).not.toBeInTheDocument();
    });

    it('shows the read-only notice instead of a generic error when a save answers 403', async () => {
        mockFetch(persona('cd'), () => json({ message: 'Este registro pertenece a otra campaña: solo se puede consultar.' }, 403));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        await waitFor(() => expect(screen.getAllByText('Este registro es de otro grupo político. Puedes verlo, pero solo su equipo puede modificarlo.').length).toBeGreaterThan(0));
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

describe('PersonaPanel cédula of another record on save', () => {
    const originalFetch = globalThis.fetch;
    const CLASH = {
        success: false,
        message: 'Esa cédula ya la tiene Ana Díaz (Lebrija).',
        duplicado: { id: 'l2', nombre: 'Ana Díaz', municipio: 'Lebrija', puede_unificar: true },
    };
    const PREVIEW = { success: true, caso: 'lideres', cedulas: null, final: { nombre: 'Ana Díaz', cedula: '1007731678', telefonos: [], cargos: [], candidaturas: [] } };

    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    async function saveWithCedula() {
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByPlaceholderText('Numero de cedula'), { target: { value: '1007731678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    }

    it('offers to unify with that record and opens the merge on it, already chosen', async () => {
        mockFetch(persona('cd'), (url) => {
            if (url.endsWith('/unificar-con/vista-previa')) return json(PREVIEW);
            if (url.endsWith('/unificar-con')) return json({ success: true, message: 'Registros unificados.', caso: 'lideres', id: 'l1', final: PREVIEW.final });
            return json(CLASH, 422);
        });
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        await saveWithCedula();

        expect(await screen.findByText('Esa cédula ya la tiene Ana Díaz (Lebrija).')).toBeInTheDocument();
        expect(screen.getByText('¿Es la misma persona?')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Revisar la cédula' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Unificar con Ana Díaz' }));

        const dialog = screen.getByRole('dialog', { name: 'Unificar con otra persona' });
        expect(dialog).toHaveTextContent('Unificar con Ana Díaz');
        expect(screen.queryByLabelText('Buscar por nombre, cédula o teléfono')).not.toBeInTheDocument();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Sí, unificar' })).toBeEnabled());
        const previewCall = globalThis.fetch.mock.calls.find(([u]) => u.endsWith('/unificar-con/vista-previa'));
        expect(previewCall[0]).toBe('/mapa-politico/persona/l1/unificar-con/vista-previa');
        expect(JSON.parse(previewCall[1].body)).toEqual({ con: 'l2', cedula: null });
        expect(globalThis.fetch.mock.calls.some(([u]) => u.includes('/unificar-con/buscar'))).toBe(false);

        const gets = () => globalThis.fetch.mock.calls.filter(([u, o = {}]) => (o.method || 'GET') === 'GET' && u === '/mapa-politico/persona/l1').length;
        const before = gets();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, unificar' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        const merge = globalThis.fetch.mock.calls.find(([u]) => u.endsWith('/unificar-con'));
        expect(JSON.parse(merge[1].body)).toEqual({ con: 'l2', cedula: null });
        await waitFor(() => expect(gets()).toBeGreaterThan(before));
        expect(await screen.findByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.queryByText('¿Es la misma persona?')).not.toBeInTheDocument();
    });

    it('with nothing else typed opens the merge without saving first', async () => {
        mockFetch(persona('cd'), (url) => (url.endsWith('/vista-previa') ? json(PREVIEW) : json(CLASH, 422)));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        await saveWithCedula();

        fireEvent.click(await screen.findByRole('button', { name: 'Unificar con Ana Díaz' }));

        expect(await screen.findByRole('dialog', { name: 'Unificar con otra persona' })).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.filter(([, o = {}]) => o.method === 'PUT')).toHaveLength(1);
    });

    it('saves what else was typed under the ficha own cédula before opening the merge', async () => {
        let puts = 0;
        mockFetch({ ...persona('cd'), cedula: '555' }, (url, options) => {
            if (url.endsWith('/vista-previa')) return json(PREVIEW);
            puts += options.method === 'PUT' ? 1 : 0;
            return puts === 1 ? json(CLASH, 422) : json({ success: true, message: 'Guardado' });
        });
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByPlaceholderText('Información clave del registro'), { target: { value: 'Es cafetero.' } });
        fireEvent.change(screen.getByPlaceholderText('Numero de cedula'), { target: { value: '1007731678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        fireEvent.click(await screen.findByRole('button', { name: 'Unificar con Ana Díaz' }));

        expect(await screen.findByRole('dialog', { name: 'Unificar con otra persona' })).toBeInTheDocument();
        const saves = globalThis.fetch.mock.calls.filter(([, o = {}]) => o.method === 'PUT');
        expect(saves).toHaveLength(2);
        expect(saves[1][0]).toBe('/mapa-politico/persona/l1');
        expect(JSON.parse(saves[1][1].body)).toMatchObject({ observacion: 'Es cafetero.', cedula: '555' });
    });

    it('a failed save of the other changes keeps the merge closed and says why', async () => {
        let puts = 0;
        mockFetch(persona('cd'), (url, options) => {
            puts += options.method === 'PUT' ? 1 : 0;
            return puts === 1 ? json(CLASH, 422) : json({ message: 'El correo no es válido.', errors: { email: ['El correo no es válido.'] } }, 422);
        });
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByPlaceholderText('Información clave del registro'), { target: { value: 'Es cafetero.' } });
        fireEvent.change(screen.getByPlaceholderText('Numero de cedula'), { target: { value: '1007731678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        fireEvent.click(await screen.findByRole('button', { name: 'Unificar con Ana Díaz' }));

        expect((await screen.findAllByText('No se pudieron guardar tus otros cambios antes de unificar: El correo no es válido.')).length).toBeGreaterThan(0);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.some(([u]) => u.includes('/unificar-con'))).toBe(false);
    });

    it('"Revisar la cédula" closes the notice and keeps the form open', async () => {
        mockFetch(persona('cd'), () => json(CLASH, 422));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        await saveWithCedula();

        fireEvent.click(await screen.findByRole('button', { name: 'Revisar la cédula' }));

        expect(screen.queryByText('Esa cédula ya la tiene Ana Díaz (Lebrija).')).not.toBeInTheDocument();
        expect(screen.getByPlaceholderText('Numero de cedula')).toHaveValue('1007731678');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('outside the territory only says where the other record is, with no merge offer', async () => {
        const message = 'Esa cédula ya la tiene otra persona registrada en Lebrija, fuera de tu territorio. Pídele a un administrador que unifique las dos fichas.';
        mockFetch(persona('cd'), () => json({ success: false, message, duplicado: { puede_unificar: false, fuera_de_territorio: true, municipio: 'Lebrija' } }, 422));
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        await saveWithCedula();

        expect(await screen.findByText(message)).toBeInTheDocument();
        expect(screen.queryByText('¿Es la misma persona?')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Unificar con / })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeInTheDocument();
    });
});
