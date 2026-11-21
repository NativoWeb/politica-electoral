import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup, within } from '@testing-library/react';
import { FUENTE_ASISTENCIAS, FUENTE_COMPROMISOS, FUENTE_EMPRESARIOS, FUENTE_PERSONAS, jsonResponse } from './reportesFixtures';

// B-3: several sources, the "fechas" filter and what tapping a row does. Same tiny Inertia as Reportes.test.jsx.
const { store, setUrl, router } = vi.hoisted(() => {
    const store = { url: '/reportes', props: {}, listeners: new Set() };
    store.snapshot = { url: store.url, props: store.props };
    const setUrl = (url) => { store.url = url; store.snapshot = { url, props: store.props }; store.listeners.forEach(l => l()); };
    const router = {
        push: vi.fn(({ url }) => setUrl(url)),
        replace: vi.fn(({ url }) => setUrl(url)),
        visit: vi.fn(),
        reload: vi.fn(),
        on: vi.fn(() => () => {}),
    };
    return { store, setUrl, router };
});

vi.mock('@inertiajs/react', async () => {
    const { useSyncExternalStore } = await import('react');
    return {
        usePage: () => useSyncExternalStore(
            (l) => { store.listeners.add(l); return () => store.listeners.delete(l); },
            () => store.snapshot,
        ),
        router,
        Head: () => null,
        Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
    };
});
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/PersonaPanel', () => ({
    default: ({ personId, onClose }) => (
        <div role="dialog" aria-label="Ficha mapa">
            <p>Ficha de {personId}</p>
            <button type="button" onClick={onClose}>Cerrar ficha</button>
        </div>
    ),
}));
vi.mock('@/Components/Empresarios/OrganizacionPanel', () => ({
    default: ({ orgId, soloVer, onClose }) => (
        <div role="dialog" aria-label="Organización">
            <p>Organización {orgId}{soloVer ? ' (solo ver)' : ''}</p>
            <button type="button" onClick={onClose}>Cerrar organización</button>
        </div>
    ),
}));

import ReportesIndex from '@/Pages/Reportes/Index';
import {
    accionDe, atajosFechas, fechaValida, leerEstado, limpiarFiltros, nombreSugerido, normalizarFuente, pedirOpciones,
    resumenFiltros, sinChip, specDe, tarjetaDe, textoExcluidos, unidadDe, validarFechas,
} from '@/lib/reportes';
import TarjetaPersona from '@/Components/Reportes/TarjetaPersona';
import { AVISO_COLUMNAS_QUITADAS } from '@/lib/exportDownload';

const TODAS = [FUENTE_PERSONAS, FUENTE_ASISTENCIAS, FUENTE_COMPROMISOS, FUENTE_EMPRESARIOS];
const PROPS = { fuentes: TODAS, puedeDatosPersonales: false, topes: { excel: 50000, pdf: 500 } };
const ASIS = normalizarFuente(FUENTE_ASISTENCIAS);
const COMP = normalizarFuente(FUENTE_COMPROMISOS);
const EMP = normalizarFuente(FUENTE_EMPRESARIOS);

const query = () => new URLSearchParams(store.url.split('?')[1]);
const filtrosUrl = () => (query().get('f') ? JSON.parse(query().get('f')) : null);
const calls = (fragment) => globalThis.fetch.mock.calls.filter(([url]) => String(url).includes(fragment));
const bodyOf = (call) => JSON.parse(call[1].body);

function mockLista(filas, total = filas.length) {
    globalThis.fetch = vi.fn((url) => {
        if (String(url).includes('/conteo')) return jsonResponse({ success: true, total: 7 });
        if (String(url).includes('/lista')) return jsonResponse({ success: true, total, pagina: 1, por_pagina: 50, filas });
        return jsonResponse({}, 404);
    });
}

const original = globalThis.fetch;
beforeEach(() => {
    setUrl('/reportes');
    store.props = { auth: { user: { name: 'Ana', permissions: ['view.reportes', 'view.mapa', 'view.votantes', 'view.eventos', 'view.empresarios'] } } };
    store.snapshot = { url: store.url, props: store.props };
    router.push.mockClear();
    router.replace.mockClear();
    router.visit.mockClear();
    mockLista([]);
});
afterEach(() => { cleanup(); globalThis.fetch = original; });

