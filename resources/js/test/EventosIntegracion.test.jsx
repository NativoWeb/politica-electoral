// Eventos especiales phase 4: events in the person's ficha, Votantes filters/column, territory view and helpers.
import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react';

const mockPage = { props: {}, url: '/votantes' };

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, preserveScroll: _s, preserveState: _st, only: _o, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => mockPage,
    useForm: () => ({ data: {}, setData: vi.fn(), errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn(), transform: vi.fn() }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: () => () => {} },
}));
vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('@/lib/swSession', () => ({ precacheExtraPages: vi.fn(), precacheExtraPagesOnce: vi.fn() }));
vi.mock('qrcode', () => ({ default: { toDataURL: () => Promise.resolve('data:image/png;base64,AAAA') } }));
vi.mock('@/lib/offlineDb', () => ({
    getOfflinePersona: vi.fn().mockResolvedValue(null),
    offlineUpdatePersona: vi.fn(),
    offlineCreateNexo: vi.fn(),
    offlineDeleteNexo: vi.fn(),
}));

import {
    attendeesExcelUrl, canSeeEventAttendees, eventCountLabel, matchingBarrios, participationLine, personEventsSummary,
    territoryExcelUrl, territoryParams,
} from '@/lib/eventosIntegracion';
import { coversAllTipos, TIPO_OPTIONS, tipoOptionsFor } from '@/lib/mapaPoliticoConstants';
import { canExportFrom } from '@/lib/exportPermission';
import PersonaEventos from '@/Components/Eventos/PersonaEventos';
import TerritorioFiltros from '@/Components/Eventos/TerritorioFiltros';
import TerritorioResultado from '@/Components/Eventos/TerritorioResultado';
import VotanteFilters, { EventFilterChips } from '@/Components/VotanteFilters';
import VotanteTable from '@/Components/VotanteTable';
import VotanteDetail from '@/Components/VotanteDetail';
import PersonaPanel, { buildFormFromData } from '@/Components/PersonaPanel';
import { getOfflinePersona } from '@/lib/offlineDb';
import Show from '@/Pages/Eventos/Show';
import Index from '@/Pages/Eventos/Index';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };
const VISIBLE = [{ id: 'cd', name: 'Centro Democrático' }, { id: 'x', name: 'Alejandro' }];

const PERSONA_EVENTOS = {
    total: 2, asistio: 2, organizo: 1,
    eventos: [
        { id: 'e1', nombre: 'Gira Girón', tipo: 'Gira', status: 'cerrado', starts_at: '2026-11-05T18:00:00-05:00', municipio: 'Girón', barrios: ['San Martín'], campaign_id: 'cd', participacion: 'asistente', rol: 'Asistente', origen: 'qr', url: '/eventos/e1' },
        { id: 'e2', nombre: 'Novena Lebrija', tipo: 'Novena', status: 'abierto', starts_at: '2026-11-01T18:00:00-05:00', municipio: 'Lebrija', barrios: [], campaign_id: 'x', participacion: 'organizador', rol: 'Organizador (Coordinadora)', origen: null, url: '/eventos/e2' },
    ],
};

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const originalFetch = globalThis.fetch;

beforeEach(() => {
    mockPage.props = { auth: { user: { campaign: CD, permissions: ['view.eventos', 'view.votantes'] } }, visibleCampaigns: VISIBLE };
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
    globalThis.fetch = originalFetch;
    cleanup();
});

