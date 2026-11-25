// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, cleanup, act } from '@testing-library/react';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, prefetch: _p, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => ({ props: {}, url: '/' }),
    router: { on: vi.fn(() => () => {}), visit: vi.fn() },
}));
// Leaflet draws SVG paths jsdom cannot measure: each polygon becomes a button that carries its paint and tooltip.
vi.mock('react-leaflet', () => ({
    MapContainer: ({ children }) => <div data-testid="mapa">{children}</div>,
    useMap: () => ({ fitBounds: () => {} }),
    GeoJSON: ({ data, style, onEachFeature }) => (
        <div>
            {data.features.map(f => {
                const manejadores = {};
                const capa = { tip: '', tipHtml: '', bindTooltip(t) { capa.tip = typeof t === 'string' ? t : t.textContent; capa.tipHtml = typeof t === 'string' ? t : t.innerHTML; }, on(h) { Object.assign(manejadores, h); } };
                onEachFeature(f, capa);
                const s = style(f);
                return (
                    <button key={f.id} type="button" data-testid={`poligono-${f.id}`} data-fill={s.fillColor} data-dash={s.dashArray ?? ''} data-tip-html={capa.tipHtml} title={capa.tip} onClick={() => manejadores.click?.()}>
                        {f.properties.nombre}
                    </button>
                );
            })}
        </div>
    ),
}));
vi.mock('recharts', () => {
    const Nada = () => null;
    return {
        ResponsiveContainer: ({ children }) => <div>{children}</div>,
        BarChart: ({ children }) => <div data-testid="grafico">{children}</div>,
        Bar: ({ children }) => <div>{children}</div>,
        CartesianGrid: Nada, LabelList: Nada, Tooltip: Nada, XAxis: Nada, YAxis: Nada,
    };
});

import Tablero from '@/Pages/CampanasElectorales/Tablero';
import Lista from '@/Pages/CampanasElectorales/Lista';
import Detalle from '@/Pages/CampanasElectorales/Detalle';
import { limpiarCacheMapa } from '@/Components/CampanasElectorales/MapaCalor';
import {
    COLORES_TRAMO, COLOR_SIN_META, PATRON_RAYAS, fechaCorta, leyendaDe, normalizarTablero, ordenarTerritorios, pinturaDe, textoFueraDeMeta, fichasDe,
    textoSemana, tramoDe,
} from '@/lib/campanas';
import {
    BGA, C01, GEO_COMUNAS_BGA, GEO_MUNICIPIOS, GIRON, IDS, LEBRIJA, PIEDE as PIEDE_BASE, campanaRaw, detalleTerritorio, tableroData,
} from './tableroFixtures';

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
const geo = (body) => Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/geo+json' } }));
const llamadas = (parte) => globalThis.fetch.mock.calls.filter(([u]) => String(u).includes(parte));

let rutas;
const original = globalThis.fetch;
const originalMatchMedia = window.matchMedia;

function servidor() {
    globalThis.fetch = vi.fn((url) => {
        const u = String(url);
        const r = Object.keys(rutas).find(k => u.includes(k));
        if (!r) return json({ success: false }, 404);
        return rutas[r](u);
    });
}

function porPantalla(movil) {
    window.matchMedia = vi.fn((q) => ({
        matches: movil && /max-width: 1023px|pointer: coarse/.test(q),
        media: q, addEventListener: () => {}, removeEventListener: () => {},
    }));
}

beforeEach(() => {
    limpiarCacheMapa();
    window.matchMedia = originalMatchMedia;
    rutas = {
        'municipios.geojson': () => geo(GEO_MUNICIPIOS),
        'comunas.geojson?municipio=68001': () => geo(GEO_COMUNAS_BGA),
        [`/tablero/territorio/${IDS.giron}`]: () => json({ success: true, data: detalleTerritorio(GIRON) }),
        [`/tablero/territorio/${IDS.lebrija}`]: () => json({ success: true, data: detalleTerritorio(LEBRIJA) }),
        [`/tablero/territorio/${IDS.c01}`]: () => json({ success: true, data: detalleTerritorio(C01) }),
        [`/tablero/territorio/${IDS.bga}`]: () => json({ success: true, data: detalleTerritorio(BGA, { comunas: [IDS.c01, IDS.c02] }) }),
    };
    servidor();
});

afterEach(() => {
    cleanup();
    globalThis.fetch = original;
    window.matchMedia = originalMatchMedia;
    document.getElementById('global-offline-toast')?.remove();
    vi.restoreAllMocks();
});

