import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import { partyColor, partyLogo, fmt } from '@/lib/electoral';

/* ── Candidato card — misma visual que alcaldía ── */
function CandidatoCard({ c, index, totalVotos, maxVotos }) {
    const color = c.color || partyColor(c.partido, index);
    const logo = partyLogo(c.partido, c.acronym);
    const pctVal = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
    const barFilled = maxVotos > 0 ? (c.votos / maxVotos * 100) : 0;

    return (
        <Link href={`/persona/${c.id}`} className="block px-5 py-5 border-b border-[var(--color-line)] hover:bg-blue-50/20 transition-colors group">
            <div className="flex items-center gap-4 mb-3">
                <div className="w-[72px] h-[72px] rounded-full flex items-center justify-center text-white text-[26px] font-bold flex-shrink-0 shadow-lg border-[3px] border-white" style={{ background: color }}>
                    {c.name.charAt(0)}
                </div>
                {logo && (
                    <div className="w-[56px] h-[56px] rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden p-1.5">
                        <img src={logo} alt="" className="w-full h-full object-contain" />
                    </div>
                )}
            </div>
            <h3 className="text-[17px] font-extrabold text-[var(--color-primary)] uppercase leading-tight group-hover:underline">{c.name}</h3>
            <p className="text-[13px] text-[var(--color-ink-soft)] mt-1 flex items-center gap-2">
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />
                {c.acronym ?? c.partido ?? '—'}
            </p>
            <div className="mt-4 h-[10px] bg-[var(--color-line)] rounded-full overflow-hidden">
                <div className="h-full rounded-full transition-all duration-700" style={{ width: `${barFilled}%`, background: color }} />
            </div>
            <div className="flex items-baseline justify-between mt-2">
                <span className="text-[24px] font-extrabold text-[var(--color-primary)] font-[var(--font-mono)]">{pctVal.toFixed(2).replace('.', ',')}%</span>
                <span className="text-[18px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(c.votos)}</span>
            </div>
        </Link>
    );
}

/**
 * Página para Senado, Cámara, Asamblea — misma visual que alcaldía.
 */
export default function CorporacionPage({
    title,
    pageTitle,
    breadcrumb,
    resultados = [],
    porPartido = [],
    sectionLabel,
}) {
    const totalVotos = porPartido.reduce((s, p) => s + p.total, 0) || resultados.reduce((s, c) => s + c.votos, 0);
    const maxVotos = resultados[0]?.votos ?? 1;
    const topCandidato = resultados[0] ?? null;

    return (
        <AppLayout title={sectionLabel} breadcrumb={breadcrumb}>
            <Head title={pageTitle} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <span className="text-[22px] font-extrabold">{title}</span>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Mesas informadas" sub="100%" color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={totalVotos > 0 ? 59 : 0} size={110} stroke={8} label="Votantes" sub={fmt(totalVotos)} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">

                {/* Izquierda: candidatos */}
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {resultados.length === 0 ? (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin datos disponibles.</p>
                    ) : (
                        resultados.map((c, i) => (
                            <CandidatoCard key={c.id} c={c} index={i} totalVotos={totalVotos} maxVotos={maxVotos} />
                        ))
                    )}
                </div>

                {/* Derecha: top candidato + partidos */}
                <div className="w-full xl:w-[400px] xl:border-l border-[var(--color-line)] flex-shrink-0">

                    {topCandidato && (
                        <div className="border-b border-[var(--color-line)]">
                            <div className="px-5 py-3" style={{ background: topCandidato.color || partyColor(topCandidato.partido, 0) }}>
                                <h3 className="text-[12px] font-bold uppercase tracking-wider text-white">Mayor votacion</h3>
                            </div>
                            <div className="p-5">
                                <div className="flex items-center gap-3 mb-3">
                                    <div className="w-14 h-14 rounded-full flex items-center justify-center text-white text-[20px] font-bold shadow-lg border-2 border-white" style={{ background: topCandidato.color || partyColor(topCandidato.partido, 0) }}>
                                        {topCandidato.name.charAt(0)}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-[15px] font-extrabold text-[var(--color-ink)] uppercase">{topCandidato.name}</p>
                                        <p className="text-[11px] text-[var(--color-ink-faint)]">{topCandidato.acronym ?? topCandidato.partido ?? '—'}</p>
                                    </div>
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <span className="text-[28px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(topCandidato.votos)}</span>
                                    <span className="text-[14px] font-bold text-[var(--color-ink-faint)] font-[var(--font-mono)]">{totalVotos > 0 ? (topCandidato.votos / totalVotos * 100).toFixed(2).replace('.', ',') : '0'}%</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Partidos con votos */}
                    {porPartido.length > 0 && (
                        <div>
                            <div className="px-5 py-3 bg-gray-50 border-b border-[var(--color-line)]">
                                <h3 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Votacion por partido</h3>
                            </div>
                            {porPartido.map((p, i) => {
                                const color = p.color || partyColor(p.name, i);
                                const logo = partyLogo(p.name, p.acronym);
                                const pctVal = totalVotos > 0 ? (p.total / totalVotos * 100) : 0;
                                return (
                                    <div key={p.id} className="flex items-center gap-3 px-5 py-2.5 border-b border-[var(--color-line)] last:border-0">
                                        {logo ? (
                                            <img src={logo} alt="" className="w-7 h-7 object-contain flex-shrink-0" />
                                        ) : (
                                            <span className="w-4 h-4 rounded-sm flex-shrink-0" style={{ background: color }} />
                                        )}
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[11px] font-semibold text-[var(--color-ink)] truncate">{p.acronym ?? p.name}</p>
                                        </div>
                                        <span className="text-[10px] text-[var(--color-ink-faint)] font-[var(--font-mono)] flex-shrink-0">{pctVal.toFixed(1).replace('.', ',')}%</span>
                                        <span className="text-[12px] font-bold text-[var(--color-ink)] font-[var(--font-mono)] w-[60px] text-right flex-shrink-0">{fmt(p.total)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
