import { useId } from 'react';
import { columnasDePreset, gruposDe, limpiarColumnas, mismasColumnas } from '@/lib/reportes';
import { Chip, IconoCandado, focoCls } from './ui';

export const AVISO_PERMISO = 'Necesitas permiso para datos de contacto. Pídeselo a tu administrador.';

function Grupo({ titulo, columnas, elegidas, puedeDatosPersonales, onAlternar }) {
    const id = useId();
    const hayBloqueadas = !puedeDatosPersonales && columnas.some(c => c.sensible);
    return (
        <fieldset className="p-4 rounded-lg bg-panel border border-line">
            <legend className="float-left w-full mb-1 font-heading text-base font-bold text-ink">{titulo}</legend>
            {hayBloqueadas && (
                <p id={`${id}-p`} className="clear-left mt-1 mb-1 flex items-start gap-2 text-[13px] text-ink-soft">
                    <IconoCandado className="w-4 h-4 mt-0.5 flex-shrink-0" />
                    {AVISO_PERMISO}
                </p>
            )}
            <ul className="clear-left mt-1 divide-y divide-line">
                {columnas.map(c => {
                    const bloqueada = c.sensible && !puedeDatosPersonales;
                    const marcada = c.siempre || elegidas.includes(c.clave);
                    return (
                        <li key={c.clave}>
                            <label className={`flex items-center gap-3 min-h-11 sm:min-h-10 py-1.5 text-[15px] ${bloqueada || c.siempre ? 'cursor-not-allowed' : 'cursor-pointer'} ${bloqueada ? 'text-ink-soft' : 'text-ink'}`}>
                                <input
                                    type="checkbox"
                                    checked={marcada && !bloqueada}
                                    disabled={bloqueada || c.siempre}
                                    onChange={() => onAlternar(c.clave)}
                                    aria-describedby={bloqueada ? `${id}-p` : undefined}
                                    className={`w-5 h-5 flex-shrink-0 rounded accent-[var(--color-primary)] ${focoCls}`}
                                />
                                <span className="flex-1 min-w-0 break-words">{c.etiqueta}</span>
                                {c.siempre && <span className="text-[13px] text-ink-soft">Siempre va</span>}
                                {bloqueada && (
                                    <span className="inline-flex items-center gap-1 text-[13px] text-ink-soft">
                                        <IconoCandado className="w-4 h-4" />
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
            <h2 className="font-heading text-xl font-bold text-ink text-balance">¿Qué datos quieres en la lista?</h2>

            {fuente.presets.length > 0 && (
                <div role="group" aria-labelledby="presets-t" className="mt-4">
                    <p id="presets-t" className="text-[15px] font-semibold text-ink mb-2">Para empezar rápido:</p>
                    <div className="flex flex-wrap gap-2">
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

            <p className="mt-4 text-[15px] text-ink" aria-live="polite">
                Elegiste <strong className="tabular-nums">{columnas.length}</strong> {columnas.length === 1 ? 'dato' : 'datos'}.
            </p>
            {!puedeDatosPersonales && fuente.columnas.some(c => c.sensible) && (
                <p className="mt-1 text-sm text-ink-soft">Los datos con candado necesitan un permiso especial.</p>
            )}

            <div className="mt-4 grid grid-cols-1 xl:grid-cols-2 gap-3 items-start">
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
