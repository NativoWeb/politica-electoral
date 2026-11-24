// «Es líder (cuenta para las metas)» in the fichas. Shown only with the Campañas electorales module on (the server sends
// es_lider only then). The ficha sends es_lider only after this box is touched, so saving any other field never
// unmarks a leader.
export default function CasillaEsLider({ checked, disabled, onChange }) {
    return (
        <div>
            <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Campañas electorales</p>
            <label
                className={`flex items-center gap-3 min-h-14 px-4 rounded-lg border-2 text-[16px] transition-colors ${
                    disabled ? 'cursor-default' : 'cursor-pointer'
                } ${checked ? 'bg-[var(--color-primary)]/5 border-[var(--color-primary)] text-[var(--color-ink)] font-semibold' : 'border-[var(--color-line-strong)] text-[var(--color-ink)]'}`}
            >
                <input
                    type="checkbox"
                    checked={!!checked}
                    disabled={disabled}
                    onChange={e => !disabled && onChange(e.target.checked)}
                    className="w-6 h-6 flex-shrink-0 accent-[var(--color-primary)] disabled:opacity-60"
                />
                <span>Es líder (cuenta para las metas)</span>
            </label>
        </div>
    );
}