const dibujar = async () => {
    render(<Tablero {...tableroData()} />);
    await screen.findByTestId(`poligono-${IDS.giron}`);
};

/* ── lib ── */

describe('normalizarTablero (the contract in one place)', () => {
    it('reads the totals counting only territories with a goal, and the ranking as one list', () => {
        const t = normalizarTablero(tableroData());
        expect(t.totales.votantes).toMatchObject({ registrados: 375, conMeta: 270, meta: 300, pct: 0.9, falta: 30, metaCalculada: true });
        expect(t.totales.indice).toBe(68);
        expect(t.ranking).toMatchObject({ mejores: [IDS.lebrija, IDS.giron], porApoyar: [], unaLista: true });
        expect(t.mapa.sinPoligono).toEqual(['Bogota (2 fichas)']);
        expect(t.puedeEditar).toBe(true);
    });

    it('a ranking id the user does not see (another territory) is dropped, and pct is null without a goal', () => {
        const t = normalizarTablero(tableroData({ ranking: { mejores: ['otro', IDS.giron], por_apoyar: [] } }));
        expect(t.ranking.mejores).toEqual([IDS.giron]);
        expect(t.porId[IDS.bga].votantes).toMatchObject({ meta: null, pct: null, falta: null });
    });

    it('tramos: 0–24, 25–49, 50–74, 75–99, 100 or more; null stays null', () => {
        expect([0, 24, 25, 49, 50, 74, 75, 99, 100, 140, null].map(tramoDe)).toEqual([0, 0, 1, 1, 2, 2, 3, 3, 4, 4, null]);
    });

    it('paint: insuficiente wins, no goal is gray, outside the campaign is white', () => {
        const t = normalizarTablero(tableroData());
        expect(pinturaDe(t.porId[IDS.c01], 'indice')).toEqual({ clase: 'insuficiente', tramo: null });
        expect(pinturaDe(t.porId[IDS.bga], 'indice').clase).toBe('sin_meta');
        expect(pinturaDe(t.porId[IDS.lebrija], 'lideres')).toEqual({ clase: 'tramo', tramo: 0 });
        expect(pinturaDe(undefined, 'indice').clase).toBe('fuera');
    });

    it('the legend counts each drawn territory once and always lists every tramo', () => {
        const t = normalizarTablero(tableroData());
        const l = leyendaDe([t.porId[IDS.giron], t.porId[IDS.lebrija], t.porId[IDS.bga], t.porId[IDS.c01]], 'votantes');
        expect(l.map(e => [e.clave, e.cuantos])).toEqual([['t0', 0], ['t1', 0], ['t2', 1], ['t3', 0], ['t4', 1], ['sin_meta', 1], ['insuficiente', 1]]);
    });

    it('«Lo que falta» puts the most missing voters first and the ones without a goal last', () => {
        const t = normalizarTablero(tableroData());
        expect(ordenarTerritorios([t.porId[IDS.bga], t.porId[IDS.lebrija], t.porId[IDS.giron]], 'falta').map(x => x.nombre)).toEqual(['Girón', 'Lebrija', 'Bucaramanga']);
        expect(ordenarTerritorios([t.porId[IDS.giron], t.porId[IDS.lebrija]], 'indice').map(x => x.nombre)).toEqual(['Lebrija', 'Girón']);
    });

    it('a week reads «Semana del 16 nov: 812 fichas nuevas»; a hidden count reads «menos de 5»', () => {
        expect(textoSemana({ inicio: '2026-11-16', nuevos: 812 })).toBe('Semana del 16 nov: 812 fichas nuevas');
        expect(textoSemana({ inicio: '2026-11-16', nuevos: null })).toBe('Semana del 16 nov: menos de 5 fichas nuevas');
        expect(fechaCorta('2025-09-22', true)).toBe('22 sep 2025');
    });
});

/* ── The screen ── */

