import { useEffect, useId, useRef, useState } from 'react';
import { conFiltro, mismoPeriodo, periodosDe, resumenFiltros, sinChip, unidadDe, validarFechas, validarRango } from '@/lib/reportes';
import ChipGroup from './ChipGroup';
import BuscadorOpciones from './BuscadorOpciones';
import { Chip, IconoChevron, IconoX, focoCls, tactilCls } from './ui';

const RANGOS_EDAD = [
    { etiqueta: '18 a 25', desde: 18, hasta: 25 },
    { etiqueta: '26 a 40', desde: 26, hasta: 40 },
    { etiqueta: '41 a 60', desde: 41, hasta: 60 },
    { etiqueta: 'Más de 60', desde: 61, hasta: null },
];

function Seccion({ titulo, abierto, onAlternar, elegidos, children }) {
    const id = useId();
    return (
        <section className="border-b border-line last:border-b-0">
            <h3>
                <button
                    type="button"
                    aria-expanded={abierto}
                    aria-controls={`${id}-c`}
                    onClick={onAlternar}
                    className={`w-full min-h-12 flex items-center justify-between gap-3 px-4 py-2.5 text-left ${focoCls}`}
                >
                    <span className="min-w-0 font-heading text-base font-bold text-ink break-words">{titulo}</span>
                    <span className="flex items-center gap-2 flex-shrink-0">
                        {elegidos > 0 && (
                            <span className="text-[13px] font-semibold text-primary bg-primary/10 rounded-full px-2.5 py-0.5">
                                {elegidos} {elegidos === 1 ? 'elegido' : 'elegidos'}
                            </span>
                        )}
                        <IconoChevron abierto={abierto} className="w-5 h-5 text-ink-soft" />
                    </span>
                </button>
            </h3>
            <div id={`${id}-c`} hidden={!abierto} className="px-4 pb-4 space-y-5">
                {children}
            </div>
        </section>
    );
}

function Rango({ filtro, valor, onCambiar }) {
    const id = useId();
    const rangos = filtro.rangos.length ? filtro.rangos : RANGOS_EDAD;
    const actual = valor ?? { desde: null, hasta: null };
    const [desde, setDesde] = useState(actual.desde ?? '');
    const [hasta, setHasta] = useState(actual.hasta ?? '');
    // The last range this box applied: a change from outside (a chip, "Quitar") refills the boxes, our own does not.
    const enviado = useRef(JSON.stringify(actual));
    const unidad = filtro.unidad || 'años';

    useEffect(() => {
        const externo = JSON.stringify({ desde: actual.desde, hasta: actual.hasta });
        if (externo === enviado.current) return;
        enviado.current = externo;
        setDesde(actual.desde ?? '');
        setHasta(actual.hasta ?? '');
    }, [actual.desde, actual.hasta]);

    const { error } = validarRango(desde, hasta);

    function aplicar(valorNuevo) {
        enviado.current = JSON.stringify(valorNuevo ?? { desde: null, hasta: null });
        onCambiar(valorNuevo);
    }

    // What is typed stays in the box; an invalid range removes the filter instead of applying half of it.
    function escribir(campo, texto) {
        const limpio = texto.slice(0, 6);
        const next = { desde, hasta, [campo]: limpio };
        campo === 'desde' ? setDesde(limpio) : setHasta(limpio);
        const r = validarRango(next.desde, next.hasta);
        aplicar(r.error ? null : r.rango);
    }

    function elegirRango(x, activo) {
        const r = activo ? null : { desde: x.desde ?? null, hasta: x.hasta ?? null };
        setDesde(r?.desde ?? '');
        setHasta(r?.hasta ?? '');
        aplicar(r);
    }

    const inputCls = `w-20 ${tactilCls} px-2 text-[15px] text-center rounded-md border bg-bg text-ink focus:border-primary ${focoCls}`;
    return (
        <div role="group" aria-labelledby={`${id}-t`}>
            <p id={`${id}-t`} className="text-[15px] font-semibold text-ink mb-2">{filtro.etiqueta}</p>
            <div className="flex flex-wrap gap-2">
                {rangos.map(x => {
                    const activo = actual.desde === (x.desde ?? null) && actual.hasta === (x.hasta ?? null);
                    return (
                        <Chip key={x.etiqueta} activo={activo} onClick={() => elegirRango(x, activo)}>
                            {x.etiqueta}
                        </Chip>
                    );
                })}
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-[15px] text-ink">
                <span>Otra: desde</span>
                <label className="sr-only" htmlFor={`${id}-d`}>{filtro.etiqueta} desde</label>
                <input id={`${id}-d`} inputMode="numeric" value={desde} onChange={e => escribir('desde', e.target.value)}
                    aria-invalid={!!error} aria-describedby={error ? `${id}-e` : undefined}
                    className={`${inputCls} ${error ? 'border-danger' : 'border-line-strong'}`} />
                <span>hasta</span>
                <label className="sr-only" htmlFor={`${id}-h`}>{filtro.etiqueta} hasta</label>
                <input id={`${id}-h`} inputMode="numeric" value={hasta} onChange={e => escribir('hasta', e.target.value)}
                    aria-invalid={!!error} aria-describedby={error ? `${id}-e` : undefined}
                    className={`${inputCls} ${error ? 'border-danger' : 'border-line-strong'}`} />
                <span>{unidad}</span>
            </div>
            {error && (
                <p id={`${id}-e`} role="alert" className="mt-2 text-sm text-danger font-semibold">
                    {error} Mientras tanto no se filtra por {filtro.etiqueta.toLowerCase()}.
                </p>
            )}
            {filtro.ayuda && <p className="mt-2 text-[13px] text-ink-soft">{filtro.ayuda}</p>}
        </div>
    );
}