describe('phase 4 helpers', () => {
    it('labels counts, participation and summaries in plain Spanish', () => {
        expect(eventCountLabel(1)).toBe('1 evento');
        expect(eventCountLabel(3)).toBe('3 eventos');
        expect(eventCountLabel(null)).toBe('0 eventos');
        expect(participationLine(PERSONA_EVENTOS.eventos[0])).toBe('Asistente · Por QR');
        expect(participationLine(PERSONA_EVENTOS.eventos[1])).toBe('Organizador (Coordinadora)');
        expect(personEventsSummary(PERSONA_EVENTOS)).toBe('Asistió a 2 eventos y organizó 1 evento.');
        expect(personEventsSummary({ total: 1, asistio: 0, organizo: 1 })).toBe('Organizó 1 evento.');
        expect(personEventsSummary({ total: 0 })).toMatch('No ha asistido ni organizado eventos');
    });

    it('builds the Excel urls with only the filters on screen', () => {
        expect(attendeesExcelUrl('ev1', {})).toBe('/eventos/ev1/asistentes.xlsx');
        expect(attendeesExcelUrl('ev1', { link_status: 'pendiente', q: 'Ana Pérez', page: 3 })).toBe('/eventos/ev1/asistentes.xlsx?link_status=pendiente&q=Ana+P%C3%A9rez');
        expect(territoryExcelUrl({ municipio: 'm1', barrio: 'San Martín' })).toBe('/eventos/territorio/excel?municipio=m1&barrio=San+Mart%C3%ADn');
        expect(territoryParams({ municipio: 'm1', barrio: '' })).toEqual({ municipio: 'm1' });
    });

    it('suggests barrios ignoring accents, case and spaces', () => {
        const barrios = [{ nombre: 'San Martín', key: 'san martin' }, { nombre: 'San Luis', key: 'san luis' }, { nombre: 'Centro', key: 'centro' }];
        expect(matchingBarrios(barrios, 'SAN  MAR').map(b => b.nombre)).toEqual(['San Martín']);
        expect(matchingBarrios(barrios, 'san').map(b => b.nombre)).toEqual(['San Martín', 'San Luis']);
        expect(matchingBarrios(barrios, 'san martin')).toEqual([]);
        expect(matchingBarrios(barrios, '')).toHaveLength(3);
    });

    it('only view.eventos or manage.eventos see attendees', () => {
        expect(canSeeEventAttendees({ permissions: ['view.eventos'] })).toBe(true);
        expect(canSeeEventAttendees({ permissions: ['manage.eventos'] })).toBe(true);
        expect(canSeeEventAttendees({ permissions: ['*'] })).toBe(true);
        expect(canSeeEventAttendees({ permissions: ['register.asistentes', 'view.votantes'] })).toBe(false);
        expect(canSeeEventAttendees(null)).toBe(false);
    });

    it('the attendee tipo is off in "Todos" and every tipo checked groups in sections', () => {
        expect(TIPO_OPTIONS.find(o => o.value === 'asistentes_evento')?.label).toBe('Asistentes a eventos');
        expect(coversAllTipos('todos')).toBe(true);
        expect(coversAllTipos(undefined)).toBe(true);
        expect(coversAllTipos('lideres,asistentes_evento')).toBe(false);
        expect(coversAllTipos(TIPO_OPTIONS.filter(o => o.value !== 'todos').map(o => o.value).join(','))).toBe(true);
    });

    it('without view.eventos the Mapa offers no attendee tipo and "every tipo" means the usual ones', () => {
        const without = tipoOptionsFor(false);
        expect(without.map(o => o.label)).not.toContain('Asistentes a eventos');
        expect(tipoOptionsFor(true).map(o => o.label)).toContain('Asistentes a eventos');
        const usual = without.filter(o => o.value !== 'todos').map(o => o.value).join(',');
        expect(coversAllTipos(usual, without)).toBe(true);
        expect(coversAllTipos(usual, tipoOptionsFor(true))).toBe(false);
    });

    it('event pages hide the header export (it would export the Mapa Político)', () => {
        const all = () => true;
        expect(canExportFrom('/eventos', all)).toBe(false);
        expect(canExportFrom('/eventos/territorio', all)).toBe(false);
        expect(canExportFrom('/mapa-politico', all)).toBe(true);
    });
});

