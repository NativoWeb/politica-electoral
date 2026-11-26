import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EnlaceDescarga from '@/Components/EnlaceDescarga';
import {
    Boton, Estado, Girando, IconoAlerta, IconoChevron, IconoDescarga, LinkBoton, botonDescargaCls, focoCls, tactilCls, tarjetaCls, tituloPaginaCls,
    tituloSeccionCls,
} from '@/Components/CampanasElectorales/ui';
import { TarjetaAvance, TarjetaIndice } from '@/Components/CampanasElectorales/TarjetaAvance';
import MapaCalor, { PatronRayas } from '@/Components/CampanasElectorales/MapaCalor';
import RankingTerritorios from '@/Components/CampanasElectorales/RankingTerritorios';
import TablaTerritorios from '@/Components/CampanasElectorales/TablaTerritorios';
import GraficoSemanal from '@/Components/CampanasElectorales/GraficoSemanal';
import DetalleTerritorio from '@/Components/CampanasElectorales/DetalleTerritorio';
import { showGlobalToast } from '@/lib/api';
import {
    BASE, TEXTO_RANKING, TEXTO_SIN_METAS, etiquetaTramo, horaDe, mensajeTablero, normalizarTablero, sinMetas,
    urlExportarTablero, verTablero,
} from '@/lib/campanas';

const ES_COMUNA = (t) => t.tipo === 'comuna' || t.tipo === 'corregimiento';
const AVISOS_INFORMATIVOS = ['territorio_usuario', 'archivada'];

const IconoInfo = ({ className = 'w-5 h-5' }) => (
    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className={className}>
        <circle cx="12" cy="12" r="9" /><path strokeLinecap="round" d="M12 11v5M12 8h.01" />
    </svg>
);

function Avisos({ avisos }) {
    if (!avisos.length) return null;
    return (
        <ul aria-label="Avisos" className="space-y-2">
            {avisos.map(a => {
                const info = AVISOS_INFORMATIVOS.includes(a.tipo);
                return (
                    <li key={`${a.tipo}:${a.texto}`} data-aviso={a.tipo} className={`flex items-start gap-2 p-3 rounded-md border text-sm text-ink ${info ? 'border-line-strong bg-panel' : 'border-accent bg-[#FFF8D6]'}`}>
                        {info ? <IconoInfo className="w-5 h-5 flex-shrink-0 text-ink-soft" /> : <IconoAlerta className="w-5 h-5 flex-shrink-0 text-ink" />}
                        <span className="text-pretty">{a.texto}</span>
                    </li>
                );
            })}
        </ul>
    );
}

function ComoSeCalcula({ formula }) {
    return (
        <details className={`group ${tarjetaCls}`}>
            <summary className={`${tactilCls} px-4 flex items-center justify-between gap-3 cursor-pointer list-none text-sm font-semibold text-ink rounded-lg ${focoCls}`}>
                Cómo se calcula
                <IconoChevron className="w-4 h-4 group-open:rotate-180 transition-transform duration-200" />
            </summary>
            <div className="px-4 pb-4 space-y-2 text-sm text-ink text-pretty">
                <p>{formula}</p>
                <p>Los colores del mapa van por tramos: {[0, 1, 2, 3].map(t => etiquetaTramo(t, 'votantes')).join(', ')} y {etiquetaTramo(4, 'votantes').toLowerCase()} (100 % o más). Un territorio sin meta se ve gris; uno rayado tiene pocas fichas con comuna todavía.</p>
                <p>Si la campaña solo tiene votos meta, la meta de votantes y la de líderes salen de los factores de la campaña.</p>
                <p>{TEXTO_RANKING} Para cuidar la privacidad, las cifras de 1 a 4 se muestran como «menos de 5».</p>
            </div>
        </details>
    );
}

