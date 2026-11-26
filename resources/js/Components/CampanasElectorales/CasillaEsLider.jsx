// «Es líder (cuenta para las metas)» in the fichas. Shown only with the Campañas electorales module on (the server sends
// es_lider only then). The ficha sends es_lider only after this box is touched, so saving any other field never
// unmarks a leader. porCargo: the ficha already counts by its cargo or tipo (App\Support\EsLider), so the box shows
// ticked and locked, and nothing is saved for it.
export const TEXTO_POR_CARGO = 'Cuenta como líder por su cargo';

export default function CasillaEsLider({ checked, disabled, onChange, porCargo = false }) {
    const marcada = porCargo || !!checked;
    const bloqueada = porCargo || disabled;
    return (
        <div>
            <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Campañas electorales</p>
            <label
                className={`flex items-center gap-3 min-h-14 px-4 rounded-lg border-2 text-[16px] transition-colors ${
                    bloqueada ? 'cursor-default' : 'cursor-pointer'
                } ${marcada ? 'bg-[var(--color-primary)]/5 border-[var(--color-primary)] text-[var(--color-ink)] font-semibold' : 'border-[var(--color-line-strong)] text-[var(--color-ink)]'}`}
            >
                <input
                    type="checkbox"
                    checked={marcada}
                    disabled={bloqueada}
                    onChange={e => !bloqueada && onChange(e.target.checked)}
                    className="w-6 h-6 flex-shrink-0 accent-[var(--color-primary)] disabled:opacity-60"
                />
                <span>
                    Es líder (cuenta para las metas)
                    {porCargo && <span className="block text-[13px] font-normal text-[var(--color-ink-soft)]">{TEXTO_POR_CARGO}</span>}
                </span>
            </label>
        </div>
    );
}