describe('PersonaEventos', () => {
    it('lists the events with role, origin, place and a link', async () => {
        globalThis.fetch = vi.fn(() => json({ success: true, data: PERSONA_EVENTOS }));
        render(<PersonaEventos liderId="l1" />);

        expect(screen.getByText('Cargando eventos…')).toBeTruthy();
        expect(await screen.findByText('Asistió a 2 eventos y organizó 1 evento.')).toBeTruthy();
        expect(globalThis.fetch).toHaveBeenCalledWith('/eventos/persona/l1', expect.objectContaining({ headers: { Accept: 'application/json' } }));
        const items = within(screen.getByRole('list', { name: 'Eventos de la persona' })).getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(within(items[0]).getByText('Asistente · Por QR')).toBeTruthy();
        expect(within(items[0]).getByText('Girón — Barrios: San Martín')).toBeTruthy();
        expect(within(items[0]).getByRole('link', { name: 'Ver el evento' }).getAttribute('href')).toBe('/eventos/e1');
        expect(within(items[1]).getByText('Organizador (Coordinadora)')).toBeTruthy();
        expect(within(items[1]).getByText('Campaña Alejandro')).toBeTruthy();
    });

    it('says when there are no events and when it cannot load them', async () => {
        globalThis.fetch = vi.fn(() => json({ success: true, data: { total: 0, asistio: 0, organizo: 0, eventos: [] } }));
        render(<PersonaEventos liderId="l1" />);
        expect(await screen.findByText(/No ha asistido ni organizado eventos/)).toBeTruthy();
        cleanup();

        globalThis.fetch = vi.fn(() => json({ message: 'No' }, 403));
        render(<PersonaEventos liderId="l1" />);
        expect((await screen.findByRole('alert')).textContent).toBe('No tiene permiso para ver los eventos de esta persona.');
        cleanup();

        globalThis.fetch = vi.fn(() => json({}, 500));
        render(<PersonaEventos liderId="l1" />);
        expect((await screen.findByRole('alert')).textContent).toBe('No se pudieron cargar los eventos. Intente de nuevo.');
    });
});

describe('Votantes with events', () => {
    const EVENTOS = [{ id: 'e1', nombre: 'Gira Girón', starts_at: '2026-11-05T18:00:00-05:00', municipio: 'Girón' }];

    it('filters by one event or by any event', () => {
        const onApply = vi.fn();
        render(<VotanteFilters filters={{}} municipios={[]} onApply={onApply} onClear={vi.fn()} eventos={EVENTOS} canViewEventos />);

        const select = screen.getByLabelText('Asistió al evento…');
        expect(within(select).getByText(/^Gira Girón — .*2026 — Girón$/)).toBeTruthy();
        fireEvent.click(screen.getByLabelText('Asistentes a eventos (cualquiera)'));
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ asistentes_eventos: '1' }));
        expect(onApply.mock.lastCall[0]).not.toHaveProperty('evento');

        fireEvent.change(select, { target: { value: 'e1' } });
        expect(screen.getByLabelText('Asistentes a eventos (cualquiera)').disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ evento: 'e1' }));
        expect(onApply.mock.lastCall[0]).not.toHaveProperty('asistentes_eventos');
    });

    it('shows no event filters without view.eventos', () => {
        render(<VotanteFilters filters={{ evento: 'e1' }} municipios={[]} onApply={vi.fn()} onClear={vi.fn()} eventos={[]} canViewEventos={false} />);
        expect(screen.queryByLabelText('Asistió al evento…')).toBeNull();
        expect(screen.queryByText('Asistentes a eventos (cualquiera)')).toBeNull();
    });

    it('shows the "N eventos" column only to who can see events', () => {
        const votantes = {
            data: [{ id: 'v1', nombre: 'Ana', eventos_count: 2 }, { id: 'v2', nombre: 'Beto', eventos_count: 0 }, { id: 'v3', nombre: 'Carla', eventos_count: 1 }],
            current_page: 1, last_page: 1, links: [{ url: null }, { url: null }], from: 1, to: 3, total: 3,
        };
        render(<VotanteTable votantes={votantes} selectedId={null} onSelect={vi.fn()} showEventos />);
        expect(screen.getByRole('columnheader', { name: 'Eventos' })).toBeTruthy();
        expect(screen.getByText('2 eventos')).toBeTruthy();
        expect(screen.getByText('1 evento')).toBeTruthy();
        cleanup();

        render(<VotanteTable votantes={votantes} selectedId={null} onSelect={vi.fn()} />);
        expect(screen.queryByRole('columnheader', { name: 'Eventos' })).toBeNull();
        expect(screen.queryByText('2 eventos')).toBeNull();
    });

    it('the detail has an Eventos section that loads when opened, only with view.eventos', async () => {
        globalThis.fetch = vi.fn(() => json({ success: true, data: PERSONA_EVENTOS }));
        const data = { id: 'v1', nombre: 'Ana', cedula: '1', municipio: 'Girón', campaign_id: 'cd', contactos: [], info_politica: [], nexos: [], referidos: [] };
        const props = { data, loading: false, creating: false, onClose: vi.fn(), onSaved: vi.fn(), onDeleted: vi.fn(), apiFetch: vi.fn(), municipios: [], showToast: vi.fn() };
        render(<VotanteDetail {...props} />);

        expect(globalThis.fetch).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Eventos' }));
        expect(await screen.findByText('Asistió a 2 eventos y organizó 1 evento.')).toBeTruthy();
        expect(globalThis.fetch).toHaveBeenCalledWith('/eventos/persona/v1', expect.anything());
        cleanup();

        mockPage.props.auth.user.permissions = ['view.votantes', 'register.asistentes'];
        render(<VotanteDetail {...props} />);
        expect(screen.queryByRole('button', { name: 'Eventos' })).toBeNull();
    });
});

