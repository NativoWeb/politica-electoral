import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PasoFuente from '@/Components/Reportes/PasoFuente';
import PasoFiltros from '@/Components/Reportes/PasoFiltros';
import PasoColumnas from '@/Components/Reportes/PasoColumnas';
import ListaReporte from '@/Components/Reportes/ListaReporte';
import BarraConteo from '@/Components/Reportes/BarraConteo';
import { Boton, Dialogo, focoCls } from '@/Components/Reportes/ui';
import {
    PASOS, columnasPorDefecto, fuenteEnVista, leerEstado, limpiarColumnas, limpiarFiltros, mensajeError, normalizarFuente,
    pareceDocumento, pedirConteo, resumenFiltros, sinChip, specDe, textoExcluidos, unidadDe, urlDeEstado, useDebouncedRequest, vistaDe,
} from '@/lib/reportes';

// Step 2 is "A quiénes" for people and "Cuáles" for compromisos or organizations.
const tituloPaso = (p, unidad) => (p.numero === 2 && unidad ? unidad.quienes : p.titulo);

// Four steps in a row while four 6em boxes fit; with bigger text (or a narrow phone) two by two, never three and one.
// The em follows «Tamaño del texto», so the switch does too.
const COLUMNAS_PASOS = 'repeat(auto-fill, minmax(clamp(calc((100% - 1.5rem) / 4 - 1px), calc((24em - 100%) * 999), calc((100% - 0.5rem) / 2 - 1px)), 1fr))';

