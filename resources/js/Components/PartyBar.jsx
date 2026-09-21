import { partyLogo } from '@/lib/electoral';

function fmt(n) {
    return n == null ? '—' : new Intl.NumberFormat('es-CO').format(n);
}

/**
 * Barra horizontal de partido con logo, color, nombre, porcentaje y total.
 */
export default function PartyBar({ name, acronym, total, maxTotal, color = '#003B71', percentage }) {
    const bar = maxTotal > 0 ? Math.max((total / maxTotal) * 100, 1) : 0;
    const logo = partyLogo(name, acronym);

    return (
        <div className="flex items-center gap-3 py-2.5">
            {logo ? (
                <div className="w-8 h-8 rounded border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden flex-shrink-0 p-0.5">
                    <img src={logo} alt={name} className="w-full h-full object-contain" />
                </div>
            ) : (
                <span className="w-3.5 h-3.5 rounded-sm flex-shrink-0" style={{ background: color }} />
            )}
            <div className="flex-1 min-w-0">
                <div className="flex items-baseline justify-between gap-2 mb-1">
                    <span className="text-[12px] font-semibold text-[var(--color-ink)] truncate">
                        {acronym || name}
                    </span>
                    {percentage != null && (
                        <span className="text-[10px] text-[var(--color-ink-faint)] font-[var(--font-mono)] flex-shrink-0">
                            {typeof percentage === 'number'
                                ? percentage.toFixed(1).replace('.', ',') + '%'
                                : percentage}
                        </span>
                    )}
                </div>
                <div className="h-[4px] bg-[var(--color-line)] rounded-full overflow-hidden">
                    <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{ width: `${bar}%`, background: color }}
                    />
                </div>
            </div>
            <span className="text-[13px] font-bold text-[var(--color-ink)] font-[var(--font-mono)] tabular-nums w-[72px] text-right flex-shrink-0">
                {fmt(total)}
            </span>
        </div>
    );
}
