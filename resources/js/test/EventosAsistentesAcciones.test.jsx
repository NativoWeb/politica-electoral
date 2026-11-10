// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, within } from '@testing-library/react';

const mockRouterPost = vi.fn();
const mockRouterReload = vi.fn();
const mockPage = { props: {}, url: '/eventos/ev1' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('qrcode', () => ({ default: { toDataURL: () => Promise.resolve('data:image/png;base64,AAAA') } }));
vi.mock('@/Components/PersonaPanel', () => ({ default: ({ personId }) => <div role="dialog" aria-label="Ficha">Ficha {personId}</div> }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, prefetch: _p, preserveScroll: _s, preserveState: _st, only: _o, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => mockPage,
    useForm: () => ({ data: {}, setData: vi.fn(), errors: {}, processing: false }),
    router: { post: (...args) => mockRouterPost(...args), reload: (...args) => mockRouterReload(...args), on: () => () => {}, get: vi.fn() },
}));

import { attendeeActions, deleteAttendeesMessage, loadLabel, undoLoadMessage } from '@/lib/eventos';
import AttendeeEditModal from '@/Components/Eventos/AttendeeEditModal';
import ImportLoads from '@/Components/Eventos/ImportLoads';
import Show from '@/Pages/Eventos/Show';

const ADMIN = { id: 'u1', name: 'Admin', permissions: ['*'], canWrite: true, campaign: { id: 'cd', name: 'CD' } };
const EVENTO = {
    id: 'ev1', nombre: 'Gira Girón', status: 'abierto', tipo: null, starts_at: '2026-11-05T18:00:00-05:00', ends_at: null,
    sitio_encuentro: 'Parque', provincia: null, municipio: { id: 'm1', nombre: 'Girón' }, barrios: [],
    campaign: { id: 'cd', nombre: 'CD' }, editable: true, descripcion: null, public_url: 'http://localhost/e/t', public_open: true,
    organizadores: [], figuras: [], created_by: null, created_at: '2026-10-31T10:00:00-05:00',
};

function attendee(id, link_status, nombres, extra = {}) {
    return { id, nombres, apellidos: 'Prueba', cedula: '12345', celular: '3001234567', barrio: 'Centro', source: 'excel', link_status, lider_id: null, ficha: null, registered_by: null, created_at: '2026-11-05T18:10:00-05:00', ...extra };
}

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const LINKED = { lider_id: 'l1', ficha: { cargo: null, lider: false } };

describe('attendee actions lib', () => {
    it('offers edit only before the attendee is in Votantes, and the ficha instead after', () => {
        const opts = { editable: true, fichaVisible: true };
        expect(attendeeActions(attendee('a', 'pendiente', 'A'), opts)).toEqual(['edit', 'approve', 'discard', 'delete']);
        expect(attendeeActions(attendee('a', 'descartado', 'A'), opts)).toEqual(['edit', 'recover', 'delete']);
        expect(attendeeActions(attendee('a', 'aprobado', 'A', LINKED), opts)).toEqual(['ficha', 'unlink', 'delete']);
        expect(attendeeActions(attendee('a', 'vinculado', 'A', LINKED), opts)).toEqual(['ficha', 'unlink', 'delete']);
    });

    it('hides the ficha without view.mapa and every write action without edit rights', () => {
        expect(attendeeActions(attendee('a', 'aprobado', 'A', LINKED), { editable: true, fichaVisible: false })).toEqual(['unlink', 'delete']);
        expect(attendeeActions(attendee('a', 'aprobado', 'A', LINKED), { editable: false, fichaVisible: true })).toEqual(['ficha']);
        expect(attendeeActions(attendee('a', 'pendiente', 'A'), { editable: false, fichaVisible: true })).toEqual([]);
    });

    it('says the ficha stays when an attendee in Votantes is deleted', () => {
        expect(deleteAttendeesMessage([attendee('a', 'pendiente', 'A')])).not.toMatch(/ficha/);
        expect(deleteAttendeesMessage([attendee('a', 'aprobado', 'A')])).toMatch(/Su ficha en Votantes NO se borra/);
    });

    it('describes a load and what undoing it keeps', () => {
        expect(loadLabel({ kind: 'excel', created_by: 'Sandra', confirmed_at: '2026-11-08T10:00:00-05:00', asistentes: 269 })).toMatch(/^Por Excel: Sandra · .+ · 269 personas$/);
        const msg = undoLoadMessage({ asistentes: 7, fichas_borrar: 2, fichas_conservar: 4, vinculadas: 1, con_autorizacion: 0 });
        expect(msg).toContain('Se eliminarán 7 asistentes');
        expect(msg).toContain('2 fichas creadas');
        expect(msg).toContain('Se conservarán 4 fichas que ya tienen otra información');
        expect(msg).toContain('no se tocan');
    });
});

