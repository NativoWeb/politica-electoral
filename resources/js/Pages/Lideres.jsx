import Spinner from '@/Components/Spinner';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import ProgressRing from '@/Components/ProgressRing';
import GeoNavigator from '@/Components/GeoNavigator';
import { fmt } from '@/lib/electoral';

const CARGO_COLORS = {
    'CONCEJAL CD': '#1B3A63',
    'COORDINADOR MUNICIPAL': '#0056A6',
    'MIEMBRO DIRECTORIO': '#4F46E5',
    'REPRESENTANTE JOVENES': '#0891B2',
    'REPRESENTANTE RESERVA': '#059669',
    'VEEDOR': '#D97706',
};

const CARGO_ICONS = {
    'CONCEJAL CD': '🏛',
    'COORDINADOR MUNICIPAL': '📋',
    'MIEMBRO DIRECTORIO': '👥',
    'REPRESENTANTE JOVENES': '🎓',
    'REPRESENTANTE RESERVA': '🛡',
    'VEEDOR': '👁',
};

function LiderCard({ l }) {
    const color = CARGO_COLORS[l.cargo] ?? '#94A3B8';

    return (
        <div className="px-5 py-4 border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/20 transition-colors">
            <div className="flex items-center gap-4">
                <div className="w-[56px] h-[56px] rounded-full flex items-center justify-center text-white text-[20px] font-bold flex-shrink-0 shadow border-[3px] border-white" style={{ background: color }}>
                    {l.nombre.charAt(0)}
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-[16px] font-extrabold text-[var(--color-primary)] uppercase leading-tight">{l.nombre}</h3>
                    <p className="text-[12px] text-[var(--color-ink-soft)] mt-0.5 flex items-center gap-2">
                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: color }} />
                        {l.cargo}
                    </p>
                    <div className="flex items-center gap-4 mt-1.5">
                        {l.telefono && (
                            <a href={`tel:${l.telefono}`} className="text-[11px] text-[var(--color-primary)] font-semibold hover:underline flex items-center gap-1">
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                                {l.telefono}
                            </a>
                        )}
                        {l.email && (
                            <a href={`mailto:${l.email}`} className="text-[11px] text-[var(--color-ink-faint)] hover:text-[var(--color-primary)] truncate">{l.email}</a>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Lideres({ municipio, lideres = [], statsByCargo = [], totalLideres = 0, totalMunicipios = 0, allMunicipios = [] }) {
    if (!municipio) return (
        <AppLayout title="Lideres" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'LIDERES' }]}>
            <Head title="Lideres — Inteligencia Electoral" />
            <Spinner message="Cargando datos..." />
        </AppLayout>
    );

    // Group by cargo
    const byCargo = {};
    lideres.forEach(l => {
        if (!byCargo[l.cargo]) byCargo[l.cargo] = [];
        byCargo[l.cargo].push(l);
    });

    return (
        <AppLayout title="Lideres" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'LIDERES' }]}>
            <Head title={`Lideres ${municipio.name} — Inteligencia Electoral`} />

            <div className="bg-[var(--color-primary)] text-white px-6 py-3 flex items-center gap-2">
                <Link href="/" className="text-[22px] font-extrabold hover:underline">SANTANDER</Link>
                <span className="text-white/40 text-[22px] font-light">/</span>
                <span className="text-[22px] font-extrabold">{municipio.name.toUpperCase()}</span>
                <GeoNavigator municipios={allMunicipios} currentMunicipio={municipio.name} basePath="/lideres" />
            </div>

            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-5 flex flex-wrap items-center justify-center gap-10 lg:gap-20">
                    <ProgressRing value={lideres.length > 0 ? 100 : 0} size={110} stroke={8} label="Lideres" sub={`${lideres.length} en ${municipio.name}`} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                    <ProgressRing value={(totalMunicipios / 87) * 100} size={110} stroke={8} label="Cobertura" sub={`${totalMunicipios} municipios`} color="#FFD100" trackColor="rgba(255,255,255,0.12)" dark />
                </div>
            </div>

            <div className="flex flex-col xl:flex-row min-h-[60vh]">

                {/* Izquierda: líderes */}
                <div className="flex-1 min-w-0 overflow-y-auto">
                    {lideres.length === 0 ? (
                        <p className="px-5 py-12 text-center text-[14px] text-[var(--color-ink-faint)]">Sin lideres registrados en este municipio.</p>
                    ) : (
                        lideres.map(l => <LiderCard key={l.id} l={l} />)
                    )}
                </div>

                {/* Derecha: stats */}
                <div className="w-full xl:w-[400px] xl:border-l border-[var(--color-line)] flex-shrink-0">
                    {statsByCargo.length > 0 && (
                        <div>
                            <div className="px-5 py-3 bg-[#1B3A63] text-white">
                                <h3 className="text-[12px] font-bold uppercase tracking-wider">Directorio · {municipio.name}</h3>
                                <p className="text-[10px] text-white/50">{lideres.length} integrantes</p>
                            </div>
                            {statsByCargo.map((s, i) => {
                                const color = CARGO_COLORS[s.cargo] ?? '#94A3B8';
                                const icon = CARGO_ICONS[s.cargo] ?? '👤';
                                return (
                                    <div key={s.cargo} className="flex items-center gap-3 px-5 py-3 border-b border-[var(--color-line)] last:border-0">
                                        <span className="text-[18px]">{icon}</span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[12px] font-semibold text-[var(--color-ink)]">{s.cargo}</p>
                                            <div className="mt-1 h-[5px] bg-[var(--color-line)] rounded-full overflow-hidden">
                                                <div className="h-full rounded-full" style={{ width: `${(s.total / lideres.length) * 100}%`, background: color }} />
                                            </div>
                                        </div>
                                        <span className="text-[16px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)]">{s.total}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Global stats */}
                    <div className="px-5 py-4 border-t border-[var(--color-line)]">
                        <h4 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-3">Santander</h4>
                        <div className="space-y-2">
                            {[
                                ['Total lideres', totalLideres],
                                ['Municipios con directorio', totalMunicipios],
                            ].map(([label, value]) => (
                                <div key={label} className="flex items-center justify-between py-1.5 border-b border-[var(--color-line)] last:border-0">
                                    <span className="text-[11px] text-[var(--color-ink-soft)]">{label}</span>
                                    <span className="text-[14px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{value}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