describe('Tablero de la campaña', () => {
    it('the three cards show the totals: N de M, %, what is missing, and the index', () => {
        render(<Tablero {...tableroData()} />);
        const votantes = screen.getByRole('region', { name: 'Votantes registrados' });
        expect(votantes).toHaveTextContent('270 de 300');
        expect(votantes).toHaveTextContent('90 %');
        expect(votantes).toHaveTextContent('Faltan 30 votantes');
        expect(votantes).toHaveTextContent('En total hay 375, contando los territorios sin meta.');
        const lideres = screen.getByRole('region', { name: 'Líderes' });
        expect(lideres).toHaveTextContent('5 de 14');
        expect(lideres).toHaveTextContent('36 %');
        expect(lideres).toHaveTextContent('Faltan 9 líderes');
        expect(screen.getByRole('region', { name: 'Índice de avance' })).toHaveTextContent('68 de 100');
        expect(screen.getByRole('status')).toHaveTextContent('Actualizado 10:05');
    });

    it('shows every warning the server sends, with its text', () => {
        render(<Tablero {...tableroData()} />);
        const avisos = within(screen.getByRole('list', { name: 'Avisos' })).getAllByRole('listitem');
        expect(avisos.map(a => a.dataset.aviso)).toEqual(['sin_metas', 'sin_lideres', 'cobertura_comuna']);
        expect(avisos[1]).toHaveTextContent('el índice llega como máximo a 60');
    });

    it('a territory name with markup stays plain text in the map tooltip', async () => {
        const malicioso = '<img src=x onerror=alert(1)>';
        const data = tableroData();
        data.territorios = data.territorios.map(t => (t.id === IDS.giron ? { ...t, nombre: malicioso } : t));
        render(<Tablero {...data} />);
        const poligono = await screen.findByTestId(`poligono-${IDS.giron}`);
        expect(poligono).toHaveAttribute('title', `${malicioso}: Índice 50 de 100`);
        expect(poligono.dataset.tipHtml).toContain('&lt;img');
        expect(poligono.dataset.tipHtml).not.toContain('<img');
    });

    it('paints the map by tramo, gray without a goal and white dotted outside the campaign; the legend counts what is drawn', async () => {
        await dibujar();
        expect(screen.getByTestId(`poligono-${IDS.giron}`)).toHaveAttribute('data-fill', COLORES_TRAMO[2]);
        expect(screen.getByTestId(`poligono-${IDS.bga}`)).toHaveAttribute('data-fill', COLOR_SIN_META);
        expect(screen.getByTestId(`poligono-${IDS.flori}`)).toHaveAttribute('data-fill', '#ffffff');
        expect(screen.getByTestId(`poligono-${IDS.flori}`).dataset.dash).not.toBe('');
        expect(screen.getByTestId(`poligono-${IDS.giron}`)).toHaveAttribute('title', 'Girón: Índice 50 de 100');

        const leyenda = screen.getByRole('list', { name: 'Leyenda del mapa' });
        const cuenta = (clave) => Number(leyenda.querySelector(`[data-leyenda="${clave}"] [aria-label]`).textContent);
        expect(cuenta('t2')).toBe(2);
        expect(cuenta('sin_meta')).toBe(2);
        expect(cuenta('fuera')).toBe(1);
        const enCampana = ['t0', 't1', 't2', 't3', 't4', 'sin_meta', 'insuficiente'].reduce((s, c) => s + cuenta(c), 0);
        expect(enCampana + cuenta('fuera')).toBe(GEO_MUNICIPIOS.features.length);

        fireEvent.click(screen.getByRole('button', { name: 'Votantes' }));
        expect(screen.getByTestId(`poligono-${IDS.lebrija}`)).toHaveAttribute('data-fill', COLORES_TRAMO[4]);
        expect(leyenda.querySelector('[data-leyenda="t4"]')).toHaveTextContent('Meta cumplida1');
    });

    it('says which territories have no polygon («Sin mapa: Bogota (2 fichas)») and cites the source', async () => {
        await dibujar();
        expect(screen.getByText('Sin mapa:').parentElement).toHaveTextContent('Sin mapa: Bogota (2 fichas)');
        expect(screen.getByText(/DANE, Marco Geoestadístico Nacional/)).toBeInTheDocument();
    });

    it('«Ver comunas de Bucaramanga» draws its comunas striped (insufficient data) and the table follows', async () => {
        await dibujar();
        fireEvent.click(screen.getByRole('button', { name: 'Ver comunas de Bucaramanga' }));
        const c01 = await screen.findByTestId(`poligono-${IDS.c01}`);
        expect(c01).toHaveAttribute('data-fill', `url(#${PATRON_RAYAS})`);
        expect(document.getElementById(PATRON_RAYAS)).not.toBeNull();
        expect(llamadas('comunas.geojson?municipio=68001')).toHaveLength(1);
        expect(screen.getByRole('list', { name: 'Leyenda del mapa' }).querySelector('[data-leyenda="insuficiente"]')).toHaveTextContent('2');
        expect(screen.getByRole('heading', { name: /Comunas de Bucaramanga \(2\)/ })).toBeInTheDocument();
        expect(screen.getByText(/Área Metropolitana de Bucaramanga/)).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: '← Volver a los municipios' })[0]);
        await screen.findByTestId(`poligono-${IDS.giron}`);
        expect(llamadas('municipios.geojson')).toHaveLength(1);
    });

    it('the same detail opens from the map, the ranking and the table', async () => {
        await dibujar();
        const abrirYLeer = async (accion) => {
            accion();
            const d = await screen.findByRole('dialog', { name: 'Girón' });
            await within(d).findByRole('table');
            const texto = d.textContent;
            fireEvent.click(within(d).getByRole('button', { name: 'Cerrar', exact: true }));
            await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
            return texto;
        };
        const desdeMapa = await abrirYLeer(() => fireEvent.click(screen.getByTestId(`poligono-${IDS.giron}`)));
        const ranking = screen.getByRole('region', { name: /Cómo va cada territorio/ });
        const desdeRanking = await abrirYLeer(() => fireEvent.click(within(ranking).getByRole('button', { name: /Girón/ })));
        const tabla = screen.getByRole('table', { name: 'Todos los territorios' });
        const desdeTabla = await abrirYLeer(() => fireEvent.click(within(tabla).getByRole('button', { name: 'Girón' })));
        expect(desdeMapa).toBe(desdeRanking);
        expect(desdeRanking).toBe(desdeTabla);
        expect(desdeMapa).toContain('50 de 100');
        expect(desdeMapa).toContain('Faltan 50 votantes');
        expect(llamadas(`/tablero/territorio/${IDS.giron}`)).toHaveLength(3);
    });

    it('the detail of a comuna says its reason, hides 1–4 por puesto and has too few fichas for weeks', async () => {
        await dibujar();
        fireEvent.click(screen.getByRole('button', { name: 'Ver comunas de Bucaramanga' }));
        fireEvent.click(await screen.findByTestId(`poligono-${IDS.c01}`));
        const d = await screen.findByRole('dialog', { name: 'Comuna 1 - Norte' });
        expect(d).toHaveTextContent('Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna');
        expect(d).toHaveTextContent('Incluye menos de 5 por puesto de votación.');
        expect(await within(d).findByText('Muy pocos registros para ver la evolución')).toBeInTheDocument();
        expect(d).toHaveTextContent('Estado: Pocas fichas con comuna');
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('from a municipio with comunas the detail offers «Ver comunas»', async () => {
        await dibujar();
        fireEvent.click(screen.getByTestId(`poligono-${IDS.bga}`));
        const d = await screen.findByRole('dialog', { name: 'Bucaramanga' });
        fireEvent.click(within(d).getByRole('button', { name: 'Ver comunas' }));
        expect(await screen.findByTestId(`poligono-${IDS.c01}`)).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('a GeoJSON error says so, keeps the table, and «Reintentar» draws the map', async () => {
        let falla = true;
        rutas['municipios.geojson'] = () => (falla ? json({ message: 'Server Error' }, 500) : geo(GEO_MUNICIPIOS));
        render(<Tablero {...tableroData()} />);
        const alerta = await screen.findByText('No pudimos cargar el mapa. La tabla de abajo tiene los mismos datos.');
        expect(alerta.closest('[role="alert"]')).not.toBeNull();
        expect(screen.getByRole('table', { name: 'Todos los territorios' })).toBeInTheDocument();
        expect(screen.queryByTestId(`poligono-${IDS.giron}`)).toBeNull();
        falla = false;
        fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(await screen.findByTestId(`poligono-${IDS.giron}`)).toBeInTheDocument();
        expect(llamadas('municipios.geojson')).toHaveLength(2);
    });

    it('a campaign of one territory has no map, only the sentence', () => {
        render(<Tablero {...tableroData({ mapa: { capa: null, con_comunas: [], sin_poligono: [], texto: 'Esta campaña es de un solo territorio: mira el avance arriba.' } })} />);
        expect(screen.getByText('Esta campaña es de un solo territorio: mira el avance arriba.')).toBeInTheDocument();
        expect(screen.queryByTestId('mapa')).toBeNull();
        expect(llamadas('geojson')).toHaveLength(0);
    });

    it('the table sorts by what is missing, index or name', () => {
        render(<Tablero {...tableroData()} />);
        const tabla = screen.getByRole('table', { name: 'Todos los territorios' });
        const nombres = () => within(tabla).getAllByRole('rowheader').map(r => r.textContent);
        expect(nombres()).toEqual(['Girón', 'Lebrija', 'Bogota', 'Bucaramanga', 'Piedecuesta']);
        const ordenar = screen.getByRole('group', { name: 'Ordenar:' });
        fireEvent.click(within(ordenar).getByRole('button', { name: 'Índice' }));
        expect(nombres().slice(0, 2)).toEqual(['Lebrija', 'Girón']);
        fireEvent.click(within(ordenar).getByRole('button', { name: 'Nombre' }));
        expect(nombres()).toEqual(['Bogota', 'Bucaramanga', 'Girón', 'Lebrija', 'Piedecuesta']);
    });

    it('a long list starts with 20 rows in the chosen order and one tap shows them all', () => {
        const muchos = Array.from({ length: 25 }, (_, i) => ({ ...PIEDE_BASE, id: `m${i}`, nombre: `Municipio ${String(i).padStart(2, '0')}` }));
        render(<Tablero {...tableroData({ territorios: muchos, ranking: { mejores: [], por_apoyar: [], una_lista: true }, mapa: { capa: null, con_comunas: [], sin_poligono: [] } })} />);
        const tabla = screen.getByRole('table', { name: 'Todos los territorios' });
        expect(within(tabla).getAllByRole('rowheader')).toHaveLength(20);
        expect(screen.getByRole('heading', { name: 'Todos los territorios (25)' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Ver los 25 territorios' }));
        expect(within(tabla).getAllByRole('rowheader')).toHaveLength(25);
        expect(screen.queryByRole('button', { name: /Ver los 25/ })).toBeNull();
    });

    it('the weekly chart has its numbers as text: weeks, bulk-load mark, before the system, and the pace', () => {
        render(<Tablero {...tableroData()} />);
        const s = screen.getByRole('region', { name: 'Fichas nuevas por semana' });
        expect(s).toHaveTextContent('Faltan 49 semanas para el 31 oct 2027: hay que registrar 1 por semana. Las últimas 4 semanas: 150 por semana.');
        expect(s).toHaveTextContent('Incluye un día con más de 300 registros (posible carga de archivo)');
        expect(s).toHaveTextContent('90.394 ya estaban registrados antes del 22 sep 2025');
        const filas = within(within(s).getByRole('table')).getAllByRole('row').slice(1).map(r => r.textContent);
        expect(filas).toEqual(['2 nov 2026Incluye un día con más de 300 registros (posible carga de archivo)812', '9 nov 2026120', '16 nov 20261']);
    });

    it('without goals: the empty state, and «Ir a las metas» only for who can edit', () => {
        const vacio = {
            totales: {
                votantes: { registrados: 375, registrados_con_meta: 0, meta: null, meta_calculada: false, pct: null, falta: null },
                lideres: { registrados: 0, registrados_con_meta: 0, meta: null, meta_calculada: false, pct: null, falta: null },
                indice: null, territorios: { total: 5, con_meta: 0, sin_meta: 5, insuficientes: 0 },
            },
            avisos: [], ranking: { mejores: [], por_apoyar: [], una_lista: true },
        };
        const { unmount } = render(<Tablero {...tableroData({ ...vacio, can: { edit: true } })} />);
        expect(screen.getByText('Esta campaña aún no tiene metas. Escríbelas en la campaña para ver el avance.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Ir a las metas' })).toHaveAttribute('href', `/campanas-electorales/${IDS.campana}`);
        expect(screen.queryByTestId('mapa')).toBeNull();
        expect(screen.queryByText('Descargar Excel')).toBeNull();
        unmount();
        render(<Tablero {...tableroData({ ...vacio, can: { edit: false } })} />);
        expect(screen.getByText('Esta campaña aún no tiene metas. Escríbelas en la campaña para ver el avance.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Ir a las metas' })).toBeNull();
    });

    it('a download is a GET to the export URL, and a second tap while it is prepared sends nothing', async () => {
        let soltar;
        rutas['/tablero/exportar.xlsx'] = () => new Promise(r => { soltar = () => r(new Response(JSON.stringify({ message: 'Pediste varias descargas seguidas. Espera un minuto.' }), { status: 429, headers: { 'Content-Type': 'application/json' } })); });
        render(<Tablero {...tableroData()} />);
        const enlace = screen.getByRole('link', { name: 'Descargar Excel' });
        expect(enlace).toHaveAttribute('href', `/campanas-electorales/${IDS.campana}/tablero/exportar.xlsx`);
        expect(screen.getByRole('link', { name: 'Descargar PDF' })).toHaveAttribute('href', `/campanas-electorales/${IDS.campana}/tablero/exportar.pdf`);
        fireEvent.click(enlace);
        await waitFor(() => expect(enlace).toHaveAttribute('aria-busy', 'true'));
        fireEvent.click(enlace);
        expect(llamadas('/tablero/exportar.xlsx')).toHaveLength(1);
        expect(llamadas('/tablero/exportar.xlsx')[0][1]?.method ?? 'GET').toBe('GET');
        await act(async () => { soltar(); });
        await waitFor(() => expect(document.getElementById('global-offline-toast')).toHaveTextContent('Pediste varias descargas seguidas. Espera un minuto.'));
        expect(enlace).not.toHaveAttribute('aria-busy');
    });

    it('«Actualizar» asks again with ?actualizar=1 and replaces the numbers; a 429 is said in words', async () => {
        const nuevo = tableroData();
        nuevo.totales.votantes = { ...nuevo.totales.votantes, registrados_con_meta: 280, pct: 0.9333, falta: 20 };
        rutas['/tablero?actualizar=1'] = () => json({ success: true, data: nuevo });
        render(<Tablero {...tableroData()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Actualizar' }));
        await waitFor(() => expect(screen.getByRole('region', { name: 'Votantes registrados' })).toHaveTextContent('280 de 300'));
        expect(llamadas('/tablero?actualizar=1')).toHaveLength(1);

        rutas['/tablero?actualizar=1'] = () => json({ success: false, message: 'Actualizaste el tablero varias veces seguidas. Espera un minuto y vuelve a intentarlo.' }, 429);
        fireEvent.click(screen.getByRole('button', { name: 'Actualizar' }));
        expect(await screen.findByText('Actualizaste el tablero varias veces seguidas. Espera un minuto y vuelve a intentarlo.', { selector: 'p' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Votantes registrados' })).toHaveTextContent('280 de 300');
    });

    it('a territory the server no longer shows (404) says so inside the detail', async () => {
        rutas[`/tablero/territorio/${IDS.giron}`] = () => json({ success: false }, 404);
        await dibujar();
        fireEvent.click(screen.getByTestId(`poligono-${IDS.giron}`));
        const d = await screen.findByRole('dialog', { name: 'Girón' });
        expect(await within(d).findByText('Este territorio ya no está en tu tablero.')).toBeInTheDocument();
        expect(within(d).getByRole('button', { name: 'Reintentar' })).toBeInTheDocument();
    });
});

describe('Correcciones del QA (2026-11-25)', () => {
    it('BUG-01: one is singular: «Falta 1 líder», «1 votante», in the cards, the table and the ranking', () => {
        const uno = { ...GIRON, votantes: { registrados: 99, meta: 100, meta_calculada: false, pct: 0.99, falta: 1 }, lideres: { registrados: 9, meta: 10, meta_calculada: false, pct: 0.9, falta: 1 } };
        const data = tableroData({ territorios: [uno], ranking: { mejores: [IDS.giron], por_apoyar: [], una_lista: true } });
        data.totales.lideres = { registrados: 9, registrados_con_meta: 9, meta: 10, meta_calculada: false, pct: 0.9, falta: 1 };
        render(<Tablero {...data} />);
        expect(screen.getByRole('region', { name: 'Líderes' })).toHaveTextContent('Falta 1 líder');
        expect(screen.getByRole('region', { name: 'Líderes' })).not.toHaveTextContent('líderes');
        const fila = within(screen.getByRole('table', { name: 'Todos los territorios' })).getByRole('rowheader', { name: 'Girón' }).closest('tr');
        expect(fila).toHaveTextContent('1 votante · 1 líder');
        expect(screen.getByRole('region', { name: /Cómo va cada territorio/ })).toHaveTextContent('falta 1 votante');
    });

    it('BUG-07: an expired session says to reload, never «tus cambios siguen en pantalla»', async () => {
        const sesion = 'Tu sesión venció. Recarga la página para volver a entrar.';
        rutas['/tablero?actualizar=1'] = () => json({ message: 'CSRF token mismatch.' }, 419);
        rutas['municipios.geojson'] = () => json({ message: 'Unauthenticated.' }, 401);
        rutas[`/tablero/territorio/${IDS.giron}`] = () => json({ message: 'Unauthenticated.' }, 401);
        render(<Tablero {...tableroData()} />);
        expect(await screen.findByText(sesion, { selector: '[role="alert"] p' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Actualizar' }));
        expect(await screen.findByText(sesion, { selector: 'p[role="alert"]' })).toBeInTheDocument();
        fireEvent.click(within(screen.getByRole('table', { name: 'Todos los territorios' })).getByRole('button', { name: 'Girón' }));
        const d = await screen.findByRole('dialog', { name: 'Girón' });
        expect(await within(d).findByText(sesion)).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent('tus cambios siguen en pantalla');
    });

    it('a municipio with goals in its comunas says what does not count for its goal, with k', async () => {
        const conMetas = { ...BGA, votantes: { registrados: 100, meta: 160, meta_calculada: false, pct: 0.625, falta: 60 }, estado: 'ok', indice: 63, tramo: { indice: 2, votantes: 2, lideres: null }, sin_comuna: 50, otras_comunas: null };
        rutas[`/tablero/territorio/${IDS.bga}`] = () => json({ success: true, data: detalleTerritorio(conMetas) });
        render(<Tablero {...tableroData({ territorios: [GIRON, conMetas, C01] })} />);
        fireEvent.click(within(screen.getByRole('table', { name: 'Todos los territorios' })).getByRole('button', { name: 'Bucaramanga' }));
        const d = await screen.findByRole('dialog', { name: 'Bucaramanga' });
        expect(d).toHaveTextContent('Además hay 50 fichas sin comuna y menos de 5 en comunas sin meta: no cuentan para la meta.');
    });

    it('the sentence leaves out a part that is 0, and says nothing when nothing was sent', () => {
        const t = normalizarTablero(tableroData({ territorios: [{ ...BGA, sin_comuna: 0, otras_comunas: 1 }, GIRON] }));
        expect(textoFueraDeMeta(t.porId[IDS.bga])).toBe('Además hay 1 ficha en comunas sin meta: no cuentan para la meta.');
        expect(textoFueraDeMeta(t.porId[IDS.giron])).toBe('');
        const objeto = normalizarTablero(tableroData({ territorios: [{ ...BGA, sin_comuna: { votantes: 12, lideres: 0 }, otras_comunas: 0 }] }));
        expect(textoFueraDeMeta(objeto.porId[IDS.bga])).toBe('Además hay 12 fichas sin comuna: no cuentan para la meta.');
    });
});

describe('Contrato F5-9 / F5-10', () => {
    it('falta is always the server sum by territory, never meta − registrados (comuna goals)', () => {
        // Meta 60, registrados 80: meta − registrados would be 0, but one comuna is 30 behind and the server says falta 30.
        const bga = { ...BGA, votantes: { registrados: 80, meta: 60, meta_calculada: false, pct: 1.3333, falta: 30 }, estado: 'ok', indice: 50, tramo: { indice: 2, votantes: 4, lideres: null }, sin_comuna: 12, otras_comunas: 3 };
        const data = tableroData({ territorios: [bga] });
        data.totales.votantes = { registrados: 95, registrados_con_meta: 80, meta: 60, meta_calculada: false, pct: 1.3333, falta: 30 };
        const t = normalizarTablero(data);
        expect(t.porId[IDS.bga].votantes.falta).toBe(30);
        expect(t.totales.votantes.falta).toBe(30);
        expect(normalizarTablero(tableroData({ territorios: [{ ...GIRON, votantes: { registrados: 50, meta: 100, pct: 0.5 } }] })).porId[IDS.giron].votantes.falta).toBeNull();
        render(<Tablero {...data} />);
        expect(screen.getByRole('region', { name: 'Votantes registrados' })).toHaveTextContent('Faltan 30 votantes');
        expect(within(screen.getByRole('table', { name: 'Todos los territorios' })).getByRole('rowheader', { name: 'Bucaramanga' }).closest('tr')).toHaveTextContent('30 votantes');
    });

    it('every ficha of a municipio is registrados + sin_comuna + otras_comunas (null when one is hidden)', () => {
        const t = normalizarTablero(tableroData({ territorios: [{ ...BGA, sin_comuna: 12, otras_comunas: 3 }, { ...PIEDE_BASE, sin_comuna: null, otras_comunas: 0 }, GIRON] }));
        expect(fichasDe(t.porId[IDS.bga])).toBe(115);
        expect(fichasDe(t.porId[IDS.piede])).toBeNull();
        expect(fichasDe(t.porId[IDS.giron])).toBe(50);
        const sin = normalizarTablero(tableroData({ territorios: [{ ...BGA, sin_comuna: 12, otras_comunas: 3 }], mapa: { capa: 'municipios', con_comunas: [], sin_poligono: ['Bucaramanga'] } }));
        expect(sin.mapa.sinPoligono).toEqual(['Bucaramanga (115 fichas)']);
    });

    it('with the goal met the pace shows the server text', () => {
        const data = tableroData();
        data.evolucion.ritmo_necesario = { semanas: 49, por_semana: 0, texto: 'La meta de votantes ya se cumplió.' };
        render(<Tablero {...data} />);
        expect(screen.getByRole('region', { name: 'Fichas nuevas por semana' })).toHaveTextContent('La meta de votantes ya se cumplió.');
        expect(screen.queryByText(/hay que registrar 0/)).toBeNull();
    });

    it('Detalle F4: a hidden puesto count (null) reads «incluye menos de 5»; an absent one says nothing', () => {
        const comuna = (id, puesto) => ({ id, nombre: `Comuna ${id}`, tipo: 'comuna', codigo: `68001-${id}`, padre_id: null, meta: null, editable: true, sugerencia: null, sin_sugerencia: null, avance: { votantes: 10, lideres: 0 }, ...(puesto === undefined ? {} : { avance_por_origen: { auto: 1, texto: 0, direccion: 0, puesto, manual: 0 } }) });
        render(<Detalle campana={campanaRaw()} can={{ edit: false, archive: false, reactivate: false, plantilla: false }} soloLectura={null} sugerencias={{ disponible: true }} filas={[comuna('c1', null), comuna('c2', undefined), comuna('c3', 7)]} totales={{}} />);
        expect(screen.getByText('incluye menos de 5 por puesto de votación')).toBeInTheDocument();
        expect(screen.getByText('incluye 7 por puesto de votación')).toBeInTheDocument();
        expect(screen.getAllByText(/por puesto de votación$/)).toHaveLength(2);
    });
});

describe('Tablero en el teléfono', () => {
    it('the table becomes cards, the detail is a bottom sheet that closes swiping down, every button is at least 48 px', async () => {
        porPantalla(true);
        render(<Tablero {...tableroData()} />);
        expect(screen.queryByRole('table', { name: 'Todos los territorios' })).toBeNull();
        const tarjetas = within(screen.getByRole('list', { name: 'Todos los territorios' })).getAllByRole('listitem');
        expect(tarjetas).toHaveLength(5);
        expect(tarjetas[0]).toHaveTextContent('Votantes50 de 100 · 50 %');

        fireEvent.click(within(tarjetas[0]).getByRole('button', { name: 'Girón' }));
        const d = await screen.findByRole('dialog', { name: 'Girón' });
        expect(d).toHaveAttribute('data-forma', 'hoja');
        const asa = d.firstElementChild;
        fireEvent.touchStart(asa, { touches: [{ clientY: 100 }] });
        fireEvent.touchEnd(asa, { changedTouches: [{ clientY: 260 }] });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());

        const chicos = screen.getAllByRole('button').filter(b => !b.dataset.testid && !/min-h-(12|14)|h-12|w-12/.test(b.className));
        expect(chicos.map(b => b.textContent)).toEqual([]);
    });

    it('on a desktop the detail is a side panel', async () => {
        porPantalla(false);
        await dibujar();
        fireEvent.click(screen.getByTestId(`poligono-${IDS.giron}`));
        expect(await screen.findByRole('dialog', { name: 'Girón' })).toHaveAttribute('data-forma', 'panel');
    });
});

describe('Entradas al tablero', () => {
    it('each card of the list has «Ver tablero»', () => {
        render(<Lista campanas={[campanaRaw()]} can={{ manage: true }} filtros={{ archivadas: false }} />);
        expect(screen.getByRole('link', { name: 'Ver tablero de Gobernación 2027' })).toHaveAttribute('href', `/campanas-electorales/${IDS.campana}/tablero`);
    });

    it('the campaign detail has «Ver tablero», also read-only', () => {
        render(<Detalle campana={campanaRaw()} can={{ edit: false, archive: false, reactivate: false, plantilla: false }} soloLectura={null} sugerencias={{ disponible: true }} filas={[]} totales={{}} />);
        expect(screen.getByRole('link', { name: 'Ver tablero' })).toHaveAttribute('href', `/campanas-electorales/${IDS.campana}/tablero`);
    });
});
