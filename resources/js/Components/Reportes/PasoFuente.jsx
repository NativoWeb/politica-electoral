import { personas } from '@/lib/reportes';
import { Boton, IconoFlecha, focoCls } from './ui';

// Step 1: one-tap shortcuts that jump straight to the list, and the cards of each source.
export default function PasoFuente({ fuentes, onAtajo, onElegir }) {
    const atajos = fuentes.flatMap(f => f.atajos.map(a => ({ ...a, fuente: f })));

    return (
        <div className="space-y-10">
            <h2 className="font-heading text-3xl font-bold text-ink text-balance">¿Qué quieres ver?</h2>

            {atajos.length > 0 && (
                <section aria-labelledby="atajos-t">
                    <h3 id="atajos-t" className="text-xl font-semibold text-ink">Atajos rápidos</h3>
                    <p className="mt-1 text-lg text-ink-soft">Un toque y ves la lista.</p>
                    <ul className="mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        {atajos.map(a => (
                            <li key={`${a.fuente.clave}:${a.clave}`}>
                                <button
                                    type="button"
                                    onClick={() => onAtajo(a.fuente, a)}
                                    className={`group w-full min-h-16 h-full flex items-center justify-between gap-4 px-5 py-4 rounded-lg border-2 border-line-strong bg-panel text-left hover:border-primary active:scale-[0.99] transition-[border-color,transform] duration-150 ${focoCls}`}
                                >
                                    <span>
                                        <span className="block text-lg font-semibold text-ink">{a.etiqueta}</span>
                                        {a.descripcion && <span className="block text-base text-ink-soft mt-0.5">{a.descripcion}</span>}
                                    </span>
                                    <IconoFlecha className="w-6 h-6 text-primary flex-shrink-0" />
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="fuentes-t">
                <h3 id="fuentes-t" className="text-xl font-semibold text-ink">O arma tu propia lista</h3>
                <ul className="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {fuentes.map(f => (
                        <li key={f.clave} className="flex flex-col gap-5 p-6 rounded-lg bg-panel border-2 border-line border-l-8 border-l-primary">
                            <div>
                                <p className="font-heading text-2xl font-bold text-ink">{f.etiqueta}</p>
                                {f.descripcion && <p className="mt-2 text-lg text-ink-soft text-pretty">{f.descripcion}</p>}
                                {f.total !== null && <p className="mt-3 text-lg font-semibold text-primary tabular-nums">{personas(f.total)}</p>}
                            </div>
                            <Boton onClick={() => onElegir(f)} className="self-start" aria-label={`Elegir ${f.etiqueta}`}>
                                Elegir
                                <IconoFlecha />
                            </Boton>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
