import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PasoFuente from '@/Components/Reportes/PasoFuente';
import PasoFiltros from '@/Components/Reportes/PasoFiltros';
import PasoColumnas from '@/Components/Reportes/PasoColumnas';
import ListaReporte from '@/Components/Reportes/ListaReporte';
import BarraConteo from '@/Components/Reportes/BarraConteo';
import { focoCls } from '@/Components/Reportes/ui';
import {
    PASOS, columnasPorDefecto, leerEstado, limpiarColumnas, limpiarFiltros, mensajeError, normalizarFuente,
    pareceDocumento, pedirConteo, resumenFiltros, sinChip, specDe, textoExcluidos, urlDeEstado, useDebouncedRequest,
} from '@/lib/reportes';

function Stepper({ paso, hayFuente, onPaso }) {
    return (
        <nav aria-label="Pasos del reporte" className="mt-5">
            <ol className="grid grid-cols-4 gap-2 sm:gap-3">
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
                                className={`w-full h-full min-h-16 flex flex-col sm:flex-row items-center sm:items-center gap-1 sm:gap-3 px-1 sm:px-4 py-2 rounded-lg border-2 text-center sm:text-left transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed ${focoCls} ${
                                    actual ? 'border-primary bg-primary text-white' : 'border-line-strong bg-panel text-ink hover:border-primary'
                                }`}
                            >
                                <span aria-hidden="true" className={`w-9 h-9 flex-shrink-0 rounded-full flex items-center justify-center font-heading text-lg font-bold ${
                                    actual ? 'bg-accent text-primary-dark' : hecho ? 'bg-primary text-white' : 'bg-bg text-ink'
                                }`}>
                                    {p.numero}
                                </span>
                                <span className="text-base sm:text-lg font-semibold leading-tight">
                                    <span className="sr-only">Paso {p.numero}: </span>{p.titulo}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export default function ReportesIndex({ fuentes: fuentesRaw = [], puedeDatosPersonales = false, topes = {}, cargosPorTipo, cargoCategorias, municipios }) {
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

    // Every change goes to the URL: reload, Back and closing a ficha never lose what was chosen.
    // A new step is a history entry (Back returns to the previous step); a filter change replaces the entry.
    // historial: also a new entry without changing step (the pages of the list: Back returns to the previous one).
    const ir = useCallback((cambios, { nuevoPaso = false, historial = false } = {}) => {
        const next = { ...estado, ...cambios };
        if (cambios.etiquetas) next.etiquetas = { ...estado.etiquetas, ...cambios.etiquetas };
        const destino = urlDeEstado(next);
        const visita = { url: destino, preserveState: true, preserveScroll: !nuevoPaso };
        if (nuevoPaso || historial) router.push(visita);
        else router.replace(visita);
    }, [estado]);

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

    function elegirFuente(f) {
        const misma = fuente?.clave === f.clave;
        ir({
            fuente: f, paso: 2, pagina: 1, busqueda: '',
            filtros: misma ? estado.filtros : {},
            columnas: misma ? estado.columnas : columnasPorDefecto(f, puede),
        }, { nuevoPaso: true });
    }

    function usarAtajo(f, atajo) {
        ir({
            fuente: f, paso: 4, pagina: 1, busqueda: '', etiquetas: {},
            filtros: limpiarFiltros(atajo.filtros, f),
            columnas: atajo.columnas ? limpiarColumnas(atajo.columnas, f, puede) : columnasPorDefecto(f, puede),
        }, { nuevoPaso: true });
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
            <div className={`max-w-5xl mx-auto px-4 lg:px-8 pt-6 lg:pt-8 ${contando ? 'pb-64 sm:pb-44' : 'pb-16'} text-lg text-ink`}>
                <header>
                    <h1 className="font-heading text-4xl font-bold text-primary">Reportes</h1>
                    <p className="mt-2 text-lg text-ink-soft">Arma una lista de personas, mírala aquí y descárgala.</p>
                    {fuentes.length > 0 && <Stepper paso={paso} hayFuente={!!fuente} onPaso={alPaso} />}
                </header>

                <section className="mt-8" aria-labelledby="paso-actual">
                    {/* Takes the focus on each step change, so a screen reader says where the user is. */}
                    <p id="paso-actual" ref={titulo} tabIndex={-1} className="sr-only">
                        Paso {paso} de 4: {PASOS[paso - 1].titulo}
                    </p>

                    {fuentes.length === 0 && (
                        <div className="p-6 rounded-lg bg-panel border-2 border-line">
                            <p className="font-heading text-2xl font-bold text-ink">Todavía no tienes listas para armar</p>
                            <p className="mt-2 text-lg text-ink">Pídele a tu administrador el permiso para ver Votantes o el Mapa Político.</p>
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
                            fuente={fuente}
                            columnas={estado.columnas}
                            puedeDatosPersonales={puede}
                            onColumnas={(columnas) => ir({ columnas })}
                        />
                    )}

                    {fuente && paso === 4 && (
                        <ListaReporte
                            spec={spec}
                            estado={estado}
                            fuente={fuente}
                            chips={chips}
                            tope={Number(topes?.excel) || 0}
                            puedeDatosPersonales={puede}
                            puedeMapa={puedeMapa}
                            fichaProps={{ cargosPorTipo, cargoCategorias, municipios }}
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
                />
            )}
        </AppLayout>
    );
}
