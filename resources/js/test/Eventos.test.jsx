// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, within, act } from '@testing-library/react';

const mockRouterPost = vi.fn();
const mockPage = { props: {}, url: '/eventos' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('qrcode', () => ({ default: { toDataURL: () => Promise.resolve('data:image/png;base64,AAAA') } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    function useForm(initial) {
        const [data, setDataState] = useState(initial);
        const setData = (key, value) => setDataState(prev => (
            typeof key === 'function' ? key(prev) : typeof key === 'object' ? key : { ...prev, [key]: value }
        ));
        return { data, setData, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn(), transform: vi.fn() };
    }

    return {
        Head: () => null,
        Link: ({ children, href, prefetch: _p, preserveScroll: _s, preserveState: _st, only: _o, ...rest }) => <a href={href} {...rest}>{children}</a>,
        usePage: () => mockPage,
        useForm,
        router: { post: (...args) => mockRouterPost(...args), on: () => () => {}, get: vi.fn(), reload: vi.fn() },
    };
});

import {
    APPROVE_MESSAGE, allSelected, composeBirthDate, eventFormErrors, figureSummary, newClientUuid, pruneSelection, toggleAll, toggleSelected,
} from '@/lib/eventos';
import EventoFormModal from '@/Components/Eventos/EventoFormModal';
import Show from '@/Pages/Eventos/Show';
import Operador from '@/Pages/Eventos/Operador';
import Publico from '@/Pages/Eventos/Publico';
import { router } from '@inertiajs/react';
import { attendeeFormFromPayload } from '@/lib/eventos';
import { db, rememberEventUuid, setOfflineOwner } from '@/lib/offlineDb';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const CD = { id: 'cd', name: 'Centro Democrático' };
const ADMIN = { id: 'u1', name: 'Admin', permissions: ['*'], canWrite: true, campaign: CD };

function attendee(id, link_status, nombres) {
    return { id, nombres, apellidos: 'Prueba', cedula: '12345', celular: '3001234567', barrio: 'Centro', source: 'qr', link_status, registered_by: null, created_at: '2026-11-05T18:10:00-05:00' };
}

const EVENTO = {
    id: 'ev1', nombre: 'Del Senado al barrio — Girón', status: 'abierto', tipo: { id: 't1', nombre: 'Del Senado al barrio' },
    starts_at: '2026-11-05T18:00:00-05:00', ends_at: null, sitio_encuentro: 'Parque principal',
    provincia: { id: 'p1', nombre: 'Área Metropolitana' }, municipio: { id: 'm1', nombre: 'Girón' },
    barrios: ['El Poblado', 'Centro'], campaign: { id: 'cd', nombre: 'Centro Democrático' }, editable: true,
    descripcion: null, public_url: 'http://localhost/e/token', public_open: true, organizadores: [], figuras: [],
    created_by: { id: 'u1', name: 'Admin' }, created_at: '2026-10-31T10:00:00-05:00',
};

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, headers: new Headers({ 'Content-Type': 'application/json' }), json: () => Promise.resolve(body) });
}

function fillAttendee({ nombres = 'Ana', apellidos = 'Pérez', cedula = '1098765432', celular = '3001234567', barrio = 'El Poblado' } = {}) {
    fireEvent.change(screen.getByLabelText(/^Nombres/), { target: { value: nombres } });
    fireEvent.change(screen.getByLabelText(/^Apellidos/), { target: { value: apellidos } });
    fireEvent.change(screen.getByLabelText(/^Cédula/), { target: { value: cedula } });
    fireEvent.change(screen.getByLabelText(/^Celular/), { target: { value: celular } });
    fireEvent.click(screen.getByRole('button', { name: barrio }));
}

function sentBody(call = 0) {
    return JSON.parse(globalThis.fetch.mock.calls[call][1].body);
}

describe('eventos lib', () => {
    it('only selects pending attendees and toggles all of them', () => {
        const rows = [attendee('a', 'pendiente', 'A'), attendee('b', 'vinculado', 'B'), attendee('c', 'pendiente', 'C')];
        let sel = toggleAll(new Set(), rows);
        expect([...sel]).toEqual(['a', 'c']);
        expect(allSelected(sel, rows)).toBe(true);
        sel = toggleSelected(sel, 'a');
        expect([...sel]).toEqual(['c']);
        expect(toggleAll(toggleAll(new Set(), rows), rows).size).toBe(0);
    });

    it('prunes ids that are no longer pending and keeps the same set when nothing changes', () => {
        const sel = new Set(['a', 'c']);
        expect(pruneSelection(sel, [attendee('a', 'pendiente', 'A'), attendee('c', 'pendiente', 'C')])).toBe(sel);
        expect([...pruneSelection(sel, [attendee('a', 'aprobado', 'A'), attendee('c', 'pendiente', 'C')])]).toEqual(['c']);
    });

    it('composes the birth date from three selects', () => {
        expect(composeBirthDate({ day: '', month: '', year: '' })).toBe('');
        expect(composeBirthDate({ day: '2', month: '4', year: '1980' })).toBe('1980-04-02');
        expect(composeBirthDate({ day: '2', month: '', year: '1980' })).toBeNull();
        expect(composeBirthDate({ day: '31', month: '2', year: '1980' })).toBeNull();
    });

    it('words the approval without any data authorization warning', () => {
        expect(APPROVE_MESSAGE).toMatch('Se crea su ficha en Votantes');
        expect(APPROVE_MESSAGE).not.toMatch(/autorización/i);
    });

    it('generates v4 uuids', () => {
        expect(newClientUuid()).toMatch(UUID_RE);
        expect(newClientUuid()).not.toBe(newClientUuid());
    });
});

