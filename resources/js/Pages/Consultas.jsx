import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import { partyColor, partyLogo, fmt } from '@/lib/electoral';

function CandidatoCard({ c, index, totalVotos, maxVotos }) {
    const color = partyColor('', index);
    const pctVal = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
    const barFilled = maxVotos > 0 ? (c.votos / maxVotos * 100) : 0;
    return (
        <Link href={`/persona/${c.id}`} className="block px-5 py-4 border-b border-[var(--color-line)] hover:bg-blue-50/20 transition-colors group">
            <div className="flex items-center gap-3 mb-2">
                <div className="w-[52px] h-[52px] rounded-full flex items-center justify-center text-white text-[18px] font-bold flex-shrink-0 shadow border-2 border-white" style={{ background: color }}>{c.name.charAt(0)}</div>
            </div>
            <h3 className="text-[15px] font-extrabold text-[var(--color-primary)] uppercase leading-tight group-hover:underline">{c.name}</h3>
            <div className="mt-3 h-[8px] bg-[var(--color-line)] rounded-full overflow-hidden">
                <div className="h-full rounded-full transition-all duration-700" style={{ width: `${barFilled}%`, background: color }} />
            </div>
            <div className="flex items-baseline justify-between mt-1.5">
                <span className="text-[20px] font-extrabold text-[var(--color-primary)] font-[var(--font-mono)]">{pctVal.toFixed(2).replace('.', ',')}%</span>
                <span className="text-[16px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(c.votos)}</span>
            </div>
        </Link>
    );
}

export default function Consultas({ resultados }) {
    const totalConsultas = resultados.length;

    return (
        <AppLayout title="Consultas 2026" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'CONSULTAS' }]}>
            <Head title="Consultas Interpartidistas 2026 — Inteligencia Electoral" />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <span className="text-[22px] font-extrabold">CONSULTAS INTERPARTIDISTAS · 2026</span>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Consultas" sub={`${totalConsultas}`} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {resultados.map(consulta => {
                        const total = consulta.candidatos.reduce((s, c) => s + c.votos, 0);
                        const max = consulta.candidatos[0]?.votos || 1;
                        return (
                            <div key={consulta.id}>
                                <div className="px-5 py-3 bg-[var(--color-primary-dark)] text-white border-t border-white/10">
                                    <h2 className="text-[14px] font-bold uppercase">{consulta.name}</h2>
                                    <p className="text-[11px] text-white/50">{consulta.candidatos.length} precandidatos · {fmt(total)} votos</p>
                                </div>
                                {consulta.candidatos.map((c, i) => (
                                    <CandidatoCard key={c.id} c={c} index={i} totalVotos={total} maxVotos={max} />
                                ))}
                            </div>
                        );
                    })}
                </div>

                <div className="w-full xl:w-[400px] xl:border-l border-[var(--color-line)] flex-shrink-0 p-5">
                    <div className="bg-white rounded-lg border border-[var(--color-line)] p-4 space-y-2">
                        {resultados.map(c => (
                            <div key={c.id} className="flex items-center justify-between py-2 border-b border-[var(--color-line)] last:border-0">
                                <span className="text-[12px] text-[var(--color-ink-soft)]">{c.name}</span>
                                <span className="text-[13px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{c.candidatos.length}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
