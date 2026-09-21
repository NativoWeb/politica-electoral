import Spinner from '@/Components/Spinner';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import ProgressRing from '@/Components/ProgressRing';
import GeoNavigator from '@/Components/GeoNavigator';
import { partyColor, partyLogo, fmt } from '@/lib/electoral';

function PartidoAccordion({ grupo, index, totalVotos, isOpen, onToggle }) {
    const color = grupo.color || partyColor(grupo.partido, index);
    const logo = partyLogo(grupo.partido, grupo.acronym);
    const grupoVotos = grupo.concejales.reduce((s, c) => s + (c.votos || 0), 0);
    const porcentaje = totalVotos > 0 ? (grupoVotos / totalVotos * 100) : 0;

    return (
        <div className="border-b border-[var(--color-line)] last:border-0">
            <button onClick={onToggle} className="w-full px-5 py-4 hover:bg-blue-50/20 transition-colors text-left">
                <div className="flex items-center gap-4 mb-3">
                    {logo ? (
                        <div className="w-[72px] h-[56px] rounded-lg border border-[var(--color-line)] bg-white flex items-center justify-center overflow-hidden p-1.5 flex-shrink-0">
                            <img src={logo} alt={grupo.partido} className="w-full h-full object-contain" />
                        </div>
                    ) : (
                        <div className="w-[72px] h-[56px] rounded-lg flex items-center justify-center flex-shrink-0 text-white text-[12px] font-bold" style={{ background: color }}>
                            {(grupo.acronym || grupo.partido || '?').slice(0, 4)}
                        </div>
                    )}
                    <p className="text-[14px] text-[var(--color-ink-soft)] flex items-center gap-2">
                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />
                        {grupo.partido}
                    </p>
                    <div className={`ml-auto w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 transition-colors ${isOpen ? 'bg-[var(--color-primary)] text-white' : 'bg-blue-50 text-[var(--color-primary)]'}`}>
                        <svg className={`w-5 h-5 transition-transform ${isOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </div>
                <div className="h-[10px] bg-[var(--color-line)] rounded-full overflow-hidden">
                    <div className="h-full rounded-full" style={{ width: `${porcentaje}%`, background: color }} />
                </div>
                <div className="flex items-baseline justify-between mt-2">
                    <span className="text-[24px] font-extrabold text-[var(--color-primary)] font-[var(--font-mono)]">{porcentaje.toFixed(2).replace('.', ',')}%</span>
                    <span className="text-[18px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(grupoVotos)}</span>
                </div>
            </button>

            {isOpen && (
                <div className="bg-blue-50/30">
                    {grupo.concejales.map((c, ci) => {
                        const cPct = totalVotos > 0 ? (c.votos / totalVotos * 100) : 0;
                        const isElecto = c.outcome === 'elected';
                        return (
                            <Link
                                key={c.id}
                                href={`/persona/${c.id}`}
                                className={`flex items-center px-5 py-3 border-t border-[var(--color-line)]/60 hover:bg-white transition-colors group ${isElecto ? 'bg-emerald-50/80' : ''}`}
                            >
                                <div className="flex-1 min-w-0 text-center">
                                    <p className={`text-[13px] group-hover:underline uppercase font-semibold ${isElecto ? 'text-[var(--color-good)]' : 'text-[var(--color-primary)]'}`}>
                                        ({c.listPosition ?? ci + 1}) {c.name}
                                    </p>
                                    {isElecto && (
                                        <span className="text-[9px] font-bold text-[var(--color-good)] uppercase tracking-wider">Electo</span>
                                    )}
                                </div>
                                <span className={`text-[14px] font-extrabold font-[var(--font-mono)] w-[70px] text-right flex-shrink-0 ${isElecto ? 'text-[var(--color-good)]' : 'text-[var(--color-primary)]'}`}>
                                    {cPct.toFixed(2).replace('.', ',')}%
                                </span>
                                <span className="text-[14px] font-bold text-[var(--color-ink)] font-[var(--font-mono)] w-[60px] text-right flex-shrink-0">
                                    {c.votos > 0 ? fmt(c.votos) : '—'}
                                </span>
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function Concejo({ municipio, concejales = [], totalVotos = 0, seats = 0, allMunicipios = [] }) {
    const [openPartido, setOpenPartido] = useState(0);

    if (!municipio) return (
        <AppLayout title="Concejo" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'CONCEJO' }]}>
            <Head title="Concejo — Inteligencia Electoral" />
            <Spinner message="Cargando datos..." />
        </AppLayout>
    );

    const byPartido = useMemo(() => {
        const map = {};
        concejales.forEach(c => {
            const key = c.partido ?? 'Sin partido';
            if (!map[key]) map[key] = { partido: key, acronym: c.acronym, color: c.color, concejales: [] };
            map[key].concejales.push(c);
        });
        return Object.values(map).sort((a, b) => {
            const va = a.concejales.reduce((s, c) => s + (c.votos || 0), 0);
            const vb = b.concejales.reduce((s, c) => s + (c.votos || 0), 0);
            return vb - va;
        });
    }, [concejales]);

    // Electos para el panel derecho
    const electos = concejales.filter(c => c.outcome === 'elected');

    return (
        <AppLayout title="Concejo" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'CONCEJO' }]}>
            <Head title={`Concejo ${municipio.name} — Inteligencia Electoral`} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3">
                <div className="flex items-center gap-2">
                    <Link href="/" className="text-[22px] font-extrabold hover:underline">SANTANDER</Link>
                    <span className="text-white/40 text-[22px] font-light">/</span>
                    <span className="text-[22px] font-extrabold">{municipio.name.toUpperCase()}</span>
                    <GeoNavigator municipios={allMunicipios} currentMunicipio={municipio.name} basePath="/concejo" />
                </div>
                <p className="text-[11px] text-white/40 mt-1">Elecciones Territoriales 2023 · Preconteo</p>
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={100} size={110} stroke={8} label="Mesas informadas" sub="100%" color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={totalVotos > 0 ? 53 : 0} size={110} stroke={8} label="Votantes" sub={fmt(totalVotos)} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">

                {/* Izquierda: partidos con desglose — electos sombreados en verde */}
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {byPartido.length === 0 ? (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin concejales registrados.</p>
                    ) : (
                        byPartido.map((g, gi) => (
                            <PartidoAccordion key={g.partido} grupo={g} index={gi} totalVotos={totalVotos} isOpen={openPartido === gi} onToggle={() => setOpenPartido(openPartido === gi ? -1 : gi)} />
                        ))
                    )}
                </div>

                {/* Derecha: Concejales electos */}
                <div className="w-full xl:w-[400px] xl:border-l border-[var(--color-line)] flex-shrink-0">
                    <div className="px-5 py-3 bg-[var(--color-good)] text-white">
                        <h3 className="text-[12px] font-bold uppercase tracking-wider">Concejales electos</h3>
                        <p className="text-[10px] text-white/60">{electos.length} de {seats || concejales.length} curules · {municipio.name}</p>
                    </div>
                    {electos.length === 0 ? (
                        <p className="px-5 py-8 text-center text-[13px] text-[var(--color-ink-faint)]">Sin concejales electos registrados.</p>
                    ) : (
                        electos.map((c, i) => {
                            const color = c.color || partyColor(c.partido, i);
                            const logo = partyLogo(c.partido, c.acronym);
                            return (
                                <Link key={c.id} href={`/persona/${c.id}`} className="flex items-center gap-3 px-5 py-3 border-b border-[var(--color-line)] last:border-0 hover:bg-emerald-50/50 transition-colors group">
                                    <div className="w-8 h-8 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0" style={{ background: color }}>
                                        {i + 1}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-[12px] font-bold text-[var(--color-ink)] group-hover:text-[var(--color-primary)] uppercase truncate">{c.name}</p>
                                        <div className="flex items-center gap-1.5 mt-0.5">
                                            {logo && <img src={logo} alt="" className="w-4 h-4 object-contain" />}
                                            <p className="text-[10px] text-[var(--color-ink-faint)] truncate">{c.partido ?? '—'}</p>
                                        </div>
                                    </div>
                                    <span className="text-[12px] font-bold font-[var(--font-mono)] text-[var(--color-ink)] flex-shrink-0">
                                        {c.votos > 0 ? fmt(c.votos) : '—'}
                                    </span>
                                </Link>
                            );
                        })
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