describe('EventoFormModal', () => {
    beforeEach(() => {
        mockPage.props = { auth: { user: ADMIN }, visibleCampaigns: [], errors: {}, flash: {} };
    });

    afterEach(cleanup);

    it('lists the missing required fields and accepts a complete event', () => {
        expect(Object.keys(eventFormErrors({ nombre: ' ', event_type_id: '', starts_at: '', sitio_encuentro: '', municipio_id: '' })))
            .toEqual(['nombre', 'event_type_id', 'starts_at', 'sitio_encuentro', 'municipio_id']);
        expect(eventFormErrors({ nombre: 'X', event_type_id: 't', starts_at: '2026-11-05T18:00', ends_at: '2026-11-05T17:00', sitio_encuentro: 'P', municipio_id: 'm' }))
            .toEqual({ ends_at: 'La hora de fin no puede ser antes del inicio.' });
        expect(eventFormErrors({ nombre: 'X', event_type_id: 't', starts_at: '2026-11-05T18:00', ends_at: '', sitio_encuentro: 'P', municipio_id: 'm' })).toEqual({});
    });

    it('summarizes the last candidacy of a figure', () => {
        expect(figureSummary({ ultimo_cargo: 'Senado', ultimo_anio: 2026, ultimo_lugar: null })).toBe('Senado 2026');
        expect(figureSummary({ ultimo_cargo: 'Alcaldía', ultimo_anio: 2023, ultimo_lugar: 'Girón' })).toBe('Alcaldía 2023 · Girón');
        expect(figureSummary({ ultimo_cargo: null, ultimo_anio: null, ultimo_lugar: null })).toBe('');
    });

    it('tells namesakes apart in the figure search and prefills the cargo, which stays editable', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(200, { success: true, data: [
            { id: 'p1', nombre: 'JUAN PÉREZ', ultimo_cargo: 'Alcaldía', ultimo_anio: 2023, ultimo_lugar: 'Girón' },
            { id: 'p2', nombre: 'JUAN PÉREZ', ultimo_cargo: null, ultimo_anio: null, ultimo_lugar: null },
        ] }));
        render(<EventoFormModal tipos={[]} provincias={[]} onClose={() => {}} />);
        fireEvent.change(screen.getByLabelText('Buscar en la base electoral'), { target: { value: 'juan' } });
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();

        expect(await screen.findByText('Alcaldía 2023 · Girón')).toBeInTheDocument();
        expect(screen.getByText('Sin candidaturas registradas')).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/buscar/figuras?q=juan');

        fireEvent.click(screen.getByText('Alcaldía 2023 · Girón'));
        const cargo = screen.getByLabelText('Cargo de JUAN PÉREZ');
        expect(cargo).toHaveValue('Alcaldía');
        fireEvent.change(cargo, { target: { value: 'Exalcalde de Girón' } });
        expect(cargo).toHaveValue('Exalcalde de Girón');
    });

    it('leaves the cargo empty for a figure that was never a candidate', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(200, { success: true, data: [
            { id: 'p2', nombre: 'ANA GÓMEZ', ultimo_cargo: null, ultimo_anio: null, ultimo_lugar: null },
        ] }));
        render(<EventoFormModal tipos={[]} provincias={[]} onClose={() => {}} />);
        fireEvent.change(screen.getByLabelText('Buscar en la base electoral'), { target: { value: 'ana g' } });
        fireEvent.click(await screen.findByText('ANA GÓMEZ', {}, { timeout: 2000 }));
        expect(screen.getByLabelText('Cargo de ANA GÓMEZ')).toHaveValue('');
    });

    it('shows the required-field messages instead of sending an empty event', () => {
        const provincias = [{ id: 'p1', name: 'Soto', municipios: [{ id: 'm1', name: 'Girón' }] }];
        render(<EventoFormModal tipos={[{ id: 't1', nombre: 'Gira' }]} provincias={provincias} onClose={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: 'Crear evento' }));
        expect(screen.getByText('Escriba el nombre del evento.')).toBeInTheDocument();
        expect(screen.getByText('Elija la provincia y el municipio.')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Provincia *'), { target: { value: 'p1' } });
        expect(screen.getByLabelText('Municipio *')).toBeEnabled();
        expect(within(screen.getByLabelText('Municipio *')).getByRole('option', { name: 'Girón' })).toBeInTheDocument();
    });

    it('only offers usable types and selects a type created from the form', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(201, { success: true, created: true, data: { id: 't9', nombre: 'Caravana', usable: true } }));
        render(<EventoFormModal tipos={[{ id: 't1', nombre: 'Gira', usable: true }, { id: 't2', nombre: 'De la subcampaña', usable: false }]} provincias={[]} onClose={() => {}} />);
        const select = screen.getByLabelText('Tipo de evento *');
        expect(within(select).queryByRole('option', { name: 'De la subcampaña' })).not.toBeInTheDocument();

        fireEvent.change(select, { target: { value: '__nuevo__' } });
        fireEvent.change(screen.getByLabelText('Nombre del nuevo tipo *'), { target: { value: '  Caravana ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar nuevo tipo' }));

        await waitFor(() => expect(select).toHaveValue('t9'));
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/tipos');
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ nombre: 'Caravana' });
        expect(screen.queryByLabelText('Nombre del nuevo tipo *')).not.toBeInTheDocument();
    });

    it('shows the server reason when a type cannot be created and keeps the previous choice', async () => {
        globalThis.fetch = vi.fn()
            .mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { nombre: ['El nombre del tipo de evento no puede tener más de 80 caracteres.'] } }))
            .mockReturnValueOnce(jsonResponse(403, { message: 'Forbidden' }));
        render(<EventoFormModal tipos={[{ id: 't1', nombre: 'Gira' }]} provincias={[]} onClose={() => {}} />);
        const select = screen.getByLabelText('Tipo de evento *');
        fireEvent.change(select, { target: { value: 't1' } });
        fireEvent.change(select, { target: { value: '__nuevo__' } });
        fireEvent.change(screen.getByLabelText('Nombre del nuevo tipo *'), { target: { value: 'Algo' } });

        fireEvent.click(screen.getByRole('button', { name: 'Guardar nuevo tipo' }));
        expect(await screen.findByText('El nombre del tipo de evento no puede tener más de 80 caracteres.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar nuevo tipo' }));
        expect(await screen.findByText('No tiene permiso para crear tipos de evento.')).toBeInTheDocument();
        expect(select).toHaveValue('t1');

        fireEvent.click(screen.getByRole('button', { name: 'Cancelar nuevo tipo' }));
        expect(screen.queryByLabelText('Nombre del nuevo tipo *')).not.toBeInTheDocument();
    });
});

