import { useMemo, useState } from 'react';
import { Boton, Chip, focoCls } from '@/Components/Reportes/ui';
import { Muestra } from '@/Components/CampanasElectorales/ui';
import { ORDENES, colorDe, cuantos, numero, ordenarTerritorios, pinturaDe, porcentaje, textoEstado } from '@/lib/campanas';
import { useEsMovil } from '@/lib/useMediaQuery';

// Long lists (87 municipios) start with the first rows in the chosen order; one tap shows them all.
const FILAS_INICIALES = 20;

const regMeta = (m) => (m.meta === null ? `${numero(m.registrados)} · sin meta` : `${numero(m.registrados)} de ${numero(m.meta)}`);

function textoFalta(t) {
    const partes = [];
    if (t.votantes.falta > 0) partes.push(cuantos(t.votantes.falta, 'votantes'));
    if (t.lideres.falta > 0) partes.push(cuantos(t.lideres.falta, 'lideres'));
    if (partes.length) return partes.join(' · ');
    return t.estado === 'sin_meta' ? '—' : 'Nada';
}

function NombreBoton({ t, onElegir, className = '' }) {
    const pintura = pinturaDe(t, 'indice');
    return (
        <button type="button" onClick={() => onElegir(t.id)} className={`min-h-12 inline-flex items-center gap-3 text-left text-lg font-semibold text-primary underline underline-offset-4 decoration-2 rounded-sm ${focoCls} ${className}`}>
            <Muestra clase={pintura.clase} color={colorDe(pintura)} className="w-4 h-4" />
            <span className="break-words">{t.nombre}</span>
        </button>
    );
}

function Tarjeta({ t, onElegir }) {
    return (
        <li className="bg-panel border-2 border-line rounded-lg p-4">
            <NombreBoton t={t} onElegir={onElegir} />
            <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-lg tabular-nums">
                <dt className="text-ink-soft">Votantes</dt><dd className="text-right">{regMeta(t.votantes)}{t.votantes.meta !== null && ` · ${porcentaje(t.votantes.pct)}`}</dd>
                <dt className="text-ink-soft">Líderes</dt><dd className="text-right">{regMeta(t.lideres)}{t.lideres.meta !== null && ` · ${porcentaje(t.lideres.pct)}`}</dd>
                <dt className="text-ink-soft">Faltan</dt><dd className="text-right">{textoFalta(t)}</dd>
                <dt className="text-ink-soft">Índice</dt><dd className="text-right font-semibold">{t.indice === null ? '—' : `${numero(t.indice)} de 100`}</dd>
                <dt className="text-ink-soft">Estado</dt><dd className="text-right">{textoEstado(t)}</dd>
            </dl>
        </li>
    );
}

export default function TablaTerritorios({ territorios, titulo = 'Todos los territorios', onElegir, accion = null }) {
    const [orden, setOrden] = useState('falta');
    const [verTodos, setVerTodos] = useState(false);
    const movil = useEsMovil();
    const ordenadas = useMemo(() => ordenarTerritorios(territorios, orden), [territorios, orden]);
    const filas = verTodos ? ordenadas : ordenadas.slice(0, FILAS_INICIALES);

    return (
        <section aria-labelledby="titulo-tabla" className="lg:bg-panel lg:border-2 lg:border-line lg:rounded-lg lg:p-6">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <h2 id="titulo-tabla" className="font-heading text-2xl font-bold text-ink">{titulo} <span className="font-normal text-ink-soft">({numero(ordenadas.length)})</span></h2>
                <div role="group" aria-labelledby="ordenar-por" className="flex flex-wrap items-center gap-2">
                    <span id="ordenar-por" className="text-lg text-ink-soft mr-1">Ordenar:</span>
                    {ORDENES.map(o => <Chip key={o.clave} activo={orden === o.clave} onClick={() => setOrden(o.clave)}>{o.etiqueta}</Chip>)}
                </div>
            </div>
            {accion && <div className="mt-4">{accion}</div>}

            {filas.length === 0 && <p className="mt-4 text-lg text-ink-soft">No hay territorios para mostrar.</p>}

            {filas.length > 0 && movil && (
                <ul aria-label={titulo} className="mt-4 space-y-3">{filas.map(t => <Tarjeta key={t.id} t={t} onElegir={onElegir} />)}</ul>
            )}

            {filas.length > 0 && !movil && (
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-lg tabular-nums">
                        <caption className="sr-only">{titulo}</caption>
                        <thead>
                            <tr className="border-b-2 border-line text-left text-base font-semibold text-ink-soft">
                                <th scope="col" className="py-3 pr-4">Territorio</th>
                                <th scope="col" className="py-3 pr-4 text-right">Votantes reg. / meta</th>
                                <th scope="col" className="py-3 pr-4 text-right">%</th>
                                <th scope="col" className="py-3 pr-4 text-right">Líderes reg. / meta</th>
                                <th scope="col" className="py-3 pr-4 text-right">%</th>
                                <th scope="col" className="py-3 pr-4 text-right">Faltan</th>
                                <th scope="col" className="py-3 pr-4 text-right">Índice</th>
                                <th scope="col" className="py-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filas.map(t => (
                                <tr key={t.id} className="border-b border-line last:border-0">
                                    <th scope="row" className="py-1 pr-4 text-left font-normal"><NombreBoton t={t} onElegir={onElegir} /></th>
                                    <td className="py-2 pr-4 text-right">{regMeta(t.votantes)}</td>
                                    <td className="py-2 pr-4 text-right">{porcentaje(t.votantes.pct) || '—'}</td>
                                    <td className="py-2 pr-4 text-right">{regMeta(t.lideres)}</td>
                                    <td className="py-2 pr-4 text-right">{porcentaje(t.lideres.pct) || '—'}</td>
                                    <td className="py-2 pr-4 text-right">{textoFalta(t)}</td>
                                    <td className="py-2 pr-4 text-right font-semibold">{t.indice === null ? '—' : numero(t.indice)}</td>
                                    <td className="py-2 text-ink-soft">{textoEstado(t)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {ordenadas.length > filas.length && (
                <div className="mt-4">
                    <Boton variante="secundario" className="w-full sm:w-auto" onClick={() => setVerTodos(true)}>Ver los {numero(ordenadas.length)} territorios</Boton>
                </div>
            )}
        </section>
    );
}
