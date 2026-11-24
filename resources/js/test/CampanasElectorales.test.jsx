// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react';

const { page, mockRouter } = vi.hoisted(() => ({
    page: { props: {}, url: '/campanas-electorales' },
    mockRouter: { on: vi.fn(() => () => {}), visit: vi.fn(), get: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
}));

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, prefetch: _p, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => page,
    router: mockRouter,
}));

import Lista from '@/Pages/CampanasElectorales/Lista';
import Formulario from '@/Pages/CampanasElectorales/Formulario';
import Detalle from '@/Pages/CampanasElectorales/Detalle';
import GrupoPoliticoSelector, { seccionDe } from '@/Components/GrupoPoliticoSelector';
import { TEXTO_NUMERO, TEXTO_PESO, TEXTO_SALIR, TEXTO_SESION } from '@/lib/campanas';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const llamadas = (metodo, parte) => globalThis.fetch.mock.calls.filter(([u, o = {}]) => (o.method || 'GET') === metodo && String(u).includes(parte));
const cuerpo = (call) => JSON.parse(call[1].body);

function campana(over = {}) {
    return {
        id: 'e1', name: 'Gobernación 2027', cargo: 'gobernacion', cargo_label: 'Gobernación', election_year: 2027, election_date: null,
        status: 'active', archived_at: null, candidato: { person_id: null, nombre: 'Ana Ruiz' }, partido: { id: 'cd', nombre: 'Centro Democrático' },
        scope_level: 'department', territorio: { resumen: '87 municipios', unidades: [] }, metas: { cargadas: 12, total: 87 },
        factores: { factor_votos: 1.1, factor_registro: 1, votos_por_lider: 50 }, grupo: { id: 'g', nombre: 'Centro Democrático', propio: true },
        solo_lectura: false, ...over,
    };
}

const BGA = {
    id: 'bga', nombre: 'Bucaramanga', tipo: 'municipality', codigo: '68001', padre_id: null, comunas: 2, meta: null, editable: true,
    suma_comunas: null,
    sugerencia: { votos: 1100, lideres: 22, votantes: 1100, base: 1000, texto: 'Sugerido: 1.100 (1.000 votos de Centro Democrático a Asamblea 2023 × 1,10)' },
    sin_sugerencia: null, avance: { votantes: 38821, lideres: 0 }, sin_comuna: { votantes: 12, lideres: 0 },
};
const C1 = { id: 'c1', nombre: 'Comuna 1 Norte', tipo: 'comuna', codigo: '68001-C01', padre_id: 'bga', comunas: 0, meta: null, editable: true, suma_comunas: null, sugerencia: null, sin_sugerencia: 'Sin sugerencia: los resultados solo existen por municipio', avance: { votantes: 3, lideres: 0 }, sin_comuna: null };
const C2 = { ...C1, id: 'c2', nombre: 'Corregimiento 1', tipo: 'corregimiento', codigo: '68001-R01' };
const GIRON = { ...BGA, id: 'giron', nombre: 'Girón', codigo: '68307', comunas: 0, meta: { votos_meta: 500, lideres_meta: null, votantes_meta: null }, sugerencia: null, sin_sugerencia: null, sin_comuna: null, avance: { votantes: 10, lideres: 1 } };

function detalleProps(over = {}) {
    return {
        campana: campana(), can: { edit: true, archive: true, reactivate: false, plantilla: true }, soloLectura: null,
        sugerencias: { disponible: true, aviso: null, comparable: 'Asamblea 2023' }, filas: [BGA, C1, C2, GIRON],
        totales: { votos_meta: 500, lideres_meta: 0, votantes_meta: 0, avance: { votantes: 38831, lideres: 1 } }, ...over,
    };
}

const originalFetch = globalThis.fetch;

beforeEach(() => {
    page.props = { auth: { user: { permissions: ['manage.campanas'] } } };
    page.url = '/campanas-electorales';
    mockRouter.on.mockClear();
    mockRouter.visit.mockClear();
    mockRouter.reload.mockClear();
    sessionStorage.clear();
    document.getElementById('global-offline-toast')?.remove();
    globalThis.fetch = vi.fn(() => json({ success: true, data: {} }));
});

