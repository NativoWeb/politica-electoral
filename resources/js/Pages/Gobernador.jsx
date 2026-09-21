import Spinner from '@/Components/Spinner';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import GeoNavigator from '@/Components/GeoNavigator';
import { partyLogo, partyColor, fmt } from '@/lib/electoral';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';

function DonutTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    return (
        <div className="bg-[var(--color-ink)] text-white px-3 py-2 rounded text-xs font-semibold shadow-xl">
            <p>{payload[0].name}</p>
            <p className="font-[var(--font-mono)]">{fmt(payload[0].value)} votos</p>
        </div>
    );
}

function CandidatoCard({ c, index, totalVotos, maxVotos }) {
    const color = c.color || partyColor(c.partido, index);
    const logo = partyLogo(c.partido, c.acronym);
    const pctVal = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
    const barFilled = maxVotos > 0 ? (c.votos / maxVotos * 100) : 0;

    return (
        <Link href={`/persona/${c.id}`} className="block px-5 py-5 border-b border-[var(--color-line)] hover:bg-blue-50/20 transition-colors group">
            <div className="flex items-center gap-4 mb-3">
                <div className="w-[72px] h-[72px] rounded-full flex items-center justify-center text-white text-[26px] font-bold flex-shrink-0 shadow-lg border-[3px] border-white" style={{ background: color }}>{c.name.charAt(0)}</div>
                {logo && (
                    <div className="w-[56px] h-[56px] rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden p-1.5">
                        <img src={logo} alt="" className="w-full h-full object-contain" />
                    </div>
                )}
            </div>
            <h3 className="text-[17px] font-extrabold text-[var(--color-primary)] uppercase leading-tight group-hover:underline">{c.name}</h3>
            <p className="text-[13px] text-[var(--color-ink-soft)] mt-1 flex items-center gap-2">
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />
                {c.partido ?? '—'}
            </p>
            {c.electo && <span className="inline-block mt-2 px-2.5 py-1 bg-[var(--color-good-light)] text-[var(--color-good)] text-[10px] font-bold uppercase tracking-wider rounded">Gobernador electo</span>}
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

export default function Gobernador({ municipio, candidatos = [], allMunicipios = [] }) {
    const totalVotos = candidatos.reduce((s, c) => s + (c.votos || 0), 0);
    const maxVotos = candidatos[0]?.votos || 1;
    const electo = candidatos.find(c => c.electo);

    const donutData = candidatos.filter(c => c.votos > 0).map((c, i) => ({
        name: c.acronym ?? c.partido ?? c.name,
        value: c.votos,
        fill: c.color || partyColor(c.partido, i),
    }));

    const munLabel = municipio?.name?.toUpperCase() ?? null;

    return (
        <AppLayout title="Gobernador" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'GOBERNADOR' }]}>
            <Head title={`Gobernador ${munLabel ?? 'Santander'} — Inteligencia Electoral`} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <div className="flex items-center gap-2">
                    <span className="text-[22px] font-extrabold">SANTANDER</span>
                    {munLabel && <>
                        <span className="text-white/40 text-[22px] font-light">/</span>
                        <span className="text-[22px] font-extrabold">{munLabel}</span>
                    </>}
                    <GeoNavigator municipios={allMunicipios} currentMunicipio={municipio?.name} basePath="/gobernador" />
                </div>
                <p className="text-[11px] text-white/40 mt-1">Elecciones Territoriales 2023 · Preconteo{munLabel ? '' : ' · Todos los municipios'}</p>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Mesas informadas" sub="100%" color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={totalVotos > 0 ? 60 : 0} size={110} stroke={8} label="Votantes" sub={fmt(totalVotos)} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {candidatos.length === 0 ? (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin candidatos registrados.</p>
                    ) : candidatos.map((c, i) => (
                        <CandidatoCard key={c.id} c={c} index={i} totalVotos={totalVotos} maxVotos={maxVotos} />
                    ))}
                </div>

                <div className="w-full xl:w-[440px] xl:border-l border-[var(--color-line)] flex-shrink-0 p-5">
                    {electo && (
                        <div className="bg-white rounded-lg border border-[var(--color-line)] overflow-hidden mb-5">
                            <div className="px-5 py-3 bg-[var(--color-good)] text-white">
                                <h3 className="text-[12px] font-bold uppercase tracking-wider">Gobernador electo</h3>
                            </div>
                            <div className="p-5">
                                <p className="text-[16px] font-extrabold text-[var(--color-ink)] uppercase">{electo.name}</p>
                                <p className="text-[11px] text-[var(--color-ink-faint)] mt-1">{electo.partido ?? '—'}</p>
                                <div className="flex items-baseline justify-between mt-3">
                                    <span className="text-[28px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(electo.votos)}</span>
                                    <span className="text-[14px] font-bold text-[var(--color-ink-faint)] font-[var(--font-mono)]">{totalVotos > 0 ? (electo.votos / totalVotos * 100).toFixed(2).replace('.', ',') : '0'}%</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {donutData.length > 0 && (
                        <>
                            <div className="h-[300px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart><Pie data={donutData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius="40%" outerRadius="85%" paddingAngle={0.3} stroke="none">
                                        {donutData.map((d, i) => <Cell key={i} fill={d.fill} />)}
                                    </Pie><Tooltip content={<DonutTooltip />} /></PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 mt-4">
                                {donutData.map((d, i) => (
                                    <div key={i} className="flex items-center gap-2 text-[10px]">
                                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: d.fill }} />
                                        <span className="text-[var(--color-ink-soft)] truncate">{d.name}</span>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
