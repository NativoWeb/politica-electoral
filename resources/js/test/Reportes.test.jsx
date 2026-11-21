import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act, cleanup, within } from '@testing-library/react';
import { FUENTE_PERSONAS, filasDe, jsonResponse } from './reportesFixtures';

// A tiny Inertia: the page URL lives in a store that router.push/replace update, as the real client visits do.
const { store, setUrl, router } = vi.hoisted(() => {
    const store = { url: '/reportes', props: {}, listeners: new Set() };
    store.snapshot = { url: store.url, props: store.props };
    const setUrl = (url) => { store.url = url; store.snapshot = { url, props: store.props }; store.listeners.forEach(l => l()); };
    const router = {
        push: vi.fn(({ url }) => setUrl(url)),
        replace: vi.fn(({ url }) => setUrl(url)),
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
    default: ({ personId, onClose, onSaved }) => (
        <div role="dialog" aria-label="Ficha mapa">
            <p>Ficha de {personId}</p>
            <button type="button" onClick={() => onSaved?.()}>Guardar ficha</button>
            <button type="button" onClick={onClose}>Cerrar ficha</button>
        </div>
    ),
}));
vi.mock('@/Components/VotanteDetail', () => ({
    default: ({ data, onClose }) => (
        <div>
            <p>Votante {data?.nombre}</p>
            <button type="button" onClick={onClose}>Cerrar votante</button>
        </div>
    ),
}));

import ReportesIndex from '@/Pages/Reportes/Index';
import ChipGroup from '@/Components/Reportes/ChipGroup';
import BarraConteo from '@/Components/Reportes/BarraConteo';
import PasoColumnas, { AVISO_PERMISO } from '@/Components/Reportes/PasoColumnas';
import BotonDescarga from '@/Components/Reportes/BotonDescarga';
import {
    leerEstado, limpiarColumnas, mensajeError, nombreArchivo, normalizarFuente, specDe, textoExcluidos, urlDeEstado, validarRango,
} from '@/lib/reportes';

const FUENTE = normalizarFuente(FUENTE_PERSONAS);
const PAGE_PROPS = { fuentes: [FUENTE_PERSONAS], puedeDatosPersonales: false, topes: { excel: 50000, pdf: 500 } };

function asUser(permissions) {
    store.props = { auth: { user: { name: 'Ana', permissions } } };
    store.snapshot = { url: store.url, props: store.props };
}

function calls(fragment) {
    return globalThis.fetch.mock.calls.filter(([url]) => String(url).includes(fragment));
}
const bodyOf = (call) => JSON.parse(call[1].body);

const original = globalThis.fetch;
beforeEach(() => {
    setUrl('/reportes');
    asUser(['view.reportes', 'view.mapa', 'view.votantes']);
    router.push.mockClear();
    router.replace.mockClear();
});
afterEach(() => { cleanup(); globalThis.fetch = original; vi.useRealTimers(); });

