import { useCallback, useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { AvisoError, Boton, Estado, IconoMas, LinkBoton, focoCls, tarjetaCls, tituloPaginaCls } from '@/Components/CampanasElectorales/ui';
import { BASE, listarCampanas, mensajeDe, normalizarLista, numero, plural, urlTablero } from '@/lib/campanas';

function TarjetaCampana({ c }) {
    const quien = [c.candidatoNombre, c.partidoNombre].filter(Boolean).join(' · ');
    return (
        <li className={`${tarjetaCls} p-4 flex flex-col sm:flex-row sm:items-center gap-3`}>
            <div className="flex-1 min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-heading text-[17px] font-bold text-ink text-balance break-words">{c.nombre}</h2>
                    <Estado archivada={c.archivada} />
                </div>
                <p className="mt-0.5 text-sm text-ink">
                    {c.cargoEtiqueta}{c.anio ? ` ${c.anio}` : ''}{quien ? <span className="text-ink-soft"> — {quien}</span> : null}
                </p>
                <p className="mt-0.5 text-[13px] text-ink-soft tabular-nums">
                    {c.territorioResumen || plural(c.territorios, 'territorio', 'territorios')} · Metas cargadas: {numero(c.metasCargadas)} de {numero(c.territorios)}
                </p>
                {!c.grupoPropio && c.grupoNombre && (
                    <p className="mt-0.5 text-[13px] font-semibold text-ink-soft">Grupo político: {c.grupoNombre} · Solo lectura</p>
                )}
            </div>
            <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <LinkBoton href={urlTablero(c.id)} className="w-full sm:w-auto" aria-label={`Ver tablero de ${c.nombre}`}>
                    Ver tablero
                </LinkBoton>
                <LinkBoton variante="secundario" href={`${BASE}/${c.id}`} className="w-full sm:w-auto" aria-label={`Ver campaña ${c.nombre}`}>
                    Ver campaña
                </LinkBoton>
            </div>
        </li>
    );
}

function Esqueleto() {
    return (
        <ul aria-hidden="true" className="space-y-3">
            {[0, 1, 2].map(i => (
                <li key={i} className={`${tarjetaCls} p-4 motion-safe:animate-pulse`}>
                    <div className="h-5 w-2/3 rounded bg-line" />
                    <div className="mt-2 h-4 w-1/2 rounded bg-line" />
                    <div className="mt-2 h-4 w-1/3 rounded bg-line" />
                </li>
            ))}
        </ul>
    );
}

// The first list comes in the page props; switching tab asks for the other one as JSON (no full visit).
export default function CampanasLista(props) {
    const inicial = normalizarLista(props);
    const [archivadas, setArchivadas] = useState(!!props.filtros?.archivadas);
    const [estado, setEstado] = useState({ cargando: false, error: '', ...inicial });
    const control = useRef(null);

    const cargar = useCallback(async (verArchivadas) => {
        control.current?.abort();
        const ctrl = new AbortController();
        control.current = ctrl;
        setEstado(e => ({ ...e, cargando: true, error: '' }));
        try {
            const res = await listarCampanas({ archivadas: verArchivadas, signal: ctrl.signal });
            if (ctrl.signal.aborted) return;
            setEstado(e => (res.ok
                ? { cargando: false, error: '', ...res.lista }
                : { ...e, cargando: false, error: res.status === 403 ? mensajeDe(res) : 'No pudimos cargar las campañas.', campanas: [] }));
        } catch {
            // Aborted: a newer request is on its way.
        }
    }, []);

    useEffect(() => () => control.current?.abort(), []);

    // New props (the superadmin changed the group selector and the page reloaded): they replace what is on screen.
    const { campanas: propsCampanas, createBlocked, can, filtros, truncated } = props;
    useEffect(() => {
        control.current?.abort();
        setArchivadas(!!filtros?.archivadas);
        setEstado({ cargando: false, error: '', ...normalizarLista({ campanas: propsCampanas, createBlocked, can, truncated }) });
    }, [propsCampanas, createBlocked, can, filtros, truncated]);

    function elegir(verArchivadas) {
        if (verArchivadas === archivadas) return;
        setArchivadas(verArchivadas);
        window.history.replaceState(window.history.state, '', verArchivadas ? `${BASE}?archivadas=1` : BASE);
        cargar(verArchivadas);
    }

    const { puedeGestionar, crearBloqueado, truncada } = estado;
    const { cargando, error, campanas } = estado;

    return (
        <AppLayout title="Campañas electorales">
            <Head title="Campañas electorales" />
            <div className="max-w-5xl mx-auto px-4 lg:px-6 pt-4 lg:pt-6 pb-10 text-[15px] text-ink">
                <header className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                    <div>
                        <h1 className={tituloPaginaCls}>Campañas electorales</h1>
                        <p className="mt-1 text-[13px] text-ink-soft">Las metas de votos, líderes y votantes de cada campaña, por territorio.</p>
                    </div>
                    {puedeGestionar && !crearBloqueado && (
                        <LinkBoton href={`${BASE}/nueva`} className="w-full sm:w-auto"><IconoMas />Nueva campaña</LinkBoton>
                    )}
                </header>
                {puedeGestionar && crearBloqueado && (
                    <p role="note" className="mt-3 p-3 rounded-md border border-line-strong bg-panel text-sm text-ink">{crearBloqueado}</p>
                )}

                <div role="group" aria-label="Qué campañas ver" className="mt-4 grid grid-cols-2 sm:inline-grid sm:grid-cols-[auto_auto] gap-1 p-1 rounded-lg bg-panel border border-line">
                    {[[false, 'Activas'], [true, 'Archivadas']].map(([valor, etiqueta]) => (
                        <button
                            key={etiqueta}
                            type="button"
                            aria-pressed={archivadas === valor}
                            onClick={() => elegir(valor)}
                            className={`min-h-11 sm:min-h-8 px-4 rounded-md text-sm transition-colors duration-150 ${focoCls} ${archivadas === valor ? 'bg-primary text-white font-semibold' : 'text-ink hover:bg-bg'}`}
                        >
                            {etiqueta}
                        </button>
                    ))}
                </div>

                <section className="mt-4" aria-live="polite" aria-busy={cargando}>
                    {cargando && <><p className="sr-only">Cargando campañas…</p><Esqueleto /></>}
                    {!cargando && error && (
                        <AvisoError accion={<Boton variante="secundario" onClick={() => cargar(archivadas)}>Reintentar</Boton>}>{error}</AvisoError>
                    )}
                    {!cargando && !error && campanas.length === 0 && (
                        <div className={`${tarjetaCls} p-5`}>
                            <p className="text-[15px] text-ink">
                                {archivadas
                                    ? 'No hay campañas archivadas.'
                                    : (puedeGestionar && !crearBloqueado ? 'Aún no hay campañas. Crea la primera con "Nueva campaña".' : 'Aún no hay campañas.')}
                            </p>
                        </div>
                    )}
                    {!cargando && !error && campanas.length > 0 && (
                        <ul className="space-y-3" aria-label={archivadas ? 'Campañas archivadas' : 'Campañas activas'}>
                            {campanas.map(c => <TarjetaCampana key={c.id} c={c} />)}
                        </ul>
                    )}
                    {!cargando && !error && truncada && (
                        <p className="mt-3 text-sm text-ink-soft">Se muestran las más recientes. Archiva las que ya no uses para ver las demás.</p>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