describe('AttendeeEditModal', () => {
    beforeEach(() => { globalThis.fetch = vi.fn(); });
    afterEach(cleanup);

    it('sends every field (empty ones as null) and reports success', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(200, { success: true, message: 'Datos del asistente actualizados.' }));
        const onSaved = vi.fn();
        render(<AttendeeEditModal eventId="ev1" attendee={attendee('a1', 'pendiente', 'Ana')} onClose={() => {}} onSaved={onSaved} />);
        fireEvent.change(screen.getByLabelText(/^Nombres/), { target: { value: 'Ana Lucía' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalledWith('Datos del asistente actualizados.'));
        const [url, options] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/eventos/ev1/asistentes/a1');
        expect(options.method).toBe('PUT');
        expect(JSON.parse(options.body)).toMatchObject({ nombres: 'Ana Lucía', cedula: '12345', fijo: null, nota: null });
    });

    it('shows the server validation next to each field and keeps the dialog open', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { cedula: ['Esa cédula ya está registrada en otro asistente de este evento.'] } }));
        const onSaved = vi.fn();
        render(<AttendeeEditModal eventId="ev1" attendee={attendee('a1', 'pendiente', 'Ana')} onClose={() => {}} onSaved={onSaved} />);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        expect(await screen.findByText('Esa cédula ya está registrada en otro asistente de este evento.')).toBeInTheDocument();
        expect(screen.getByLabelText(/^Cédula/)).toHaveAttribute('aria-invalid', 'true');
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('says when there is no connection', async () => {
        globalThis.fetch.mockRejectedValueOnce(new TypeError('offline'));
        render(<AttendeeEditModal eventId="ev1" attendee={attendee('a1', 'pendiente', 'Ana')} onClose={() => {}} onSaved={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('No hay conexión');
    });
});

describe('ImportLoads', () => {
    const CARGA = { id: 'imp1', kind: 'excel', created_by: 'Sandra', confirmed_at: '2026-11-08T10:00:00-05:00', asistentes: 7 };
    beforeEach(() => { globalThis.fetch = vi.fn(); });
    afterEach(cleanup);

    it('asks the server what would be deleted, confirms with the counts and then undoes the load', async () => {
        globalThis.fetch
            .mockReturnValueOnce(jsonResponse(200, { success: true, data: { asistentes: 7, fichas_borrar: 2, fichas_conservar: 4, vinculadas: 1, con_autorizacion: 0 } }))
            .mockReturnValueOnce(jsonResponse(200, { success: true, message: 'Se eliminaron 7 asistentes.' }));
        const onDone = vi.fn();
        render(<ImportLoads eventId="ev1" cargas={[CARGA]} onDone={onDone} />);
        fireEvent.click(screen.getByRole('button', { name: 'Eliminar todos los asistentes de esta carga' }));

        const dialog = await screen.findByRole('alertdialog');
        expect(dialog).toHaveTextContent('¿Deshacer la carga de 7 personas?');
        expect(dialog).toHaveTextContent('Se conservarán 4 fichas que ya tienen otra información');
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/ev1/cargas/imp1/deshacer');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Sí, eliminar la carga' }));
        await waitFor(() => expect(onDone).toHaveBeenCalledWith('Se eliminaron 7 asistentes.'));
        expect(globalThis.fetch.mock.calls[1]).toEqual(['/eventos/ev1/cargas/imp1', expect.objectContaining({ method: 'DELETE' })]);
    });

    it('cancelling deletes nothing and a server refusal is shown', async () => {
        globalThis.fetch
            .mockReturnValueOnce(jsonResponse(200, { success: true, data: { asistentes: 7, fichas_borrar: 0, fichas_conservar: 0, vinculadas: 0, con_autorizacion: 0 } }))
            .mockReturnValueOnce(jsonResponse(403, { message: 'Este registro pertenece a otra campaña: solo se puede consultar.' }));
        render(<ImportLoads eventId="ev1" cargas={[CARGA]} onDone={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: 'Eliminar todos los asistentes de esta carga' }));
        fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'No, mantener' }));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Eliminar todos los asistentes de esta carga' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('otra campaña');
    });
});