describe('lib/reportes: the one adapter to the contract', () => {
    it('reads the backend shape: sections, single chips, on/off and ranges', () => {
        expect(FUENTE.secciones.map(s => s.titulo)).toEqual(['¿Dónde viven?', '¿Qué son?', 'Edad y datos personales', 'Autorización y estado']);
        const tipo = (k) => FUENTE.filtros.find(f => f.clave === k);
        expect(tipo('estado').tipo).toBe('unico');
        expect(tipo('estado').porDefecto).toBe('activas');
        expect(tipo('incluir_fallecidos').tipo).toBe('interruptor');
        expect(tipo('barrio').remoto).toBe(true);
        expect(tipo('barrio').opcionesDe).toBe('barrios');
        expect(tipo('edad').rangos[1]).toEqual({ etiqueta: 'Más de 60', desde: 61, hasta: null });
        expect(FUENTE.atajos[0].filtros).toEqual({ marcas: ['gran_elector'] });
    });

    it('keeps the type of each value: the server only takes eventos_min as a number', () => {
        const estado = leerEstado(`/reportes?fuente=personas&paso=4&f=${encodeURIComponent('{"eventos_min":1,"estado":"activas"}')}`, [FUENTE], false);
        expect(estado.filtros).toEqual({ eventos_min: 1 });
        expect(leerEstado(`/reportes?fuente=personas&paso=4&f=${encodeURIComponent('{"eventos_min":"2"}')}`, [FUENTE], false).filtros).toEqual({ eventos_min: 2 });
        expect(FUENTE.ordenes.map(o => o.valor)).toEqual(['nombre:asc', 'municipio:asc']);
    });

    it('offers the comunas only of the chosen municipio', () => {
        expect(FUENTE.filtros.find(f => f.clave === 'comuna').dependeDe).toBe('municipio');
        expect(FUENTE.filtros.find(f => f.clave === 'municipio').dependeDe).toBeNull();
    });

    it('keeps the whole state in the URL and reads it back the same', () => {
        const estado = {
            ...leerEstado('/reportes', [FUENTE], true),
            fuente: FUENTE, paso: 3,
            filtros: { municipio: ['m-bga'], edad: { desde: 40, hasta: null }, estado: 'todas' },
            columnas: ['nombre', 'telefono'], orden: 'municipio:asc', busqueda: 'ana', pagina: 2,
        };
        const back = leerEstado(urlDeEstado(estado), [FUENTE], true);
        expect(back.paso).toBe(3);
        expect(back.filtros).toEqual(estado.filtros);
        expect(back.columnas).toEqual(['nombre', 'telefono']);
        expect(specDe(back)).toEqual({
            v: 1, fuente: 'personas', filtros: estado.filtros, columnas: ['nombre', 'telefono'],
            orden: { campo: 'municipio', dir: 'asc' }, busqueda: 'ana',
        });
    });

    it('drops what the URL cannot hold: unknown filters, values outside the list, the default, a broken JSON', () => {
        const f = encodeURIComponent(JSON.stringify({ sql: '1=1', campaign_id: 'x', marcas: ['gran_elector', 'hack'], estado: 'activas' }));
        const estado = leerEstado(`/reportes?fuente=personas&paso=2&f=${f}`, [FUENTE], false);
        expect(estado.filtros).toEqual({ marcas: ['gran_elector'] });
        expect(leerEstado('/reportes?fuente=personas&paso=2&f=%7Bmal', [FUENTE], false).filtros).toEqual({});
        expect(leerEstado('/reportes?fuente=otra&paso=4', [FUENTE], false).paso).toBe(1);
    });

    it('never asks for a sensitive column without the permission (the API would answer 403)', () => {
        expect(limpiarColumnas(['cedula', 'telefono', 'municipio'], FUENTE, false)).toEqual(['nombre', 'municipio']);
        expect(limpiarColumnas(['cedula', 'municipio'], FUENTE, true)).toEqual(['nombre', 'cedula', 'municipio']);
        // Default without the permission: the first preset with no locked column ("Solo nombre y lugar").
        expect(leerEstado('/reportes?fuente=personas&paso=3', [FUENTE], false).columnas).toEqual(['nombre', 'municipio', 'barrio', 'tipo']);
    });

    it('says the plan words for each download failure', () => {
        expect(mensajeError({ status: 429, data: { motivo: 'en_curso' } }, 'descarga')).toBe('Ya estamos preparando una descarga. Espera a que termine.');
        expect(mensajeError({ status: 429, data: {} }, 'descarga')).toBe('Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.');
        expect(mensajeError({ status: 403 }, 'descarga')).toBe('No tienes permiso para descargar estos datos.');
        expect(mensajeError({ status: 0 }, 'descarga')).toBe('No se pudo descargar. Revisa tu conexión e intenta de nuevo.');
        expect(mensajeError({ status: 0 }, 'conteo')).toBe('No pudimos contar. Revisa tu conexión.');
        expect(mensajeError({ status: 0 }, 'descarga', 'pdf')).toBe('No se pudo descargar. Revisa tu conexión e intenta de nuevo.');
        expect(mensajeError({ status: 500 }, 'descarga', 'pdf')).toBe('No pudimos preparar el archivo. Prueba con menos filas o usa el Excel.');
        expect(mensajeError({ status: 503 }, 'descarga', 'pdf')).toBe('No pudimos preparar el archivo. Prueba con menos filas o usa el Excel.');
        expect(mensajeError({ status: 500 }, 'descarga', 'excel')).toBe('No pudimos preparar el archivo. Prueba con menos filas.');
        expect(mensajeError({ status: 504 }, 'descarga')).toBe('No pudimos preparar el archivo. Prueba con menos filas.');
        expect(nombreArchivo("attachment; filename*=UTF-8''Reporte_Gran_elector_2026-11-20.xlsx", 'x.xlsx')).toBe('Reporte_Gran_elector_2026-11-20.xlsx');
    });
});