describe('TipoAdminModal (from the event form)', () => {
    const TIPOS = [
        { id: 't1', nombre: 'Gira', usable: true, editable: false },
        { id: 't2', nombre: 'Cabalgata', usable: true, editable: true },
        { id: 't3', nombre: 'Caravana', usable: true, editable: true },
    ];

    beforeEach(() => {
        mockPage.props = { auth: { user: ADMIN }, visibleCampaigns: [], errors: {}, flash: {} };
    });

    afterEach(cleanup);

    function openAdmin(props = {}) {
        render(<EventoFormModal tipos={TIPOS} provincias={[]} onClose={() => {}} {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Administrar tipos' }));

        return screen.getByRole('dialog', { name: 'TIPOS DE EVENTO' });
    }

    it('is offered only when some type is editable and global types have no actions', () => {
        render(<EventoFormModal tipos={[TIPOS[0]]} provincias={[]} onClose={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Administrar tipos' })).not.toBeInTheDocument();
        cleanup();

        const dialog = openAdmin();
        expect(within(dialog).getByText('General: no se puede modificar')).toBeInTheDocument();
        expect(within(dialog).queryByRole('button', { name: 'Editar Gira' })).not.toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Editar Cabalgata' })).toBeInTheDocument();
    });

    it('renames a type and the select shows the new name', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(200, { success: true, data: { id: 't2', nombre: 'Cabalgata rural', usable: true, editable: true } }));
        const dialog = openAdmin();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Editar Cabalgata' }));
        fireEvent.change(within(dialog).getByLabelText('Nuevo nombre para «Cabalgata»'), { target: { value: 'Cabalgata rural' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }));

        expect(await within(dialog).findByText('Tipo de evento actualizado.')).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/tipos/t2');
        expect(globalThis.fetch.mock.calls[0][1].method).toBe('PUT');
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ nombre: 'Cabalgata rural' });
        expect(within(screen.getByLabelText('Tipo de evento *')).getByRole('option', { name: 'Cabalgata rural' })).toBeInTheDocument();
    });

    it('shows the duplicate-name error and keeps editing', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { nombre: ['Ya existe un tipo con ese nombre.'] } }));
        const dialog = openAdmin();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Editar Cabalgata' }));
        fireEvent.change(within(dialog).getByLabelText('Nuevo nombre para «Cabalgata»'), { target: { value: 'Gira' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }));

        expect(await within(dialog).findByText('Ya existe un tipo con ese nombre.')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('Nuevo nombre para «Cabalgata»')).toHaveValue('Gira');
    });

    it('asks before deleting, removes the type and clears it from the select', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(200, { success: true, deleted: false, deactivated: true, message: 'Se desactivó: ya hay eventos con este tipo.' }));
        const dialog = openAdmin();
        const select = screen.getByLabelText('Tipo de evento *');
        fireEvent.change(select, { target: { value: 't3' } });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Eliminar Caravana' }));
        expect(globalThis.fetch).not.toHaveBeenCalled();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Sí, eliminar' }));

        expect(await within(dialog).findByText('Se desactivó: ya hay eventos con este tipo.')).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/tipos/t3');
        expect(globalThis.fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(within(dialog).queryByText('Caravana')).not.toBeInTheDocument();
        expect(within(select).queryByRole('option', { name: 'Caravana' })).not.toBeInTheDocument();
        expect(select).toHaveValue('');
    });

    it('keeps the type when the deletion is cancelled or refused', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(jsonResponse(403, { message: 'Forbidden' }));
        const dialog = openAdmin();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Eliminar Cabalgata' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'No, conservar' }));
        expect(within(dialog).getByText('Cabalgata')).toBeInTheDocument();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Eliminar Cabalgata' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Sí, eliminar' }));
        expect(await within(dialog).findByText('No tiene permiso para modificar este tipo de evento.')).toBeInTheDocument();
        expect(within(screen.getByLabelText('Tipo de evento *')).getByRole('option', { name: 'Cabalgata' })).toBeInTheDocument();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Listo' }));
        expect(screen.queryByRole('dialog', { name: 'TIPOS DE EVENTO' })).not.toBeInTheDocument();
    });

    it('shows the deactivated type of an edited event as a disabled option', () => {
        render(<EventoFormModal evento={{ ...EVENTO, tipo: { id: 't9', nombre: 'Antiguo' } }} tipos={TIPOS} provincias={[]} onClose={() => {}} />);
        const select = screen.getByLabelText('Tipo de evento *');
        expect(select).toHaveValue('t9');
        expect(within(select).getByRole('option', { name: 'Antiguo (desactivado)' })).toBeDisabled();
    });
});

