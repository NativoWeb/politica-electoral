import { useEffect, useId, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { DEBOUNCE_MS, accionDe, cantidad, mayuscula, mensajeError, numero, pedirLista, unidadDe, useDebouncedRequest } from '@/lib/reportes';
import OrganizacionPanel from '@/Components/Empresarios/OrganizacionPanel';
import TarjetaPersona, { textoCelda } from './TarjetaPersona';
import BotonDescarga from './BotonDescarga';
import FichaPersona from './FichaPersona';
import { AvisoError, Boton, IconoBuscar, IconoFlecha, IconoX, focoCls, tactilCls } from './ui';

function Esqueleto() {
    return (
        <div aria-hidden="true" className="space-y-2">
            {Array.from({ length: 5 }, (_, i) => (
                <div key={i} className="h-11 rounded-md bg-line animate-pulse motion-reduce:animate-none" />
            ))}
        </div>
    );
}

function Paginacion({ pagina, total, porPagina, onPagina }) {
    const paginas = Math.max(1, Math.ceil(total / porPagina));
    if (total === 0) return null;
    const desde = (pagina - 1) * porPagina + 1;
    const hasta = Math.min(total, pagina * porPagina);
    return (
        <nav aria-label="Páginas de la lista" className="mt-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            <Boton variante="secundario" onClick={() => onPagina(pagina - 1)} disabled={pagina <= 1}>
                <IconoFlecha direccion="izquierda" />
                Anteriores
            </Boton>
            <p className="text-sm text-ink text-center">
                <span className="font-semibold">Página {numero(pagina)} de {numero(paginas)}</span>
                <span className="block text-ink-soft">Mostrando {numero(desde)} a {numero(hasta)} de {numero(total)}</span>
            </p>
            <Boton variante="secundario" onClick={() => onPagina(pagina + 1)} disabled={pagina >= paginas}>
                Siguientes
                <IconoFlecha />
            </Boton>
        </nav>
    );
}

// Step 4: the list itself. Tapping a row does what its accion says: a ficha or an organization opens on top
// (closing it reloads the same page of the same spec; the rows stay on screen meanwhile, so the scroll does not
// jump); a compromiso is a visit to its page (Back returns here: the whole list lives in the URL); no accion, no tap.
export default function ListaReporte({
    spec, estado, fuente, chips, tope, topePdf, puedeDatosPersonales, puedeMapa, fichaProps, organizacionProps = null, nota = '',
    onBusqueda, onOrden, onPagina, onCambiarPaso,
}) {
    const id = useId();
    const [refresco, setRefresco] = useState(0);
    const [ficha, setFicha] = useState(null);
    const [org, setOrg] = useState(null);
    const [aviso, setAviso] = useState('');
    const pendiente = useRef(null);
    const botones = useRef(new Map());
    const inicioLista = useRef(null);
    const [texto, setTexto] = useState(estado.busqueda);

    const caja = useRef(null);
    // The URL catches up a moment after each key: while typing, the box keeps what is typed.
    useEffect(() => {
        if (document.activeElement !== caja.current) setTexto(estado.busqueda);
    }, [estado.busqueda]);

    const clave = JSON.stringify([spec, estado.pagina, refresco]);
    // Typing waits for the pause; the reload after closing a ficha goes at once.
    const lista = useDebouncedRequest((signal) => pedirLista(spec, estado.pagina, signal), clave, { delay: pendiente.current ? 0 : DEBOUNCE_MS });
    const datos = lista.datos;
    const refrescando = lista.estado === 'cargando' && pendiente.current !== null;

    useEffect(() => {
        if (lista.estado !== 'ok' || !pendiente.current) return;
        const { filaId: fichaId, nombre, guardado } = pendiente.current;
        pendiente.current = null;
        if (!datos.filas.some(f => String(f.id) === String(fichaId))) {
            setAviso(`${guardado ? 'Guardado. ' : ''}${nombre} ya no cumple los filtros, por eso salió de la lista.`);
            if (datos.filas.length === 0 && estado.pagina > 1) onPagina(estado.pagina - 1, { reemplazar: true });
        } else {
            if (guardado) setAviso('Guardado.');
            // Back to the row that opened it: the table button on a desktop, the card on a phone.
            for (const k of [String(fichaId), `m:${fichaId}`]) {
                const el = botones.current.get(k);
                el?.focus();
                if (el && document.activeElement === el) break;
            }
        }
    }, [lista.estado, datos, estado.pagina, onPagina]);

    const columnas = estado.columnas.map(c => fuente.columnas.find(x => x.clave === c)).filter(Boolean);
    const u = unidadDe(fuente, estado.filtros);
    const textoCambiar = u.quienes === 'A quiénes' ? 'Cambiar a quiénes' : 'Cambiar filtros';

    function abrir(fila) {
        const accion = accionDe(fila);
        if (!accion) return;
        setAviso('');
        if (accion.tipo === 'compromiso') {
            router.visit(accion.url);
            return;
        }
        const abierta = { filaId: fila.id, id: accion.id, nombre: textoCelda(fila.celdas?.nombre ?? fila.celdas?.[columnas[0]?.clave]), guardado: false };
        if (accion.tipo === 'organizacion') setOrg(abierta);
        else setFicha(abierta);
    }

    function cerrar() {
        pendiente.current = ficha ?? org;
        setFicha(null);
        setOrg(null);
        setRefresco(r => r + 1);
    }

    const marcarGuardado = (set) => () => set(f => (f ? { ...f, guardado: true } : f));

    function irAPagina(p) {
        onPagina(p);
        inicioLista.current?.scrollIntoView?.({ block: 'start' });
    }

    const [campoOrden, dirOrden] = estado.orden.split(':');
    const total = datos?.total ?? null;
    const mostrarEsqueleto = lista.estado !== 'error' && !datos && lista.estado !== 'ok';
    const sinDatos = lista.estado === 'ok' && datos.filas.length === 0 && !refrescando;

    function ordenarPor(col) {
        const asc = `${col.clave}:asc`;
        const desc = `${col.clave}:desc`;
        const valor = campoOrden === col.clave && dirOrden === 'asc' ? desc : asc;
        if (fuente.ordenes.some(o => o.valor === valor)) onOrden(valor);
    }

    return (
        <div>
            <h2 className="font-heading text-xl font-bold text-ink">Tu lista</h2>
            <p className="mt-1 text-base text-ink break-words" aria-live="polite">
                <strong className="tabular-nums">{total === null ? 'Cargando…' : cantidad(total, u)}</strong>
                {chips.map(c => <span key={c.id}> · {c.texto}</span>)}
            </p>
            {nota && <p className="mt-0.5 text-[13px] text-ink-soft">{nota}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
                <Boton variante="secundario" onClick={() => onCambiarPaso(2)}>{textoCambiar}</Boton>
                <Boton variante="secundario" onClick={() => onCambiarPaso(3)}>Cambiar datos</Boton>
            </div>

            <div className="mt-4 p-4 rounded-lg bg-panel border border-line grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4 items-end">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label htmlFor={`${id}-q`} className="block text-sm font-semibold text-ink mb-1">Buscar en esta lista</label>
                        <div className="relative">
                            <IconoBuscar className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-soft pointer-events-none" />
                            <input
                                ref={caja}
                                id={`${id}-q`}
                                type="search"
                                value={texto}
                                maxLength={100}
                                onChange={e => { setTexto(e.target.value); onBusqueda(e.target.value); }}
                                placeholder={fuente.clave === 'personas' ? 'Nombre o cédula…' : 'Escribe para buscar…'}
                                autoComplete="off"
                                enterKeyHint="search"
                                className={`w-full ${tactilCls} pl-9 pr-3 text-[15px] rounded-md border border-line-strong bg-bg text-ink placeholder:text-ink-soft focus:border-primary ${focoCls}`}
                            />
                        </div>
                    </div>
                    <div>
                        <label htmlFor={`${id}-o`} className="block text-sm font-semibold text-ink mb-1">Ordenar por</label>
                        <select
                            id={`${id}-o`}
                            value={estado.orden}
                            onChange={e => onOrden(e.target.value)}
                            className={`w-full ${tactilCls} px-3 text-[15px] rounded-md border border-line-strong bg-panel text-ink focus:border-primary ${focoCls}`}
                        >
                            {fuente.ordenes.map(o => <option key={o.valor} value={o.valor}>{o.etiqueta}</option>)}
                        </select>
                    </div>
                </div>
                <div>
                    <BotonDescarga
                        spec={spec}
                        total={total ?? 0}
                        tope={tope}
                        topePdf={topePdf}
                        columnas={columnas.map(c => c.etiqueta)}
                        chips={chips}
                        vacia={!total}
                        unidad={u}
                        base={fuente.etiqueta}
                    />
                    {!puedeDatosPersonales && fuente.columnas.some(c => c.sensible) && (
                        <p className="mt-1 text-[13px] text-ink-soft max-w-xs">
                            {fuente.clave === 'personas' ? 'Tu descarga no incluye cédula, teléfono ni dirección.' : 'Tu descarga no incluye los datos con candado.'}
                        </p>
                    )}
                </div>
            </div>

            {aviso && (
                <div role="status" className="mt-4 flex items-start justify-between gap-3 p-3 rounded-md border border-primary bg-primary/5 text-[15px] text-ink">
                    <span>{aviso}</span>
                    <button type="button" onClick={() => setAviso('')} aria-label="Cerrar aviso" className={`w-11 h-11 sm:w-9 sm:h-9 -m-2 sm:-m-1 flex-shrink-0 flex items-center justify-center rounded-md text-ink-soft hover:bg-primary/10 ${focoCls}`}>
                        <IconoX className="w-5 h-5" />
                    </button>
                </div>
            )}

            <div ref={inicioLista} className="mt-4 scroll-mt-24" aria-busy={lista.estado === 'cargando'}>
                {lista.estado === 'error' && (
                    <AvisoError accion={<Boton variante="secundario" onClick={lista.reintentar}>Intentar de nuevo</Boton>}>
                        {mensajeError(lista.error, 'lista')}
                    </AvisoError>
                )}

                {mostrarEsqueleto && <Esqueleto />}

                {sinDatos && (
                    <div className="p-4 rounded-lg bg-panel border border-line text-[15px] text-ink flex flex-col sm:flex-row sm:items-center gap-3">
                        {estado.busqueda.trim() ? (
                            <>
                                <span className="flex-1">{u.ninguna} con «{estado.busqueda.trim()}» en esta lista.</span>
                                <Boton variante="secundario" onClick={() => { setTexto(''); onBusqueda(''); }}>Borrar búsqueda</Boton>
                            </>
                        ) : (
                            <>
                                <span className="flex-1">{u.ninguna} cumple todos estos filtros. Prueba quitando uno.</span>
                                <Boton variante="secundario" onClick={() => onCambiarPaso(2)}>{textoCambiar}</Boton>
                            </>
                        )}
                    </div>
                )}

                {datos && datos.filas.length > 0 && lista.estado !== 'error' && (
                    <div className={lista.estado === 'cargando' && !refrescando ? 'opacity-50 transition-opacity' : ''}>
                        {/* Desktop: table. The whole row does its accion; the first cell is the keyboard target. A row without one is plain text. */}
                        <div className="hidden lg:block overflow-x-auto rounded-lg border border-line bg-panel">
                            <table className="w-full text-sm">
                                <caption className="sr-only">{mayuscula(u.plural)} de la lista, página {estado.pagina}</caption>
                                <thead className="bg-bg">
                                    <tr>
                                        {columnas.map(col => {
                                            const activo = campoOrden === col.clave;
                                            const sort = activo ? (dirOrden === 'desc' ? 'descending' : 'ascending') : undefined;
                                            return (
                                                <th key={col.clave} scope="col" aria-sort={sort} className="px-3 py-2 text-left font-semibold text-ink whitespace-nowrap">
                                                    {col.ordenable ? (
                                                        <button type="button" onClick={() => ordenarPor(col)} className={`min-h-9 inline-flex items-center gap-1.5 underline-offset-4 hover:underline rounded ${focoCls}`}>
                                                            {col.etiqueta}
                                                            {activo && <span aria-hidden="true">{dirOrden === 'desc' ? '↓' : '↑'}</span>}
                                                        </button>
                                                    ) : col.etiqueta}
                                                </th>
                                            );
                                        })}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {datos.filas.map(fila => {
                                        const clicable = accionDe(fila) !== null;
                                        return (
                                        <tr key={fila.id} onClick={clicable ? () => abrir(fila) : undefined} className={clicable ? 'h-11 cursor-pointer hover:bg-primary/5' : 'h-11'}>
                                            {columnas.map((col, i) => (
                                                <td key={col.clave} className="px-3 py-1.5 text-ink align-middle">
                                                    {i === 0 && !clicable ? (
                                                        <span className="font-semibold text-ink">{textoCelda(fila.celdas?.[col.clave])}</span>
                                                    ) : i === 0 ? (
                                                        <button
                                                            type="button"
                                                            ref={el => { if (el) botones.current.set(String(fila.id), el); }}
                                                            onClick={e => { e.stopPropagation(); abrir(fila); }}
                                                            className={`text-left font-semibold text-primary underline-offset-4 hover:underline rounded ${focoCls}`}
                                                        >
                                                            {textoCelda(fila.celdas?.[col.clave])}
                                                            {accionDe(fila)?.editable === false && <span className="sr-only"> (solo para ver)</span>}
                                                        </button>
                                                    ) : textoCelda(fila.celdas?.[col.clave])}
                                                </td>
                                            ))}
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Phone: cards. */}
                        <ul className="lg:hidden space-y-2">
                            {datos.filas.map(fila => (
                                <li key={fila.id}>
                                    <TarjetaPersona
                                        fila={fila}
                                        columnas={columnas}
                                        onAbrir={accionDe(fila) ? abrir : null}
                                        soloVer={accionDe(fila)?.editable === false}
                                        fuenteClave={fuente.clave}
                                        botonRef={el => { if (el) botones.current.set(`m:${fila.id}`, el); }}
                                    />
                                </li>
                            ))}
                        </ul>

                        <Paginacion pagina={estado.pagina} total={datos.total} porPagina={datos.porPagina} onPagina={irAPagina} />
                    </div>
                )}
            </div>

            {ficha && (
                <FichaPersona
                    personId={ficha.id}
                    puedeMapa={puedeMapa}
                    onClose={cerrar}
                    onSaved={marcarGuardado(setFicha)}
                    {...fichaProps}
                />
            )}

            {org && (
                <OrganizacionPanel
                    key={org.id}
                    orgId={org.id}
                    soloVer={!organizacionProps?.catalogos}
                    canManage={false}
                    catalogos={organizacionProps?.catalogos ?? {}}
                    municipios={organizacionProps?.municipios ?? []}
                    gremios={organizacionProps?.gremios ?? []}
                    rangos={organizacionProps?.rangos ?? []}
                    roles={organizacionProps?.roles ?? []}
                    onClose={cerrar}
                    onOpen={orgId => setOrg(o => (o ? { ...o, id: orgId } : o))}
                    onSaved={marcarGuardado(setOrg)}
                    onDeleted={cerrar}
                />
            )}
        </div>
    );
}
