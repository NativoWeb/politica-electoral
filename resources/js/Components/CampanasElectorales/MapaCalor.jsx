import { useCallback, useEffect, useMemo, useState } from 'react';
import { MapContainer, GeoJSON, useMap } from 'react-leaflet';
import L from 'leaflet';
import { Boton, Chip } from '@/Components/Reportes/ui';
import { Muestra } from '@/Components/CampanasElectorales/ui';
import {
    COLOR_RAYAS, COLOR_SIN_META, MEDIDAS, PATRON_RAYAS, TEXTO_ERROR_MAPA, TEXTO_INSUFICIENTE, TEXTO_SESION_TABLERO, TEXTO_UN_TERRITORIO, colorDe, cuantos, leyendaDe, numero,
    pinturaDe, porcentaje,
} from '@/lib/campanas';
import useMediaQuery from '@/lib/useMediaQuery';

const FUENTE_DANE = 'Fuente de los límites: DANE, Marco Geoestadístico Nacional (MGN) 2025.';
const FUENTE_AMB = 'Fuente de los límites: Área Metropolitana de Bucaramanga (AMB) y Alcaldía de Bucaramanga (veredas).';
const FUENTE_PUESTOS = 'Comunas por puesto de votación: Registraduría Nacional del Estado Civil, Divipole 2023 (datos.gov.co).';

// Polygons are kept for the session: switching measure or coming back to a layer does not ask again.
const cache = new Map();

async function pedirCapa(url) {
    if (cache.has(url)) return cache.get(url);
    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/geo+json, application/json' } });
    if (!res.ok) throw Object.assign(new Error(String(res.status)), { status: res.status });
    const data = await res.json();
    if (!data || !Array.isArray(data.features)) throw new Error('formato');
    cache.set(url, data);
    return data;
}

export function limpiarCacheMapa() {
    cache.clear();
}

function urlCapa(capa, codigoMunicipio) {
    return capa === 'comunas'
        ? `/api/geo/comunas.geojson?municipio=${encodeURIComponent(codigoMunicipio ?? '')}`
        : '/api/geo/municipios.geojson';
}

const idDe = (feature) => feature?.properties?.id ?? feature?.id;

function textoValor(t, medida) {
    if (!t) return 'Fuera de la campaña';
    const p = pinturaDe(t, medida);
    if (p.clase === 'insuficiente') return TEXTO_INSUFICIENTE;
    if (p.clase === 'sin_meta') return 'Sin meta';
    if (medida === 'indice') return `Índice ${numero(t.indice)} de 100`;
    const m = t[medida];
    return `${numero(m.registrados)} de ${cuantos(m.meta, medida)} (${porcentaje(m.pct)})`;
}

function Encuadre({ data }) {
    const map = useMap();
    useEffect(() => {
        try {
            const limites = L.geoJSON(data).getBounds();
            if (limites.isValid()) map.fitBounds(limites, { padding: [8, 8] });
        } catch {
            // A collection without geometry has no bounds: the map keeps its default view.
        }
    }, [map, data]);
    return null;
}

export function PatronRayas() {
    return (
        <svg width="0" height="0" aria-hidden="true" focusable="false" className="absolute">
            <defs>
                <pattern id={PATRON_RAYAS} patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)">
                    <rect width="8" height="8" fill={COLOR_SIN_META} />
                    <line x1="0" y1="0" x2="0" y2="8" stroke={COLOR_RAYAS} strokeWidth="3" />
                </pattern>
            </defs>
        </svg>
    );
}

function BanderaGirando() {
    return (
        <div className="flex flex-col items-center gap-4">
            <div className="relative w-16 h-16">
                <div className="absolute inset-0 rounded-full border-[5px] border-line" />
                <div className="absolute inset-0 rounded-full border-[5px] border-transparent border-t-primary border-r-accent animate-spin motion-reduce:animate-none" />
                <div className="absolute inset-0 flex items-center justify-center">
                    <div className="w-7 h-5 rounded-[3px] overflow-hidden flex flex-col">
                        <span className="flex-[2] bg-[#FCD116]" /><span className="flex-1 bg-[#003893]" /><span className="flex-1 bg-[#CE1126]" />
                    </div>
                </div>
            </div>
            <p className="text-lg font-semibold text-ink">Cargando mapa…</p>
        </div>
    );
}

