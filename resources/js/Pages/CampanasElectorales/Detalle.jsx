import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { AvisoError, Boton, Dialogo, Girando, IconoAlerta } from '@/Components/Reportes/ui';
import { Estado, FranjaSoloLectura, IconoReloj, LinkBoton } from '@/Components/CampanasElectorales/ui';
import FilaMeta, { COLUMNAS, FuentePuestos } from '@/Components/CampanasElectorales/FilaMeta';
import { usaPuestos } from '@/lib/comunas';
import ExcelMetas from '@/Components/CampanasElectorales/ExcelMetas';
import Historial from '@/Components/CampanasElectorales/Historial';
import { showGlobalToast } from '@/lib/api';
import {
    BASE, CAMPOS_META, LOTE_MAXIMO, TEXTO_SALIR, archivarCampana, borradorDe, cambiosDe, erroresDe, erroresDeMetas,
    guardarMetas, mensajeDe, mezclarDetalle, normalizarDetalle, numero, reactivarCampana, sugerenciaDe, totales,
    urlTablero, usarSugerido, verCampana,
} from '@/lib/campanas';

// Goals of a save refused for an expired session, kept in this tab until the page is back (sessionStorage may fail:
// private mode, blocked storage; then they are only on screen).
const claveBorrador = (id) => `campana-metas-sin-guardar:${id}`;
function guardarBorrador(id, borrador, cambios) {
    try {
        const ids = new Set(cambios.map(c => c.geographic_unit_id));
        sessionStorage.setItem(claveBorrador(id), JSON.stringify(Object.fromEntries(Object.entries(borrador).filter(([k]) => ids.has(k)))));
    } catch { /* storage unavailable: the changes stay on screen */ }
}
function leerBorrador(id) {
    try {
        const raw = sessionStorage.getItem(claveBorrador(id));
        sessionStorage.removeItem(claveBorrador(id));
        const data = raw ? JSON.parse(raw) : null;
        return data && typeof data === 'object' && Object.keys(data).length ? data : null;
    } catch {
        return null;
    }
}
function borrarBorrador(id) {
    try { sessionStorage.removeItem(claveBorrador(id)); } catch { /* nothing to clean */ }
}

const motivoPorDefecto = (campana) => (campana.archivada
    ? 'Esta campaña está archivada. Reactívala para cambiar las metas.'
    : 'Puedes ver las metas, pero no cambiarlas.');