describe('Eventos/Show attendee actions', () => {
    const ROWS = [
        attendee('a1', 'pendiente', 'Ana'),
        attendee('a2', 'vinculado', 'Beto', LINKED),
        attendee('a3', 'descartado', 'Carla'),
    ];

    function renderShow(overrides = {}) {
        return render(
            <Show
                evento={EVENTO}
                asistentes={{ data: ROWS, current_page: 1, last_page: 1, per_page: 50, total: 3, links: [] }}
                kpis={{ total: 3 }}
                filters={{ link_status: null, source: null, q: null }}
                tipos={[]}
                provincias={[]}
                cargas={[]}
                can={{ manage: true, register: true, edit: true }}
                {...overrides}
            />,
        );
    }

    beforeEach(() => {
        mockRouterPost.mockReset();
        mockRouterPost.mockImplementation((url, data, options) => { options?.onSuccess?.(); options?.onFinish?.(); });
        mockPage.props = { auth: { user: ADMIN }, visibleCampaigns: [], errors: {}, flash: {} };
    });
    afterEach(cleanup);

    function openActions(name) {
        fireEvent.click(screen.getByRole('button', { name: `Acciones de ${name} Prueba` }));
        return screen.getByRole('group', { name: `Acciones de ${name} Prueba` });
    }

    it('a linked attendee has Ver ficha and Desvincular but no Editar; the ficha opens the panel', async () => {
        renderShow();
        const group = openActions('Beto');
        expect(within(group).queryByRole('button', { name: 'Editar datos' })).toBeNull();
        fireEvent.click(within(group).getByRole('button', { name: 'Ver ficha' }));
        expect(await screen.findByRole('dialog', { name: 'Ficha' })).toHaveTextContent('Ficha l1');
    });

    it('without view.mapa there is no Ver ficha', () => {
        mockPage.props.auth.user = { ...ADMIN, permissions: ['manage.eventos', 'view.votantes'] };
        renderShow();
        const group = openActions('Beto');
        expect(within(group).queryByRole('button', { name: 'Ver ficha' })).toBeNull();
        expect(within(group).getByRole('button', { name: 'Desvincular' })).toBeInTheDocument();
    });

    it('deleting a linked attendee warns that the ficha stays and posts its id', () => {
        renderShow();
        fireEvent.click(within(openActions('Beto')).getByRole('button', { name: 'Eliminar' }));
        const dialog = screen.getByRole('alertdialog');
        expect(dialog).toHaveTextContent('Su ficha en Votantes NO se borra');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Sí, eliminar' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/eventos/ev1/asistentes/eliminar', { ids: ['a2'] }, expect.any(Object));
    });

    it('recovers a discarded attendee from its row and from the selection', () => {
        renderShow();
        fireEvent.click(within(openActions('Carla')).getByRole('button', { name: 'Recuperar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, recuperar' }));
        expect(mockRouterPost).toHaveBeenLastCalledWith('/eventos/ev1/asistentes/recuperar', { ids: ['a3'] }, expect.any(Object));

        fireEvent.click(screen.getByRole('checkbox', { name: /Carla/ }));
        expect(screen.queryByRole('button', { name: 'Pasar a Votantes' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Recuperar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, recuperar' }));
        expect(mockRouterPost).toHaveBeenLastCalledWith('/eventos/ev1/asistentes/recuperar', { ids: ['a3'] }, expect.any(Object));
    });

    it('opens the edit dialog for a pending attendee and lists the loads to undo', () => {
        renderShow({ cargas: [{ id: 'imp1', kind: 'excel', created_by: 'Sandra', confirmed_at: '2026-11-08T10:00:00-05:00', asistentes: 3 }] });
        fireEvent.click(within(openActions('Ana')).getByRole('button', { name: 'Editar datos' }));
        expect(screen.getByRole('dialog', { name: 'Editar datos del asistente' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Eliminar todos los asistentes de esta carga' })).toBeInTheDocument();
    });

    it('another campaign\'s event shows no write actions nor loads', () => {
        renderShow({ can: { manage: true, register: true, edit: false }, cargas: [] });
        const group = openActions('Beto');
        expect(within(group).getAllByRole('button').map(b => b.textContent)).toEqual(['Ver ficha']);
        expect(screen.queryByRole('button', { name: 'Acciones de Ana Prueba' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Eliminar todos los asistentes de esta carga' })).toBeNull();
    });
});