afterEach(() => {
    cleanup();
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
});

/* ── Lista ── */

describe('Lista de campañas', () => {
    it('shows each campaign with its territory and loaded goals, and the subgroup as read-only', () => {
        render(<Lista campanas={[campana(), campana({ id: 'e2', name: 'Alcaldía Alejandro', grupo: { id: 'a', nombre: 'Alejandro Cárdenas', propio: false } })]} can={{ manage: true }} filtros={{ archivadas: false }} />);
        expect(screen.getByRole('link', { name: 'Nueva campaña' })).toHaveAttribute('href', '/campanas-electorales/nueva');
        const primera = screen.getByRole('heading', { name: 'Gobernación 2027' }).closest('li');
        expect(within(primera).getByText('87 municipios · Metas cargadas: 12 de 87')).toBeInTheDocument();
        expect(within(primera).getByText(/Ana Ruiz · Centro Democrático/)).toBeInTheDocument();
        expect(within(primera).getByRole('link', { name: 'Ver campaña Gobernación 2027' })).toHaveAttribute('href', '/campanas-electorales/e1');
        expect(screen.getByText('Grupo político: Alejandro Cárdenas · Solo lectura')).toBeInTheDocument();
    });

    it('empty: invites to create with manage, plain without it', () => {
        render(<Lista campanas={[]} can={{ manage: true }} />);
        expect(screen.getByText('Aún no hay campañas. Crea la primera con "Nueva campaña".')).toBeInTheDocument();
        cleanup();
        render(<Lista campanas={[]} can={{ manage: false }} />);
        expect(screen.getByText('Aún no hay campañas.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Nueva campaña' })).not.toBeInTheDocument();
    });

    it('with the superadmin selector blocking, says why instead of offering to create', () => {
        render(<Lista campanas={[]} can={{ manage: true }} createBlocked="Estás viendo «Alejandro». Para crear, incluye tu grupo." />);
        expect(screen.queryByRole('link', { name: 'Nueva campaña' })).not.toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent('Estás viendo «Alejandro».');
    });

    it('the Archivadas tab loads (skeleton), fails with Reintentar, and retries', async () => {
        let responder;
        globalThis.fetch = vi.fn(() => new Promise(r => { responder = r; }));
        render(<Lista campanas={[campana()]} can={{ manage: true }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Archivadas' }));
        expect(screen.getByRole('button', { name: 'Archivadas' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByText('Cargando campañas…')).toBeInTheDocument();
        expect(llamadas('GET', '/campanas-electorales?archivadas=1')).toHaveLength(1);
        responder(new Response('{}', { status: 500 }));

        expect(await screen.findByText('No pudimos cargar las campañas.')).toBeInTheDocument();
        globalThis.fetch = vi.fn(() => json({ success: true, data: { campanas: [campana({ status: 'archived', name: 'Concejo 2023' })], can: { manage: true } } }));
        fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(await screen.findByRole('heading', { name: 'Concejo 2023' })).toBeInTheDocument();
        expect(screen.getByText('Archivada')).toBeInTheDocument();
    });

    it('new props (the group selector reloaded the page) replace the list on screen', () => {
        const { rerender } = render(<Lista campanas={[campana({ name: 'Del subgrupo' })]} can={{ manage: true }} createBlocked="Estás viendo «Sub»." />);
        expect(screen.getByRole('heading', { name: 'Del subgrupo' })).toBeInTheDocument();
        rerender(<Lista campanas={[campana({ id: 'e9', name: 'Del CD' })]} can={{ manage: true }} createBlocked={null} />);
        expect(screen.getByRole('heading', { name: 'Del CD' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Del subgrupo' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Nueva campaña' })).toBeInTheDocument();
    });
});

describe('Selector de grupo sobre una página que deja de existir', () => {
    it('the section list of a path', () => {
        expect(seccionDe('/campanas-electorales/e1')).toBe('/campanas-electorales');
        expect(seccionDe('/campanas-electorales')).toBe('/campanas-electorales');
        expect(seccionDe('/')).toBe('/');
    });

    it('a 404 on the reload goes to the list instead of painting the error page', async () => {
        globalThis.fetch = vi.fn(() => json({ success: true }));
        window.history.pushState({}, '', '/campanas-electorales/e1');
        render(<GrupoPoliticoSelector vista={{ options: [{ id: 'cd', name: 'Centro Democrático' }, { id: 'sub', name: 'Sub' }], selected: null }} />);
        fireEvent.click(screen.getByRole('button', { name: /Grupo político/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Centro Democrático' }));
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
        await waitFor(() => expect(mockRouter.reload).toHaveBeenCalled());
        const opciones = mockRouter.reload.mock.calls[0][0];
        expect(opciones.onHttpException({ status: 404 })).toBe(false);
        expect(mockRouter.visit).toHaveBeenCalledWith('/campanas-electorales');
        expect(opciones.onHttpException({ status: 500 })).toBeUndefined();
        window.history.pushState({}, '', '/');
    });
});

/* ── Formulario ── */

const OPCIONES = {
    cargos: [
        { value: 'gobernacion', label: 'Gobernación', scopes: ['department'] },
        { value: 'alcaldia', label: 'Alcaldía', scopes: ['municipality'] },
        { value: 'senado', label: 'Senado', scopes: ['department', 'municipality'] },
        { value: 'jal', label: 'JAL', scopes: ['comuna'] },
    ],
    anio: { min: 2024, max: 2040, defecto: 2027 },
    departamento: { id: 'sant', nombre: 'Santander' },
    municipios: [{ id: 'giron', nombre: 'Girón' }, { id: 'bga', nombre: 'Bucaramanga' }],
    comunas: [{ id: 'c1', nombre: 'Comuna 1 Norte', tipo: 'comuna', municipio_id: 'bga', municipio: 'Bucaramanga' }],
    partidos: [{ id: 'cd', nombre: 'Centro Democrático' }],
    factores: { factor_votos: { defecto: 1.1, min: 0.5, max: 3 }, factor_registro: { defecto: 1, min: 0.1, max: 3 }, votos_por_lider: { defecto: 50, min: 5, max: 1000 } },
};

describe('Formulario de campaña', () => {
    it('says what is missing under each field and sends nothing', async () => {
        render(<Formulario opciones={OPCIONES} />);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar campaña' }));
        expect(screen.getByText('Escribe el nombre')).toBeInTheDocument();
        expect(screen.getByText('Elige el cargo')).toBeInTheDocument();
        expect(screen.getByLabelText('Nombre de la campaña')).toHaveAttribute('aria-invalid', 'true');

        fireEvent.change(screen.getByLabelText('Nombre de la campaña'), { target: { value: 'Alcaldía Girón' } });
        fireEvent.click(screen.getByRole('button', { name: 'Alcaldía' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar campaña' }));
        expect(screen.getByText('Elige un municipio', { selector: 'p' })).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('a double click creates one campaign, then opens it', async () => {
        let responder;
        globalThis.fetch = vi.fn(() => new Promise(r => { responder = r; }));
        render(<Formulario opciones={OPCIONES} />);
        fireEvent.change(screen.getByLabelText('Nombre de la campaña'), { target: { value: 'Alcaldía Girón' } });
        fireEvent.click(screen.getByRole('button', { name: 'Alcaldía' }));
        fireEvent.change(screen.getByLabelText('Municipio'), { target: { value: 'giron' } });
        const guardar = screen.getByRole('button', { name: 'Guardar campaña' });
        fireEvent.click(guardar);
        fireEvent.click(guardar);
        expect(llamadas('POST', '/campanas-electorales')).toHaveLength(1);
        expect(screen.getByRole('button', { name: /Guardando…/ })).toBeDisabled();
        expect(cuerpo(llamadas('POST', '/campanas-electorales')[0])).toMatchObject({
            name: 'Alcaldía Girón', cargo: 'alcaldia', election_year: 2027, scope_level: 'municipality', territorio_ids: ['giron'],
        });
        responder(new Response(JSON.stringify({ success: true, data: { campana: { id: 'nueva' } } }), { status: 201 }));
        await waitFor(() => expect(mockRouter.visit).toHaveBeenCalledWith('/campanas-electorales/nueva'));
    });

    it('shows the server 422 under its field', async () => {
        globalThis.fetch = vi.fn(() => json({ message: 'x', errors: { name: ['Ya tienes una campaña activa con ese nombre'] } }, 422));
        render(<Formulario opciones={OPCIONES} />);
        fireEvent.change(screen.getByLabelText('Nombre de la campaña'), { target: { value: 'Gobernación 2027' } });
        fireEvent.click(screen.getByRole('button', { name: 'Gobernación' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar campaña' }));
        expect(await screen.findByText('Ya tienes una campaña activa con ese nombre')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Guardar campaña' })).not.toBeDisabled();
        expect(mockRouter.visit).not.toHaveBeenCalled();
    });

    it('Senado: all Santander or some municipios', () => {
        render(<Formulario opciones={OPCIONES} />);
        fireEvent.click(screen.getByRole('button', { name: 'Senado' }));
        expect(screen.getByRole('button', { name: 'Todo Santander' })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: 'Algunos municipios' }));
        fireEvent.click(screen.getByLabelText('Girón'));
        expect(screen.getByText('1 municipio elegido')).toBeInTheDocument();
    });

    it('a group without results: the candidate search answers 403 and only the free text stays', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        globalThis.fetch = vi.fn(() => json({ success: false, message: 'Sin resultados' }, 403));
        render(<Formulario opciones={OPCIONES} />);
        fireEvent.change(screen.getByLabelText('Busca al candidato en la plataforma'), { target: { value: 'Ana' } });
        await vi.advanceTimersByTimeAsync(400);
        await waitFor(() => expect(screen.queryByLabelText('Busca al candidato en la plataforma')).not.toBeInTheDocument());
        expect(screen.getByLabelText('Escribe el nombre del candidato')).toBeInTheDocument();
        vi.useRealTimers();
    });

    it('without permission to search (no electoral results), never offers it nor talks about suggestions', () => {
        render(<Formulario opciones={OPCIONES} puedeBuscarCandidatos={false} />);
        expect(screen.queryByLabelText('Busca al candidato en la plataforma')).not.toBeInTheDocument();
        expect(screen.queryByText('Se usa para sugerir metas.')).not.toBeInTheDocument();
    });

    it('with results, the party says what it is for; the factors show two decimals', () => {
        render(<Formulario opciones={OPCIONES} />);
        expect(screen.getByText('Se usa para sugerir metas.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cómo sugerimos las metas' }));
        expect(screen.getByLabelText('Votos que queremos sacar')).toHaveValue('1,10');
        expect(screen.getByLabelText('Votantes que hay que registrar')).toHaveValue('1,00');
    });
});

/* ── Detalle ── */

describe('Detalle de campaña', () => {
    it('PUT /metas 500: the error shows inside the fixed save bar and as a toast', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response('<html>error</html>', { status: 500 })));
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: '700' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        const alerta = await screen.findByRole('alert');
        expect(alerta).toHaveTextContent('No pudimos guardar las metas. Intenta de nuevo.');
        // Same box as the Guardar button: the fixed bar the person is looking at.
        expect(alerta.parentElement).toContainElement(screen.getByRole('button', { name: 'Guardar cambios' }));
        expect(document.getElementById('global-offline-toast')).toHaveTextContent('No pudimos guardar las metas. Intenta de nuevo.');
        expect(screen.getByLabelText('Votos meta de Girón')).toHaveValue('700');
    });

    it('PUT /metas 419: says the session expired and the changes come back after reloading', async () => {
        globalThis.fetch = vi.fn(() => json({ message: 'CSRF token mismatch.' }, 419));
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: '700' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(TEXTO_SESION);
        cleanup();
        render(<Detalle {...detalleProps()} />);
        expect(screen.getByLabelText('Votos meta de Girón')).toHaveValue('700');
        expect(screen.getByText(/Recuperamos los cambios que no se guardaron/)).toBeInTheDocument();
        expect(screen.getByText('1 territorio cambiado')).toBeInTheDocument();
    });

    it('a 403 while archiving a read-only screen shows under the header', async () => {
        globalThis.fetch = vi.fn(() => json({ success: false, message: 'Solo el equipo del grupo puede archivarla.' }, 403));
        render(<Detalle {...detalleProps({ can: { edit: false, archive: true, reactivate: false, plantilla: true } })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Archivar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, archivar' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Solo el equipo del grupo puede archivarla.');
    });

    it('an upload too big for the server says the 2 MB limit', async () => {
        globalThis.fetch = vi.fn(() => json({ message: 'x', errors: { archivo: ['No se pudo subir el archivo archivo.'] } }, 422));
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByTestId('excel-metas'), { target: { files: [new File(['x'], 'metas.xlsx')] } });
        const alerta = await screen.findByRole('alert');
        expect(alerta).toHaveTextContent(TEXTO_PESO);
        expect(within(alerta).queryAllByRole('listitem')).toHaveLength(0);
    });

    it('shows the suggestion; «Usar sugerido» fills it and Guardar sends only that row, once', async () => {
        let responder;
        globalThis.fetch = vi.fn(() => new Promise(r => { responder = r; }));
        render(<Detalle {...detalleProps()} />);

        expect(screen.getByText('Sugerido: 1.100 (1.000 votos de Centro Democrático a Asamblea 2023 × 1,10)')).toBeInTheDocument();
        expect(screen.getByLabelText('Votos meta de Bucaramanga')).toHaveValue('');
        fireEvent.click(screen.getByRole('button', { name: 'Usar sugerido en Bucaramanga' }));
        expect(screen.getByLabelText('Votos meta de Bucaramanga')).toHaveValue('1.100');
        expect(screen.getByLabelText('Líderes meta de Bucaramanga')).toHaveValue('22');
        expect(screen.getByLabelText('Votantes meta de Bucaramanga')).toHaveValue('1.100');
        expect(screen.getByText('1 territorio cambiado')).toBeInTheDocument();

        const guardar = screen.getByRole('button', { name: 'Guardar cambios' });
        fireEvent.click(guardar);
        fireEvent.click(guardar);
        expect(llamadas('PUT', '/metas')).toHaveLength(1);
        expect(cuerpo(llamadas('PUT', '/metas')[0])).toEqual({ metas: [{ geographic_unit_id: 'bga', votos_meta: 1100, lideres_meta: 22, votantes_meta: 1100 }] });

        const guardada = { ...BGA, meta: { votos_meta: 1100, lideres_meta: 22, votantes_meta: 1100 } };
        responder(new Response(JSON.stringify({ success: true, message: 'Metas guardadas', data: { filas: [guardada, C1, C2, GIRON], totales: {}, campana: campana() } }), { status: 200 }));
        expect(await screen.findByText('Metas guardadas', { selector: 'strong' })).toBeInTheDocument();
        expect(screen.getByLabelText('Votos meta de Bucaramanga')).toHaveValue('1.100');
        expect(screen.queryByText('1 territorio cambiado')).not.toBeInTheDocument();
    });

    it('a bad goal is marked in red and nothing is sent', () => {
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: 'abc' } });
        expect(screen.getByLabelText('Votos meta de Girón')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText(TEXTO_NUMERO)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Revisa las casillas marcadas en rojo.');
        expect(llamadas('PUT', '/metas')).toHaveLength(0);
    });

    it('nothing changed: says so and sends nothing', () => {
        render(<Detalle {...detalleProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        expect(screen.getByText('No hay cambios para guardar.')).toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('the server 422 lands on the cell that failed', async () => {
        globalThis.fetch = vi.fn(() => json({ message: 'x', errors: { 'metas.0.votos_meta': ['Escribe un número entero, 0 o más'] } }, 422));
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: '9' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));
        await waitFor(() => expect(screen.getByLabelText('Votos meta de Girón')).toHaveAttribute('aria-invalid', 'true'));
        expect(screen.getByRole('alert')).toHaveTextContent('Revisa las casillas marcadas en rojo.');
    });

    it('comunas: «Ver por comunas», the row without comuna, and the municipio becomes their sum', () => {
        render(<Detalle {...detalleProps()} />);
        const ver = screen.getByRole('button', { name: 'Ver por comunas (1 + 1 corregimiento)' });
        fireEvent.click(ver);
        expect(screen.getByText('Sin comuna asignada:').parentElement).toHaveTextContent('Sin comuna asignada: 12 fichas');
        expect(screen.getAllByText('Sin sugerencia: los resultados solo existen por municipio')).toHaveLength(2);

        fireEvent.change(screen.getByLabelText('Votos meta de Comuna 1 Norte'), { target: { value: '300' } });
        fireEvent.change(screen.getByLabelText('Votos meta de Corregimiento 1'), { target: { value: '200' } });
        expect(screen.queryByLabelText('Votos meta de Bucaramanga')).not.toBeInTheDocument();
        expect(screen.getByText('Las metas de Bucaramanga son la suma de sus comunas.')).toBeInTheDocument();
        const totales = screen.getByRole('group', { name: 'Totales' });
        expect(within(totales).getByText('Votos meta').parentElement).toHaveTextContent('1.000');
    });

    it('read-only: a band with the reason and no inputs, no save bar, no upload', () => {
        render(<Detalle {...detalleProps({ can: { edit: false, archive: false, reactivate: false, plantilla: false }, soloLectura: { motivo: 'subgrupo', texto: 'Solo lectura: esta campaña es de Centro Democrático.' } })} />);
        expect(screen.getByRole('note')).toHaveTextContent('Solo lectura. Esta campaña es de Centro Democrático.');
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Guardar cambios' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Usar sugerido/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Cargar metas/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Archivar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Editar' })).not.toBeInTheDocument();
    });

    it('a group without results sees the notice and no suggestion', () => {
        const filas = [{ ...BGA, sugerencia: undefined }, GIRON];
        delete filas[0].sugerencia;
        render(<Detalle {...detalleProps({ filas, sugerencias: { disponible: false, aviso: 'Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano' } })} />);
        expect(screen.getByText('Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano')).toBeInTheDocument();
        expect(screen.queryByText(/^Sugerido/)).not.toBeInTheDocument();
    });

    it('with unsaved changes, leaving asks first (and only then)', () => {
        const add = vi.spyOn(window, 'addEventListener');
        render(<Detalle {...detalleProps()} />);
        expect(add.mock.calls.filter(([t]) => t === 'beforeunload')).toHaveLength(0);
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: '700' } });
        const alSalir = add.mock.calls.find(([t]) => t === 'beforeunload')[1];
        const evento = new Event('beforeunload', { cancelable: true });
        alSalir(evento);
        expect(evento.defaultPrevented).toBe(true);

        const before = mockRouter.on.mock.calls.filter(([n]) => n === 'before').at(-1)[1];
        const confirmar = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const prefetch = { detail: { visit: { prefetch: true } }, preventDefault: vi.fn() };
        before(prefetch);
        expect(confirmar).not.toHaveBeenCalled();
        const visita = { detail: { visit: {} }, preventDefault: vi.fn() };
        before(visita);
        expect(confirmar).toHaveBeenCalledWith(TEXTO_SALIR);
        expect(visita.preventDefault).toHaveBeenCalled();
    });

    it('archiving asks first, then archives once', async () => {
        globalThis.fetch = vi.fn((url, o = {}) => ((o.method || 'GET') === 'POST'
            ? json({ success: true })
            : json({ success: true, data: detalleProps({ campana: campana({ status: 'archived' }), can: { edit: false, archive: false, reactivate: true, plantilla: true } }) })));
        render(<Detalle {...detalleProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Archivar' }));
        const dialogo = screen.getByRole('dialog', { name: '¿Archivar esta campaña?' });
        expect(dialogo).toHaveTextContent('Dejará de aparecer en Activas. Podrás reactivarla.');
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Sí, archivar' }));
        expect(await screen.findByRole('button', { name: 'Reactivar' })).toBeInTheDocument();
        expect(llamadas('POST', '/archivar')).toHaveLength(1);
        expect(screen.getByText('Archivada')).toBeInTheDocument();
    });

    it('«No, volver» does not archive', () => {
        render(<Detalle {...detalleProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Archivar' }));
        fireEvent.click(screen.getByRole('button', { name: 'No, volver' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('an Excel with a bad row: nothing is loaded and each row to fix is listed', async () => {
        globalThis.fetch = vi.fn(() => json({ success: false, message: 'No se cargó nada. Corrige estas filas:', errors: { archivo: ['Fila 4: "abc" no es un número', 'Fila 9: -5 no es válido'] } }, 422));
        render(<Detalle {...detalleProps()} />);
        const archivo = new File(['x'], 'metas.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        fireEvent.change(screen.getByTestId('excel-metas'), { target: { files: [archivo] } });
        const alerta = await screen.findByRole('alert');
        expect(alerta).toHaveTextContent('No se cargó nada. Corrige estas filas:');
        expect(within(alerta).getAllByRole('listitem').map(li => li.textContent)).toEqual(['Fila 4: "abc" no es un número', 'Fila 9: -5 no es válido']);
        expect(llamadas('POST', '/metas/importar')).toHaveLength(1);
        expect(llamadas('POST', '/metas/importar')[0][1].body).toBeInstanceOf(FormData);
    });

    it('an Excel that loads says how many goals and shows them', async () => {
        const cargada = { ...GIRON, meta: { votos_meta: 800, lideres_meta: 16, votantes_meta: 800 } };
        globalThis.fetch = vi.fn(() => json({ success: true, message: 'Se cargaron 1 metas', data: { cargadas: 1, filas: [BGA, C1, C2, cargada], totales: {}, campana: campana() } }));
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByTestId('excel-metas'), { target: { files: [new File(['x'], 'metas.xlsx')] } });
        expect(await screen.findByText('Se cargaron 1 meta')).toBeInTheDocument();
        expect(screen.getByLabelText('Votos meta de Girón')).toHaveValue('800');
    });

    it('a file that is not .xlsx, or the screen with unsaved changes, never reaches the server', () => {
        render(<Detalle {...detalleProps()} />);
        fireEvent.change(screen.getByTestId('excel-metas'), { target: { files: [new File(['x'], 'metas.csv')] } });
        expect(screen.getByRole('alert')).toHaveTextContent('El archivo debe ser un Excel (.xlsx). Usa la plantilla.');
        fireEvent.change(screen.getByLabelText('Votos meta de Girón'), { target: { value: '700' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cargar metas desde Excel' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Guarda o descarta tus cambios antes de cargar el Excel.');
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('the history lists who changed what', async () => {
        globalThis.fetch = vi.fn(() => json({ success: true, data: [
            { id: 'h1', fecha: '2026-11-23T10:00:00-05:00', usuario: 'Ana', accion: 'electoral_campaign.goals.updated', accion_label: 'Metas guardadas',
                cambios: [{ territorio: 'Bucaramanga', campo: 'votos_meta', campo_label: 'Votos meta', antes: null, despues: 1200 }] },
        ] }));
        render(<Detalle {...detalleProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Historial' }));
        const dialogo = screen.getByRole('dialog', { name: 'Historial de cambios' });
        expect(await within(dialogo).findByText('Metas guardadas')).toBeInTheDocument();
        expect(within(dialogo).getByText('Bucaramanga · Votos meta: vacío → 1.200')).toBeInTheDocument();
    });
});