describe('Paso 1 with several sources', () => {
    it('shows one big card per source with its total in its own words and its shortcuts inside it', () => {
        render(<ReportesIndex {...PROPS} />);
        const tarjeta = (nombre) => screen.getByRole('region', { name: nombre });

        expect(tarjeta('Personas')).toHaveTextContent('12.084 personas');
        expect(tarjeta('Eventos y asistencia')).toHaveTextContent('320 asistentes');
        expect(tarjeta('Eventos y asistencia')).toHaveTextContent('Quiénes fueron a cada evento');
        expect(tarjeta('Compromisos y actas')).toHaveTextContent('41 compromisos');
        expect(tarjeta('Empresarios y gremios')).toHaveTextContent('15 organizaciones');

        // Each shortcut lives in the card of its source, not in a shared list.
        expect(within(tarjeta('Personas')).getByRole('button', { name: /Grandes electores/ })).toBeInTheDocument();
        expect(within(tarjeta('Compromisos y actas')).getByRole('button', { name: /Compromisos vencidos/ })).toBeInTheDocument();
        expect(within(tarjeta('Personas')).queryByRole('button', { name: /Compromisos vencidos/ })).not.toBeInTheDocument();
        expect(within(tarjeta('Eventos y asistencia')).getByRole('button', { name: /Asistentes del último mes/ })).toBeInTheDocument();
        expect(screen.queryByText('Atajos rápidos')).not.toBeInTheDocument();
    });

    it('with only one source keeps the B-1 screen', () => {
        render(<ReportesIndex {...PROPS} fuentes={[FUENTE_PERSONAS]} />);
        expect(screen.getByText('Atajos rápidos')).toBeInTheDocument();
        expect(screen.getByText('12.084 personas')).toBeInTheDocument();
        expect(screen.getByText('Arma una lista de personas, mírala aquí y descárgala.')).toBeInTheDocument();
    });

    it('a shortcut of a source with a relative period goes to its list with that period', async () => {
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Asistentes del último mes/ }));
        expect(query().get('fuente')).toBe('asistencias');
        expect(query().get('paso')).toBe('4');
        expect(filtrosUrl()).toEqual({ fecha: { relativo: 'ultimos_30' } });
        await waitFor(() => expect(calls('/reportes/asistencias/lista')).toHaveLength(1), { timeout: 1500 });
        expect(bodyOf(calls('/reportes/asistencias/lista')[0]).spec.filtros).toEqual({ fecha: { relativo: 'ultimos_30' } });
    });
});

