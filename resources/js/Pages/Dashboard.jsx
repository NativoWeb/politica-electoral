import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import SantanderMap from '@/Components/SantanderMap';
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

/* ── Municipio card (estilo Registraduría — departamento/municipio con partido ganador) ── */
function MunicipioCard({ m }) {
    // No tenemos partido ganador directamente, pero tenemos alcalde
    return (
        <Link
            href={`/municipio/${m.id}`}
            className="flex items-center gap-3 px-4 py-4 border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/30 transition-colors group"
        >
            {/* Nombre municipio */}
            <div className="flex-shrink-0 w-[140px]">
                <p className="text-[14px] font-extrabold text-[var(--color-ink)] uppercase group-hover:text-[var(--color-primary)]">{m.name}</p>
                <p className="text-[10px] text-[var(--color-ink-faint)]">{m.provincia}</p>
            </div>

            {/* Alcalde electo + barras */}
            <div className="flex-1 min-w-0">
                {m.alcalde ? (
                    <p className="text-[11px] font-semibold text-[var(--color-good)] truncate mb-1">{m.alcalde}</p>
                ) : (
                    <p className="text-[11px] text-[var(--color-ink-faint)] mb-1">—</p>
                )}
                <div className="flex items-center gap-3">
                    <div className="flex-1">
                        <div className="flex items-center justify-between text-[9px] text-[var(--color-ink-faint)] mb-0.5">
                            <span>Votos</span>
                        </div>
                        <div className="h-[5px] bg-[var(--color-line)] rounded-full overflow-hidden">
                            <div className="h-full rounded-full bg-[var(--color-primary)]" style={{ width: `${m.barPct}%` }} />
                        </div>
                    </div>
                    <span className="text-[13px] font-bold font-[var(--font-mono)] text-[var(--color-ink)] tabular-nums w-[70px] text-right flex-shrink-0">
                        {m.totalVotos > 0 ? fmt(m.totalVotos) : '—'}
                    </span>
                </div>
            </div>

            {/* Arrow */}
            <div className="w-8 h-8 rounded-full border border-[var(--color-primary)] text-[var(--color-primary)] flex items-center justify-center flex-shrink-0 group-hover:bg-[var(--color-primary)] group-hover:text-white transition-colors">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </div>
        </Link>
    );
}

