// Regression tests for the QA findings on Eventos (BUG-01, 03, 05, 06, 08). Lives outside Pages/.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';

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

import { attendeeClientErrors, canManageEventos, eventErrorMessage, formatEventRange, qrClosedReason } from '@/lib/eventos';
import Index from '@/Pages/Eventos/Index';
import Show from '@/Pages/Eventos/Show';
import Operador from '@/Pages/Eventos/Operador';
import Publico from '@/Pages/Eventos/Publico';

const CD = { id: 'cd', name: 'Centro Democrático' };
const MANAGER_NO_WRITE = { id: 'u2', name: 'Gestor', permissions: ['manage.eventos'], canWrite: false, campaign: CD };
const OPERATOR = { id: 'u3', name: 'Operador', permissions: ['register.asistentes'], canWrite: false, campaign: CD };

const EVENTO = {
    id: 'ev1', nombre: 'Del Senado al barrio — Girón', status: 'borrador', tipo: { id: 't1', nombre: 'Del Senado al barrio' },
    starts_at: '2026-11-05T18:00:00-05:00', ends_at: '2026-11-05T21:00:00-05:00', registration_closes_at: null, sitio_encuentro: 'Parque principal',
    provincia: { id: 'p1', nombre: 'Área Metropolitana' }, municipio: { id: 'm1', nombre: 'Girón' },
    barrios: ['El Poblado'], campaign: { id: 'cd', nombre: 'Centro Democrático' }, editable: true,
    descripcion: null, public_url: 'http://localhost/e/token', public_open: false, organizadores: [], figuras: [],
    created_by: null, created_at: null,
};

const PAGINATOR = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, links: [] };

function renderShow(can) {
    return render(
        <Show evento={EVENTO} asistentes={PAGINATOR} kpis={{}} filters={{}} consent={{ id: 'c1', version: 'v1', cuerpo: 'x', approved: true }} tipos={[]} provincias={[]} can={can} />,
    );
}

beforeEach(() => {
    mockRouterPost.mockReset();
    mockPage.props = { auth: { user: MANAGER_NO_WRITE }, visibleCampaigns: [], errors: {}, flash: {} };
});

afterEach(cleanup);