// Two big date boxes with one-tap periods. Same rule as Rango: while the pair is invalid nothing is applied.
// A relative period ({relativo}) leaves the boxes empty: it is a moving window, not two dates.
function Fechas({ filtro, valor, onCambiar }) {
    const id = useId();
    const actual = valor ?? { desde: null, hasta: null };
    const externo = JSON.stringify(actual);
    const [desde, setDesde] = useState(actual.desde ?? '');
    const [hasta, setHasta] = useState(actual.hasta ?? '');
    const [incompletas, setIncompletas] = useState({ desde: false, hasta: false });
    const enviado = useRef(externo);
    const periodos = periodosDe(filtro);

    useEffect(() => {
        if (externo === enviado.current) return;
        enviado.current = externo;
        const v = JSON.parse(externo);
        setDesde(v.desde ?? '');
        setHasta(v.hasta ?? '');
        setIncompletas({ desde: false, hasta: false });
    }, [externo]);

    const { error } = validarFechas(desde, hasta, { incompleta: incompletas.desde || incompletas.hasta });

    function aplicar(valorNuevo) {
        enviado.current = JSON.stringify(valorNuevo ?? { desde: null, hasta: null });
        onCambiar(valorNuevo);
    }

    // A date box gives '' while the date is half typed; validity.badInput tells that apart from an empty box.
    function escribir(campo, input) {
        const next = { desde, hasta, [campo]: input.value };
        const inc = { ...incompletas, [campo]: !!input.validity?.badInput };
        campo === 'desde' ? setDesde(input.value) : setHasta(input.value);
        setIncompletas(inc);
        const r = validarFechas(next.desde, next.hasta, { incompleta: inc.desde || inc.hasta });
        aplicar(r.error ? null : r.rango);
    }

    function elegir(p, activo) {
        const r = activo ? null : p.valor;
        setDesde(r?.desde ?? '');
        setHasta(r?.hasta ?? '');
        setIncompletas({ desde: false, hasta: false });
        aplicar(r);
    }

    const inputCls = `w-full sm:w-48 ${tactilCls} px-3 text-[15px] rounded-md border bg-bg text-ink focus:border-primary ${focoCls}`;
    const caja = (campo, texto, v) => (
        <div>
            <label htmlFor={`${id}-${campo}`} className="block text-sm text-ink mb-1">
                {texto}<span className="sr-only"> ({filtro.etiqueta})</span>
            </label>
            <input
                id={`${id}-${campo}`}
                type="date"
                value={v}
                onChange={e => escribir(campo, e.target)}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-e` : undefined}
                className={`${inputCls} ${error ? 'border-danger' : 'border-line-strong'}`}
            />
        </div>
    );

    return (
        <div role="group" aria-labelledby={`${id}-t`}>
            <p id={`${id}-t`} className="text-[15px] font-semibold text-ink mb-2">{filtro.etiqueta}</p>
            <div className="flex flex-wrap gap-2">
                {periodos.map(p => {
                    const activo = mismoPeriodo(p.valor, actual);
                    return <Chip key={p.etiqueta} activo={activo} onClick={() => elegir(p, activo)}>{p.etiqueta}</Chip>;
                })}
            </div>
            <div className="mt-3 flex flex-col sm:flex-row sm:items-end gap-3">
                {caja('desde', 'Desde', desde)}
                {caja('hasta', 'Hasta', hasta)}
            </div>
            {error && (
                <p id={`${id}-e`} role="alert" className="mt-2 text-sm text-danger font-semibold">
                    {error} Mientras tanto no se filtra por {filtro.etiqueta.toLowerCase()}.
                </p>
            )}
            {filtro.ayuda && <p className="mt-2 text-[13px] text-ink-soft">{filtro.ayuda}</p>}
        </div>
    );
}

function Interruptor({ filtro, valor, onCambiar }) {
    return (
        <div>
            <Chip activo={!!valor} onClick={() => onCambiar(!valor)}>{filtro.etiqueta}</Chip>
            {filtro.ayuda && <p className="mt-2 text-[13px] text-ink-soft">{filtro.ayuda}</p>}
        </div>
    );
}

function Control({ filtro, filtros, etiquetas, onCambiar }) {
    const valor = filtros[filtro.clave];
    const cambiar = (v, nuevasEtiquetas) => onCambiar(filtro.clave, v, nuevasEtiquetas);

    if (filtro.tipo === 'rango') return <Rango filtro={filtro} valor={valor} onCambiar={cambiar} />;
    if (filtro.tipo === 'fechas') return <Fechas filtro={filtro} valor={valor} onCambiar={cambiar} />;
    if (filtro.tipo === 'interruptor') return <Interruptor filtro={filtro} valor={valor} onCambiar={cambiar} />;

    // A filter that depends on another (Comuna on Municipio) only offers the options of what is chosen there.
    const padre = filtro.dependeDe ? [].concat(filtros[filtro.dependeDe] ?? []) : [];
    let opciones = filtro.opciones;
    if (filtro.dependeDe && opciones) {
        opciones = opciones.filter(o => padre.includes(String(o[filtro.dependeDe] ?? o.padre ?? '')));
        if (opciones.length === 0) return null;
    }

    if (filtro.tipo === 'buscar') {
        return (
            <BuscadorOpciones
                filtro={opciones ? { ...filtro, opciones } : filtro}
                valor={[].concat(valor ?? [])}
                etiquetas={etiquetas}
                // A remote search (barrios…) looks only in the municipios already chosen.
                municipios={filtro.remoto ? [].concat(filtros.municipio ?? []).map(String) : []}
                onCambiar={cambiar}
            />
        );
    }
    return (
        <ChipGroup
            etiqueta={filtro.etiqueta}
            opciones={opciones ?? []}
            valor={valor}
            multiple={filtro.tipo !== 'unico'}
            porDefecto={filtro.porDefecto}
            ayuda={filtro.ayuda}
            onCambiar={cambiar}
        />
    );
}

export default function PasoFiltros({ fuente, filtros, etiquetas, onFiltros }) {
    const grupos = fuente.secciones;
    const [abiertos, setAbiertos] = useState(() => new Set(grupos.filter(g => g.abierto).map(g => g.titulo)));
    const chips = resumenFiltros(filtros, fuente, etiquetas);
    const u = unidadDe(fuente, filtros);
    const pregunta = u.quienes === 'A quiénes' ? '¿A quiénes quieres ver?' : `¿Qué ${u.plural} quieres ver?`;

    function cambiar(clave, valor, nuevasEtiquetas = {}) {
        const next = conFiltro(filtros, clave, valor);
        // Changing the parent (Municipio) drops the children (Comuna) that no longer belong to it.
        const padres = [].concat(next[clave] ?? []);
        for (const hijo of fuente.filtros.filter(f => f.dependeDe === clave && f.opciones && next[f.clave] !== undefined)) {
            const vivos = [].concat(next[hijo.clave]).filter(v => hijo.opciones.some(o => o.valor === v && padres.includes(String(o[clave] ?? o.padre ?? ''))));
            if (vivos.length) next[hijo.clave] = vivos;
            else delete next[hijo.clave];
        }
        onFiltros(next, nuevasEtiquetas);
    }

    function alternar(titulo) {
        setAbiertos(prev => {
            const next = new Set(prev);
            next.has(titulo) ? next.delete(titulo) : next.add(titulo);
            return next;
        });
    }

    return (
        <div>
            <h2 className="font-heading text-xl font-bold text-ink text-balance">{pregunta}</h2>

            <div className="mt-4 p-4 rounded-lg bg-panel border border-line">
                {chips.length === 0 ? (
                    <p className="text-[15px] text-ink">Estás viendo {u.a}<strong>{u.todas}</strong>. Elige abajo para quedarte con {u.femenino ? 'algunas' : 'algunos'}.</p>
                ) : (
                    <>
                        <p className="text-[15px] font-semibold text-ink mb-2">Estás viendo:</p>
                        <ul className="flex flex-wrap gap-2" aria-label="Filtros elegidos">
                            {chips.map(c => (
                                <li key={c.id}>
                                    <Chip activo onClick={() => onFiltros(sinChip(filtros, c), {})} aria-label={`Quitar ${c.texto}`}>
                                        {c.texto}
                                        <IconoX />
                                    </Chip>
                                </li>
                            ))}
                        </ul>
                        <button type="button" onClick={() => onFiltros({}, {})} className={`mt-3 ${tactilCls} px-1 text-sm font-semibold text-primary underline underline-offset-4 rounded ${focoCls}`}>
                            Quitar todos los filtros
                        </button>
                    </>
                )}
            </div>

            <p className="mt-3 text-sm text-ink-soft">Si eliges varios, verás {u.a}{u.femenino ? 'las' : 'los'} {u.plural} de cualquiera de ellos.</p>

            <div className="mt-3 rounded-lg bg-panel border border-line">
                {grupos.map(g => (
                    <Seccion
                        key={g.titulo}
                        titulo={g.titulo}
                        abierto={abiertos.has(g.titulo)}
                        onAlternar={() => alternar(g.titulo)}
                        elegidos={g.filtros.filter(f => filtros[f.clave] !== undefined).length}
                    >
                        {g.filtros.map(f => (
                            <Control key={f.clave} filtro={f} filtros={filtros} etiquetas={etiquetas} onCambiar={cambiar} />
                        ))}
                    </Seccion>
                ))}
            </div>
        </div>
    );
}