describe('QA B-1 fixes', () => {
    it('BUG-05: an age range is only applied when both boxes are whole years 0-120 and in order', () => {
        expect(validarRango('30', '')).toEqual({ rango: { desde: 30, hasta: null } });
        expect(validarRango('30.5', '')).toEqual({ error: 'Escribe años enteros, por ejemplo 30.' });
        expect(validarRango('30', '20')).toEqual({ error: 'El primer número debe ser menor que el segundo.' });
        expect(validarRango('', '121')).toEqual({ error: 'Escribe una edad entre 0 y 120.' });
        // A range that came in the URL in the wrong order is not applied at all.
        const f = encodeURIComponent('{"edad":{"desde":60,"hasta":20}}');
        expect(leerEstado(`/reportes?fuente=personas&paso=2&f=${f}`, [FUENTE], false).filtros).toEqual({});
    });

    it('BUG-04: says who is left out while Estado and Incluir fallecidos keep their default', () => {
        expect(textoExcluidos({}, FUENTE)).toBe('No incluye fallecidos ni personas inactivas.');
        expect(textoExcluidos({ estado: 'todas' }, FUENTE)).toBe('No incluye fallecidos.');
        expect(textoExcluidos({ incluir_fallecidos: true }, FUENTE)).toBe('No incluye personas inactivas.');
        expect(textoExcluidos({ estado: 'todas', incluir_fallecidos: true }, FUENTE)).toBe('');
    });

    it('BUG-09: a search that looks like a cédula or a phone never goes to the URL', () => {
        const base = { ...leerEstado('/reportes?fuente=personas&paso=4', [FUENTE], false), paso: 4 };
        expect(urlDeEstado({ ...base, busqueda: '1098765432' })).not.toContain('q=');
        expect(urlDeEstado({ ...base, busqueda: '317 555 1234' })).not.toContain('q=');
        expect(urlDeEstado({ ...base, busqueda: 'Ana' })).toContain('q=Ana');
    });
});

describe('ChipGroup', () => {
    it('marks each chip with aria-pressed and adds or removes values', () => {
        const onCambiar = vi.fn();
        const opciones = [{ valor: 'a', etiqueta: 'Gran elector' }, { valor: 'b', etiqueta: 'Militante' }];
        const { rerender } = render(<ChipGroup etiqueta="Marcas" opciones={opciones} valor={['a']} onCambiar={onCambiar} />);
        expect(screen.getByRole('button', { name: 'Gran elector' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Militante' })).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(screen.getByRole('button', { name: 'Militante' }));
        expect(onCambiar).toHaveBeenLastCalledWith(['a', 'b']);
        fireEvent.click(screen.getByRole('button', { name: 'Gran elector' }));
        expect(onCambiar).toHaveBeenLastCalledWith([]);

        // A single choice shows its default on when nothing is chosen; tapping the default clears the filter.
        const estado = [{ valor: 'activas', etiqueta: 'Activas' }, { valor: 'todas', etiqueta: 'Todas' }];
        rerender(<ChipGroup etiqueta="Estado" opciones={estado} valor={null} multiple={false} porDefecto="activas" onCambiar={onCambiar} />);
        expect(screen.getByRole('button', { name: 'Activas' })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: 'Todas' }));
        expect(onCambiar).toHaveBeenLastCalledWith('todas');
    });
});

describe('BarraConteo', () => {
    const siguiente = { texto: 'Siguiente: elegir datos', onClick: vi.fn() };

    it('dims the previous number while counting and announces it politely', () => {
        render(<BarraConteo estado="cargando" total={12084} siguiente={siguiente} />);
        const numero = screen.getByText('12.084');
        expect(numero.className).toContain('opacity-50');
        expect(screen.getByText('Contando…')).toBeInTheDocument();
        expect(numero.closest('[aria-live="polite"]')).toBeTruthy();
    });

    it('shows the number when done', () => {
        render(<BarraConteo estado="ok" total={1} siguiente={siguiente} />);
        expect(screen.getByText('1').className).not.toContain('opacity-50');
        expect(screen.getByText('persona')).toBeInTheDocument();
        expect(screen.queryByText('Contando…')).not.toBeInTheDocument();
    });

    it('on error says so and offers to try again', () => {
        const onReintentar = vi.fn();
        render(<BarraConteo estado="error" total={5} mensajeError="No pudimos contar. Revisa tu conexión." onReintentar={onReintentar} siguiente={siguiente} />);
        expect(screen.getByText('No pudimos contar. Revisa tu conexión.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Intentar de nuevo' }));
        expect(onReintentar).toHaveBeenCalledTimes(1);
    });

    it('with nobody left offers to remove the last filter and blocks going on', () => {
        const onQuitarUltimo = vi.fn();
        render(<BarraConteo estado="ok" total={0} ultimoFiltro={{ texto: 'Bucaramanga' }} onQuitarUltimo={onQuitarUltimo} siguiente={siguiente} />);
        expect(screen.getByText('Nadie cumple todos estos filtros. Prueba quitando uno.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Quitar «Bucaramanga»' }));
        expect(onQuitarUltimo).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: /Siguiente: elegir datos/ })).toBeDisabled();
    });
});