describe('Changing source', () => {
    const conFiltros = `/reportes?fuente=personas&paso=1&f=${encodeURIComponent('{"marcas":["gran_elector"]}')}&c=nombre,municipio`;

    it('with filters chosen asks first; "No" keeps everything, "Sí" starts over with the new source', () => {
        setUrl(conFiltros);
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Elegir Compromisos y actas' }));
        const dialogo = screen.getByRole('dialog', { name: '¿Empezar de nuevo con Compromisos y actas?' });
        expect(dialogo).toHaveTextContent('Se quitarán los filtros elegidos.');

        fireEvent.click(within(dialogo).getByRole('button', { name: 'No, seguir con Personas' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(store.url).toBe(conFiltros);

        fireEvent.click(screen.getByRole('button', { name: 'Elegir Compromisos y actas' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, empezar de nuevo' }));
        expect(query().get('fuente')).toBe('compromisos');
        expect(query().get('paso')).toBe('2');
        expect(query().get('f')).toBeNull();
        // The columns are the new source's (no presets: its first ones), not the Personas ones.
        expect(query().get('c')).toBe('descripcion,estado,fecha_limite');
        expect(query().get('o')).toBe('fecha_limite:asc');
    });

    it('a shortcut of another source asks too; Escape answers "No"', () => {
        setUrl(conFiltros);
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Compromisos vencidos/ }));
        const dialogo = screen.getByRole('dialog');
        fireEvent.keyDown(dialogo, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(store.url).toBe(conFiltros);
    });

    it('without filters chosen, or within the same source, it does not ask', () => {
        setUrl('/reportes?fuente=personas&paso=1');
        const { unmount } = render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Elegir Compromisos y actas' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(query().get('fuente')).toBe('compromisos');
        unmount();

        setUrl(conFiltros);
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Elegir Personas' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(filtrosUrl()).toEqual({ marcas: ['gran_elector'] });
    });
});

describe('Filter "fechas"', () => {
    it('lib: whole real dates only, desde ≤ hasta, never half applied', () => {
        expect(fechaValida('2026-11-21')).toBe(true);
        expect(fechaValida('2026-02-30')).toBe(false);
        expect(fechaValida('21/11/2026')).toBe(false);
        expect(validarFechas('2026-11-01', '')).toEqual({ rango: { desde: '2026-11-01', hasta: null } });
        expect(validarFechas('2026-11-20', '2026-11-01').error).toBe('La fecha «desde» debe ser igual o anterior a la fecha «hasta».');
        expect(validarFechas('', '', { incompleta: true }).error).toBe('Escribe la fecha completa: día, mes y año.');

        // From the URL: a pair in the wrong order, an impossible date and an unknown period are dropped.
        expect(limpiarFiltros({ fecha: { desde: '2026-11-20', hasta: '2026-11-01' } }, ASIS)).toEqual({});
        expect(limpiarFiltros({ fecha: { desde: '2026-13-01', hasta: null } }, ASIS)).toEqual({});
        expect(limpiarFiltros({ fecha: { relativo: 'siempre' } }, ASIS)).toEqual({});
        expect(limpiarFiltros({ fecha: { relativo: 'ultimos_7' } }, ASIS)).toEqual({ fecha: { relativo: 'ultimos_7' } });
        expect(resumenFiltros({ fecha: { desde: '2026-11-01', hasta: '2026-11-15' } }, ASIS)[0].texto).toBe('Fecha del evento: del 1/11/2026 al 15/11/2026');
        expect(resumenFiltros({ fecha: { relativo: 'ultimos_30' } }, ASIS)[0].texto).toBe('Fecha del evento: Último mes');
    });

    it('lib: the quick periods follow Bogotá\'s calendar (Monday to Sunday, whole month)', () => {
        // 2026-11-21 02:00 UTC is still Friday 20 in Bogotá.
        const [hoy, semana, mes, ultimo] = atajosFechas(new Date('2026-11-21T02:00:00Z'));
        expect(hoy).toEqual({ etiqueta: 'Hoy', desde: '2026-11-20', hasta: '2026-11-20' });
        expect(semana).toEqual({ etiqueta: 'Esta semana', desde: '2026-11-16', hasta: '2026-11-22' });
        expect(mes).toEqual({ etiqueta: 'Este mes', desde: '2026-11-01', hasta: '2026-11-30' });
        expect(ultimo).toEqual({ etiqueta: 'Último mes', desde: '2026-10-20', hasta: '2026-11-20' });
        expect(atajosFechas(new Date('2026-03-31T15:00:00Z'))[3]).toEqual({ etiqueta: 'Último mes', desde: '2026-02-28', hasta: '2026-03-31' });
    });

    it('two big date boxes: a valid pair filters; "hasta" before "desde" shows the message and filters nothing', () => {
        setUrl('/reportes?fuente=asistencias&paso=2');
        render(<ReportesIndex {...PROPS} />);
        const desde = screen.getByLabelText('Desde (Fecha del evento)');
        const hasta = screen.getByLabelText('Hasta (Fecha del evento)');
        expect(desde).toHaveAttribute('type', 'date');

        fireEvent.change(desde, { target: { value: '2026-11-01' } });
        expect(filtrosUrl()).toEqual({ fecha: { desde: '2026-11-01', hasta: null } });
        fireEvent.change(hasta, { target: { value: '2026-11-15' } });
        expect(filtrosUrl()).toEqual({ fecha: { desde: '2026-11-01', hasta: '2026-11-15' } });
        expect(screen.getByRole('button', { name: 'Quitar Fecha del evento: del 1/11/2026 al 15/11/2026' })).toBeInTheDocument();

        fireEvent.change(hasta, { target: { value: '2026-10-01' } });
        expect(screen.getByRole('alert')).toHaveTextContent('La fecha «desde» debe ser igual o anterior a la fecha «hasta». Mientras tanto no se filtra por fecha del evento.');
        expect(filtrosUrl()).toBeNull();
        expect(desde).toHaveValue('2026-11-01');
        expect(hasta).toHaveAttribute('aria-invalid', 'true');
    });

    it('offers Hoy, Esta semana and Este mes plus the source\'s own periods, without two "Último mes"', () => {
        setUrl('/reportes?fuente=asistencias&paso=2');
        render(<ReportesIndex {...PROPS} />);
        const grupo = screen.getByRole('group', { name: 'Fecha del evento' });
        const nombres = within(grupo).getAllByRole('button').map(b => b.textContent);
        expect(nombres).toEqual(['Hoy', 'Esta semana', 'Este mes', 'Última semana', 'Último mes']);

        fireEvent.click(within(grupo).getByRole('button', { name: 'Último mes' }));
        expect(filtrosUrl()).toEqual({ fecha: { relativo: 'ultimos_30' } });
        expect(within(grupo).getByRole('button', { name: 'Último mes' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByLabelText('Desde (Fecha del evento)')).toHaveValue('');

        fireEvent.click(within(grupo).getByRole('button', { name: 'Hoy' }));
        const hoy = atajosFechas()[0].desde;
        expect(filtrosUrl()).toEqual({ fecha: { desde: hoy, hasta: hoy } });
        expect(screen.getByLabelText('Hasta (Fecha del evento)')).toHaveValue(hoy);
        // Tapping the chosen one again removes the filter.
        fireEvent.click(within(grupo).getByRole('button', { name: 'Hoy' }));
        expect(filtrosUrl()).toBeNull();
    });
});

describe('Tapping a row does its accion', () => {
    it('lib: reads every accion and refuses a url to another site', () => {
        expect(accionDe({ id: 'a1', accion: { tipo: 'ficha', id: 'l9', editable: true } })).toEqual({ tipo: 'ficha', id: 'l9', editable: true });
        expect(accionDe({ id: 'c1', accion: { tipo: 'compromiso', id: 'c1', url: '/eventos/compromisos?abrir=c1' } })).toEqual({ tipo: 'compromiso', url: '/eventos/compromisos?abrir=c1' });
        expect(accionDe({ id: 'o1', accion: { tipo: 'organizacion', id: 'o1', url: '/empresarios?abrir=o1' } })).toEqual({ tipo: 'organizacion', id: 'o1' });
        expect(accionDe({ id: 'a2', accion: null })).toBeNull();
        expect(accionDe({ id: 'x', accion: { tipo: 'compromiso', url: '//evil.example/x' } })).toBeNull();
        expect(accionDe({ id: 'x', accion: { tipo: 'compromiso', url: 'https://evil.example/x' } })).toBeNull();
        expect(accionDe({ id: 'x', accion: { tipo: 'compromiso', url: 'javascript:alert(1)' } })).toBeNull();
        expect(accionDe({ id: 'x', accion: { tipo: 'compromiso', url: '/\\evil.example' } })).toBeNull();
        expect(accionDe({ id: 'x', accion: { tipo: 'otra', id: 'x' } })).toBeNull();
        // A B-1 row (no accion key) still opens its ficha.
        expect(accionDe({ id: 'p1', editable: true })).toEqual({ tipo: 'ficha', id: 'p1', editable: true });
    });

    it('asistencias: a linked row opens the ficha of the person (not the attendee id); an unlinked one is plain text', async () => {
        mockLista([
            { id: 'a1', editable: false, accion: { tipo: 'ficha', id: 'l9', editable: true }, celdas: { nombre: 'Ana Ruiz', evento: 'Foro', fecha: '1/11/2026' } },
            { id: 'a2', editable: false, accion: null, celdas: { nombre: 'Luis Sin Ficha', evento: 'Foro', fecha: '1/11/2026' } },
        ]);
        setUrl('/reportes?fuente=asistencias&paso=4');
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Ana Ruiz' }));
        expect(screen.getByRole('dialog', { name: 'Ficha mapa' })).toHaveTextContent('Ficha de l9');
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }));

        // Neither the table nor the phone card make the row without accion a button, and the row has no pointer.
        expect(screen.queryAllByRole('button', { name: /Luis Sin Ficha/ })).toHaveLength(0);
        const fila = screen.getAllByText('Luis Sin Ficha').map(el => el.closest('tr')).find(Boolean);
        expect(fila.className).not.toContain('cursor-pointer');
        fireEvent.click(fila);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        // The phone card of a row with accion shows the arrow; the one without does not.
        const tarjetas = screen.getAllByText(/Ana Ruiz|Luis Sin Ficha/).map(el => el.closest('li')).filter(Boolean);
        expect(tarjetas.find(li => li.textContent.includes('Ana Ruiz')).querySelector('svg')).not.toBeNull();
        expect(tarjetas.find(li => li.textContent.includes('Luis Sin Ficha')).querySelector('svg')).toBeNull();
    });

    it('compromisos: the row visits its page (Back comes back to the same list: it lives in the URL)', async () => {
        mockLista([{ id: 'c1', editable: false, accion: { tipo: 'compromiso', id: 'c1', url: '/eventos/compromisos?abrir=c1' }, celdas: { descripcion: 'Arreglar la vía' } }]);
        const url = '/reportes?fuente=compromisos&paso=4&c=descripcion&o=fecha_limite:asc&p=2';
        setUrl(url);
        render(<ReportesIndex {...PROPS} />);
        const [enTabla, enTarjeta] = await screen.findAllByRole('button', { name: 'Arreglar la vía' });
        fireEvent.click(enTabla);
        fireEvent.click(enTarjeta);
        expect(router.visit.mock.calls).toEqual([['/eventos/compromisos?abrir=c1'], ['/eventos/compromisos?abrir=c1']]);
        expect(store.url).toBe(url);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        // editable:false is about fichas: a compromiso row never says "Solo para ver".
        expect(screen.queryByText('Solo para ver')).not.toBeInTheDocument();
    });

    it('empresarios: the row opens the organization panel, only to look (the page has no catalogs to edit)', async () => {
        mockLista([{ id: 'o1', editable: false, accion: { tipo: 'organizacion', id: 'o1', url: '/empresarios?abrir=o1' }, celdas: { nombre: 'Café del Sur' } }]);
        setUrl('/reportes?fuente=empresarios&paso=4');
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Café del Sur' }));
        expect(screen.getByRole('dialog', { name: 'Organización' })).toHaveTextContent('Organización o1 (solo ver)');
        expect(router.visit).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar organización' }));
        await waitFor(() => expect(calls('/lista')).toHaveLength(2));
    });
});

describe('Words of each source', () => {
    it('lib: units from the backend, the vista of Empresarios and a phrase for each', () => {
        expect(unidadDe(ASIS)).toMatchObject({ singular: 'asistente', plural: 'asistentes', ninguna: 'Ningún asistente', quienes: 'A quiénes' });
        expect(unidadDe(COMP)).toMatchObject({ plural: 'compromisos', ninguna: 'Ningún compromiso', quienes: 'Cuáles' });
        expect(unidadDe(EMP)).toMatchObject({ plural: 'organizaciones', femenino: true, ninguna: 'Ninguna organización' });
        expect(unidadDe(EMP, { vista: 'miembros' })).toMatchObject({ plural: 'miembros', femenino: false, quienes: 'A quiénes' });
        expect(nombreSugerido([], new Date('2026-11-21T15:00:00Z'), 'xlsx', 'Compromisos y actas')).toBe('Reporte_Compromisos_y_actas_2026-11-21.xlsx');
    });

    it('"No incluye fallecidos…" only for Personas, even when another source has an "estado" filter', () => {
        expect(textoExcluidos({}, COMP)).toBe('');
        expect(textoExcluidos({}, normalizarFuente(FUENTE_PERSONAS))).toBe('No incluye fallecidos ni personas inactivas.');
        setUrl('/reportes?fuente=compromisos&paso=2');
        render(<ReportesIndex {...PROPS} />);
        expect(screen.queryByText(/No incluye/)).not.toBeInTheDocument();
    });

    it('step 2 and the count bar speak of compromisos', async () => {
        setUrl('/reportes?fuente=compromisos&paso=2');
        render(<ReportesIndex {...PROPS} />);
        expect(screen.getByRole('heading', { name: '¿Qué compromisos quieres ver?' })).toBeInTheDocument();
        expect(screen.getByText(/Estás viendo/)).toHaveTextContent('Estás viendo todos los compromisos. Elige abajo para quedarte con algunos.');
        expect(screen.getByRole('button', { name: /Paso 2:\s?Cuáles/ })).toBeInTheDocument();
        expect(await screen.findByText('compromisos')).toBeInTheDocument();
    });

    it('step 4 says the total in the words of the source; an empty list says "Ningún compromiso…"', async () => {
        mockLista([{ id: 'c1', accion: null, celdas: { descripcion: 'Arreglar la vía' } }], 41);
        setUrl('/reportes?fuente=compromisos&paso=4');
        const { unmount } = render(<ReportesIndex {...PROPS} />);
        expect(await screen.findByText('41 compromisos')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cambiar filtros' })).toBeInTheDocument();
        expect(screen.getByLabelText('Buscar en esta lista')).toHaveAttribute('placeholder', 'Escribe para buscar…');
        // No sensitive column in this source: no notice about cédula or teléfono.
        expect(screen.queryByText(/Tu descarga no incluye/)).not.toBeInTheDocument();
        unmount();

        mockLista([], 0);
        render(<ReportesIndex {...PROPS} />);
        expect(await screen.findByText('Ningún compromiso cumple todos estos filtros. Prueba quitando uno.')).toBeInTheDocument();
    });

    it('Empresarios: choosing the vista Miembros changes the words and the columns to the members ones', async () => {
        setUrl('/reportes?fuente=empresarios&paso=2');
        render(<ReportesIndex {...PROPS} />);
        expect(query().get('c')).toBeNull();
        expect(screen.getByText(/Estás viendo/)).toHaveTextContent('Estás viendo todas las organizaciones.');
        fireEvent.click(screen.getByRole('button', { name: 'Miembros' }));
        expect(filtrosUrl()).toEqual({ vista: 'miembros' });
        expect(query().get('c')).toBe('nombre,organizacion,rol');
        expect(screen.getByRole('heading', { name: '¿A quiénes quieres ver?' })).toBeInTheDocument();
        expect(await screen.findByText('miembros')).toBeInTheDocument();

        // Back to organizations: the members' columns go away (the server answers 422 for them).
        fireEvent.click(screen.getByRole('button', { name: 'Organizaciones' }));
        expect(query().get('c')).toBe('nombre,nit');
        await waitFor(() => expect(calls('/reportes/empresarios/conteo').length).toBeGreaterThan(0), { timeout: 1500 });
    });

    it('Empresarios: an organizations column in the URL of the Miembros vista is dropped; the spec carries the vista', () => {
        const estado = leerEstado(`/reportes?fuente=empresarios&paso=4&f=${encodeURIComponent('{"vista":"miembros"}')}&c=nombre,nit,rol`, [EMP], true);
        expect(estado.columnas).toEqual(['nombre', 'rol']);
        expect(specDe(estado).filtros).toEqual({ vista: 'miembros' });
    });

    it('a remote search tells the server which source it is for', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse({ success: true, opciones: [{ valor: 'e1', etiqueta: 'Foro' }] }));
        await pedirOpciones('barrios', 'cen', ['m1'], undefined, 'asistencias');
        const [url] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/reportes/opciones/barrios?fuente=asistencias&q=cen&municipios%5B%5D=m1');
        expect(ASIS.filtros.find(f => f.clave === 'evento').fuente).toBe('asistencias');
    });
});

describe('QA B-3 fixes', () => {
    const col = (clave, etiqueta) => ({ clave, etiqueta });

    it('BUG-23: the phone card says what matters for each source', () => {
        const asis = tarjetaDe('asistencias', { celdas: { nombre: 'Ana', evento: 'Foro', fecha: '1/11/2026', municipio: 'Girón', cedula: '' } },
            [col('nombre', 'Persona'), col('evento', 'Evento'), col('fecha', 'Fecha'), col('cedula', 'Cédula'), col('municipio', 'Municipio')]);
        expect(asis.subtitulo).toEqual(['Foro', '1/11/2026']);
        expect(asis.datos[0]).toEqual({ clave: 'municipio', etiqueta: 'Municipio del evento', valor: 'Girón' });

        const comp = tarjetaDe('compromisos',
            { celdas: { descripcion: 'Arreglar la vía', estado: 'Pendiente', fecha_limite: '30/11/2026', origen: 'Evento', origen_nombre: 'Foro', responsables: 'Luis', municipio: 'Girón' } },
            [col('descripcion', 'Compromiso'), col('municipio', 'Municipio'), col('estado', 'Estado'), col('fecha_limite', 'Fecha límite'), col('origen', 'Origen'), col('origen_nombre', 'Origen'), col('responsables', 'Responsables')]);
        expect(comp.titulo).toBe('descripcion');
        expect(comp.subtitulo).toEqual(['Pendiente', '30/11/2026']);
        expect(comp.datos).toEqual([
            { clave: 'origen_nombre', etiqueta: 'Evento', valor: 'Foro' },
            { clave: 'responsables', etiqueta: 'Responsable', valor: 'Luis' },
            { clave: 'municipio', etiqueta: 'Municipio', valor: 'Girón' },
        ]);

        // Personas as before: "Municipio · Tipo", never repeated below even when empty.
        const per = tarjetaDe('personas', { celdas: { nombre: 'Ana', municipio: '', tipo: 'Votante', barrio: 'Centro' } },
            [col('nombre', 'Nombre'), col('municipio', 'Municipio'), col('barrio', 'Barrio'), col('tipo', 'Tipo')]);
        expect(per.subtitulo).toEqual(['Votante']);
        expect(per.datos.map(d => d.clave)).toEqual(['barrio']);

        render(<TarjetaPersona fuenteClave="compromisos" fila={{ id: 'c1', celdas: { descripcion: 'Arreglar la vía', estado: 'Pendiente', fecha_limite: '30/11/2026' } }}
            columnas={[col('descripcion', 'Compromiso'), col('estado', 'Estado'), col('fecha_limite', 'Fecha límite')]} />);
        expect(screen.getByText('Pendiente · 30/11/2026')).toBeInTheDocument();
    });

    it('BUG-20: the change dialog focuses "No" and also asks when only a search was typed', () => {
        setUrl('/reportes?fuente=personas&paso=1&q=Ana');
        render(<ReportesIndex {...PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Elegir Compromisos y actas' }));
        const dialogo = screen.getByRole('dialog');
        expect(dialogo).toHaveTextContent('Se quitará la búsqueda.');
        expect(within(dialogo).getByRole('button', { name: 'No, seguir con Personas' })).toHaveFocus();
        // Enter on the focused button keeps everything.
        fireEvent.click(document.activeElement);
        expect(query().get('fuente')).toBe('personas');
        expect(query().get('q')).toBe('Ana');
    });

    it('BUG-21: a chosen value is never shown as its id', () => {
        const id1 = '7d3f0c2a-1b2c-4d5e-8f90-a1b2c3d4e5f6';
        const id2 = '0a1b2c3d-4e5f-4a6b-9c8d-7e6f5a4b3c2d';
        expect(resumenFiltros({ evento: [id1] }, ASIS).map(c => c.texto)).toEqual(['1 evento elegido']);
        const chips = resumenFiltros({ evento: [id1, id2, 'e-con-nombre'] }, ASIS, { 'e-con-nombre': 'Foro' });
        expect(chips.map(c => c.texto)).toEqual(['Foro', '2 eventos elegidos']);
        // That chip removes the unnamed ones together, and only them.
        expect(sinChip({ evento: [id1, id2, 'e-con-nombre'] }, chips[1])).toEqual({ evento: ['e-con-nombre'] });
        // A value that is a name (a barrio) is still shown as it is.
        expect(resumenFiltros({ evento: ['Centro'] }, ASIS)[0].texto).toBe('Centro');

        setUrl(`/reportes?fuente=asistencias&paso=2&f=${encodeURIComponent(JSON.stringify({ evento: [id1] }))}`);
        render(<ReportesIndex {...PROPS} />);
        expect(screen.queryByText(new RegExp(id1))).not.toBeInTheDocument();
        // The summary chip on top and the chosen value inside the search box both say it in words.
        expect(screen.getAllByRole('button', { name: 'Quitar 1 evento elegido' })).toHaveLength(2);
    });

    it('O-1: the notice names every personal field that was left out', () => {
        expect(AVISO_COLUMNAS_QUITADAS).toBe('El archivo no incluye datos personales (cédula, teléfonos, correo, dirección u observaciones) porque no tienes el permiso para descargar datos personales.');
    });

    it('O-3: columns the URL cannot hold are dropped from the URL too (replace, not a new entry)', async () => {
        setUrl(`/reportes?fuente=empresarios&paso=3&f=${encodeURIComponent('{"vista":"miembros"}')}&c=nombre,nit,rol`);
        render(<ReportesIndex {...PROPS} />);
        await waitFor(() => expect(query().get('c')).toBe('nombre,rol'));
        expect(router.replace).toHaveBeenCalledTimes(1);
        expect(router.push).not.toHaveBeenCalled();
    });

    it('O-3: valid columns leave the URL alone', () => {
        const url = '/reportes?fuente=compromisos&paso=3&c=descripcion,estado&o=fecha_limite:asc';
        setUrl(url);
        render(<ReportesIndex {...PROPS} />);
        expect(router.replace).not.toHaveBeenCalled();
        expect(store.url).toBe(url);
    });
});
