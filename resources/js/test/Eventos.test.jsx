// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, within } from '@testing-library/react';

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
    allSelected, approveMessage, composeBirthDate, eventFormErrors, figureSummary, newClientUuid, provisionalCount, pruneSelection, toggleAll, toggleSelected,
} from '@/lib/eventos';
import EventoFormModal from '@/Components/Eventos/EventoFormModal';
import Show from '@/Pages/Eventos/Show';
import Operador from '@/Pages/Eventos/Operador';
import Publico from '@/Pages/Eventos/Publico';

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
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
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

    it('counts the selected attendees with a provisional consent and words the warning', () => {
        const rows = [{ id: 'a', consentimiento_provisional: true }, { id: 'b', consentimiento_provisional: false }, { id: 'c', consentimiento_provisional: true }];
        expect(provisionalCount(['a', 'b'], rows)).toBe(1);
        expect(provisionalCount(['b'], rows)).toBe(0);
        expect(approveMessage(0)).not.toMatch(/SIN autorización/);
        expect(approveMessage(1)).toMatch('1 de los seleccionados entra SIN autorización de datos');
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
                consent={{ id: 'c1', version: 'v1', cuerpo: 'Texto', approved: true }}
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

    it('marks attendees with a provisional consent and warns how many enter Votantes without data authorization', () => {
        const rows = [
            { ...attendee('a1', 'pendiente', 'Ana'), consentimiento_provisional: true },
            { ...attendee('a2', 'pendiente', 'Beto'), consentimiento_provisional: false },
            { ...attendee('a3', 'pendiente', 'Carla'), consentimiento_provisional: true },
        ];
        renderShow({ asistentes: { data: rows, current_page: 1, last_page: 1, per_page: 50, total: 3, links: [] } });
        expect(screen.getAllByText('Autorización provisional')).toHaveLength(2);

        fireEvent.click(screen.getByRole('checkbox', { name: /Seleccionar todos los pendientes/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Pasar a Votantes' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent('2 de los seleccionados entran SIN autorización de datos');
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

        fireEvent.click(screen.getByRole('checkbox', { name: /Ana/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: /Carla/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Pasar a Votantes' }));
        expect(screen.getByRole('alertdialog')).not.toHaveTextContent('SIN autorización');
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

    it('explains a missing approved consent text when opening fails', () => {
        mockPage.props.errors = { consent: 'La campaña no tiene un texto de autorización de datos aprobado.' };
        renderShow({ evento: { ...EVENTO, status: 'borrador', public_open: false } });
        expect(screen.getByRole('alert')).toHaveTextContent('Falta el texto de autorización aprobado por la campaña');
        expect(screen.getByText(/El evento está en borrador/)).toBeInTheDocument();
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

    function renderOperador(consent = { id: 'c1', version: 'v1', cuerpo: 'Texto de autorización', approved: true }) {
        return render(<Operador evento={OP_EVENTO} consent={consent} registrados={5} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);
    }

    beforeEach(() => {
        mockPage.props = { auth: { user: { ...ADMIN, permissions: ['register.asistentes'] } }, visibleCampaigns: [], errors: {}, flash: {} };
        globalThis.fetch = vi.fn();
    });

    afterEach(cleanup);

    it('sends the client_uuid, clears the form, counts and focuses the first field', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, message: 'Asistente registrado.', data: { id: 'x1', duplicate: false } }));
        renderOperador();
        fillAttendee();
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));

        await screen.findByText('¡Registrado!');
        const body = sentBody();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/ev1/asistentes');
        expect(body).toMatchObject({ client_uuid: '11111111-1111-4111-8111-111111111111', consent_text_id: 'c1', autoriza_datos: true, nombres: 'Ana', barrio: 'El Poblado' });
        expect(screen.getByTestId('session-count')).toHaveTextContent('1');
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(document.activeElement).toBe(screen.getByLabelText(/^Nombres/));

        globalThis.fetch.mockReturnValueOnce(jsonResponse(201, { success: true, data: { id: 'x2', duplicate: false } }));
        fillAttendee({ nombres: 'Beto', cedula: '1098000000' });
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        await waitFor(() => expect(screen.getByTestId('session-count')).toHaveTextContent('2'));
        expect(sentBody(1).client_uuid).toMatch(UUID_RE);
        expect(sentBody(1).client_uuid).not.toBe(body.client_uuid);
    });

    it('shows the 422 errors next to each field and retries with the same client_uuid', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(422, { message: 'x', errors: { celular: ['El celular debe tener 10 dígitos y empezar por 3.'] } }));
        renderOperador();
        fillAttendee({ celular: '12' });
        fireEvent.click(screen.getByRole('checkbox'));
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

    it('keeps the client_uuid after a network failure, so the retry cannot duplicate', async () => {
        globalThis.fetch.mockReturnValueOnce(Promise.reject(new Error('offline')));
        renderOperador();
        fillAttendee();
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(await screen.findByText(/No hay conexión/)).toBeInTheDocument();

        globalThis.fetch.mockReturnValueOnce(jsonResponse(200, { success: true, message: 'Esta persona ya estaba registrada en el evento.', data: { id: 'x1', duplicate: true } }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(await screen.findByText('Ya estaba registrado')).toBeInTheDocument();
        expect(sentBody(1).client_uuid).toBe(sentBody(0).client_uuid);
        expect(screen.getByTestId('session-count')).toHaveTextContent('0');
    });

    it('does not send without the consent box and marks a provisional text', async () => {
        renderOperador({ id: 'c1', version: 'provisional-1', cuerpo: 'Provisional', approved: false });
        expect(screen.getByText(/TEXTO PROVISIONAL/)).toBeInTheDocument();
        fillAttendee();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(await screen.findByText('Debe marcar la casilla de autorización.')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});

describe('Eventos/Publico', () => {
    const PROPS = {
        estado: 'abierto',
        evento: { nombre: 'Del Senado al barrio — Girón', descripcion: null, tipo: 'Del Senado al barrio', starts_at: '2026-11-05T18:00:00-05:00', ends_at: null, sitio_encuentro: 'Parque principal', municipio: 'Girón', provincia: 'Soto' },
        barrios: ['El Poblado', 'Centro'],
        consent: { id: 'c1', version: 'v1', cuerpo: 'Autorizo el tratamiento de mis datos personales completo.' },
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

    it('keeps "Registrarme" disabled until the consent box is checked and shows the full text on demand', () => {
        render(<Publico {...PROPS} />);
        const button = screen.getByRole('button', { name: 'Registrarme' });
        expect(button).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Leer el texto de autorización completo' }));
        expect(screen.getByText(PROPS.consent.cuerpo)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('checkbox'));
        expect(button).toBeEnabled();
    });

    it('sends the birth date from the three selects, the honeypot and form_token, then always thanks', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(200, { success: true, message: '¡Gracias, quedó registrado!' }));
        render(<Publico {...PROPS} />);
        fillAttendee({ barrio: 'Centro' });
        fireEvent.change(screen.getByLabelText('Día'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText('Mes'), { target: { value: '4' } });
        fireEvent.change(screen.getByLabelText('Año'), { target: { value: '1980' } });
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));

        expect(await screen.findByText('¡Gracias, quedó registrado!')).toBeInTheDocument();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/e/abcdefghijklmnopqrstuvwxyz');
        expect(sentBody()).toMatchObject({
            fecha_nacimiento: '1980-04-02', form_token: 'tok', website: '', client_uuid: PROPS.client_uuid, barrio: 'Centro', consent_text_id: 'c1',
        });

        fireEvent.click(screen.getByRole('button', { name: 'Registrar a otra persona' }));
        expect(screen.getByLabelText(/^Nombres/)).toHaveValue('');
        expect(screen.getByRole('button', { name: 'Registrarme' })).toBeDisabled();
    });

    it('asks for the full date when only part of it is chosen', async () => {
        render(<Publico {...PROPS} />);
        fillAttendee();
        fireEvent.change(screen.getByLabelText('Año'), { target: { value: '1980' } });
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));
        expect(await screen.findByText('Elija día, mes y año, o deje los tres vacíos.')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('turns into the closed message when the server answers 410', async () => {
        globalThis.fetch.mockReturnValueOnce(jsonResponse(410, { success: false, message: 'Este evento ya no recibe registros.' }));
        render(<Publico {...PROPS} />);
        fillAttendee();
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));
        expect(await screen.findByText('Este evento ya no recibe registros')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Registrarme' })).toBeNull();
    });

    it('shows the thanks page when the server already marked it submitted, and the closed page for estado cerrado', () => {
        const { unmount } = render(<Publico {...PROPS} submitted message="¡Gracias, quedó registrado!" />);
        expect(screen.getByText('¡Gracias, quedó registrado!')).toBeInTheDocument();
        unmount();
        render(<Publico {...PROPS} estado="cerrado" consent={null} barrios={[]} />);
        expect(screen.getByText('Este evento ya no recibe registros')).toBeInTheDocument();
    });
});
