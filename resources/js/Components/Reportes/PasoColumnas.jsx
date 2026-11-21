import { useId } from 'react';
import { columnasDePreset, gruposDe, limpiarColumnas, mismasColumnas } from '@/lib/reportes';
import { Chip, IconoCandado, focoCls } from './ui';

export const AVISO_PERMISO = 'Necesitas permiso para datos de contacto. Pídeselo a tu administrador.';

function Grupo({ titulo, columnas, elegidas, puedeDatosPersonales, onAlternar }) {
    const id = useId();
    const hayBloqueadas = !puedeDatosPersonales && columnas.some(c => c.sensible);
    return (
        <fieldset className="p-5 rounded-lg bg-panel border-2 border-line">
            <legend className="float-left w-full mb-2 font-heading text-xl font-bold text-ink">{titulo}</legend>
            {hayBloqueadas && (
                <p id={`${id}-p`} className="clear-left mt-1 mb-2 flex items-start gap-2 text-base text-ink-soft">
                    <IconoCandado className="w-5 h-5 mt-0.5 flex-shrink-0" />
                    {AVISO_PERMISO}
                </p>
            )}
            <ul className="clear-left mt-2 divide-y divide-line">
                {columnas.map(c => {
                    const bloqueada = c.sensible && !puedeDatosPersonales;
                    const marcada = c.siempre || elegidas.includes(c.clave);
                    return (
                        <li key={c.clave}>
                            <label className={`flex items-center gap-4 min-h-14 py-2 text-lg ${bloqueada || c.siempre ? 'cursor-not-allowed' : 'cursor-pointer'} ${bloqueada ? 'text-ink-soft' : 'text-ink'}`}>
                                <input
                                    type="checkbox"
                                    checked={marcada && !bloqueada}
                                    disabled={bloqueada || c.siempre}
                                    onChange={() => onAlternar(c.clave)}
                                    aria-describedby={bloqueada ? `${id}-p` : undefined}
                                    className={`w-6 h-6 flex-shrink-0 rounded accent-[var(--color-primary)] ${focoCls}`}
                                />
                                <span className="flex-1">{c.etiqueta}</span>
                                {c.siempre && <span className="text-base text-ink-soft">Siempre va</span>}
                                {bloqueada && (
                                    <span className="inline-flex items-center gap-1.5 text-base text-ink-soft">
                                        <IconoCandado className="w-5 h-5" />
                                        <span className="sr-only">Bloqueado: </span>Con permiso
                                    </span>
                                )}
                            </label>
                        </li>
                    );
                })}
            </ul>
        </fieldset>
    );
}

// Step 3: which data (columns) go in the list and the file. Sensitive columns without the permission are
// shown locked, never selectable (the API answers 403 anyway).
export default function PasoColumnas({ fuente, columnas, puedeDatosPersonales, onColumnas }) {
    const grupos = gruposDe(fuente.columnas);

    function alternar(clave) {
        const next = columnas.includes(clave) ? columnas.filter(c => c !== clave) : [...columnas, clave];
        onColumnas(limpiarColumnas(next, fuente, puedeDatosPersonales));
    }

    return (
        <div>
            <h2 className="font-heading text-3xl font-bold text-ink text-balance">¿Qué datos quieres en la lista?</h2>

            {fuente.presets.length > 0 && (
                <div role="group" aria-labelledby="presets-t" className="mt-5">
                    <p id="presets-t" className="text-lg font-semibold text-ink mb-3">Para empezar rápido:</p>
                    <div className="flex flex-wrap gap-3">
                        {fuente.presets.map(p => {
                            const cols = columnasDePreset(p, fuente, puedeDatosPersonales);
                            return (
                                <Chip key={p.clave} activo={mismasColumnas(cols, columnas)} onClick={() => onColumnas(cols)}>
                                    {p.etiqueta}
                                </Chip>
                            );
                        })}
                    </div>
                </div>
            )}

            <p className="mt-5 text-lg text-ink" aria-live="polite">
                Elegiste <strong className="tabular-nums">{columnas.length}</strong> {columnas.length === 1 ? 'dato' : 'datos'}.
            </p>
            {!puedeDatosPersonales && fuente.columnas.some(c => c.sensible) && (
                <p className="mt-2 text-lg text-ink-soft">Los datos con candado necesitan un permiso especial.</p>
            )}

            <div className="mt-5 grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
                {grupos.map(g => (
                    <Grupo
                        key={g.titulo}
                        titulo={g.titulo}
                        columnas={g.items}
                        elegidas={columnas}
                        puedeDatosPersonales={puedeDatosPersonales}
                        onAlternar={alternar}
                    />
                ))}
            </div>
        </div>
    );
}
