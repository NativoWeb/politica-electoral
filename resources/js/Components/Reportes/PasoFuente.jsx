import { cantidad, personas, unidadDe } from '@/lib/reportes';
import { Boton, IconoFlecha, focoCls } from './ui';

function BotonAtajo({ atajo, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`group w-full min-h-12 h-full flex items-center justify-between gap-3 px-4 py-2.5 rounded-lg border border-line-strong bg-panel text-left hover:border-primary active:scale-[0.99] transition-[border-color,transform] duration-150 ${focoCls}`}
        >
            <span className="min-w-0">
                <span className="block text-[15px] font-semibold text-ink break-words">{atajo.etiqueta}</span>
                {atajo.descripcion && <span className="block text-[13px] text-ink-soft mt-0.5">{atajo.descripcion}</span>}
            </span>
            <IconoFlecha className="w-5 h-5 text-primary flex-shrink-0" />
        </button>
    );
}

// One source (B-1): the shortcuts on top and its card under them.
function UnaFuente({ fuente: f, onAtajo, onElegir }) {
    return (
        <div className="space-y-6">
            <h2 className="font-heading text-xl font-bold text-ink text-balance">¿Qué quieres ver?</h2>

            {f.atajos.length > 0 && (
                <section aria-labelledby="atajos-t">
                    <h3 id="atajos-t" className="text-base font-semibold text-ink">Atajos rápidos</h3>
                    <p className="mt-0.5 text-sm text-ink-soft">Un toque y ves la lista.</p>
                    <ul className="mt-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
                        {f.atajos.map(a => (
                            <li key={a.clave}><BotonAtajo atajo={a} onClick={() => onAtajo(f, a)} /></li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="fuentes-t">
                <h3 id="fuentes-t" className="text-base font-semibold text-ink">O arma tu propia lista</h3>
                <ul className="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <li className="flex flex-col gap-3 p-4 rounded-lg bg-panel border border-line border-l-4 border-l-primary">
                        <div>
                            <p className="font-heading text-lg font-bold text-ink">{f.etiqueta}</p>
                            {f.descripcion && <p className="mt-1 text-[15px] text-ink-soft text-pretty">{f.descripcion}</p>}
                            {f.total !== null && <p className="mt-2 text-[15px] font-semibold text-primary tabular-nums">{personas(f.total)}</p>}
                        </div>
                        <Boton onClick={() => onElegir(f)} className="self-start" aria-label={`Elegir ${f.etiqueta}`}>
                            Elegir
                            <IconoFlecha />
                        </Boton>
                    </li>
                </ul>
            </section>
        </div>
    );
}

// Several sources: one big card each, with its total in its own words and its shortcuts inside it.
export default function PasoFuente({ fuentes, onAtajo, onElegir }) {
    if (fuentes.length === 1) return <UnaFuente fuente={fuentes[0]} onAtajo={onAtajo} onElegir={onElegir} />;

    return (
        <div>
            <h2 className="font-heading text-xl font-bold text-ink text-balance">¿Qué quieres ver?</h2>
            <p className="mt-1 text-[15px] text-ink-soft">Elige de dónde sale tu lista. Los atajos la muestran de una vez.</p>

            <ul className="mt-4 space-y-3">
                {fuentes.map(f => {
                    const idT = `fuente-${f.clave}-t`;
                    return (
                        <li key={f.clave}>
                            <section aria-labelledby={idT} className="p-4 rounded-lg bg-panel border border-line border-l-4 border-l-primary">
                                <div className="flex flex-col sm:flex-row sm:items-start gap-3">
                                    <div className="flex-1 min-w-0">
                                        <h3 id={idT} className="font-heading text-lg font-bold text-ink">{f.etiqueta}</h3>
                                        {f.descripcion && <p className="mt-1 text-[15px] text-ink-soft text-pretty">{f.descripcion}</p>}
                                        {f.total !== null && (
                                            <p className="mt-2 text-base font-semibold text-primary tabular-nums">{cantidad(f.total, unidadDe(f))}</p>
                                        )}
                                    </div>
                                    <Boton onClick={() => onElegir(f)} className="sm:self-start flex-shrink-0" aria-label={`Elegir ${f.etiqueta}`}>
                                        Armar mi lista
                                        <IconoFlecha />
                                    </Boton>
                                </div>

                                {f.atajos.length > 0 && (
                                    <div className="mt-4 pt-3 border-t border-line">
                                        <p className="text-[15px] font-semibold text-ink">Atajos de {f.etiqueta.toLowerCase()}</p>
                                        <ul className="mt-2 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
                                            {f.atajos.map(a => (
                                                <li key={a.clave}><BotonAtajo atajo={a} onClick={() => onAtajo(f, a)} /></li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </section>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
