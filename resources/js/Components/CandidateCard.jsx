import { Link } from '@inertiajs/react';
import { partyLogo } from '@/lib/electoral';

function fmt(n) {
    return n == null ? '—' : new Intl.NumberFormat('es-CO').format(n);
}

/**
 * Card de candidato estilo electoral profesional.
 * Franja lateral con color del partido, logo, nombre, partido, barra de %, votos.
 */
export default function CandidateCard({
    rank,
    name,
    party,
    partyAcronym,
    votes,
    percentage,
    maxVotes,
    color = '#003B71',
    href,
    elected,
}) {
    const bar = maxVotes > 0 ? Math.max((votes / maxVotes) * 100, 0.8) : 0;
    const logo = partyLogo(party, partyAcronym);

    const content = (
        <div className="group flex items-stretch bg-white rounded-lg border border-[var(--color-line)] hover:border-[var(--color-primary-light)] hover:shadow-sm transition-all overflow-hidden">
            {/* Franja lateral color partido */}
            <div className="w-1.5 flex-shrink-0" style={{ background: color }} />

            <div className="flex items-center gap-3 px-4 py-3 flex-1 min-w-0">
                {/* Logo partido o rank */}
                {logo ? (
                    <div className="w-10 h-10 rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden flex-shrink-0 p-0.5">
                        <img src={logo} alt={party || ''} className="w-full h-full object-contain" />
                    </div>
                ) : rank != null ? (
                    <div
                        className="w-9 h-9 rounded-full flex items-center justify-center text-white text-[12px] font-bold flex-shrink-0"
                        style={{ background: rank <= 3 ? color : '#94A3B8' }}
                    >
                        {rank}
                    </div>
                ) : null}

                {/* Info */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-baseline gap-2">
                        <p className="text-[13px] font-bold text-[var(--color-ink)] truncate group-hover:text-[var(--color-primary)]">
                            {name}
                        </p>
                        {elected && (
                            <span className="text-[9px] font-bold uppercase tracking-wider text-[var(--color-good)] bg-[var(--color-good-light)] px-1.5 py-0.5 rounded flex-shrink-0">
                                Electo
                            </span>
                        )}
                    </div>
                    <p className="text-[10px] text-[var(--color-ink-faint)] truncate mt-0.5">
                        {partyAcronym || party || '—'}
                    </p>
                    <div className="mt-1.5 flex items-center gap-3">
                        <div className="flex-1 h-[5px] bg-[var(--color-line)] rounded-full overflow-hidden">
                            <div
                                className="h-full rounded-full transition-all duration-500"
                                style={{ width: `${bar}%`, background: color }}
                            />
                        </div>
                        {percentage != null && (
                            <span className="text-[11px] font-semibold text-[var(--color-ink-faint)] font-[var(--font-mono)] flex-shrink-0 w-12 text-right">
                                {typeof percentage === 'number'
                                    ? percentage.toFixed(1).replace('.', ',') + '%'
                                    : percentage}
                            </span>
                        )}
                    </div>
                </div>

                {/* Votos */}
                <div className="text-right flex-shrink-0 pl-3">
                    <p className="text-[16px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)] tabular-nums leading-tight">
                        {fmt(votes)}
                    </p>
                    <p className="text-[9px] text-[var(--color-ink-faint)] uppercase tracking-wide">
                        votos
                    </p>
                </div>
            </div>
        </div>
    );

    if (href) {
        return <Link href={href} className="block">{content}</Link>;
    }
    return content;
}