describe('BUG-01: manage.eventos is enough, write.data is not needed', () => {
    it('derives the right from the server flag or the permissions', () => {
        expect(canManageEventos(MANAGER_NO_WRITE)).toBe(true);
        expect(canManageEventos(OPERATOR)).toBe(false);
        expect(canManageEventos({ permissions: ['*'] })).toBe(true);
        expect(canManageEventos({ isSuperadmin: true, permissions: [] })).toBe(true);
        expect(canManageEventos({ canManageEventos: false, permissions: ['manage.eventos'] })).toBe(false);
        expect(canManageEventos({ canManageEventos: true, permissions: [] })).toBe(true);
    });

    it('shows "+ Nuevo evento" to a manager without write.data, and not to an operator', () => {
        const props = { eventos: { ...PAGINATOR, total: 0 }, filters: {}, provincias: [], tipos: [], statuses: [] };
        const { unmount } = render(<Index {...props} can={{ manage: true, view: true, register: true }} />);
        expect(screen.getByRole('button', { name: '+ Nuevo evento' })).toBeInTheDocument();
        unmount();

        mockPage.props.auth.user = OPERATOR;
        render(<Index {...props} can={{ manage: false, view: false, register: true }} />);
        expect(screen.queryByRole('button', { name: '+ Nuevo evento' })).toBeNull();
    });

    it('gives a manager without write.data the event actions and the "Pulse Abrir registro" hint', () => {
        renderShow({ manage: true, register: true, edit: true });
        expect(screen.getByRole('button', { name: 'Abrir registro' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.getByText(/Pulse "Abrir registro"/)).toBeInTheDocument();
    });

    it('does not tell a viewer to press a button they do not have', () => {
        mockPage.props.auth.user = { ...MANAGER_NO_WRITE, permissions: ['view.eventos', 'register.asistentes'] };
        renderShow({ manage: false, register: true, edit: false });
        expect(screen.queryByRole('button', { name: 'Abrir registro' })).toBeNull();
        expect(screen.queryByText(/Pulse/)).toBeNull();
        expect(screen.getByText(/cuando el equipo del evento abra el registro/)).toBeInTheDocument();
        expect(qrClosedReason({ status: 'cerrado', public_open: false })).toMatch('el equipo del evento');
    });
});

describe('BUG-03: public and operator form text is at least 18px', () => {
    it('uses no font size under 18px inside the public form', () => {
        window.history.replaceState({}, '', '/e/abcdefghijklmnopqrstuvwxyz');
        const { container } = render(
            <Publico estado="abierto" evento={{ nombre: 'Evento', tipo: 'Gira', starts_at: EVENTO.starts_at, ends_at: null, sitio_encuentro: 'Parque', municipio: 'Girón', provincia: 'Soto' }}
                barrios={['El Poblado']} consent={{ id: 'c1', version: 'v1', cuerpo: 'Texto' }} form_token="t" client_uuid="22222222-2222-4222-8222-222222222222" submitted={false} message={null} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leer el texto de autorización completo' }));
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Registrarme' }));
        expect(screen.getByText('Escriba los nombres.')).toBeInTheDocument();
        const form = container.querySelector('form');
        const sizes = [...form.querySelectorAll('*')]
            .flatMap(el => [...String(el.getAttribute('class') ?? '').matchAll(/text-\[(\d+)px\]/g)].map(m => Number(m[1])));
        expect(sizes.length).toBeGreaterThan(10);
        expect(Math.min(...sizes)).toBeGreaterThanOrEqual(18);
        expect(screen.getByText('Día').className).toContain('text-[18px]');
        expect(screen.getByRole('button', { name: 'El Poblado' }).className).toContain('text-[18px]');
        expect(screen.getByText('Solo números, sin puntos.').className).toContain('text-[18px]');
    });
});

describe('BUG-05: the end time is shown when there is one', () => {
    it('formats the range for the same day and for another day', () => {
        expect(formatEventRange(EVENTO.starts_at, EVENTO.ends_at)).toMatch(/6:00\sp\.\sm\. a 9:00\sp\.\sm\.$/);
        expect(formatEventRange(EVENTO.starts_at, '2026-11-06T02:00:00-05:00')).toMatch(/hasta el viernes, 6 de noviembre de 2026/);
        expect(formatEventRange(EVENTO.starts_at, null)).not.toMatch(/ a |hasta/);
    });

    it('shows it in the detail, the operator and the public page', () => {
        const { unmount } = renderShow({ manage: true, register: true, edit: true });
        expect(screen.getAllByText(/a 9:00\sp\.\sm\./).length).toBeGreaterThan(0);
        unmount();

        mockPage.props.auth.user = OPERATOR;
        const op = render(<Operador evento={{ ...EVENTO, accepts_registrations: true }} consent={{ id: 'c1', version: 'v1', cuerpo: 'x', approved: true }} registrados={0} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);
        expect(screen.getAllByText(/a 9:00\sp\.\sm\./).length).toBeGreaterThan(0);
        op.unmount();

        render(<Publico estado="cerrado" evento={{ nombre: 'Evento', tipo: 'Gira', starts_at: EVENTO.starts_at, ends_at: EVENTO.ends_at, sitio_encuentro: 'Parque', municipio: 'Girón', provincia: 'Soto' }} barrios={[]} consent={null} submitted={false} />);
        expect(screen.getByText(/a 9:00\sp\.\sm\./)).toBeInTheDocument();
    });
});

describe('BUG-06: an impossible birth date says so', () => {
    const base = { nombres: 'A', apellidos: 'B', cedula: '12345', celular: '3001234567', barrio: 'C', autoriza_datos: true };

    it('tells an impossible date apart from an incomplete one', () => {
        expect(attendeeClientErrors({ ...base, birth: { day: '31', month: '2', year: '1980' } }).fecha_nacimiento).toBe('Esa fecha no existe.');
        expect(attendeeClientErrors({ ...base, birth: { day: '', month: '2', year: '1980' } }).fecha_nacimiento).toBe('Elija día, mes y año, o deje los tres vacíos.');
        expect(attendeeClientErrors({ ...base, birth: { day: '29', month: '2', year: '1980' } }).fecha_nacimiento).toBeUndefined();
    });

    it('shows "Esa fecha no existe." on the operator form for 31 February', () => {
        mockPage.props.auth.user = OPERATOR;
        globalThis.fetch = vi.fn();
        render(<Operador evento={{ ...EVENTO, accepts_registrations: true }} consent={{ id: 'c1', version: 'v1', cuerpo: 'x', approved: true }} registrados={0} mis_registros={[]} client_uuid="11111111-1111-4111-8111-111111111111" />);
        fireEvent.change(screen.getByLabelText('Día'), { target: { value: '31' } });
        fireEvent.change(screen.getByLabelText('Mes'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText('Año'), { target: { value: '1980' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar asistente' }));
        expect(screen.getByText('Esa fecha no existe.')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});

describe('BUG-08: the cancel-event dialog does not have a "Cancelar" exit button', () => {
    it('labels the exit "No, mantener evento" and only it closes without posting', () => {
        renderShow({ manage: true, register: true, edit: true });
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar evento' }));
        const dialog = screen.getByRole('alertdialog');
        expect(within(dialog).queryByRole('button', { name: 'Cancelar' })).toBeNull();
        fireEvent.click(within(dialog).getByRole('button', { name: 'No, mantener evento' }));
        expect(mockRouterPost).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });
});

describe('Error messages use "usted"', () => {
    it('words the failed-action messages formally and for events', () => {
        expect(eventErrorMessage({ status: 403, data: {} })).toBe('No tiene permiso para hacer este cambio.');
        expect(eventErrorMessage({ status: 404 })).toBe('Este evento ya no existe o no lo puede ver. Recargue la página.');
        expect(eventErrorMessage({ status: 500 })).toBe('No se pudo guardar el cambio. Intente de nuevo en un momento.');
        expect(eventErrorMessage({ status: 403, data: { message: 'Este registro está fuera de tu territorio.' } })).toBe('Este registro está fuera de tu territorio.');
    });
});