describe('PersonaPanel Eventos tab', () => {
    const lider = { id: 'l1', source: 'lider', campaign_id: 'cd', nombre: 'Ana Uno', municipio: 'Girón', cargo: 'Líder', tipo_registro: 'Asistentes a eventos', nexos: [], contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [] };

    function mockFetch(record) {
        globalThis.fetch = vi.fn((url) => {
            if (url.includes('posibles-duplicados')) return json({ data: [] });
            if (url.startsWith('/eventos/persona/')) return json({ success: true, data: PERSONA_EVENTOS });
            return json(record);
        });
    }

    it('shows the tab for a lider ficha and loads its events when opened', async () => {
        mockFetch(lider);
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        const tab = await screen.findByRole('button', { name: 'Eventos' });
        expect(globalThis.fetch.mock.calls.some(([url]) => url.startsWith('/eventos/persona/'))).toBe(false);
        fireEvent.click(tab);
        expect(await screen.findByText('Asistió a 2 eventos y organizó 1 evento.')).toBeTruthy();
    });

    it('has no Eventos tab without view.eventos or for an electoral candidate', async () => {
        mockPage.props.auth.user.permissions = ['view.mapa'];
        mockFetch(lider);
        render(<PersonaPanel personId="l1" onClose={vi.fn()} />);
        await screen.findByRole('button', { name: /Basicos/ });
        expect(screen.queryByRole('button', { name: 'Eventos' })).toBeNull();
        cleanup();

        mockPage.props.auth.user.permissions = ['view.eventos', 'view.mapa'];
        mockFetch({ ...lider, source: 'persona', campaign_id: null });
        render(<PersonaPanel personId="p1" onClose={vi.fn()} />);
        await screen.findByRole('button', { name: /Basicos/ });
        expect(screen.queryByRole('button', { name: 'Eventos' })).toBeNull();
    });
});

