import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import GeoNavigator from '@/Components/GeoNavigator';
import { partyColor, partyLogo, fmt } from '@/lib/electoral';
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

function CandidatoAlcalde({ c, index, totalVotos, maxVotos }) {
    const color = c.color || partyColor(c.partido, index);
    const logo = partyLogo(c.partido, c.acronym);
    const pctVal = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
    const barFilled = maxVotos > 0 ? (c.votos / maxVotos * 100) : 0;

    return (
        <Link href={`/persona/${c.personId}`} className="block px-5 py-5 border-b border-[var(--color-line)] hover:bg-blue-50/20 transition-colors group">
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
            <p className="text-[13px] text-[var(--color-ink-soft)] mt-1 flex items-center gap-2 flex-wrap">
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />
                <span>{c.partido ?? 'Sin partido'}</span>
                {c.aval && (
                    <span className="px-1.5 py-0.5 bg-amber-50 text-amber-700 text-[9px] font-bold uppercase rounded border border-amber-200">
                        {c.aval}
                    </span>
                )}
            </p>
            {c.electo && (
                <span className="inline-block mt-2 px-2.5 py-1 bg-[var(--color-good-light)] text-[var(--color-good)] text-[10px] font-bold uppercase tracking-wider rounded">Alcalde electo</span>
            )}
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

export default function Municipio({ municipio, alcaldia, alcaldeElecto, candidatosCD = [], concejo, partidosPie, eventos, allMunicipios = [] }) {
    const totalVotosAlcaldia = alcaldia.reduce((s, c) => s + c.votos, 0);
    const maxVotosAlcaldia = alcaldia[0]?.votos ?? 1;

    // Donut data from partidosPie or from alcaldia candidates
    const donutData = (partidosPie?.length > 0 ? partidosPie.map((d, i) => ({
        name: d.acronym ?? d.name,
        value: d.total,
        fill: d.color || partyColor(d.name, i),
    })) : alcaldia.filter(c => c.votos > 0).map((c, i) => ({
        name: c.acronym ?? c.partido ?? c.name,
        value: c.votos,
        fill: c.color || partyColor(c.partido, i),
    }))).filter(d => d.value > 0);

    if (!municipio) return null;

    return (
        <AppLayout title={municipio.name} breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: municipio.name.toUpperCase() }]}>
            <Head title={`${municipio.name} — Inteligencia Electoral`} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <div className="flex items-center gap-2">
                    <Link href="/" className="text-[22px] font-extrabold hover:underline">SANTANDER</Link>
                    <span className="text-white/40 text-[22px] font-light">/</span>
                    <span className="text-[22px] font-extrabold">{municipio.name.toUpperCase()}</span>
                    <GeoNavigator municipios={allMunicipios} currentMunicipio={municipio.name} basePath="/municipio" />
                </div>
                <p className="text-[11px] text-white/40 mt-1">Elecciones Territoriales 2023 · Preconteo</p>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Mesas informadas" sub={`${alcaldia.length} de ${alcaldia.length}`} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={totalVotosAlcaldia > 0 ? 53 : 0} size={110} stroke={8} label="Votantes" sub={fmt(totalVotosAlcaldia)} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">

                {/* IZQUIERDA: Candidatos */}
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {alcaldia.length > 0 ? (
                        alcaldia.map((c, i) => (
                            <CandidatoAlcalde key={c.id} c={c} index={i} totalVotos={totalVotosAlcaldia} maxVotos={maxVotosAlcaldia} />
                        ))
                    ) : (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin candidatos registrados.</p>
                    )}
                </div>

                {/* DERECHA: Alcalde electo + Donut + Leyenda partidos */}
                <div className="w-full xl:w-[440px] xl:border-l border-[var(--color-line)] flex-shrink-0">

                    {/* Alcalde electo */}
                    {alcaldeElecto && (
                        <div className="border-b border-[var(--color-line)]">
                            <div className="px-5 py-3 bg-[var(--color-good)] text-white">
                                <h3 className="text-[12px] font-bold uppercase tracking-wider">Alcalde electo</h3>
                            </div>
                            <div className="p-5">
                                <div className="flex items-center gap-3 mb-3">
                                    <div className="w-14 h-14 rounded-full flex items-center justify-center text-white text-[20px] font-bold shadow-lg border-2 border-white" style={{ background: alcaldeElecto.color || partyColor(alcaldeElecto.partido, 0) }}>
                                        {alcaldeElecto.name.charAt(0)}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-[15px] font-extrabold text-[var(--color-ink)] uppercase">{alcaldeElecto.name}</p>
                                        <div className="flex items-center gap-2 mt-0.5">
                                            {partyLogo(alcaldeElecto.partido, alcaldeElecto.acronym) && (
                                                <img src={partyLogo(alcaldeElecto.partido, alcaldeElecto.acronym)} alt="" className="w-5 h-5 object-contain" />
                                            )}
                                            <p className="text-[11px] text-[var(--color-ink-faint)]">{alcaldeElecto.partido ?? '—'}</p>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <span className="text-[26px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(alcaldeElecto.votos)}</span>
                                    <span className="text-[13px] font-bold text-[var(--color-ink-faint)] font-[var(--font-mono)]">
                                        {totalVotosAlcaldia > 0 ? (alcaldeElecto.votos / totalVotosAlcaldia * 100).toFixed(2).replace('.', ',') : '0'}%
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Donut — distribución por candidato/partido */}
                    {donutData.length > 0 && (
                        <div className="p-4">
                            <div className="h-[300px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie data={donutData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius="40%" outerRadius="85%" paddingAngle={0.3} stroke="none">
                                            {donutData.map((d, i) => <Cell key={i} fill={d.fill} />)}
                                        </Pie>
                                        <Tooltip content={<DonutTooltip />} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="grid grid-cols-1 gap-y-1.5 mt-3">
                                {donutData.map((d, i) => (
                                    <div key={i} className="flex items-center gap-2 text-[10px]">
                                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: d.fill }} />
                                        <span className="text-[var(--color-ink-soft)] truncate flex-1">{d.name}</span>
                                        <span className="text-[var(--color-ink-faint)] font-[var(--font-mono)]">{fmt(d.value)}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