describe('Eventos/Show', () => {
    const KPIS = { total: 3, vinculados: 1, pendientes: 2, aprobados: 0, descartados: 0, qr: 3, operador: 0 };
    const ROWS = [attendee('a1', 'pendiente', 'Ana'), attendee('a2', 'vinculado', 'Beto'), attendee('a3', 'pendiente', 'Carla')];

    function renderShow(overrides = {}) {
        return render(
            <Show
                evento={EVENTO}
                asistentes={{ data: ROWS, current_page: 1, last_page: 1, per_page: 50, total: 3, links: [] }}
                kpis={KPIS}
                filters={{ link_status: null, source: null, q: null }}
                tipos={[]}
                provincias={[]}
                can={{ manage: true, register: true, edit: true }}
                {...overrides}
            />,
        );
    }

    beforeEach(() => {
        mockRouterPost.mockReset();
        mockPage.url = '/eventos/ev1';
        mockPage.props = { auth: { user: ADMIN }, visibleCampaigns: [], errors: {}, flash: {} };
    });

    afterEach(cleanup);

    it('labels every attendee and offers a checkbox only for the pending ones', () => {
        renderShow();
        const list = screen.getByRole('list', { name: 'Lista de asistentes' });
        expect(within(list).getAllByText('Pendiente de aprobar')).toHaveLength(2);
        expect(within(list).getByText('Ya estaba en la plataforma')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: /Ana/ })).toBeInTheDocument();
        expect(screen.queryByRole('checkbox', { name: /Beto/ })).toBeNull();
    });

    it('approves the selected attendees after confirming', () => {
        renderShow();
        fireEvent.click(screen.getByRole('checkbox', { name: /Ana/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: /Carla/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Pasar a Votantes' }));
        expect(screen.getByText('¿Pasar 2 asistentes a Votantes?')).toBeInTheDocument();
        expect(mockRouterPost).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, pasar a Votantes' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/eventos/ev1/asistentes/aprobar', { ids: ['a1', 'a3'] }, expect.any(Object));
    });

    it('never mentions data authorization: no badge, no filter and no warning when approving', () => {
        const rows = [attendee('a1', 'pendiente', 'Ana'), attendee('a2', 'pendiente', 'Beto')];
        renderShow({ asistentes: { data: rows, current_page: 1, last_page: 1, per_page: 50, total: 2, links: [] } });
        expect(screen.queryByText(/autorizaci/i)).toBeNull();
        expect(screen.queryByRole('button', { name: /Sin autorización/ })).toBeNull();

        fireEvent.click(screen.getByRole('checkbox', { name: /Seleccionar todos los pendientes/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Pasar a Votantes' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent('Se crea su ficha en Votantes');
        expect(screen.getByRole('alertdialog')).not.toHaveTextContent(/autorizaci/i);
    });

    it('selects every pending attendee at once and discards them; cancelling sends nothing', () => {
        renderShow();
        fireEvent.click(screen.getByRole('checkbox', { name: /Seleccionar todos los pendientes/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
        expect(mockRouterPost).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, descartar' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/eventos/ev1/asistentes/descartar', { ids: ['a1', 'a3'] }, expect.any(Object));
    });

    it('explains a draft event without any approved text requirement', () => {
        renderShow({ evento: { ...EVENTO, status: 'borrador', public_open: false } });
        expect(screen.getByText(/El evento está en borrador/)).toBeInTheDocument();
        expect(screen.queryByText(/texto de autorización/)).toBeNull();
    });

    it('asks before regenerating the QR', () => {
        renderShow();
        fireEvent.click(screen.getByRole('button', { name: 'Generar QR nuevo' }));
        expect(screen.getByText(/El QR anterior dejará de funcionar/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, generar QR nuevo' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/eventos/ev1/token', {}, expect.any(Object));
    });

    it('hides write actions without manage rights', () => {
        mockPage.props.auth.user = { ...ADMIN, permissions: ['view.eventos'], canWrite: false };
        renderShow({ can: { manage: false, register: false, edit: false } });
        expect(screen.queryByRole('button', { name: 'Cerrar registro' })).toBeNull();
        expect(screen.queryByRole('checkbox', { name: /Ana/ })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Modo operador' })).toBeNull();
    });
});

describe('Eventos/Operador', () => {
    const OP_EVENTO = { ...EVENTO, accepts_registrations: true };

    function renderOperador() {
        return render(<Operador evento={OP_EVENTO} registrados={5} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);
    }

    let online = true;

    function setOnline(value) {
        online = value;
        window.dispatchEvent(new Event(value ? 'online' : 'offline'));
    }

    async function register(person) {
        fillAttendee(person);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await screen.findByText('Guardado en el teléfono');
    }

    beforeEach(async () => {
        mockPage.props = { auth: { user: { ...ADMIN, permissions: ['register.asistentes'] } }, visibleCampaigns: [], errors: {}, flash: {} };
        globalThis.fetch = vi.fn();
        online = true;
        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => online });
        await Promise.all([db.pendingChanges.clear(), db.eventos.clear(), db.eventUuids.clear()]);
        setOfflineOwner('owner-test');
        localStorage.setItem('electoral:offline-user', 'owner-test');
        router.reload.mockClear();
    });

    afterEach(() => {
        cleanup();
        delete navigator.onLine;
    });

    it('sends the client_uuid, clears the form, counts and focuses the first field', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, message: 'Asistente registrado.', data: { id: 'x1', duplicate: false } }));
        renderOperador();
        fillAttendee();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));

        await screen.findByText('¡Registrado!');
        const body = sentBody();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/ev1/asistentes');
        expect(body).toMatchObject({ client_uuid: '11111111-1111-4111-8111-111111111111', nombres: 'Ana', barrio: 'El Poblado' });
        expect(body).not.toHaveProperty('consent_text_id');
        expect(body).not.toHaveProperty('autoriza_datos');
        expect(screen.getByTestId('session-count')).toHaveTextContent('1');
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
        expect(document.activeElement).toBe(screen.getByLabelText(/^Nombres/));

        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, data: { id: 'x2', duplicate: false } }));
        fillAttendee({ nombres: 'Beto', cedula: '1098000000' });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await waitFor(() => expect(screen.getByTestId('session-count')).toHaveTextContent('2'));
        expect(sentBody(1).client_uuid).toMatch(UUID_RE);
        expect(sentBody(1).client_uuid).not.toBe(body.client_uuid);
    });

    it('shows the 422 errors next to each field and retries with the same client_uuid', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { celular: ['El celular debe tener 10 dígitos y empezar por 3.'] } }));
        renderOperador();
        fillAttendee({ celular: '12' });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));

        expect(await screen.findByText('El celular debe tener 10 dígitos y empezar por 3.')).toBeInTheDocument();
        expect(screen.getByLabelText(/^Celular/)).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('Ana');

        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, data: { id: 'x1', duplicate: false } }));
        fireEvent.change(screen.getByLabelText(/^Celular/), { target: { value: '3001234567' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await screen.findByText('¡Registrado!');
        expect(sentBody(1).client_uuid).toBe(sentBody(0).client_uuid);
    });

    it('keeps a registration whose POST fails on the network in the phone, with its client_uuid', async () => {
        globalThis.fetch.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));
        renderOperador();
        await register();

        expect(screen.getByText(/Ana Pérez: se enviará al recuperar la señal/)).toBeInTheDocument();
        expect(await screen.findByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        expect(screen.getByTestId('session-count')).toHaveTextContent('1');
        expect(screen.getByTestId('event-total')).toHaveTextContent('6');
        const [queued] = await db.pendingChanges.toArray();
        expect(queued.data.payload.client_uuid).toBe(sentBody(0).client_uuid);
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
    });

    it('without signal registers 3 people in the phone, then sends them once when the signal is back', async () => {
        online = false;
        renderOperador();
        expect(screen.getByTestId('connection-status')).toHaveTextContent('Sin señal');

        await register({ nombres: 'Ana', cedula: '1098000001' });
        await register({ nombres: 'Beto', cedula: '1098000002' });
        await register({ nombres: 'Carla', cedula: '1098000003' });

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(await screen.findByRole('heading', { name: 'Pendientes de enviar (3)' })).toBeInTheDocument();
        expect(screen.getByTestId('session-count')).toHaveTextContent('3');
        expect(screen.getByTestId('event-total')).toHaveTextContent('8');
        const uuids = (await db.pendingChanges.toArray()).map(c => c.data.payload.client_uuid);
        expect(new Set(uuids).size).toBe(3);

        const seen = new Set();
        globalThis.fetch.mockImplementation((url, init) => {
            const { client_uuid: id } = JSON.parse(init.body);
            const duplicate = seen.has(id);
            seen.add(id);
            return jsonResponse(duplicate ? 200 : 201, { success: true, data: { client_uuid: id, duplicate } });
        });
        act(() => setOnline(true));

        await waitFor(() => expect(screen.queryByRole('heading', { name: /Pendientes de enviar/ })).not.toBeInTheDocument());
        expect(globalThis.fetch).toHaveBeenCalledTimes(3);
        expect(globalThis.fetch.mock.calls.every(([url]) => url === '/eventos/ev1/asistentes')).toBe(true);
        expect(seen.size).toBe(3);
        expect(screen.getByTestId('connection-status')).toHaveTextContent('Con señal');
        expect(router.reload).toHaveBeenCalledWith({ only: ['registrados', 'mis_registros'] });

        // Going offline and online again does not send them a second time.
        act(() => setOnline(false));
        act(() => setOnline(true));
        await new Promise(r => setTimeout(r, 20));
        expect(globalThis.fetch).toHaveBeenCalledTimes(3);
    });

    it('shows a registration the server refused (422) with its reason, and "Corregir" puts it back in the form', async () => {
        online = false;
        renderOperador();
        await register({ nombres: 'Ana', celular: '3009999999' });

        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { cedula: ['La cédula debe tener entre 5 y 12 números.'] } }));
        act(() => setOnline(true));

        expect(await screen.findByRole('heading', { name: 'No se pudieron guardar (1)' })).toBeInTheDocument();
        expect(screen.getByText('La cédula debe tener entre 5 y 12 números.')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: /Pendientes de enviar/ })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Corregir' }));
        await waitFor(() => expect(screen.getByLabelText(/^Nombres/)).toHaveValue('Ana'));
        expect(screen.getByLabelText(/^Celular/)).toHaveValue('3009999999');
        // It stays in the list (and in the phone) until the corrected one is saved.
        expect(screen.getByText('En el formulario: sale de esta lista al guardarlo.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Corregir' })).toBeNull();
        expect(await db.pendingChanges.count()).toBe(1);

        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, data: { id: 'x9', duplicate: false } }));
        fireEvent.change(screen.getByLabelText(/^Cédula/), { target: { value: '1098765432' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await screen.findByText('¡Registrado!');
        expect(sentBody(1).cedula).toBe('1098765432');
        expect(sentBody(1).client_uuid).not.toBe(sentBody(0).client_uuid);
        await waitFor(() => expect(screen.queryByRole('heading', { name: /No se pudieron guardar/ })).not.toBeInTheDocument());
        expect(await db.pendingChanges.count()).toBe(0);
    });

    it('if the corrected one fails validation the refused one is kept; typed data is only replaced after confirming', async () => {
        online = false;
        renderOperador();
        await register({ nombres: 'Ana' });
        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { celular: ['El celular debe tener 10 dígitos y empezar por 3.'] } }));
        act(() => setOnline(true));
        await screen.findByRole('heading', { name: 'No se pudieron guardar (1)' });

        fireEvent.change(screen.getByLabelText(/^Nombres/), { target: { value: 'Persona en curso' } });
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        fireEvent.click(screen.getByRole('button', { name: 'Corregir' }));
        expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining('El formulario tiene datos escritos'));
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('Persona en curso');

        fireEvent.click(screen.getByRole('button', { name: 'Corregir' }));
        await waitFor(() => expect(screen.getByLabelText(/^Nombres/)).toHaveValue('Ana'));
        confirmSpy.mockRestore();

        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { celular: ['El celular debe tener 10 dígitos y empezar por 3.'] } }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(await screen.findAllByText('El celular debe tener 10 dígitos y empezar por 3.')).not.toHaveLength(0);
        expect(await db.pendingChanges.count()).toBe(1);
        expect(screen.getByRole('heading', { name: 'No se pudieron guardar (1)' })).toBeInTheDocument();
    });

    it('a corrected registration queued without signal also takes the refused one out of the list', async () => {
        online = false;
        renderOperador();
        await register({ nombres: 'Ana' });
        globalThis.fetch.mockReturnValueOnce(jsonResponse(409, { success: false, message: 'Ese registro pertenece a otro evento.' }));
        act(() => setOnline(true));
        await screen.findByRole('heading', { name: 'No se pudieron guardar (1)' });

        fireEvent.click(screen.getByRole('button', { name: 'Corregir' }));
        await waitFor(() => expect(screen.getByLabelText(/^Nombres/)).toHaveValue('Ana'));
        act(() => setOnline(false));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await screen.findByText('Guardado en el teléfono');

        await waitFor(() => expect(screen.queryByRole('heading', { name: /No se pudieron guardar/ })).not.toBeInTheDocument());
        expect(screen.getByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        const [left] = await db.pendingChanges.toArray();
        expect(left.synced).toBe(0);
    });

    it('does not offer "Corregir" when the event no longer takes registrations', async () => {
        await db.pendingChanges.add({
            type: 'event_attendee', action: 'create', owner: 'owner-test', synced: 1, rejected: true, rejectedStatus: 410,
            rejectedReason: 'El evento ya no recibe registros.', rejectedAt: new Date().toISOString(), createdAt: new Date().toISOString(),
            data: { eventId: 'ev1', payload: { nombres: 'Ana', apellidos: 'Pérez' } },
        });
        render(<Operador evento={{ ...OP_EVENTO, accepts_registrations: false }} registrados={0} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);

        expect(await screen.findByRole('heading', { name: 'No se pudieron guardar (1)' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Corregir' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Descartar' })).toBeInTheDocument();
        expect(screen.getByText(/solo se pueden descartar/)).toBeInTheDocument();
    });

    it('a redirect that ends in an HTML page is not taken as saved: it stays in the phone', async () => {
        globalThis.fetch.mockReturnValueOnce(Promise.resolve({ ok: true, status: 200, redirected: true, headers: new Headers({ 'Content-Type': 'text/html' }), json: () => Promise.reject(new Error('html')) }));
        renderOperador();
        await register();

        expect(screen.queryByText('¡Registrado!')).toBeNull();
        expect(screen.getByText('Debe cambiar su contraseña antes de seguir; lo guardado en el teléfono no se pierde.')).toBeInTheDocument();
        expect(screen.getByText(/se enviará cuando cambie su contraseña\./)).toBeInTheDocument();
        expect(await screen.findByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        expect(screen.getByText('El servidor pidió una acción antes de recibirlos (por ejemplo, cambiar la contraseña).')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Cambiar contraseña' })).toHaveAttribute('href', '/forzar-cambio-password');
        expect(screen.getByTestId('session-count')).toHaveTextContent('1');
    });

    it.each([
        [401, 'Su sesión se cerró. Inicie sesión de nuevo; lo guardado en el teléfono no se pierde.'],
        [419, 'Su sesión se cerró. Inicie sesión de nuevo; lo guardado en el teléfono no se pierde.'],
        [503, 'El servidor no respondió; quedó guardado en el teléfono y se enviará después.'],
    ])('with signal, a %i keeps the registration in the phone instead of losing what was typed', async (status, message) => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(status, { message: 'x' }));
        renderOperador();
        await register();

        expect(screen.getByText(message)).toBeInTheDocument();
        expect(await screen.findByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        // The banner says when it will go out, by the cause (OBS-04): never "al recuperar la señal" with signal.
        expect(screen.queryByText(/al recuperar la señal/)).toBeNull();
        if (status === 503) {
            expect(screen.getByText(/se enviará cuando el servidor responda\./)).toBeInTheDocument();
        } else {
            expect(screen.getByText(/se enviará cuando inicie sesión de nuevo\./)).toBeInTheDocument();
            expect(screen.getByText(/Se enviarán cuando inicie sesión de nuevo/)).toBeInTheDocument();
            expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toHaveAttribute('href', '/login');
        }
        const [queued] = await db.pendingChanges.toArray();
        expect(queued.data.payload).toMatchObject({ nombres: 'Ana', client_uuid: sentBody(0).client_uuid });
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
    });

    it('discards a refused registration only after confirming', async () => {
        online = false;
        renderOperador();
        await register();
        globalThis.fetch.mockReturnValueOnce(jsonResponse(410, { success: false, message: 'El evento ya no recibe registros.' }));
        act(() => setOnline(true));
        expect(await screen.findByText('El evento ya no recibe registros.')).toBeInTheDocument();

        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
        await new Promise(r => setTimeout(r, 20));
        expect(await db.pendingChanges.count()).toBe(1);
        fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
        await waitFor(() => expect(screen.queryByRole('heading', { name: /No se pudieron guardar/ })).not.toBeInTheDocument());
        expect(await db.pendingChanges.count()).toBe(0);
        confirmSpy.mockRestore();
    });

    it('keeps the queue when the session expired (401) and says to log in again', async () => {
        online = false;
        renderOperador();
        await register();
        globalThis.fetch.mockReturnValueOnce(jsonResponse(401, { message: 'Unauthenticated.' }));
        act(() => setOnline(true));

        expect(await screen.findByText(/Su sesión se cerró/)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: /No se pudieron guardar/ })).not.toBeInTheDocument();
    });

    it('never reuses the client_uuid of a page restored from the cache once it was used', async () => {
        await rememberEventUuid('ev1', '11111111-1111-4111-8111-111111111111');
        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, data: { id: 'x1', duplicate: false } }));
        renderOperador();
        fillAttendee();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await screen.findByText('¡Registrado!');
        expect(sentBody().client_uuid).toMatch(UUID_RE);
        expect(sentBody().client_uuid).not.toBe('11111111-1111-4111-8111-111111111111');
    });

    it('opened without signal, uses the event saved in the phone (even a snapshot saved with a consent text)', async () => {
        await db.eventos.put({
            id: 'ev1', owner: 'owner-test', savedAt: '2026-11-05T17:00:00.000Z',
            evento: { ...OP_EVENTO, barrios: ['Barrio Guardado'] },
            consent: { id: 'c2', version: 'v2', cuerpo: 'Texto guardado', approved: true },
        });
        online = false;
        renderOperador();
        expect(await screen.findByRole('button', { name: 'Barrio Guardado' })).toBeInTheDocument();
        await register({ barrio: 'Barrio Guardado' });
        const [queued] = await db.pendingChanges.toArray();
        expect(queued.data.payload.barrio).toBe('Barrio Guardado');
        expect(queued.data.payload).not.toHaveProperty('consent_text_id');
    });

    it('does not count in "Registrados por usted" the ones the server refused (OBS-03)', async () => {
        online = false;
        renderOperador();
        await register({ nombres: 'Ana', cedula: '1098000001' });
        await register({ nombres: 'Beto', cedula: '1098000002' });
        expect(screen.getByTestId('session-count')).toHaveTextContent('2');

        globalThis.fetch
            .mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { cedula: ['Cédula inválida.'] } }))
            .mockReturnValueOnce(jsonResponse(201, { success: true, data: { duplicate: false } }));
        act(() => setOnline(true));

        await screen.findByRole('heading', { name: 'No se pudieron guardar (1)' });
        await waitFor(() => expect(screen.getByTestId('session-count')).toHaveTextContent('1'));
    });

    it.each([
        ['owner_mismatch del servidor', () => jsonResponse(409, { success: false, code: 'owner_mismatch' }), 'Este navegador tiene abierta la sesión de otra cuenta', 'Iniciar sesión', '/login'],
        ['cambio de contraseña forzado (redirect)', () => Promise.resolve({ ok: false, status: 0, type: 'opaqueredirect', headers: new Headers() }), 'El servidor pidió una acción antes de recibirlos \\(por ejemplo, cambiar la contraseña\\)', 'Cambiar contraseña', '/forzar-cambio-password'],
        ['sesión vencida (419)', () => jsonResponse(419, { message: 'CSRF token mismatch.' }), 'Su sesión se cerró. Se enviarán cuando inicie sesión', 'Iniciar sesión', '/login'],
    ])('when the sync stops by %s the pending list says what to do (OBS-05)', async (_name, response, text, linkName, href) => {
        online = false;
        renderOperador();
        await register();
        globalThis.fetch.mockImplementationOnce(response);
        act(() => setOnline(true));

        expect(await screen.findByText(new RegExp(text))).toBeInTheDocument();
        expect(screen.getByRole('link', { name: linkName })).toHaveAttribute('href', href);
        expect(screen.getByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: /No se pudieron guardar/ })).toBeNull();
    });

    it.each([
        ['otra cuenta abierta en el navegador', 'owner-b', 'Este navegador tiene abierta la sesión de otra cuenta. Inicie sesión con su cuenta para enviarlos'],
        ['ninguna cuenta abierta', null, 'Este navegador ya no tiene abierta su sesión. Inicie sesión con su cuenta para enviarlos.'],
    ])('tells apart %s when the shared session is not this operator', async (_name, shared, text) => {
        online = false;
        renderOperador();
        await register();
        if (shared) localStorage.setItem('electoral:offline-user', shared);
        else localStorage.removeItem('electoral:offline-user');
        act(() => setOnline(true));

        expect(await screen.findByText(text, { exact: false })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toHaveAttribute('href', '/login');
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(screen.getByRole('heading', { name: 'Pendientes de enviar (1)' })).toBeInTheDocument();
    });

    it('offers the printable sheet in the header to an operator, but not for a cancelled event (BUG-01)', () => {
        const { unmount } = renderOperador();
        expect(screen.getByRole('link', { name: 'Planilla para imprimir' })).toHaveAttribute('href', '/eventos/ev1/planilla.pdf');
        unmount();

        render(<Operador evento={{ ...OP_EVENTO, status: 'cancelado', accepts_registrations: false }} registrados={0} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);
        expect(screen.queryByRole('link', { name: 'Planilla para imprimir' })).toBeNull();
    });

    it('turns a queued payload back into form values, leaving out the consent fields of an old queue', () => {
        const values = attendeeFormFromPayload({ nombres: 'Ana', apellidos: 'P', cedula: '123456', celular: '3001234567', barrio: 'Centro', fijo: null, direccion: null, fecha_nacimiento: '1980-04-02', autoriza_datos: true, consent_text_id: 'c1' });
        expect(values).toMatchObject({ fijo: '', direccion: '', birth: { day: '2', month: '4', year: '1980' } });
        expect(values).not.toHaveProperty('autoriza_datos');
    });

    it('saves without any consent box', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, message: 'Asistente registrado.', data: { id: 'x1', duplicate: false } }));
        renderOperador();
        expect(screen.queryByRole('checkbox')).toBeNull();
        expect(screen.queryByText(/autorizaci/i)).toBeNull();
        fillAttendee();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(await screen.findByText('¡Registrado!')).toBeInTheDocument();
    });
});