// The page props are the first detail; after saving, the server's answer replaces it (mezclarDetalle), and archiving or
// reactivating reloads it as JSON.
export default function CampanaDetalle(props) {
    const [detalle, setDetalle] = useState(() => normalizarDetalle(props));
    const [borrador, setBorrador] = useState(() => borradorDe(detalle.filas));
    const [abiertos, setAbiertos] = useState(() => new Set());
    const [erroresServidor, setErroresServidor] = useState({});
    const [aviso, setAviso] = useState(null);
    const [guardando, setGuardando] = useState(false);
    const [confirmar, setConfirmar] = useState(null);
    const [accionEnCurso, setAccionEnCurso] = useState(false);
    const [verHistorial, setVerHistorial] = useState(false);
    const enviando = useRef(false);

    // An error is said where the eye is (inside the fixed save bar, or under the header when read-only) and as a toast.
    const avisarError = useCallback((texto) => {
        setAviso({ tipo: 'error', texto });
        showGlobalToast(texto, 'error');
    }, []);

    const { campana, filas, soloLectura, can } = detalle;
    const id = campana.id;

    const aplicar = useCallback((nuevo) => {
        setDetalle(nuevo);
        setBorrador(borradorDe(nuevo.filas));
        setErroresServidor({});
    }, []);

    const recargar = useCallback(async () => {
        const res = await verCampana(id);
        if (res.ok) aplicar(res.detalle);
        else avisarError(mensajeDe(res, 'No pudimos actualizar la campaña. Recarga la página.'));
    }, [id, aplicar, avisarError]);

    // New page props (the group selector reloaded the page): they replace the detail on screen.
    const primeraVez = useRef(true);
    const { campana: propsCampana, filas: propsFilas, can: propsCan, soloLectura: propsSoloLectura, sugerencias, totales: propsTotales } = props;
    useEffect(() => {
        if (primeraVez.current) { primeraVez.current = false; return; }
        aplicar(normalizarDetalle({ campana: propsCampana, filas: propsFilas, can: propsCan, soloLectura: propsSoloLectura, sugerencias, totales: propsTotales }));
    }, [propsCampana, propsFilas, propsCan, propsSoloLectura, sugerencias, propsTotales, aplicar]);

    // Goals left unsaved by an expired session come back after reloading (this tab only).
    useEffect(() => {
        const pendientes = leerBorrador(id);
        if (!pendientes) return;
        setBorrador(b => ({ ...b, ...Object.fromEntries(Object.entries(pendientes).filter(([k]) => k in b)) }));
        setAviso({ tipo: 'info', texto: 'Recuperamos los cambios que no se guardaron. Revísalos y pulsa «Guardar cambios».' });
    }, [id]);

    const cambios = useMemo(() => cambiosDe(filas, borrador), [filas, borrador]);
    const erroresLocales = useMemo(() => erroresDe(borrador), [borrador]);
    const errores = useMemo(() => {
        const out = { ...erroresServidor };
        Object.entries(erroresLocales).forEach(([k, v]) => { out[k] = { ...out[k], ...v }; });
        return out;
    }, [erroresLocales, erroresServidor]);
    const hayCambios = cambios.length > 0;
    const suma = useMemo(() => totales(filas, borrador), [filas, borrador]);

    // Leaving with unsaved goals asks first: closing the tab, reloading, or following any link.
    useEffect(() => {
        if (!hayCambios) return undefined;
        const alSalir = e => { e.preventDefault(); e.returnValue = ''; };
        window.addEventListener('beforeunload', alSalir);
        const off = router.on?.('before', event => {
            // A prefetch (hovering a menu link) is not leaving the page.
            if (event.detail?.visit?.prefetch) return;
            if (!window.confirm(TEXTO_SALIR)) event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', alSalir);
            if (typeof off === 'function') off();
        };
    }, [hayCambios]);

    const alCambiar = useCallback((unidad, clave, valor) => {
        setBorrador(b => ({ ...b, [unidad]: { ...b[unidad], [clave]: valor } }));
        setErroresServidor(e => (e[unidad]?.[clave] ? { ...e, [unidad]: { ...e[unidad], [clave]: undefined } } : e));
        setAviso(a => (a?.tipo === 'ok' ? null : a));
    }, []);

    const filaDe = useMemo(() => {
        const m = {};
        const poner = f => { m[f.id] = f; f.hijos.forEach(poner); };
        filas.forEach(poner);
        return m;
    }, [filas]);

    const alUsar = useCallback((unidad) => {
        setBorrador(b => {
            const fila = filaDe[unidad];
            const s = fila && sugerenciaDe(fila, b[unidad], campana.factores);
            return s ? { ...b, [unidad]: usarSugerido(b[unidad], s) } : b;
        });
    }, [filaDe, campana.factores]);

    const alAbrir = useCallback((unidad) => {
        setAbiertos(s => { const n = new Set(s); if (n.has(unidad)) n.delete(unidad); else n.add(unidad); return n; });
    }, []);

    function enfocarError(mapa) {
        const [unidad, celdas] = Object.entries(mapa).find(([, c]) => c && Object.values(c).some(Boolean)) ?? [];
        if (!unidad) return;
        const padre = filas.find(f => f.hijos.some(h => h.id === unidad));
        if (padre) setAbiertos(s => new Set(s).add(padre.id));
        const clave = CAMPOS_META.find(c => celdas[c.clave])?.clave;
        setTimeout(() => document.getElementById(`meta-${unidad}-${clave}`)?.focus(), 0);
    }

    async function guardar() {
        if (enviando.current || soloLectura) return;
        if (Object.keys(erroresLocales).length) {
            avisarError('Revisa las casillas marcadas en rojo.');
            enfocarError(erroresLocales);
            return;
        }
        if (!hayCambios) { setAviso({ tipo: 'info', texto: 'No hay cambios para guardar.' }); return; }
        if (cambios.length > LOTE_MAXIMO) {
            avisarError(`Cambiaste ${numero(cambios.length)} territorios. Guarda como máximo ${LOTE_MAXIMO} a la vez, o usa la carga desde Excel.`);
            return;
        }
        enviando.current = true;
        setGuardando(true);
        setAviso(null);
        const enviados = cambios;
        const res = await guardarMetas(id, enviados);
        enviando.current = false;
        setGuardando(false);
        if (res.ok) {
            borrarBorrador(id);
            showGlobalToast('Metas guardadas');
            setAviso({ tipo: 'ok', texto: 'Metas guardadas' });
            if (Array.isArray(res.body?.data?.filas)) aplicar(mezclarDetalle(detalle, res.body.data));
            else await recargar();
            return;
        }
        const { celdas, general } = res.status === 422 ? erroresDeMetas(res.body, enviados) : { celdas: {}, general: null };
        setErroresServidor(celdas);
        if (res.status === 419 || res.status === 401) guardarBorrador(id, borrador, cambios);
        avisarError(general ?? (Object.keys(celdas).length ? 'Revisa las casillas marcadas en rojo.' : mensajeDe(res, 'No pudimos guardar las metas. Intenta de nuevo.')));
        enfocarError(celdas);
    }

    function descartar() {
        setBorrador(borradorDe(filas));
        setErroresServidor({});
        setAviso(null);
    }

    async function confirmarAccion() {
        if (accionEnCurso) return;
        setAccionEnCurso(true);
        const accion = confirmar;
        const res = accion === 'archivar' ? await archivarCampana(id) : await reactivarCampana(id);
        setAccionEnCurso(false);
        setConfirmar(null);
        if (res.ok) {
            showGlobalToast(accion === 'archivar' ? 'Campaña archivada' : 'Campaña reactivada');
            setAviso(null);
            await recargar();
        } else {
            avisarError(mensajeDe(res, 'No pudimos cambiar el estado de la campaña.'));
        }
    }

    function alCargarExcel(data) {
        if (Array.isArray(data?.filas)) aplicar(mezclarDetalle(detalle, data));
        else recargar();
    }

    const quien = [campana.candidatoNombre, campana.partidoNombre].filter(Boolean).join(' · ');
    const puedeArchivar = !campana.archivada && can.archivar;
    const puedeReactivar = campana.archivada && can.reactivar;

    return (
        <AppLayout title={campana.nombre || 'Campaña electoral'}>
            <Head title={campana.nombre || 'Campaña electoral'} />
            <div className="max-w-6xl mx-auto px-4 lg:px-8 pt-6 lg:pt-8 pb-16 text-lg text-ink">
                <LinkBoton variante="secundario" href={BASE} className="min-h-12 px-4 text-base border-transparent">← Campañas electorales</LinkBoton>

                <header className="mt-4 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-heading text-4xl font-bold text-primary text-balance break-words">{campana.nombre}</h1>
                            <Estado archivada={campana.archivada} />
                        </div>
                        <p className="mt-2 text-lg text-ink">
                            {campana.cargoEtiqueta} {campana.anio}{quien ? <span className="text-ink-soft"> — {quien}</span> : null}
                        </p>
                        <p className="text-base text-ink-soft">
                            {[campana.territorioResumen, campana.grupoNombre && `Grupo político: ${campana.grupoNombre}`].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-3">
                        <LinkBoton href={urlTablero(id)}>Ver tablero</LinkBoton>
                        {can.editar && <LinkBoton variante="secundario" href={`${BASE}/${id}/editar`}>Editar</LinkBoton>}
                        {puedeArchivar && <Boton variante="secundario" onClick={() => setConfirmar('archivar')}>Archivar</Boton>}
                        {puedeReactivar && <Boton variante="secundario" onClick={() => setConfirmar('reactivar')}>Reactivar</Boton>}
                    </div>
                </header>

                <div className="mt-6 space-y-4">
                    {soloLectura && <FranjaSoloLectura>{detalle.motivoSoloLectura || motivoPorDefecto(campana)}</FranjaSoloLectura>}
                    {(soloLectura || filas.length === 0) && aviso?.tipo === 'error' && <AvisoError>{aviso.texto}</AvisoError>}
                    {detalle.avisoSugerencias && <p role="note" className="p-4 rounded-md border-2 border-line-strong bg-panel text-lg">{detalle.avisoSugerencias}</p>}
                    <div className="flex flex-col lg:flex-row lg:items-start gap-3">
                        <div className="flex-1">
                            <ExcelMetas
                                campanaId={id}
                                nombre={campana.nombre}
                                puedeDescargar={can.plantilla}
                                puedeCargar={!soloLectura}
                                hayCambios={hayCambios}
                                onCargado={alCargarExcel}
                            />
                        </div>
                        <Boton variante="secundario" onClick={() => setVerHistorial(true)}><IconoReloj />Historial</Boton>
                    </div>
                </div>

                <section aria-labelledby="titulo-metas" className="mt-8">
                    <h2 id="titulo-metas" className="font-heading text-2xl font-bold text-ink">Metas por territorio</h2>
                    {filas.length === 0 ? (
                        <p className="mt-4 p-6 rounded-lg bg-panel border-2 border-line">No hay territorios para mostrar en esta campaña.</p>
                    ) : (
                        <div className="mt-4 lg:bg-panel lg:border-2 lg:border-line lg:rounded-lg">
                            <div aria-hidden="true" className={`hidden ${COLUMNAS} px-4 py-3 border-b-2 border-line text-base font-semibold text-ink-soft`}>
                                <span>Territorio</span>
                                {CAMPOS_META.map(c => <span key={c.clave}>{c.etiqueta}</span>)}
                                <span>Avance</span>
                            </div>
                            <ul className="space-y-4 lg:space-y-0" aria-label="Territorios">
                                {filas.map(f => (
                                    <FilaMeta
                                        key={f.id}
                                        fila={f}
                                        borrador={borrador}
                                        errores={errores}
                                        factores={campana.factores}
                                        soloLectura={soloLectura}
                                        abierto={abiertos.has(f.id)}
                                        onAbrir={alAbrir}
                                        onCambio={alCambiar}
                                        onUsar={alUsar}
                                    />
                                ))}
                            </ul>
                            <div aria-label="Totales" role="group" className="mt-4 lg:mt-0 p-4 sm:p-5 lg:px-4 lg:py-4 rounded-lg lg:rounded-none bg-panel border-2 border-primary lg:border-0 lg:border-t-2">
                                <div className={`space-y-2 lg:space-y-0 ${COLUMNAS}`}>
                                    <p className="font-heading text-xl font-bold text-primary">Totales</p>
                                    {CAMPOS_META.map(c => (
                                        <p key={c.clave} className="flex justify-between lg:block text-lg font-bold tabular-nums">
                                            <span className="font-normal text-ink-soft lg:sr-only">{c.etiqueta}</span>{numero(suma[c.clave])}
                                        </p>
                                    ))}
                                    <p className="flex justify-between gap-3 lg:block text-base tabular-nums">
                                        <span className="text-lg text-ink-soft lg:sr-only">Avance</span>
                                        <span>Votantes {numero(detalle.totalAvance.votantes ?? suma.avanceVotantes)} · Líderes {numero(detalle.totalAvance.lideres ?? suma.avanceLideres)}</span>
                                    </p>
                                </div>
                                {detalle.totalSugerido?.texto && <p className="mt-3 text-base text-ink-soft">Para toda la campaña: {detalle.totalSugerido.texto}</p>}
                                {usaPuestos(filas) && <FuentePuestos />}
                            </div>
                        </div>
                    )}
                </section>


                {!soloLectura && filas.length > 0 && (
                    <div className="sticky bottom-[calc(72px+env(safe-area-inset-bottom,0px))] lg:bottom-0 z-20 mt-6 -mx-4 lg:mx-0 px-4 py-3 bg-panel border-t-2 lg:border-2 border-line-strong lg:rounded-t-lg shadow-[0_-8px_24px_rgba(0,27,60,0.12)]">
                        {aviso?.tipo === 'error' && (
                            <p role="alert" className="mb-3 flex items-start gap-2 p-3 rounded-md border-2 border-danger bg-danger-light text-lg font-semibold text-ink">
                                <IconoAlerta className="w-6 h-6 flex-shrink-0 text-danger" />{aviso.texto}
                            </p>
                        )}
                        {aviso?.tipo === 'info' && <p className="mb-3 p-3 rounded-md border-2 border-line-strong bg-bg text-lg">{aviso.texto}</p>}
                        <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                            <p className="flex-1 text-lg tabular-nums" role="status">
                                {aviso?.tipo === 'ok' && !hayCambios
                                    ? <strong className="font-semibold text-ink">Metas guardadas</strong>
                                    : hayCambios
                                        ? <><strong className="font-semibold">{cambios.length === 1 ? '1 territorio cambiado' : `${numero(cambios.length)} territorios cambiados`}</strong><span className="text-ink-soft"> sin guardar</span></>
                                        : <span className="text-ink-soft">Sin cambios</span>}
                            </p>
                            {hayCambios && <Boton variante="suave" onClick={descartar} disabled={guardando}>Descartar</Boton>}
                            <Boton onClick={guardar} disabled={guardando} aria-busy={guardando || undefined}>
                                {guardando ? <><Girando className="w-5 h-5" />Guardando…</> : 'Guardar cambios'}
                            </Boton>
                        </div>
                    </div>
                )}
            </div>

            {confirmar && (
                <Dialogo
                    titulo={confirmar === 'archivar' ? '¿Archivar esta campaña?' : '¿Reactivar esta campaña?'}
                    onVolver={() => !accionEnCurso && setConfirmar(null)}
                    acciones={<>
                        <Boton onClick={confirmarAccion} disabled={accionEnCurso}>
                            {accionEnCurso ? <><Girando className="w-5 h-5" />Un momento…</> : (confirmar === 'archivar' ? 'Sí, archivar' : 'Sí, reactivar')}
                        </Boton>
                        <Boton variante="secundario" autoFocus onClick={() => setConfirmar(null)} disabled={accionEnCurso}>No, volver</Boton>
                    </>}
                >
                    <p>{confirmar === 'archivar' ? 'Dejará de aparecer en Activas. Podrás reactivarla.' : 'Volverá a aparecer en Activas y podrás cambiar sus metas.'}</p>
                </Dialogo>
            )}
            {verHistorial && <Historial campanaId={id} onCerrar={() => setVerHistorial(false)} />}
        </AppLayout>
    );
}