/* ── MAIN ── */
export default function Dashboard({ kpis, events, topPartidosAlcaldia, topMunicipios, topAlcaldes, municipios, resumenCorporaciones }) {
    const [filterProv, setFilterProv] = useState('');
    const [search, setSearch] = useState('');

    const totalVotosAlcaldia = kpis.totalVotosAlcaldia || topPartidosAlcaldia.reduce((s, p) => s + p.totalVotos, 0);

    const provincias = useMemo(() => {
        const map = {};
        municipios.forEach(m => {
            const p = m.provincia || 'Sin provincia';
            if (!map[p]) map[p] = { name: p, count: 0 };
            map[p].count++;
        });
        return Object.values(map).sort((a, b) => a.name.localeCompare(b.name));
    }, [municipios]);

    const maxVotosMpio = Math.max(...municipios.map(m => m.totalVotos || 0), 1);

    const filtered = useMemo(() => {
        return municipios
            .map(m => ({ ...m, barPct: maxVotosMpio > 0 ? Math.max((m.totalVotos / maxVotosMpio) * 100, 0.3) : 0 }))
            .filter(m => {
                if (filterProv && m.provincia !== filterProv) return false;
                if (search && !m.name.toLowerCase().includes(search.toLowerCase())) return false;
                return true;
            });
    }, [municipios, filterProv, search, maxVotosMpio]);

    const donutData = topPartidosAlcaldia.map((p, i) => ({
        name: p.acronym || p.name,
        value: p.totalVotos,
        fill: p.color || partyColor(p.name, i),
    }));

    const potencial = kpis.municipios * 20000; // approximate

    return (
        <AppLayout
            title="Dashboard"
            breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'ALCALDE' }]}
        >
            <Head title="Resultados Electorales — Santander" />

            {/* ── Breadcrumb geográfico con filtro ── */}
            <div className="bg-[var(--color-primary)] text-white px-6 py-2.5 flex items-center gap-2 border-b border-white/10">
                <span className="text-[18px] font-extrabold">SANTANDER</span>
                <GeoNavigator municipios={municipios} basePath="/municipio" />
            </div>

            {/* ── Header KPIs ── */}
            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-8 lg:gap-16">
                    <ProgressRing value={kpis.municipios > 0 ? (kpis.municipios / 87) * 100 : 0} size={100} stroke={7} label="Mesas informadas" sub={`${kpis.municipios} de 87`} color="#FFD100" trackColor="rgba(255,255,255,0.15)" dark />
                    <ProgressRing value={totalVotosAlcaldia > 0 ? 59 : 0} size={100} stroke={7} label="Votantes" sub={fmt(totalVotosAlcaldia)} color="#FFD100" trackColor="rgba(255,255,255,0.15)" dark />
                </div>
            </div>

            {/* ── Layout 2 columnas estilo Registraduría ── */}
            <div className="flex flex-col xl:flex-row">

                {/* ═══ Izquierda: Lista de municipios con resultados ═══ */}
                <div className="flex-1 min-w-0">

                    {/* Filtro provincia */}
                    <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 bg-gray-50 border-b border-[var(--color-line)]">
                        <div className="flex items-center gap-2">
                            <select
                                value={filterProv}
                                onChange={e => setFilterProv(e.target.value)}
                                className="text-[12px] border border-[var(--color-line)] rounded-lg px-3 py-1.5 text-[var(--color-ink-soft)] focus:outline-none focus:border-[var(--color-primary)] font-semibold"
                            >
                                <option value="">Todos los municipios ({municipios.length})</option>
                                {provincias.map(p => <option key={p.name} value={p.name}>{p.name} ({p.count})</option>)}
                            </select>
                        </div>
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Buscar municipio..."
                            className="text-[12px] border border-[var(--color-line)] rounded-lg px-3 py-1.5 w-[180px] focus:outline-none focus:border-[var(--color-primary)]"
                        />
                    </div>

                    {/* Municipios cards */}
                    <div className="max-h-[calc(100vh-300px)] overflow-y-auto">
                        {filtered.map(m => <MunicipioCard key={m.id} m={m} />)}
                        {filtered.length === 0 && <p className="px-4 py-12 text-center text-[13px] text-[var(--color-ink-faint)]">Sin resultados</p>}
                    </div>
                </div>

                {/* ═══ Derecha: Mapa + Donut + Top alcaldes ═══ */}
                <div className="w-full xl:w-[420px] xl:border-l border-[var(--color-line)] flex-shrink-0">

                    {/* Top alcaldes */}
                    {topAlcaldes.length > 0 && (
                        <div className="border-b border-[var(--color-line)]">
                            <div className="px-4 py-2.5 bg-gray-50 border-b border-[var(--color-line)]">
                                <h3 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Top candidatos · Alcaldia</h3>
                            </div>
                            {topAlcaldes.slice(0, 8).map((a, i) => {
                                const pctVal = totalVotosAlcaldia > 0 ? (a.totalVotos / totalVotosAlcaldia * 100) : 0;
                                return (
                                    <Link key={a.id} href={`/persona/${a.id}`} className="flex items-center gap-2.5 px-4 py-2.5 border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors group">
                                        <div className="w-8 h-8 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center text-[11px] font-bold flex-shrink-0">{i + 1}</div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[12px] font-bold text-[var(--color-ink)] group-hover:text-[var(--color-primary)] truncate uppercase">{a.name}</p>
                                            {a.electo && <span className="text-[8px] text-[var(--color-good)] font-bold uppercase">Electo</span>}
                                        </div>
                                        <div className="text-right flex-shrink-0">
                                            <p className="text-[12px] font-bold font-[var(--font-mono)] text-[var(--color-ink)]">{fmt(a.totalVotos)}</p>
                                            <p className="text-[9px] text-[var(--color-ink-faint)] font-[var(--font-mono)]">{pctVal.toFixed(1).replace('.', ',')}%</p>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}

                    {/* Donut */}
                    {donutData.length > 0 && (
                        <div className="p-4 border-b border-[var(--color-line)]">
                            <div className="h-[280px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie data={donutData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius="42%" outerRadius="82%" paddingAngle={0.5} stroke="none">
                                            {donutData.map((d, i) => <Cell key={i} fill={d.fill} />)}
                                        </Pie>
                                        <Tooltip content={<DonutTooltip />} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="grid grid-cols-2 gap-x-3 gap-y-1 mt-2">
                                {donutData.map((d, i) => (
                                    <div key={i} className="flex items-center gap-1.5 text-[9px]">
                                        <span className="w-2.5 h-2.5 rounded-sm flex-shrink-0" style={{ background: d.fill }} />
                                        <span className="text-[var(--color-ink-soft)] truncate">{d.name}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Mapa */}
                    <div className="p-3">
                        <SantanderMap municipios={municipios} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
