import Spinner from '@/Components/Spinner';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import GeoNavigator from '@/Components/GeoNavigator';
import { partyColor, partyLogo, fmt } from '@/lib/electoral';

function CandidatoCard({ c, index, totalVotos, maxVotos }) {
    const color = c.color || partyColor(c.partido, index);
    const logo = partyLogo(c.partido, c.acronym);
    const pctVal = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
    const barFilled = maxVotos > 0 ? (c.votos / maxVotos * 100) : 0;
    return (
        <Link href={`/persona/${c.id}`} className="block px-5 py-5 border-b border-[var(--color-line)] hover:bg-blue-50/20 transition-colors group">
            <div className="flex items-center gap-4 mb-3">
                <div className="w-[72px] h-[72px] rounded-full flex items-center justify-center text-white text-[26px] font-bold flex-shrink-0 shadow-lg border-[3px] border-white" style={{ background: color }}>{c.name.charAt(0)}</div>
                {logo && <div className="w-[56px] h-[56px] rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden p-1.5"><img src={logo} alt="" className="w-full h-full object-contain" /></div>}
            </div>
            <h3 className="text-[17px] font-extrabold text-[var(--color-primary)] uppercase leading-tight group-hover:underline">{c.name}</h3>
            <p className="text-[13px] text-[var(--color-ink-soft)] mt-1 flex items-center gap-2">
                <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />{c.acronym ?? c.partido ?? '—'}
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

export default function Camara({ municipio, resultados = [], porPartido = [], votosLista = [], allMunicipios = [] }) {
    const totalVotosMpio = resultados.reduce((s, c) => s + c.votos, 0) + votosLista.reduce((s, p) => s + p.total, 0);
    const maxVotos = resultados[0]?.votos || 1;
    const totalListaVotos = votosLista.reduce((s, p) => s + p.total, 0);

    const munLabel = municipio?.name?.toUpperCase() ?? null;

    return (
        <AppLayout title="Camara" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'CAMARA' }]}>
            <Head title={`Camara ${munLabel ?? 'Santander'} — Inteligencia Electoral`} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <div className="flex items-center gap-2">
                    <span className="text-[22px] font-extrabold">SANTANDER</span>
                    {munLabel && <>
                        <span className="text-white/40 text-[22px] font-light">/</span>
                        <span className="text-[22px] font-extrabold">{munLabel}</span>
                    </>}
                    <GeoNavigator municipios={allMunicipios} currentMunicipio={municipio?.name} basePath="/camara" />
                </div>
                <p className="text-[11px] text-white/40 mt-1">Elecciones Legislativas 2026 · Preconteo{munLabel ? '' : ' · Todos los municipios'}</p>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Mesas informadas" sub="100%" color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={totalVotosMpio > 0 ? 59 : 0} size={110} stroke={8} label="Votantes" sub={fmt(totalVotosMpio)} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {resultados.length === 0 ? (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin candidatos con votos en este municipio.</p>
                    ) : resultados.map((c, i) => (
                        <CandidatoCard key={c.id} c={c} index={i} totalVotos={totalVotosMpio} maxVotos={maxVotos} />
                    ))}
                </div>

                <div className="w-full xl:w-[420px] xl:border-l border-[var(--color-line)] flex-shrink-0">
                    {votosLista.length > 0 && (
                        <div>
                            <div className="px-5 py-3 bg-[var(--color-primary-dark)] text-white">
                                <h3 className="text-[13px] font-bold uppercase tracking-wider">Votos por partido / lista</h3>
                                <p className="text-[10px] text-white/50 mt-0.5">{municipio?.name ?? 'Santander'} · {fmt(totalListaVotos)} votos</p>
                            </div>
                            {votosLista.map((p, i) => {
                                const color = p.color || partyColor(p.name, i);
                                const logo = partyLogo(p.name, p.acronym);
                                const pctVal = totalListaVotos > 0 ? (p.total / totalListaVotos * 100) : 0;
                                return (
                                    <div key={p.id} className="px-5 py-3 border-b border-[var(--color-line)] last:border-0">
                                        <div className="flex items-center gap-3 mb-2">
                                            {logo ? (
                                                <div className="w-10 h-10 rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden p-1 flex-shrink-0">
                                                    <img src={logo} alt="" className="w-full h-full object-contain" />
                                                </div>
                                            ) : (
                                                <span className="w-4 h-4 rounded-sm flex-shrink-0" style={{ background: color }} />
                                            )}
                                            <span className="text-[12px] font-semibold text-[var(--color-ink)] truncate flex-1">{p.acronym ?? p.name}</span>
                                        </div>
                                        <div className="h-[6px] bg-[var(--color-line)] rounded-full overflow-hidden">
                                            <div className="h-full rounded-full" style={{ width: `${pctVal}%`, background: color }} />
                                        </div>
                                        <div className="flex items-baseline justify-between mt-1.5">
                                            <span className="text-[18px] font-extrabold text-[var(--color-primary)] font-[var(--font-mono)]">{pctVal.toFixed(2).replace('.', ',')}%</span>
                                            <span className="text-[14px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(p.total)}</span>
                                        </div>
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