function Leyenda({ entradas, fuera, medida }) {
    const titulo = MEDIDAS.find(m => m.clave === medida)?.etiqueta ?? '';
    return (
        <div>
            <h3 className="text-base font-semibold text-ink-soft">{medida === 'indice' ? 'Índice (de 0 a 100)' : `${titulo}: % de la meta`}</h3>
            <ul aria-label="Leyenda del mapa" className="mt-2 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-x-6 gap-y-2">
                {entradas.map(e => (
                    <li key={e.clave} data-leyenda={e.clave} className="flex items-center gap-3 text-base text-ink">
                        <Muestra clase={e.clase} color={e.clase === 'tramo' ? colorDe(e) : COLOR_SIN_META} />
                        <span className="flex-1">{e.etiqueta}</span>
                        <span className="font-semibold tabular-nums" aria-label={cuantos(e.cuantos, 'territorios')}>{numero(e.cuantos)}</span>
                    </li>
                ))}
                {fuera > 0 && (
                    <li data-leyenda="fuera" className="flex items-center gap-3 text-base text-ink-soft">
                        <Muestra clase="fuera" />
                        <span className="flex-1">Fuera de la campaña o de tus municipios</span>
                        <span className="font-semibold tabular-nums" aria-label={cuantos(fuera, 'territorios')}>{numero(fuera)}</span>
                    </li>
                )}
            </ul>
        </div>
    );
}