describe('Eventos/Publico', () => {
    const PROPS = {
        estado: 'abierto',
        evento: { nombre: 'Del Senado al barrio — Girón', descripcion: null, tipo: 'Del Senado al barrio', starts_at: '2026-11-05T18:00:00-05:00', ends_at: null, sitio_encuentro: 'Parque principal', municipio: 'Girón', provincia: 'Soto' },
        barrios: ['El Poblado', 'Centro'],
        form_token: 'tok',
        client_uuid: '22222222-2222-4222-8222-222222222222',
        submitted: false,
        message: null,
    };

    beforeEach(() => {
        globalThis.fetch = vi.fn();
        window.history.replaceState({}, '', '/e/abcdefghijklmnopqrstuvwxyz');
    });

    afterEach(cleanup);

    it('has no consent box: "Registrarme" is enabled from the start', () => {
        render(<Publico {...PROPS} />);
        expect(screen.getByRole('button', { name: 'Registrarme' })).toBeEnabled();
        expect(screen.queryByRole('checkbox')).toBeNull();
        expect(screen.queryByText(/autorizaci/i)).toBeNull();
    });

    it('sends the birth date from the three selects, the honeypot and form_token, then always thanks', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(200, { success: true, message: '¡Gracias, quedó registrado!' }));
        render(<Publico {...PROPS} />);
        fillAttendee({ barrio: 'Centro' });
        fireEvent.change(screen.getByLabelText('Día'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText('Mes'), { target: { value: '4' } });
        fireEvent.change(screen.getByLabelText('Año'), { target: { value: '1980' } });
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));

        expect(await screen.findByText('¡Gracias, quedó registrado!')).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/e/abcdefghijklmnopqrstuvwxyz');
        expect(sentBody()).toMatchObject({
            fecha_nacimiento: '1980-04-02', form_token: 'tok', website: '', client_uuid: PROPS.client_uuid, barrio: 'Centro',
        });
        expect(sentBody()).not.toHaveProperty('consent_text_id');

        fireEvent.click(screen.getByRole('button', { name: 'Registrar a otra persona' }));
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
    });

    it('asks for the full date when only part of it is chosen', async () => {
        render(<Publico {...PROPS} />);
        fillAttendee();
        fireEvent.change(screen.getByLabelText('Año'), { target: { value: '1980' } });
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));
        expect(await screen.findByText('Elija día, mes y año, o deje los tres vacíos.')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('turns into the closed message when the server answers 410', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(410, { success: false, message: 'Este evento ya no recibe registros.' }));
        render(<Publico {...PROPS} />);
        fillAttendee();
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));
        expect(await screen.findByText('Este evento ya no recibe registros')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Registrarme' })).toBeNull();
    });

    it('shows the thanks page when the server already marked it submitted, and the closed page for estado cerrado', () => {
        const { unmount } = render(<Publico {...PROPS} submitted message="¡Gracias, quedó registrado!" />);
        expect(screen.getByText('¡Gracias, quedó registrado!')).toBeInTheDocument();
        unmount();
        render(<Publico {...PROPS} estado="cerrado" barrios={[]} />);
        expect(screen.getByText('Este evento ya no recibe registros')).toBeInTheDocument();
    });
});
