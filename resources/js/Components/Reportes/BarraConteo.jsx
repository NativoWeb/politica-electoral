import { numero, unidadDe } from '@/lib/reportes';
import { Boton, Girando, IconoFlecha } from './ui';

// Fixed at the bottom while choosing (steps 2 and 3): how many rows (people, compromisos…) the list has right now.
// On a phone it sits on top of the 72px tab bar (which grows by the iPhone safe area, so this does too). States: counting (previous number dimmed), error, zero, ok.
const PERSONAS = unidadDe({ clave: 'personas' });

export default function BarraConteo({ estado, total, mensajeError, nota = '', ultimoFiltro, onQuitarUltimo, onReintentar, siguiente, unidad = PERSONAS }) {
    const contando = estado === 'cargando' || estado === 'idle';
    const error = estado === 'error';
    const cero = estado === 'ok' && total === 0;

    return (
        <div className="fixed left-0 right-0 bottom-[calc(72px+env(safe-area-inset-bottom,0px))] lg:bottom-0 lg:left-[200px] z-30 bg-panel border-t-2 border-line-strong shadow-[0_-8px_24px_rgba(0,27,60,0.12)]">
            <div className="max-w-5xl mx-auto px-4 lg:px-8 py-3 flex flex-col sm:flex-row sm:items-center gap-3">
                <div className="flex-1 min-w-0" aria-live="polite" aria-atomic="true">
                    {error ? (
                        <p className="text-lg font-semibold text-danger">{mensajeError}</p>
                    ) : (
                        <p className="flex items-baseline flex-wrap gap-x-3">
                            <span className={`font-heading text-3xl font-bold tabular-nums text-ink transition-opacity duration-150 ${contando ? 'opacity-50' : ''}`}>
                                {total === null ? '—' : numero(total)}
                            </span>
                            <span className="text-lg text-ink">{total === 1 ? unidad.singular : unidad.plural}</span>
                            {contando && (
                                <span className="inline-flex items-center gap-2 text-lg text-ink-soft"><Girando className="w-5 h-5" />Contando…</span>
                            )}
                        </p>
                    )}
                    {cero && <p className="text-lg text-ink">{unidad.ninguna} cumple todos estos filtros. Prueba quitando uno.</p>}
                    {nota && !error && <p className="text-base text-ink-soft">{nota}</p>}
                </div>
                <div className="flex flex-wrap gap-3">
                    {error && <Boton variante="secundario" onClick={onReintentar}>Intentar de nuevo</Boton>}
                    {cero && ultimoFiltro && (
                        <Boton variante="secundario" onClick={onQuitarUltimo}>Quitar «{ultimoFiltro.texto}»</Boton>
                    )}
                    {siguiente && (
                        <Boton onClick={siguiente.onClick} disabled={cero} className="flex-1 sm:flex-none">
                            {siguiente.texto}
                            <IconoFlecha />
                        </Boton>
                    )}
                </div>
            </div>
        </div>
    );
}