describe('PasoColumnas', () => {
    it('locks the sensitive columns without the permission and explains why', () => {
        const onColumnas = vi.fn();
        render(<PasoColumnas fuente={FUENTE} columnas={['nombre', 'municipio']} puedeDatosPersonales={false} onColumnas={onColumnas} />);
        const cedula = screen.getByRole('checkbox', { name: /Cédula/ });
        expect(cedula).toBeDisabled();
        expect(cedula).not.toBeChecked();
        expect(cedula).toHaveAccessibleDescription(AVISO_PERMISO);
        expect(screen.getByRole('checkbox', { name: /Nombre/ })).toBeDisabled();
        expect(screen.getByRole('checkbox', { name: /Nombre/ })).toBeChecked();

        fireEvent.click(screen.getByRole('checkbox', { name: /Barrio/ }));
        expect(onColumnas).toHaveBeenLastCalledWith(['nombre', 'municipio', 'barrio']);

        // "Contacto básico" without the permission leaves the locked ones out.
        fireEvent.click(screen.getByRole('button', { name: 'Contacto básico' }));
        expect(onColumnas).toHaveBeenLastCalledWith(['nombre', 'municipio', 'barrio']);
    });

    it('lets choose them with the permission', () => {
        const onColumnas = vi.fn();
        render(<PasoColumnas fuente={FUENTE} columnas={['nombre']} puedeDatosPersonales onColumnas={onColumnas} />);
        expect(screen.getByRole('checkbox', { name: /Cédula/ })).toBeEnabled();
        expect(screen.queryByText(AVISO_PERMISO)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('checkbox', { name: /Cédula/ }));
        expect(onColumnas).toHaveBeenLastCalledWith(['nombre', 'cedula']);
    });
});