// The campaign's dashboard. The first data comes in the page props; «Actualizar» asks for it again (?actualizar=1).
export default function CampanaTablero(props) {
    const datosProps = props.tablero ?? props.data ?? props;
    const [tablero, setTablero] = useState(() => normalizarTablero(datosProps));
    const [medida, setMedida] = useState('indice');
    const [municipioComunas, setMunicipioComunas] = useState(null);
    const [elegidoId, setElegidoId] = useState(null);
    const [actualizando, setActualizando] = useState(false);
    const [error, setError] = useState('');
    const mapaRef = useRef(null);
    const pidiendo = useRef(false);

    // New page props (the group selector reloaded the page): they replace what is on screen.
    const primeraVez = useRef(true);
    const ultimos = useRef(datosProps);
    ultimos.current = datosProps;
    const { tablero: pTablero, data: pData, territorios: pTerritorios, totales: pTotales, campana: pCampana, generado_at: pGenerado } = props;
    useEffect(() => {
        if (primeraVez.current) { primeraVez.current = false; return; }
        setTablero(normalizarTablero(ultimos.current));
        setMunicipioComunas(null);
        setElegidoId(null);
    }, [pTablero, pData, pTerritorios, pTotales, pCampana, pGenerado]);

    const { campana, territorios, porId, mapa, totales } = tablero;
    const id = campana.id;

    const actualizar = useCallback(async () => {
        if (pidiendo.current) return;
        pidiendo.current = true;
        setActualizando(true);
        setError('');
        const res = await verTablero(id, { actualizar: true });
        pidiendo.current = false;
        setActualizando(false);
        if (res.ok) {
            setTablero(res.tablero);
            showGlobalToast('Tablero actualizado');
        } else {
            const texto = mensajeTablero(res, 'No pudimos actualizar el tablero. Intenta de nuevo.');
            setError(texto);
            showGlobalToast(texto, 'error');
        }
    }, [id]);

    const hijosDe = useMemo(() => {
        const m = {};
        territorios.forEach(t => { if (t.padreId && porId[t.padreId]) (m[t.padreId] ??= []).push(t); });
        return m;
    }, [territorios, porId]);

    const conComunas = useMemo(() => new Set(mapa.conComunas.map(c => c.id)), [mapa.conComunas]);
    const puedeVerComunas = useCallback((t) => mapa.capa === 'municipios' && t.tipo === 'municipality' && (conComunas.has(t.id) || !!hijosDe[t.id]?.length), [mapa.capa, conComunas, hijosDe]);

    const enTabla = useMemo(() => {
        if (municipioComunas) return hijosDe[municipioComunas.id] ?? [];
        if (mapa.capa === 'comunas') {
            const comunas = territorios.filter(ES_COMUNA);
            if (comunas.length) return comunas;
        }
        return territorios.filter(t => !t.padreId || !porId[t.padreId]);
    }, [municipioComunas, mapa.capa, territorios, porId, hijosDe]);

    const usaPuestos = territorios.some(t => t.porOrigen && t.porOrigen.puesto !== 0);

    const verComunas = useCallback((municipio) => {
        setElegidoId(null);
        setMunicipioComunas({ id: municipio.id, nombre: municipio.nombre, codigo: municipio.codigo || mapa.conComunas.find(c => c.id === municipio.id)?.codigo });
        setTimeout(() => mapaRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }), 0);
    }, [mapa.conComunas]);

    const elegido = elegidoId ? porId[elegidoId] : null;
    const vacio = sinMetas(tablero);
    const hora = horaDe(tablero.generadoAt);
    const nombre = campana.nombre || 'Campaña electoral';

    return (
        <AppLayout title={`Tablero · ${nombre}`}>
            <Head title={`Tablero · ${nombre}`} />
            <PatronRayas />
            <div className="max-w-7xl mx-auto px-4 lg:px-6 pt-4 lg:pt-6 pb-10 text-[15px] text-ink">
                <LinkBoton variante="secundario" href={`${BASE}/${id}`} className="-ml-2 px-2 text-[13px] border-transparent">← Volver a la campaña</LinkBoton>

                <header className="mt-2 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wider text-ink-soft">Tablero</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className={tituloPaginaCls}>{nombre}</h1>
                            <Estado archivada={campana.archivada} />
                        </div>
                        <p className="mt-1 text-[13px] text-ink-soft">
                            {[`${campana.cargoEtiqueta} ${campana.anio ?? ''}`.trim(), campana.territorioResumen].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
                        <p className="text-xs text-ink-soft tabular-nums sm:order-last" role="status">{hora ? `Actualizado ${hora}` : ''}</p>
                        <Boton variante="secundario" onClick={actualizar} disabled={actualizando} aria-busy={actualizando || undefined}>
                            {actualizando ? <><Girando className="w-4 h-4" />Actualizando…</> : 'Actualizar'}
                        </Boton>
                        {!vacio && (
                            <>
                                <EnlaceDescarga href={urlExportarTablero(id, 'xlsx')} nombre={`Tablero ${nombre}`} className={botonDescargaCls}><IconoDescarga className="w-4 h-4" />Descargar Excel</EnlaceDescarga>
                                <EnlaceDescarga href={urlExportarTablero(id, 'pdf')} nombre={`Tablero ${nombre}`} className={botonDescargaCls}><IconoDescarga className="w-4 h-4" />Descargar PDF</EnlaceDescarga>
                            </>
                        )}
                    </div>
                </header>

                {error && <p role="alert" className="mt-3 flex items-start gap-2 p-3 rounded-md border border-danger bg-danger-light text-sm font-semibold"><IconoAlerta className="w-5 h-5 flex-shrink-0 text-danger" />{error}</p>}

                <div className="mt-4"><Avisos avisos={tablero.avisos} /></div>

                {vacio ? (
                    <section className={`mt-4 p-5 ${tarjetaCls}`}>
                        <h2 className={tituloSeccionCls}>Aún no hay metas</h2>
                        <p className="mt-1 text-sm text-ink text-pretty">{TEXTO_SIN_METAS}</p>
                        {tablero.puedeEditar && <LinkBoton href={`${BASE}/${id}`} className="mt-3 w-full sm:w-auto">Ir a las metas</LinkBoton>}
                    </section>
                ) : (
                    <div className="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-4">
                        <div className="lg:col-span-4"><TarjetaAvance titulo="Votantes registrados" medida={totales.votantes} unidad="votantes" /></div>
                        <div className="lg:col-span-4"><TarjetaAvance titulo="Líderes" medida={totales.lideres} unidad="lideres" /></div>
                        <div className="lg:col-span-4"><TarjetaIndice indice={totales.indice} totales={totales} /></div>

                        <div ref={mapaRef} className="lg:col-span-8 scroll-mt-4">
                            <MapaCalor
                                tablero={tablero}
                                medida={medida}
                                onMedida={setMedida}
                                municipioComunas={municipioComunas}
                                onVerComunas={verComunas}
                                onVolverMunicipios={() => setMunicipioComunas(null)}
                                onElegir={setElegidoId}
                                usaPuestos={usaPuestos}
                            />
                        </div>
                        <div className="lg:col-span-4"><RankingTerritorios tablero={tablero} onElegir={setElegidoId} apilado /></div>

                        <div className="lg:col-span-12">
                            <TablaTerritorios
                                territorios={enTabla}
                                titulo={municipioComunas ? `Comunas de ${municipioComunas.nombre}` : 'Todos los territorios'}
                                onElegir={setElegidoId}
                                accion={municipioComunas ? <Boton variante="secundario" onClick={() => setMunicipioComunas(null)}>← Volver a los municipios</Boton> : null}
                            />
                        </div>

                        <div className="lg:col-span-12"><GraficoSemanal evolucion={tablero.evolucion} fechaEleccion={campana.fecha} /></div>
                    </div>
                )}

                <div className="mt-4"><ComoSeCalcula formula={tablero.formula} /></div>
            </div>

            {elegido && (
                <DetalleTerritorio
                    key={elegido.id}
                    campanaId={id}
                    territorio={elegido}
                    tieneComunas={puedeVerComunas(elegido)}
                    pocosRegistros={tablero.pocosRegistros}
                    onVerComunas={verComunas}
                    onCerrar={() => setElegidoId(null)}
                />
            )}
        </AppLayout>
    );
}
