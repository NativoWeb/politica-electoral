import { useEffect, useId, useRef, useState } from 'react';
import { conFiltro, resumenFiltros, sinChip, validarRango } from '@/lib/reportes';
import ChipGroup from './ChipGroup';
import BuscadorOpciones from './BuscadorOpciones';
import { Chip, IconoChevron, IconoX, focoCls } from './ui';

const RANGOS_EDAD = [
    { etiqueta: '18 a 25', desde: 18, hasta: 25 },
    { etiqueta: '26 a 40', desde: 26, hasta: 40 },
    { etiqueta: '41 a 60', desde: 41, hasta: 60 },
    { etiqueta: 'Más de 60', desde: 61, hasta: null },
];

function Seccion({ titulo, abierto, onAlternar, elegidos, children }) {
    const id = useId();
    return (
        <section className="border-b-2 border-line last:border-b-0">
            <h3>
                <button
                    type="button"
                    aria-expanded={abierto}
                    aria-controls={`${id}-c`}
                    onClick={onAlternar}
                    className={`w-full min-h-16 flex items-center justify-between gap-4 px-5 py-4 text-left ${focoCls}`}
                >
                    <span className="font-heading text-xl font-bold text-ink">{titulo}</span>
                    <span className="flex items-center gap-3">
                        {elegidos > 0 && (
                            <span className="text-base font-semibold text-primary bg-primary/10 rounded-full px-3 py-1">
                                {elegidos} {elegidos === 1 ? 'elegido' : 'elegidos'}
                            </span>
                        )}
                        <IconoChevron abierto={abierto} className="w-6 h-6 text-ink-soft" />
                    </span>
                </button>
            </h3>
            <div id={`${id}-c`} hidden={!abierto} className="px-5 pb-6 space-y-7">
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

    const inputCls = `w-24 min-h-14 px-3 text-lg text-center rounded-md border-2 bg-bg text-ink focus:border-primary ${focoCls}`;
    return (
        <div role="group" aria-labelledby={`${id}-t`}>
            <p id={`${id}-t`} className="text-lg font-semibold text-ink mb-3">{filtro.etiqueta}</p>
            <div className="flex flex-wrap gap-3">
                {rangos.map(x => {
                    const activo = actual.desde === (x.desde ?? null) && actual.hasta === (x.hasta ?? null);
                    return (
                        <Chip key={x.etiqueta} activo={activo} onClick={() => elegirRango(x, activo)}>
                            {x.etiqueta}
                        </Chip>
                    );
                })}
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-3 text-lg text-ink">
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
                <p id={`${id}-e`} role="alert" className="mt-3 text-lg text-danger font-semibold">
                    {error} Mientras tanto no se filtra por {filtro.etiqueta.toLowerCase()}.
                </p>
            )}
            {filtro.ayuda && <p className="mt-3 text-base text-ink-soft">{filtro.ayuda}</p>}
        </div>
    );
}

function Interruptor({ filtro, valor, onCambiar }) {
    return (
        <div>
            <Chip activo={!!valor} onClick={() => onCambiar(!valor)}>{filtro.etiqueta}</Chip>
            {filtro.ayuda && <p className="mt-3 text-base text-ink-soft">{filtro.ayuda}</p>}
        </div>
    );
}

function Control({ filtro, filtros, etiquetas, onCambiar }) {
    const valor = filtros[filtro.clave];
    const cambiar = (v, nuevasEtiquetas) => onCambiar(filtro.clave, v, nuevasEtiquetas);

    if (filtro.tipo === 'rango') return <Rango filtro={filtro} valor={valor} onCambiar={cambiar} />;
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
            <h2 className="font-heading text-3xl font-bold text-ink text-balance">¿A quiénes quieres ver?</h2>

            <div className="mt-5 p-5 rounded-lg bg-panel border-2 border-line">
                {chips.length === 0 ? (
                    <p className="text-lg text-ink">Estás viendo a <strong>todas las personas</strong>. Elige abajo para quedarte con algunas.</p>
                ) : (
                    <>
                        <p className="text-lg font-semibold text-ink mb-3">Estás viendo:</p>
                        <ul className="flex flex-wrap gap-3" aria-label="Filtros elegidos">
                            {chips.map(c => (
                                <li key={c.id}>
                                    <Chip activo onClick={() => onFiltros(sinChip(filtros, c), {})} aria-label={`Quitar ${c.texto}`}>
                                        {c.texto}
                                        <IconoX />
                                    </Chip>
                                </li>
                            ))}
                        </ul>
                        <button type="button" onClick={() => onFiltros({}, {})} className={`mt-4 min-h-12 px-2 text-lg font-semibold text-primary underline underline-offset-4 rounded ${focoCls}`}>
                            Quitar todos los filtros
                        </button>
                    </>
                )}
            </div>

            <p className="mt-4 text-lg text-ink-soft">Si eliges varios, verás a las personas de cualquiera de ellos.</p>

            <div className="mt-4 rounded-lg bg-panel border-2 border-line">
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
