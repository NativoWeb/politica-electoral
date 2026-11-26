import { useId, useMemo, useState } from 'react';
import { etiquetaValor, mismoValor, pedirOpciones, useDebouncedRequest } from '@/lib/reportes';
import { Boton, Chip, Girando, IconoBuscar, IconoX, focoCls, tactilCls } from './ui';

const sinTildes = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// "Buscar municipio…", "Buscar barrio…": local options filtered as you type, or a remote search
// (GET /reportes/opciones/{filtro}) debounced with the request in flight aborted. Never takes the focus by itself.
export default function BuscadorOpciones({ filtro, valor = [], etiquetas = {}, municipios = [], onCambiar }) {
    const id = useId();
    const [q, setQ] = useState('');
    const [abierto, setAbierto] = useState(false);
    const remoto = filtro.remoto;
    const clave = `${filtro.clave}|${q.trim()}|${municipios.join(',')}`;
    const busqueda = useDebouncedRequest(
        (signal) => pedirOpciones(filtro.opcionesDe, q.trim(), municipios, signal, filtro.fuente),
        clave,
        { enabled: remoto && abierto },
    );

    const locales = useMemo(() => {
        if (remoto) return [];
        const t = sinTildes(q.trim());
        return (filtro.opciones ?? []).filter(o => !t || sinTildes(o.etiqueta).includes(t));
    }, [remoto, filtro.opciones, q]);

    const resultados = remoto ? (busqueda.datos ?? []) : locales;
    const cargando = remoto && busqueda.estado === 'cargando';

    function alternar(op) {
        const ya = valor.some(v => mismoValor(v, op.valor));
        onCambiar(ya ? valor.filter(v => !mismoValor(v, op.valor)) : [...valor, op.valor], ya || !remoto ? {} : { [String(op.valor)]: op.etiqueta });
    }

    return (
        <div>
            <label htmlFor={`${id}-q`} className="block text-[15px] font-semibold text-ink mb-2">{filtro.etiqueta}</label>
            {valor.length > 0 && (
                <ul className="flex flex-wrap gap-2 mb-2" aria-label={`${filtro.etiqueta} elegidos`}>
                    {valor.map(v => {
                        const nombre = etiquetaValor(filtro, v, etiquetas);
                        return (
                            <li key={v}>
                                <Chip activo onClick={() => onCambiar(valor.filter(x => !mismoValor(x, v)), {})} aria-label={`Quitar ${nombre}`}>
                                    {nombre}
                                    <IconoX className="w-4 h-4" />
                                </Chip>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="relative">
                <IconoBuscar className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-soft pointer-events-none" />
                <input
                    id={`${id}-q`}
                    type="search"
                    value={q}
                    onChange={e => { setQ(e.target.value); setAbierto(true); }}
                    onFocus={() => setAbierto(true)}
                    placeholder={filtro.placeholder}
                    autoComplete="off"
                    enterKeyHint="search"
                    aria-controls={`${id}-r`}
                    aria-expanded={abierto}
                    className={`w-full ${tactilCls} pl-9 pr-3 text-[15px] rounded-md border border-line-strong bg-bg text-ink placeholder:text-ink-soft focus:border-primary ${focoCls}`}
                />
            </div>
            {abierto && (
                <div id={`${id}-r`} className="mt-1.5 rounded-md border border-line bg-panel">
                    <div aria-live="polite" className="text-sm text-ink-soft">
                        {cargando && <p className="flex items-center gap-2 px-3 py-2"><Girando className="w-4 h-4" />Buscando…</p>}
                        {remoto && busqueda.estado === 'error' && (
                            <div className="flex flex-wrap items-center gap-2 px-3 py-2 text-ink">
                                No pudimos buscar. Revisa tu conexión.
                                <Boton variante="secundario" onClick={busqueda.reintentar}>Intentar de nuevo</Boton>
                            </div>
                        )}
                        {!cargando && busqueda.estado !== 'error' && resultados.length === 0 && (
                            <p className="px-3 py-2">{q.trim() ? `No encontramos «${q.trim()}».` : 'Escribe para buscar.'}</p>
                        )}
                    </div>
                    {resultados.length > 0 && (
                        <ul className={`max-h-72 overflow-y-auto divide-y divide-line ${cargando ? 'opacity-50' : ''}`}>
                            {resultados.map(op => {
                                const marcado = valor.some(v => mismoValor(v, op.valor));
                                return (
                                    <li key={op.valor}>
                                        <button
                                            type="button"
                                            aria-pressed={marcado}
                                            onClick={() => alternar(op)}
                                            className={`w-full ${tactilCls} flex items-center gap-3 px-3 py-1.5 text-left text-[15px] ${focoCls} ${marcado ? 'bg-primary/5 font-semibold text-primary' : 'text-ink hover:bg-bg'}`}
                                        >
                                            <span aria-hidden="true" className={`w-5 h-5 flex-shrink-0 rounded border-2 flex items-center justify-center ${marcado ? 'bg-primary border-primary text-white' : 'border-line-strong bg-panel'}`}>
                                                {marcado && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3.5} className="w-3.5 h-3.5"><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>}
                                            </span>
                                            {op.etiqueta}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <div className="p-2 border-t border-line flex justify-end">
                        <Boton variante="secundario" onClick={() => { setAbierto(false); setQ(''); }}>Listo</Boton>
                    </div>
                </div>
            )}
        </div>
    );
}