describe('BotonDescarga', () => {
    const spec = { v: 1, fuente: 'personas', filtros: {}, columnas: ['nombre'], orden: { campo: 'nombre', dir: 'asc' }, busqueda: '' };
    const chips = [{ id: 'm', texto: 'Bucaramanga' }];
    let resolver;

    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        globalThis.fetch = vi.fn(() => new Promise(r => { resolver = r; }));
    });

    it('a double click sends one request and the button stays disabled while preparing', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} chips={chips} />);
        const boton = screen.getByRole('button', { name: /Descargar Excel/ });
        fireEvent.click(boton);
        fireEvent.click(boton);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: /Preparando tu archivo…/ })).toBeDisabled();
        expect(bodyOf(globalThis.fetch.mock.calls[0])).toEqual({ spec });

        await act(async () => {
            resolver(new Response(new Blob(['x']), { status: 200, headers: {
                'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition': 'attachment; filename="Reporte_Bucaramanga_2026-11-20.xlsx"',
            } }));
        });
        expect(await screen.findByText('Listo. Se descargó «Reporte_Bucaramanga_2026-11-20.xlsx».')).toBeInTheDocument();
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeEnabled();
    });

    it('shows the throttle and lock refusals in plain words', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        await act(async () => { resolver(new Response(JSON.stringify({ success: false, message: 'Ya hay una descarga en curso.' }), { status: 429, headers: { 'Content-Type': 'application/json' } })); });
        expect(await screen.findByRole('alert')).toHaveTextContent('Ya estamos preparando una descarga. Espera a que termine.');

        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        await act(async () => { resolver(new Response(JSON.stringify({ success: false, message: 'No.' }), { status: 403, headers: { 'Content-Type': 'application/json' } })); });
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No tienes permiso para descargar estos datos.'));
    });

    it('above the cap asks first; Volver downloads nothing', () => {
        render(<BotonDescarga spec={spec} total={62300} tope={50000} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        const dialogo = screen.getByRole('dialog');
        expect(dialogo).toHaveTextContent('Tu lista tiene 62.300 personas. El Excel trae las primeras 50.000. Para tener el resto, divide la lista por municipio.');
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Volver' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Descargar las primeras 50.000' }));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    const pdfOk = () => new Response(new Blob(['%PDF']), { status: 200, headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': 'attachment; filename="Reporte_Bucaramanga_2026-11-20.pdf"',
        'X-Reporte-Filas': '10', 'X-Reporte-Total': '10', 'X-Reporte-Recortado': '0',
    } });
    const OCHO = ['Nombre', 'Municipio', 'Barrio', 'Tipo', 'Edad', 'Género', 'Partido', 'Cargo'];

    it('Descargar PDF posts the spec to the pdf endpoint and names the .pdf file', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, opciones] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/reportes/personas/pdf');
        expect(opciones.method).toBe('POST');
        expect(new Headers(opciones.headers).get('Accept')).toContain('application/pdf');
        expect(bodyOf(globalThis.fetch.mock.calls[0])).toEqual({ spec });

        await act(async () => { resolver(pdfOk()); });
        expect(await screen.findByText('Listo. Se descargó «Reporte_Bucaramanga_2026-11-20.pdf».')).toBeInTheDocument();
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
    });

    it('without Content-Disposition the PDF falls back to a .pdf name', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        await act(async () => { resolver(new Response(new Blob(['%PDF']), { status: 200, headers: { 'Content-Type': 'application/pdf' } })); });
        expect(await screen.findByText(/^Listo\. Se descargó «Reporte_Bucaramanga_\d{4}-\d{2}-\d{2}\.pdf»\.$/)).toBeInTheDocument();
    });

    it('above the PDF cap asks first, with the PDF, Excel and Volver choices', () => {
        render(<BotonDescarga spec={spec} total={1646} tope={50000} topePdf={500} columnas={['Nombre', 'Municipio']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        const dialogo = screen.getByRole('dialog');
        expect(dialogo).toHaveTextContent('Tu lista tiene 1.646 personas. El PDF trae las primeras 500. Para tenerlas todas, descarga el Excel.');
        expect(dialogo).not.toHaveTextContent('datos por persona');
        expect(globalThis.fetch).not.toHaveBeenCalled();

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Volver' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Descargar PDF con las primeras 500' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.map(c => c[0])).toEqual(['/reportes/personas/pdf']);
    });

    it('with more than 8 columns says which 8 go in the PDF; Descargar Excel downloads the Excel', () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={[...OCHO, 'Teléfono']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        const dialogo = screen.getByRole('dialog');
        expect(dialogo).toHaveTextContent(`El PDF solo cabe con 8 datos por persona. Saldrán: ${OCHO.join(', ')}. Para ver todos, usa el Excel.`);
        expect(dialogo).not.toHaveTextContent('Teléfono');
        expect(dialogo).not.toHaveTextContent('Tu lista tiene');
        expect(within(dialogo).getByRole('button', { name: 'Descargar PDF' })).toBeInTheDocument();

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Descargar Excel' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.map(c => c[0])).toEqual(['/reportes/personas/excel']);
    });

    it('exactly 8 columns and a list at the cap download the PDF without asking', () => {
        render(<BotonDescarga spec={spec} total={500} tope={50000} topePdf={500} columnas={OCHO} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.map(c => c[0])).toEqual(['/reportes/personas/pdf']);
    });

    it('over the cap and over 8 columns shows a single dialog with both notices', () => {
        render(<BotonDescarga spec={spec} total={1646} tope={50000} topePdf={500} columnas={[...OCHO, 'Teléfono']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        const dialogos = screen.getAllByRole('dialog');
        expect(dialogos).toHaveLength(1);
        expect(dialogos[0]).toHaveTextContent('Tu lista tiene 1.646 personas. El PDF trae las primeras 500.');
        expect(dialogos[0]).toHaveTextContent('El PDF solo cabe con 8 datos por persona.');
        fireEvent.click(within(dialogos[0]).getByRole('button', { name: 'Descargar PDF con las primeras 500' }));
        expect(globalThis.fetch.mock.calls.map(c => c[0])).toEqual(['/reportes/personas/pdf']);
    });

    it('the Excel cap still asks when Descargar Excel is chosen from the PDF dialog', () => {
        render(<BotonDescarga spec={spec} total={62300} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Descargar Excel' }));
        const dialogos = screen.getAllByRole('dialog');
        expect(dialogos).toHaveLength(1);
        expect(dialogos[0]).toHaveTextContent('El Excel trae las primeras 50.000.');
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('one download at a time: while the Excel is prepared the PDF button is disabled and sends nothing', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        const pdf = screen.getByRole('button', { name: /Descargar PDF/ });
        expect(pdf).toBeDisabled();
        fireEvent.click(pdf);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getAllByRole('button', { name: /Preparando tu archivo…/ })).toHaveLength(1);

        await act(async () => { resolver(new Response(new Blob(['x']), { status: 200, headers: { 'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } })); });
        await waitFor(() => expect(screen.getByRole('button', { name: /Descargar PDF/ })).toBeEnabled());

        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeDisabled();
        expect(globalThis.fetch.mock.calls.map(c => c[0])).toEqual(['/reportes/personas/excel', '/reportes/personas/pdf']);
    });

    it('a 429 on the PDF says it in plain words and lets the user try again', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        await act(async () => { resolver(new Response(JSON.stringify({ success: false, message: 'Demasiadas.', motivo: 'seguidas' }), { status: 429, headers: { 'Content-Type': 'application/json', 'Retry-After': '60' } })); });
        expect(await screen.findByRole('alert')).toHaveTextContent('Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.');
        expect(screen.getByRole('button', { name: /Descargar PDF/ })).toBeEnabled();
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeEnabled();
    });

    it('a PDF answered as JSON with 200, a 500, a 419 and a network failure are errors, not files', async () => {
        render(<BotonDescarga spec={spec} total={10} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} />);
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        await act(async () => { resolver(new Response(JSON.stringify({ success: false }), { status: 200, headers: { 'Content-Type': 'application/json' } })); });
        expect(await screen.findByRole('alert')).toHaveTextContent('No pudimos preparar el archivo. Prueba con menos filas o usa el Excel.');

        fireEvent.click(screen.getByRole('button', { name: /Descargar Excel/ }));
        await act(async () => { resolver(new Response('<html>Server Error</html>', { status: 500, headers: { 'Content-Type': 'text/html' } })); });
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No pudimos preparar el archivo. Prueba con menos filas.'));
        expect(screen.getByRole('alert')).not.toHaveTextContent('usa el Excel');

        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        await act(async () => { resolver(new Response('', { status: 419 })); });
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('La página estuvo abierta mucho tiempo. Recárgala e intenta de nuevo.'));

        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No se pudo descargar. Revisa tu conexión e intenta de nuevo.'));
        expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled();
    });

    it('an empty list disables both buttons', () => {
        render(<BotonDescarga spec={spec} total={0} tope={50000} topePdf={500} columnas={['Nombre']} chips={chips} vacia />);
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Descargar PDF/ })).toBeDisabled();
    });
});

describe('Reportes page', () => {
    function mockApi({ lista = () => jsonResponse({ success: true, total: 3, pagina: 1, por_pagina: 50, filas: filasDe(3) }), conteo = () => jsonResponse({ success: true, total: 42 }) } = {}) {
        globalThis.fetch = vi.fn((url, options = {}) => {
            if (String(url).includes('/conteo')) return conteo(url, options);
            if (String(url).includes('/lista')) return lista(url, options);
            if (String(url).startsWith('/votantes/')) return jsonResponse({ data: { id: 'p1', nombre: 'Persona 1' } });
            return jsonResponse({}, 404);
        });
    }

    it('a shortcut goes straight to the list with its filters', async () => {
        mockApi();
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Grandes electores/ }));
        const query = new URLSearchParams(store.url.split('?')[1]);
        expect(query.get('paso')).toBe('4');
        expect(JSON.parse(query.get('f'))).toEqual({ marcas: ['gran_elector'] });
        expect(router.push).toHaveBeenCalledTimes(1);
        expect(await screen.findByRole('button', { name: 'Persona 0' })).toBeInTheDocument();
        expect(bodyOf(calls('/reportes/personas/lista')[0]).spec.filtros).toEqual({ marcas: ['gran_elector'] });
    });

    it('step 4 offers the PDF next to the Excel and sends the same spec the list uses', async () => {
        mockApi();
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Grandes electores/ }));
        expect(await screen.findByRole('button', { name: 'Persona 0' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await waitFor(() => expect(calls('/reportes/personas/pdf')).toHaveLength(1));
        expect(bodyOf(calls('/reportes/personas/pdf')[0]).spec).toEqual(bodyOf(calls('/reportes/personas/lista')[0]).spec);
    });

    it('while the list total is still loading both downloads are disabled, so the cap dialog is never skipped', async () => {
        let soltar;
        mockApi({ lista: () => new Promise(r => { soltar = r; }) });
        render(<ReportesIndex {...PAGE_PROPS} topes={{ excel: 50000, pdf: 500 }} />);
        fireEvent.click(screen.getByRole('button', { name: /Grandes electores/ }));
        expect(await screen.findByText('Cargando…')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Descargar Excel/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Descargar PDF/ })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(calls('/pdf')).toHaveLength(0);

        await waitFor(() => expect(soltar).toBeTypeOf('function'), { timeout: 1500 });
        await act(async () => { soltar(await jsonResponse({ success: true, total: 1646, pagina: 1, por_pagina: 50, filas: filasDe(3) })); });
        await waitFor(() => expect(screen.getByRole('button', { name: /Descargar PDF/ })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: /Descargar PDF/ }));
        expect(screen.getByRole('dialog')).toHaveTextContent('Tu lista tiene 1.646 personas. El PDF trae las primeras 500.');
        expect(calls('/pdf')).toHaveLength(0);
    });

    it('the events shortcut sends eventos_min as a number', async () => {
        mockApi();
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Asistentes a eventos/ }));
        await waitFor(() => expect(calls('/lista').length).toBe(1), { timeout: 1500 });
        expect(bodyOf(calls('/lista')[0]).spec.filtros).toEqual({ eventos_min: 1 });
    });

    it('shows Comuna only once its municipio is chosen, and drops it with the municipio', async () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=2');
        render(<ReportesIndex {...PAGE_PROPS} />);
        expect(screen.queryByRole('button', { name: 'Comuna 1 Norte' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Bucaramanga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Comuna 1 Norte' }));
        expect(JSON.parse(new URLSearchParams(store.url.split('?')[1]).get('f'))).toEqual({ municipio: ['m-bga'], comuna: ['c1'] });
        fireEvent.click(screen.getByRole('button', { name: 'Bucaramanga' }));
        expect(new URLSearchParams(store.url.split('?')[1]).get('f')).toBeNull();
    });

    it('counts once per pause (debounce) and aborts the count in flight', async () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=2');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bucaramanga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Girón' }));
        await waitFor(() => expect(calls('/conteo').length).toBe(1), { timeout: 1500 });
        expect(bodyOf(calls('/conteo')[0]).spec.filtros).toEqual({ municipio: ['m-bga', 'm-giron'] });
        expect(await screen.findByText('42')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Bucaramanga' })).toHaveAttribute('aria-pressed', 'true');
        // Filters replace the history entry; they are not new steps.
        expect(router.push).not.toHaveBeenCalled();

        // The filter chips at the top remove a value.
        fireEvent.click(screen.getByRole('button', { name: 'Quitar Girón' }));
        expect(JSON.parse(new URLSearchParams(store.url.split('?')[1]).get('f'))).toEqual({ municipio: ['m-bga'] });
    });

    it('open a ficha from the list and close it: same URL, same page reloaded', async () => {
        mockApi();
        const url = `/reportes?fuente=personas&paso=4&f=${encodeURIComponent('{"marcas":["gran_elector"]}')}&c=nombre,municipio&o=nombre:asc&p=2`;
        setUrl(url);
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Persona 1' }));
        expect(screen.getByRole('dialog', { name: 'Ficha mapa' })).toHaveTextContent('Ficha de p1');

        fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }));
        expect(screen.queryByRole('dialog', { name: 'Ficha mapa' })).not.toBeInTheDocument();
        await waitFor(() => expect(calls('/lista').length).toBe(2));
        expect(store.url).toBe(url);
        const [primera, segunda] = calls('/lista').map(bodyOf);
        expect(segunda).toEqual(primera);
        expect(segunda.pagina).toBe(2);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Persona 1' })).toHaveFocus());
    });

    it('says so when the person no longer fits after closing the ficha', async () => {
        let n = 0;
        mockApi({ lista: () => jsonResponse({ success: true, total: 3 - (n ? 1 : 0), pagina: 1, por_pagina: 50, filas: n++ ? filasDe(3).filter(f => f.id !== 'p1') : filasDe(3) }) });
        setUrl('/reportes?fuente=personas&paso=4');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Persona 1' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }));
        expect(await screen.findByText('Persona 1 ya no cumple los filtros, por eso salió de la lista.')).toBeInTheDocument();
    });

    it('says "Guardado." when the Mapa ficha was saved before the person left the list', async () => {
        let n = 0;
        mockApi({ lista: () => jsonResponse({ success: true, total: 2, pagina: 1, por_pagina: 50, filas: n++ ? filasDe(3).filter(f => f.id !== 'p1') : filasDe(3) }) });
        setUrl('/reportes?fuente=personas&paso=4');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Persona 1' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar ficha' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }));
        expect(await screen.findByText('Guardado. Persona 1 ya no cumple los filtros, por eso salió de la lista.')).toBeInTheDocument();
    });

    it('opens the Votantes ficha for who does not see the Mapa', async () => {
        asUser(['view.reportes', 'view.votantes']);
        mockApi();
        setUrl('/reportes?fuente=personas&paso=4');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Persona 1' }));
        expect(await screen.findByText('Votante Persona 1')).toBeInTheDocument();
        expect(screen.queryByRole('dialog', { name: 'Ficha mapa' })).not.toBeInTheDocument();
    });

    it('list states: error with retry, empty search with "Borrar búsqueda", notice without the permission', async () => {
        let falla = true;
        mockApi({ lista: () => (falla ? Promise.reject(new TypeError('offline')) : jsonResponse({ success: true, total: 0, pagina: 1, por_pagina: 50, filas: [] })) });
        setUrl('/reportes?fuente=personas&paso=4&q=xyz');
        render(<ReportesIndex {...PAGE_PROPS} />);
        expect(await screen.findByText('No pudimos cargar la lista.')).toBeInTheDocument();
        expect(screen.getByText('Tu descarga no incluye cédula, teléfono ni dirección.')).toBeInTheDocument();
        falla = false;
        fireEvent.click(screen.getByRole('button', { name: 'Intentar de nuevo' }));
        expect(await screen.findByText('Nadie con «xyz» en esta lista.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Borrar búsqueda' }));
        expect(new URLSearchParams(store.url.split('?')[1]).get('q')).toBeNull();
    });

    it('BUG-05: "Otra" edad: 30.5 shows the message, keeps what was typed and filters nothing', () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=2');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(screen.getByRole('button', { name: /Edad y datos personales/ }));
        const filtrosDeUrl = () => new URLSearchParams(store.url.split('?')[1]).get('f');
        const desde = screen.getByLabelText('Edad desde');
        fireEvent.change(desde, { target: { value: '30.5' } });
        expect(screen.getByRole('alert')).toHaveTextContent('Escribe años enteros, por ejemplo 30.');
        expect(desde).toHaveValue('30.5');
        expect(filtrosDeUrl()).toBeNull();

        fireEvent.change(desde, { target: { value: '30' } });
        expect(JSON.parse(filtrosDeUrl())).toEqual({ edad: { desde: 30, hasta: null } });
        // A "hasta" below "desde": the error shows and the whole filter goes away (not half of it).
        fireEvent.change(screen.getByLabelText('Edad hasta'), { target: { value: '20' } });
        expect(screen.getByRole('alert')).toHaveTextContent('El primer número debe ser menor que el segundo.');
        expect(filtrosDeUrl()).toBeNull();
        expect(desde).toHaveValue('30');
    });

    it('BUG-04: the count bar and the list say fallecidos and inactivas are left out', () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=2');
        const { unmount } = render(<ReportesIndex {...PAGE_PROPS} />);
        expect(screen.getByText('No incluye fallecidos ni personas inactivas.')).toBeInTheDocument();
        unmount();
        setUrl(`/reportes?fuente=personas&paso=4&f=${encodeURIComponent('{"estado":"todas","incluir_fallecidos":true}')}`);
        render(<ReportesIndex {...PAGE_PROPS} />);
        expect(screen.queryByText(/No incluye/)).not.toBeInTheDocument();
    });

    it('BUG-02: changing page is a history entry; sorting is not', async () => {
        mockApi({ lista: () => jsonResponse({ success: true, total: 120, pagina: 1, por_pagina: 50, filas: filasDe(50) }) });
        setUrl('/reportes?fuente=personas&paso=4');
        Element.prototype.scrollIntoView = vi.fn();
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: /Siguientes/ }));
        expect(router.push).toHaveBeenCalledTimes(1);
        expect(new URLSearchParams(store.url.split('?')[1]).get('p')).toBe('2');
        fireEvent.change(screen.getByLabelText('Ordenar por'), { target: { value: 'municipio:asc' } });
        expect(router.push).toHaveBeenCalledTimes(1);
        expect(router.replace).toHaveBeenCalled();
    });

    it('BUG-09: searching a cédula filters the list but leaves the URL without it', async () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=4');
        render(<ReportesIndex {...PAGE_PROPS} />);
        await screen.findByRole('button', { name: 'Persona 1' });
        fireEvent.change(screen.getByLabelText('Buscar en esta lista'), { target: { value: '1098765432' } });
        expect(store.url).not.toContain('1098765432');
        await waitFor(() => expect(calls('/lista').some(c => bodyOf(c).spec.busqueda === '1098765432')).toBe(true), { timeout: 1500 });
        expect(screen.getByLabelText('Buscar en esta lista')).toHaveValue('1098765432');
    });

    it('BUG-01: "Guardado." also when the saved person stays in the list', async () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=4');
        render(<ReportesIndex {...PAGE_PROPS} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Persona 1' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar ficha' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar ficha' }));
        expect(await screen.findByText('Guardado.')).toBeInTheDocument();
    });

    it('tells who has no source what to ask for', () => {
        render(<ReportesIndex {...PAGE_PROPS} fuentes={[]} />);
        expect(screen.getByText('Todavía no tienes listas para armar')).toBeInTheDocument();
    });

    it('never puts the focus in a search box by itself', () => {
        mockApi();
        setUrl('/reportes?fuente=personas&paso=2');
        render(<ReportesIndex {...PAGE_PROPS} />);
        expect(document.activeElement?.tagName).not.toBe('INPUT');
        expect(document.querySelector('input[autofocus]')).toBeNull();
    });
});