describe('Territory view', () => {
    const PROVINCIAS = [
        { id: 'p1', name: 'Área Metropolitana', municipios: [{ id: 'm1', name: 'Girón' }, { id: 'm2', name: 'Lebrija' }] },
        { id: 'p2', name: 'Vélez', municipios: [{ id: 'm3', name: 'Vélez' }] },
    ];
    const BARRIOS = [{ nombre: 'San Martín', key: 'san martin', total: 5 }, { nombre: 'Centro', key: 'centro', total: 2 }];

    it('picks a municipio, then a barrio from the suggestions', () => {
        const onSearch = vi.fn();
        const { rerender } = render(<TerritorioFiltros provincias={PROVINCIAS} barrios={[]} filters={{}} onSearch={onSearch} />);
        expect(screen.queryByLabelText('Barrio (opcional)')).toBeNull();
        fireEvent.change(screen.getByLabelText('Provincia'), { target: { value: 'p2' } });
        expect(within(screen.getByLabelText('Municipio')).queryByText('Girón')).toBeNull();
        fireEvent.change(screen.getByLabelText('Municipio'), { target: { value: 'm3' } });
        expect(onSearch).toHaveBeenLastCalledWith({ municipio: 'm3', barrio: null });

        rerender(<TerritorioFiltros provincias={PROVINCIAS} barrios={BARRIOS} filters={{ municipio: 'm1' }} onSearch={onSearch} />);
        fireEvent.change(screen.getByLabelText('Barrio (opcional)'), { target: { value: 'SAN MAR' } });
        const group = screen.getByRole('group', { name: 'Barrios sugeridos' });
        expect(within(group).getAllByRole('button').map(b => b.textContent)).toEqual(['San Martín']);
        fireEvent.click(within(group).getByRole('button', { name: 'San Martín' }));
        expect(onSearch).toHaveBeenLastCalledWith({ municipio: 'm1', barrio: 'San Martín' });
    });

    it('typed barrio is searched as written and can be removed', () => {
        const onSearch = vi.fn();
        render(<TerritorioFiltros provincias={PROVINCIAS} barrios={BARRIOS} filters={{ municipio: 'm1', barrio: 'centro' }} onSearch={onSearch} />);
        fireEvent.change(screen.getByLabelText('Barrio (opcional)'), { target: { value: '  La   Cumbre ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));
        expect(onSearch).toHaveBeenLastCalledWith({ municipio: 'm1', barrio: 'La Cumbre' });
        fireEvent.click(screen.getByRole('button', { name: 'Ver todo el municipio (quitar barrio)' }));
        expect(onSearch).toHaveBeenLastCalledWith({ municipio: 'm1', barrio: null });
    });

    const RESULTADO = {
        kpis: { lideres: 2, eventos: 1, asistentes: 3, personas: 3, en_votantes: 2 },
        lideres: { data: [{ id: 'l1', nombre: 'Ana Uno', cargo: 'Líder', barrio: 'San Martín', campaign_id: 'cd' }, { id: 'l2', nombre: 'Beto Equis', campaign_id: 'x' }], total: 2, last_page: 1 },
        eventos: [{ id: 'e1', nombre: 'Gira Girón', tipo: 'Gira', status: 'cerrado', starts_at: '2026-11-05T18:00:00-05:00', campaign_id: 'cd', asistentes: 3, asistentes_barrio: 2, url: '/eventos/e1' }],
        asistentes: { data: [{ id: 'a1', nombres: 'Pepe', apellidos: 'Pérez', cedula: '666', celular: '3001234567', barrio: 'San Martin', source: 'operador', link_status: 'pendiente', evento: { id: 'e1', nombre: 'Gira Girón' }, campaign_id: 'cd' }], total: 3, last_page: 1 },
    };

    it('shows KPIs, the three lists and the Excel with the same filters', () => {
        render(<TerritorioResultado resultado={RESULTADO} filters={{ municipio: 'm1', barrio: 'San Martín' }} lugar="Barrio San Martín, Girón" />);
        expect(screen.getByText('Barrio San Martín, Girón')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Descargar Excel' }).getAttribute('href')).toBe('/eventos/territorio/excel?municipio=m1&barrio=San+Mart%C3%ADn');
        expect(screen.getByText('Eventos en el barrio')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Eventos realizados en el barrio (1)' })).toBeTruthy();
        expect(screen.getByText('2 del barrio')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'Gira Girón' }).map(a => a.getAttribute('href'))).toEqual(['/eventos/e1', '/eventos/e1']);
        expect(screen.getByText('C.C. 666 · 3001234567 · Barrio San Martin · Por operador')).toBeTruthy();
        expect(screen.getByText('Pendiente de aprobar')).toBeTruthy();
        expect(screen.getByText('Campaña Alejandro')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Líderes (2)' })).toBeTruthy();
    });

    it('says clearly when the barrio has nothing', () => {
        const empty = { kpis: { lideres: 0, eventos: 0, asistentes: 0, personas: 0, en_votantes: 0 }, lideres: { data: [], total: 0 }, eventos: [], asistentes: { data: [], total: 0 } };
        render(<TerritorioResultado resultado={empty} filters={{ municipio: 'm1', barrio: 'Centro' }} lugar="Barrio Centro, Girón" />);
        expect(screen.getByText('No hay eventos registrados en este barrio.')).toBeTruthy();
        expect(screen.getByText('Nadie de este barrio aparece en los eventos.')).toBeTruthy();
        expect(screen.getByText('No hay líderes registrados en este barrio.')).toBeTruthy();
    });
});

describe('Entry points in the Eventos pages', () => {
    const EVENTO = {
        id: 'ev1', nombre: 'Gira Girón', status: 'cerrado', tipo: { id: 't', nombre: 'Gira' }, starts_at: '2026-11-05T18:00:00-05:00', ends_at: null,
        sitio_encuentro: 'Parque', provincia: { id: 'p', nombre: 'Soto' }, municipio: { id: 'm', nombre: 'Girón' }, barrios: [],
        campaign: { id: 'cd', nombre: 'CD' }, editable: false, public_url: null, public_open: false, organizadores: [], figuras: [],
    };
    const ASISTENTE = { id: 'a1', nombres: 'Ana', apellidos: 'Pérez', cedula: '1', source: 'qr', link_status: 'pendiente', autoriza_datos: true, consentimiento_provisional: false, registered_by: null, created_at: '2026-11-01T10:00:00-05:00' };

    it('the detail downloads the attendees Excel with the filters on screen', () => {
        const asistentes = { data: [ASISTENTE], current_page: 1, last_page: 1, total: 1, links: [] };
        const props = { evento: EVENTO, asistentes, kpis: {}, consent: null, tipos: [], provincias: [], can: { manage: false, register: false, edit: false } };
        const { unmount } = render(<Show {...props} filters={{}} />);
        expect(screen.getByRole('link', { name: 'Descargar Excel' }).getAttribute('href')).toBe('/eventos/ev1/asistentes.xlsx');
        unmount();

        render(<Show {...props} filters={{ link_status: 'pendiente', q: 'Ana' }} />);
        expect(screen.getByRole('link', { name: 'Descargar Excel (con el filtro)' }).getAttribute('href')).toBe('/eventos/ev1/asistentes.xlsx?link_status=pendiente&q=Ana');
        cleanup();

        render(<Show {...props} asistentes={{ ...asistentes, data: [], total: 0 }} filters={{}} />);
        expect(screen.queryByRole('link', { name: /Descargar Excel/ })).toBeNull();
    });

    it('the list links to the territory view only for who sees attendees', () => {
        const props = { eventos: { data: [], current_page: 1, last_page: 1, total: 0, links: [] }, filters: {}, provincias: [], tipos: [], statuses: [] };
        const { unmount } = render(<Index {...props} can={{ manage: false, view: true, register: false }} />);
        expect(screen.getByRole('link', { name: 'Ver por territorio' }).getAttribute('href')).toBe('/eventos/territorio');
        unmount();

        render(<Index {...props} can={{ manage: false, view: false, register: true }} />);
        expect(screen.queryByRole('link', { name: 'Ver por territorio' })).toBeNull();
    });
});

describe('QA F4 fixes', () => {
    it('BUG-01: a ficha without cargo opens normally, with its Eventos tab', async () => {
        expect(buildFormFromData({ cargos: [null] }).cargos).toEqual([]);
        expect(buildFormFromData({ cargos: ['Líder, JAC', null] }).cargos).toEqual(['Líder', 'JAC']);
        expect(buildFormFromData({ cargo: null }).cargos).toEqual([]);

        const asistente = { id: 'l9', source: 'lider', campaign_id: 'cd', nombre: 'Zoe Asistente', municipio: 'Girón', cargo: null, cargos: [null], tipo_registro: 'Asistentes a eventos', nexos: [], contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [] };
        getOfflinePersona.mockClear();
        globalThis.fetch = vi.fn((url) => {
            if (url.includes('posibles-duplicados')) return json({ data: [] });
            return json(asistente);
        });
        render(<PersonaPanel personId="l9" onClose={vi.fn()} />);
        expect(await screen.findByRole('button', { name: 'Eventos' })).toBeTruthy();
        expect(getOfflinePersona).not.toHaveBeenCalled();
    });

    it('BUG-01: a ficha the screen cannot show is an error, not the saved copy', async () => {
        getOfflinePersona.mockClear();
        globalThis.fetch = vi.fn(() => json(null));
        render(<PersonaPanel personId="l9" onClose={vi.fn()} fallbackData={{ id: 'l9', nombre: 'Copia' }} />);
        expect(await screen.findByText('La ficha llegó pero no se pudo mostrar. Avise al equipo técnico.')).toBeTruthy();
        expect(getOfflinePersona).not.toHaveBeenCalled();
        expect(screen.queryByText('Copia')).toBeNull();
    });

    it('BUG-01: without signal it still falls back to the saved copy', async () => {
        getOfflinePersona.mockClear();
        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        render(<PersonaPanel personId="l9" onClose={vi.fn()} fallbackData={{ id: 'l9', nombre: 'Copia Guardada', source: 'lider', cargos: [null] }} />);
        expect(await screen.findAllByText('Copia Guardada')).toBeTruthy();
        expect(getOfflinePersona).toHaveBeenCalledWith('l9');
    });

    it('BUG-02: with no barrio typed nor applied the suggestions are shown', () => {
        const provincias = [{ id: 'p1', name: 'Área Metropolitana', municipios: [{ id: 'm1', name: 'Girón' }] }];
        const barrios = [{ nombre: 'San Martín', key: 'san martin' }, { nombre: 'Centro', key: 'centro' }];
        render(<TerritorioFiltros provincias={provincias} barrios={barrios} filters={{ municipio: 'm1', barrio: null }} onSearch={vi.fn()} />);
        const group = screen.getByRole('group', { name: 'Barrios sugeridos' });
        expect(within(group).getAllByRole('button').map(b => b.textContent)).toEqual(['San Martín', 'Centro']);
    });

    it('BUG-04: two filter panels (desktop and mobile) never share ids, and the event filter shows as a chip', () => {
        const eventos = [{ id: 'e1', nombre: 'Gira Girón', starts_at: null, municipio: 'Girón' }];
        render(<>
            <VotanteFilters filters={{}} municipios={[]} onApply={vi.fn()} onClear={vi.fn()} eventos={eventos} canViewEventos />
            <VotanteFilters filters={{}} municipios={[]} onApply={vi.fn()} onClear={vi.fn()} eventos={eventos} canViewEventos />
        </>);
        const selects = screen.getAllByLabelText('Asistió al evento…');
        expect(selects).toHaveLength(2);
        expect(selects[0].id).not.toBe(selects[1].id);
        cleanup();

        const onRemove = vi.fn();
        render(<EventFilterChips filters={{ evento: 'e1' }} eventos={eventos} onRemove={onRemove} />);
        expect(screen.getByText('Asistió a: Gira Girón')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Quitar filtro Asistió a: Gira Girón' }));
        expect(onRemove).toHaveBeenCalledWith('evento');
        cleanup();

        render(<EventFilterChips filters={{ asistentes_eventos: '1' }} eventos={eventos} onRemove={vi.fn()} />);
        expect(screen.getByText('Asistentes a eventos')).toBeTruthy();
        cleanup();
        const { container } = render(<EventFilterChips filters={{}} eventos={eventos} onRemove={vi.fn()} />);
        expect(container.textContent).toBe('');
    });
});