function Stepper({ paso, hayFuente, onPaso, unidad }) {
    return (
        <nav aria-label="Pasos del reporte" className="mt-4">
            <ol className="grid gap-2 text-[13px]" style={{ gridTemplateColumns: COLUMNAS_PASOS }}>
                {PASOS.map(p => {
                    const actual = p.numero === paso;
                    const hecho = p.numero < paso;
                    const disponible = p.numero === 1 || hayFuente;
                    return (
                        <li key={p.numero}>
                            <button
                                type="button"
                                onClick={() => onPaso(p.numero)}
                                disabled={!disponible}
                                aria-current={actual ? 'step' : undefined}
                                className={`w-full h-full min-h-12 flex flex-col sm:flex-row items-center sm:items-center gap-1 sm:gap-2 px-1 sm:px-3 py-1.5 rounded-lg border text-center sm:text-left transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed ${focoCls} ${
                                    actual ? 'border-primary bg-primary text-white' : 'border-line-strong bg-panel text-ink hover:border-primary'
                                }`}
                            >
                                <span aria-hidden="true" className={`w-7 h-7 flex-shrink-0 rounded-full flex items-center justify-center font-heading text-sm font-bold ${
                                    actual ? 'bg-accent text-primary-dark' : hecho ? 'bg-primary text-white' : 'bg-bg text-ink'
                                }`}>
                                    {p.numero}
                                </span>
                                <span className="min-w-0 max-w-full text-[13px] sm:text-sm font-semibold leading-tight">
                                    <span className="sr-only">Paso {p.numero}: </span>{tituloPaso(p, unidad)}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export default function ReportesIndex({
    fuentes: fuentesRaw = [], puedeDatosPersonales = false, topes = {}, cargosPorTipo, cargoCategorias, municipios, empresarios = null,
}) {
    const { url, props } = usePage();
    const perms = props.auth?.user?.permissions ?? [];
    const puedeMapa = perms.includes('*') || perms.includes('view.mapa');
    const puede = !!puedeDatosPersonales;

    const fuentes = useMemo(() => (Array.isArray(fuentesRaw) ? fuentesRaw : []).map(normalizarFuente).filter(f => f.clave), [fuentesRaw]);
    const estadoUrl = useMemo(() => leerEstado(url, fuentes, puede), [url, fuentes, puede]);
    // A search that looks like a cédula or a phone lives only here, never in the URL (urlDeEstado drops it).
    const [busquedaPrivada, setBusquedaPrivada] = useState(null);
    const estado = useMemo(
        () => (busquedaPrivada === null ? estadoUrl : { ...estadoUrl, busqueda: busquedaPrivada }),
        [estadoUrl, busquedaPrivada],
    );
    const { fuente, paso } = estado;
    const unidad = fuente ? unidadDe(fuente, estado.filtros) : null;
    const vista = fuente ? vistaDe(fuente, estado.filtros) : null;
    // The columns and presets of the chosen vista (the same source when it has no vista).
    const fuenteVista = useMemo(() => (fuente ? fuenteEnVista(fuente, vista === null ? {} : { vista }) : null), [fuente, vista]);
    // Changing source with filters chosen asks first: {fuente, atajo?} waiting for the answer.
    const [cambio, setCambio] = useState(null);

    // Every change goes to the URL: reload, Back and closing a ficha never lose what was chosen.
    // A new step is a history entry (Back returns to the previous step); a filter change replaces the entry.
    // historial: also a new entry without changing step (the pages of the list: Back returns to the previous one).
    const ir = useCallback((cambios, { nuevoPaso = false, historial = false } = {}) => {
        const next = { ...estado, ...cambios };
        if (cambios.etiquetas) next.etiquetas = { ...estado.etiquetas, ...cambios.etiquetas };
        // Another vista (Empresarios: organizaciones ↔ miembros) has other columns: start from its default ones.
        if (cambios.filtros && !cambios.columnas && next.fuente && next.fuente === estado.fuente
            && vistaDe(next.fuente, next.filtros) !== vistaDe(estado.fuente, estado.filtros)) {
            next.columnas = columnasPorDefecto(fuenteEnVista(next.fuente, next.filtros), puede);
        }
        const destino = urlDeEstado(next);
        const visita = { url: destino, preserveState: true, preserveScroll: !nuevoPaso };
        if (nuevoPaso || historial) router.push(visita);
        else router.replace(visita);
    }, [estado, puede]);

    const columnasUrl = new URLSearchParams(url.split('?')[1] ?? '').get('c');
    const columnasLimpias = fuente ? estado.columnas.join(',') : null;
    useEffect(() => {
        if (columnasUrl === null || columnasLimpias === null || columnasUrl === columnasLimpias) return;
        router.replace({ url: urlDeEstado(estado), preserveState: true, preserveScroll: true });
        // Only when the cleaned columns differ from the URL; estado is read at that moment.
    }, [columnasUrl, columnasLimpias]);

    const titulo = useRef(null);
    const pasoPrevio = useRef(paso);
    useEffect(() => {
        if (pasoPrevio.current !== paso) titulo.current?.focus({ preventScroll: true });
        pasoPrevio.current = paso;
    }, [paso]);

    const contando = !!fuente && (paso === 2 || paso === 3);
    const claveConteo = fuente ? JSON.stringify([fuente.clave, estado.filtros]) : '';
    const conteo = useDebouncedRequest((signal) => pedirConteo(specDe(estado), signal), claveConteo, { enabled: contando });
    const totalConteo = conteo.datos?.total ?? (Object.keys(estado.filtros).length === 0 ? fuente?.total ?? null : null);

    const chips = fuente ? resumenFiltros(estado.filtros, fuente, estado.etiquetas) : [];
    const ultimoChip = chips[chips.length - 1] ?? null;
    const spec = fuente ? specDe(estado) : null;

    function alPaso(n) {
        if (n === paso) return;
        ir({ paso: n, pagina: 1 }, { nuevoPaso: true });
    }

    function entrarEnFuente(f) {
        const misma = fuente?.clave === f.clave;
        if (!misma) setBusquedaPrivada(null);
        ir({
            fuente: f, paso: 2, pagina: 1, busqueda: '',
            filtros: misma ? estado.filtros : {},
            columnas: misma ? estado.columnas : columnasPorDefecto(fuenteEnVista(f, {}), puede),
            orden: misma ? estado.orden : f.ordenes[0].valor,
            ...(misma ? {} : { etiquetas: {} }),
        }, { nuevoPaso: true });
    }

    function entrarConAtajo(f, atajo) {
        setBusquedaPrivada(null);
        const filtros = limpiarFiltros(atajo.filtros, f);
        const enVista = fuenteEnVista(f, filtros);
        ir({
            fuente: f, paso: 4, pagina: 1, busqueda: '', etiquetas: {}, filtros,
            columnas: atajo.columnas ? limpiarColumnas(atajo.columnas, enVista, puede) : columnasPorDefecto(enVista, puede),
            orden: fuente?.clave === f.clave ? estado.orden : f.ordenes[0].valor,
        }, { nuevoPaso: true });
    }

    // Another source starts from zero (its filters and columns are others); with filters chosen it asks first.
    // What would be lost by starting over: the filters and the search typed in the list.
    const hayFiltros = Object.keys(estado.filtros).length > 0;
    const hayBusqueda = estado.busqueda.trim() !== '';
    const pideConfirmar = (f) => !!fuente && fuente.clave !== f.clave && (hayFiltros || hayBusqueda);
    const seQuita = hayFiltros && hayBusqueda
        ? 'Se quitarán los filtros elegidos y la búsqueda.'
        : hayFiltros ? 'Se quitarán los filtros elegidos.' : 'Se quitará la búsqueda.';

    function elegirFuente(f) {
        if (pideConfirmar(f)) setCambio({ fuente: f, atajo: null });
        else entrarEnFuente(f);
    }

    function usarAtajo(f, atajo) {
        if (pideConfirmar(f)) setCambio({ fuente: f, atajo });
        else entrarConAtajo(f, atajo);
    }

    function confirmarCambio() {
        const c = cambio;
        setCambio(null);
        if (c.atajo) entrarConAtajo(c.fuente, c.atajo);
        else entrarEnFuente(c.fuente);
    }

    const siguiente = paso === 2
        ? { texto: 'Siguiente: elegir datos', onClick: () => alPaso(3) }
        : { texto: 'Siguiente: ver la lista', onClick: () => alPaso(4) };

    const onPagina = useCallback((p, { reemplazar = false } = {}) => ir({ pagina: p }, { historial: !reemplazar }), [ir]);
    const excluidos = fuente ? textoExcluidos(estado.filtros, fuente) : '';

    function buscar(busqueda) {
        setBusquedaPrivada(pareceDocumento(busqueda) ? busqueda : null);
        ir({ busqueda, pagina: 1 });
    }

    return (
        <AppLayout title="Reportes">
            <Head title="Reportes" />
            <div className={`max-w-5xl mx-auto px-4 lg:px-6 pt-4 lg:pt-6 ${contando ? 'pb-48 sm:pb-32' : 'pb-10'} text-[15px] text-ink`}>
                <header>
                    <h1 className="font-heading text-[22px] lg:text-[28px] font-extrabold text-primary leading-tight">Reportes</h1>
                    <p className="mt-0.5 text-[13px] text-ink-soft">
                        {fuentes.length > 1 ? 'Arma una lista, mírala aquí y descárgala.' : 'Arma una lista de personas, mírala aquí y descárgala.'}
                    </p>
                    {fuentes.length > 0 && <Stepper paso={paso} hayFuente={!!fuente} onPaso={alPaso} unidad={unidad} />}
                </header>

                <section className="mt-5" aria-labelledby="paso-actual">
                    {/* Takes the focus on each step change, so a screen reader says where the user is. */}
                    <p id="paso-actual" ref={titulo} tabIndex={-1} className="sr-only">
                        Paso {paso} de 4: {tituloPaso(PASOS[paso - 1], unidad)}
                    </p>

                    {fuentes.length === 0 && (
                        <div className="p-4 rounded-lg bg-panel border border-line">
                            <p className="font-heading text-lg font-bold text-ink">Todavía no tienes listas para armar</p>
                            <p className="mt-1 text-[15px] text-ink">Pídele a tu administrador el permiso para ver Votantes o el Mapa Político.</p>
                        </div>
                    )}

                    {fuentes.length > 0 && paso === 1 && <PasoFuente fuentes={fuentes} onAtajo={usarAtajo} onElegir={elegirFuente} />}

                    {fuente && paso === 2 && (
                        <PasoFiltros
                            fuente={fuente}
                            filtros={estado.filtros}
                            etiquetas={estado.etiquetas}
                            onFiltros={(filtros, etiquetas) => ir({ filtros, etiquetas, pagina: 1 })}
                        />
                    )}

                    {fuente && paso === 3 && (
                        <PasoColumnas
                            fuente={fuenteVista}
                            columnas={estado.columnas}
                            puedeDatosPersonales={puede}
                            onColumnas={(columnas) => ir({ columnas })}
                        />
                    )}

                    {fuente && paso === 4 && (
                        <ListaReporte
                            spec={spec}
                            estado={estado}
                            fuente={fuenteVista}
                            chips={chips}
                            tope={Number(topes?.excel) || 0}
                            topePdf={Number(topes?.pdf) || 0}
                            puedeDatosPersonales={puede}
                            puedeMapa={puedeMapa}
                            fichaProps={{ cargosPorTipo, cargoCategorias, municipios }}
                            organizacionProps={empresarios}
                            nota={excluidos}
                            onBusqueda={buscar}
                            onOrden={(orden) => ir({ orden, pagina: 1 })}
                            onPagina={onPagina}
                            onCambiarPaso={alPaso}
                        />
                    )}
                </section>
            </div>

            {contando && (
                <BarraConteo
                    estado={conteo.estado}
                    total={totalConteo}
                    mensajeError={mensajeError(conteo.error, 'conteo')}
                    nota={excluidos}
                    ultimoFiltro={ultimoChip}
                    onQuitarUltimo={() => ultimoChip && ir({ filtros: sinChip(estado.filtros, ultimoChip), pagina: 1 })}
                    onReintentar={conteo.reintentar}
                    siguiente={siguiente}
                    unidad={unidad}
                />
            )}

            {cambio && (
                <Dialogo
                    titulo={`¿Empezar de nuevo con ${cambio.fuente.etiqueta}?`}
                    onVolver={() => setCambio(null)}
                    acciones={<>
                        <Boton onClick={confirmarCambio}>Sí, empezar de nuevo</Boton>
                        {/* The safe answer takes the focus: Enter by mistake loses nothing. */}
                        <Boton variante="secundario" autoFocus onClick={() => setCambio(null)}>No, seguir con {fuente?.etiqueta}</Boton>
                    </>}
                >
                    <p>{seQuita}</p>
                </Dialogo>
            )}
        </AppLayout>
    );
}