// The heat map: polygons over white (no tiles), painted in the client from the tablero rows by id.
// municipioComunas: the municipio whose comunas are on screen (departmental campaign after «Ver comunas de X»).
export default function MapaCalor({ tablero, medida, onMedida, municipioComunas = null, onVerComunas, onVolverMunicipios, onElegir, usaPuestos = false }) {
    const { mapa, porId } = tablero;
    const tactil = useMediaQuery('(pointer: coarse)');
    const capa = municipioComunas ? 'comunas' : mapa.capa;
    const codigoComunas = municipioComunas?.codigo
        ?? porId[mapa.conComunas[0]?.id]?.codigo
        ?? mapa.conComunas[0]?.codigo
        ?? tablero.territorios.find(t => t.tipo === 'municipality')?.codigo;
    const url = capa ? urlCapa(capa, codigoComunas) : null;

    const [estado, setEstado] = useState({ cargando: !!url, error: '', data: null });
    const [intento, setIntento] = useState(0);

    useEffect(() => {
        if (!url) return undefined;
        let vigente = true;
        setEstado({ cargando: true, error: '', data: null });
        pedirCapa(url).then(
            data => { if (vigente) setEstado({ cargando: false, error: '', data }); },
            e => {
                const sesion = e?.status === 401 || e?.status === 419;
                if (vigente) setEstado({ cargando: false, error: sesion ? TEXTO_SESION_TABLERO : TEXTO_ERROR_MAPA, data: null });
            },
        );
        return () => { vigente = false; };
    }, [url, intento]);

    const reintentar = useCallback(() => {
        cache.delete(url);
        setIntento(i => i + 1);
    }, [url]);

    const { dibujados, fuera } = useMemo(() => {
        const features = estado.data?.features ?? [];
        const dentro = features.map(f => porId[idDe(f)]).filter(Boolean);
        return { dibujados: dentro, fuera: features.length - dentro.length };
    }, [estado.data, porId]);
    const leyenda = useMemo(() => leyendaDe(dibujados, medida), [dibujados, medida]);

    const estilo = useCallback((feature) => {
        const t = porId[idDe(feature)];
        const pintura = pinturaDe(t, medida);
        if (pintura.clase === 'fuera') return { fillColor: '#ffffff', fillOpacity: 1, color: '#9ca3af', weight: 1, dashArray: '3 4' };
        return { fillColor: colorDe(pintura), fillOpacity: 1, color: '#ffffff', weight: 1.5 };
    }, [porId, medida]);

    const alCadaUno = useCallback((feature, layer) => {
        const t = porId[idDe(feature)];
        const nombre = t?.nombre ?? feature?.properties?.nombre ?? '';
        // Leaflet puts a string tooltip in as HTML: a DOM node with textContent keeps a territory name as plain text.
        const etiqueta = document.createElement('span');
        etiqueta.textContent = `${nombre}: ${textoValor(t, medida)}`;
        layer.bindTooltip(etiqueta, { sticky: true });
        if (t) layer.on({ click: () => onElegir?.(t.id) });
    }, [porId, medida, onElegir]);

    const comunasDisponibles = !municipioComunas && mapa.capa === 'municipios'
        ? mapa.conComunas.map(c => porId[c.id]).filter(Boolean)
        : [];

    if (!mapa.capa) {
        return (
            <section aria-labelledby="titulo-mapa" className="bg-panel border-2 border-line rounded-lg p-5 sm:p-6">
                <h2 id="titulo-mapa" className="font-heading text-2xl font-bold text-ink">Mapa</h2>
                <p className="mt-2 text-lg text-ink">{mapa.texto || TEXTO_UN_TERRITORIO}</p>
            </section>
        );
    }

    return (
        <section aria-labelledby="titulo-mapa" className="bg-panel border-2 border-line rounded-lg p-4 sm:p-6">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <h2 id="titulo-mapa" className="font-heading text-2xl font-bold text-ink">
                    {municipioComunas ? `Comunas de ${municipioComunas.nombre}` : 'Mapa del avance'}
                </h2>
                <div role="group" aria-labelledby="colorear-por" className="flex flex-wrap items-center gap-2">
                    <span id="colorear-por" className="text-lg text-ink-soft mr-1">Colorear por:</span>
                    {MEDIDAS.map(m => <Chip key={m.clave} activo={medida === m.clave} onClick={() => onMedida(m.clave)}>{m.etiqueta}</Chip>)}
                </div>
            </div>

            {(municipioComunas || comunasDisponibles.length > 0) && (
                <div className="mt-4 flex flex-wrap gap-3">
                    {municipioComunas && <Boton variante="secundario" onClick={onVolverMunicipios}>← Volver a los municipios</Boton>}
                    {comunasDisponibles.map(m => (
                        <Boton key={m.id} variante="secundario" onClick={() => onVerComunas(m)}>Ver comunas de {m.nombre}</Boton>
                    ))}
                </div>
            )}

            <div className="mt-4 space-y-4">
                <div
                    className="tablero-mapa relative h-[320px] sm:h-[420px] lg:h-[480px] rounded-md border-2 border-line bg-white overflow-hidden"
                    aria-label="Mapa del avance por territorio. La tabla de abajo tiene los mismos datos."
                    role="region"
                    aria-busy={estado.cargando || undefined}
                >
                    {estado.cargando && <div className="absolute inset-0 flex items-center justify-center"><BanderaGirando /></div>}
                    {estado.error && (
                        <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center">
                            <p className="text-lg text-ink text-pretty max-w-md">{estado.error}</p>
                            <Boton variante="secundario" onClick={reintentar}>Reintentar</Boton>
                        </div>
                    )}
                    {estado.data && (
                        <MapContainer
                            key={url}
                            center={[6.8, -73.3]}
                            zoom={8}
                            zoomSnap={0.25}
                            scrollWheelZoom={false}
                            dragging={!tactil}
                            attributionControl={false}
                            className="w-full h-full"
                            style={{ background: '#ffffff' }}
                        >
                            <GeoJSON key={`${url}:${medida}`} data={estado.data} style={estilo} onEachFeature={alCadaUno} />
                            <Encuadre data={estado.data} />
                        </MapContainer>
                    )}
                </div>
                <div className="space-y-3">
                    {estado.data && <Leyenda entradas={leyenda} fuera={fuera} medida={medida} />}
                    <p className="text-base text-ink-soft">Toca un territorio para ver su detalle.</p>
                </div>
            </div>

            {mapa.sinPoligono.length > 0 && !municipioComunas && (
                <p className="mt-4 text-base text-ink">
                    <strong className="font-semibold">Sin mapa:</strong> {mapa.sinPoligono.join(', ')}
                </p>
            )}
            <div className="mt-3 space-y-1 text-base text-ink-soft">
                <p>{capa === 'comunas' ? FUENTE_AMB : FUENTE_DANE}</p>
                {usaPuestos && <p>{FUENTE_PUESTOS}</p>}
            </div>
        </section>
    );
}
