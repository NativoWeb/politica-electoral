import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import SectionCard from '@/Components/SectionCard';
import { fmt } from '@/lib/electoral';

const fmtDate = (dateStr) => {
    if (!dateStr) return '—';
    const [y, m, d] = String(dateStr).slice(0, 10).split('-');
    const months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return `${parseInt(d, 10)} ${months[parseInt(m, 10) - 1]} ${y}`;
};
const fmtYear = (dateStr) => dateStr ? String(dateStr).slice(0, 4) : '—';
const fmtPeriodo = (inicio, fin) => !inicio ? '—' : fin ? `${fmtYear(inicio)} – ${fmtYear(fin)}` : `${fmtYear(inicio)} – presente`;

const OUTCOME_LABELS = {
    elected:      { label: 'ELECTO',       bg: 'var(--color-good-light)',    text: 'var(--color-good)' },
    lost:         { label: 'NO ELECTO',    bg: '#FEF3C7',                    text: '#92400E' },
    withdrawn:    { label: 'RETIRADO',     bg: '#F3F4F6',                    text: '#6B7280' },
    disqualified: { label: 'INHABILITADO', bg: 'var(--color-danger-light)',  text: 'var(--color-danger)' },
};
const STATUS_LABELS = {
    active:  { label: 'En ejercicio', bg: 'var(--color-good-light)', text: 'var(--color-good)' },
    ended:   { label: 'Finalizado',   bg: '#F3F4F6',                  text: '#6B7280' },
    interim: { label: 'Interino',     bg: '#FEF3C7',                  text: '#92400E' },
};

function Badge({ map, value }) {
    const meta = map[value] ?? { label: value ?? '—', bg: '#F3F4F6', text: '#6B7280' };
    return <span className="inline-block px-2 py-0.5 rounded text-[10px] font-bold tracking-wide" style={{ background: meta.bg, color: meta.text }}>{meta.label}</span>;
}

function StatRow({ label, value }) {
    return (
        <div className="flex items-center justify-between py-2.5 border-b border-[var(--color-line)] last:border-0">
            <span className="text-[12px] text-[var(--color-ink-soft)]">{label}</span>
            <span className="text-[14px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{value}</span>
        </div>
    );
}

function CandidaturaCard({ c }) {
    const dotColor = c.color ?? (c.electo ? 'var(--color-good)' : 'var(--color-ink-faint)');

    return (
        <div className="relative pl-8 pb-6">
            <div className="absolute left-[11px] top-0 bottom-0 w-px bg-[var(--color-line)]" />
            <div className="absolute left-[5px] top-[14px] w-[13px] h-[13px] rounded-full border-2 border-white" style={{ background: dotColor }} />

            <div className={`bg-white border border-[var(--color-line)] rounded-lg p-4 ${c.electo ? 'ring-2 ring-[var(--color-good)] ring-offset-1' : ''}`}>
                <div className="flex flex-wrap items-start justify-between gap-2 mb-3">
                    <div>
                        <p className="text-[10px] font-semibold tracking-widest text-[var(--color-ink-faint)] uppercase">
                            {fmtYear(c.fecha)} · {c.evento}
                        </p>
                        <p className="text-[15px] font-bold text-[var(--color-ink)] leading-tight mt-0.5">{c.cargo ?? '—'}</p>
                    </div>
                    <Badge map={OUTCOME_LABELS} value={c.outcome} />
                </div>

                <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-[12px]">
                    <div>
                        <p className="text-[10px] text-[var(--color-ink-faint)] uppercase tracking-wide">Municipio</p>
                        {c.municipioId ? (
                            <Link href={`/municipio/${c.municipioId}`} className="font-semibold text-[var(--color-primary)] hover:underline">{c.municipio ?? '—'}</Link>
                        ) : (
                            <p className="font-semibold text-[var(--color-ink)]">{c.municipio ?? '—'}</p>
                        )}
                    </div>
                    <div>
                        <p className="text-[10px] text-[var(--color-ink-faint)] uppercase tracking-wide">Partido</p>
                        <div className="flex items-center gap-1.5 mt-0.5">
                            {c.color && <span className="w-2.5 h-2.5 rounded-sm flex-shrink-0" style={{ background: c.color }} />}
                            <p className="font-semibold text-[var(--color-ink)] truncate">{c.acronym ?? c.partido ?? '—'}</p>
                        </div>
                    </div>
                    <div>
                        <p className="text-[10px] text-[var(--color-ink-faint)] uppercase tracking-wide">Votos</p>
                        <p className="text-[18px] font-extrabold text-[var(--color-ink)] font-[var(--font-mono)] leading-tight">{c.votos > 0 ? fmt(c.votos) : '—'}</p>
                    </div>
                    <div>
                        <p className="text-[10px] text-[var(--color-ink-faint)] uppercase tracking-wide">Fecha</p>
                        <p className="font-semibold text-[var(--color-ink)]">{fmtDate(c.fecha)}</p>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Persona({ persona, candidaturas, cargos }) {
    const electas = candidaturas.filter(c => c.electo);
    const totalVotos = candidaturas.reduce((s, c) => s + c.votos, 0);
    const initials = [persona.firstName, persona.lastName].filter(Boolean).map(s => s.charAt(0).toUpperCase()).join('') || persona.name.charAt(0).toUpperCase();

    const identityLabels = { confirmed: 'Identidad confirmada', merged: 'Perfil fusionado', duplicate: 'Posible duplicado', unverified: 'No verificado' };
    const identityColors = { confirmed: 'var(--color-good)', merged: '#6B3FA0', duplicate: 'var(--color-danger)', unverified: 'var(--color-ink-faint)' };

    return (
        <AppLayout title={persona.name} breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: persona.name }]}>
            <Head title={`${persona.name} — Inteligencia Electoral`} />

            {/* Hero */}
            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-6">
                    <div className="flex flex-wrap items-center gap-5">
                        <div className="w-16 h-16 rounded-full bg-[var(--color-primary-light)] flex items-center justify-center text-white text-[22px] font-extrabold flex-shrink-0 border-2 border-white/20">
                            {initials}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">Perfil Electoral</p>
                            <h1 className="text-[26px] font-extrabold leading-tight truncate">{persona.name}</h1>
                            <div className="flex items-center gap-2 mt-1">
                                <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: identityColors[persona.status] ?? 'var(--color-ink-faint)' }} />
                                <p className="text-[12px] text-white/50">{identityLabels[persona.status] ?? 'Sin verificar'}</p>
                            </div>
                        </div>
                        <div className="flex gap-8 flex-shrink-0">
                            <div className="text-center">
                                <p className="text-[28px] font-extrabold font-[var(--font-mono)] leading-tight">{candidaturas.length}</p>
                                <p className="text-[10px] text-white/40 uppercase tracking-wide">Candidaturas</p>
                            </div>
                            <div className="text-center">
                                <p className="text-[28px] font-extrabold font-[var(--font-mono)] leading-tight text-[var(--color-accent)]">{electas.length}</p>
                                <p className="text-[10px] text-white/40 uppercase tracking-wide">Victorias</p>
                            </div>
                            {totalVotos > 0 && (
                                <div className="text-center">
                                    <p className="text-[28px] font-extrabold font-[var(--font-mono)] leading-tight">{fmt(totalVotos)}</p>
                                    <p className="text-[10px] text-white/40 uppercase tracking-wide">Votos</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="p-4 lg:p-6">
                <div className="grid grid-cols-1 xl:grid-cols-12 gap-6">

                    {/* Timeline */}
                    <div className="xl:col-span-8 space-y-6">
                        <SectionCard title={`Trayectoria electoral — ${candidaturas.length} ${candidaturas.length === 1 ? 'candidatura' : 'candidaturas'}`}>
                            {candidaturas.length === 0 ? (
                                <p className="text-[13px] text-[var(--color-ink-faint)] py-4">Sin candidaturas registradas.</p>
                            ) : (
                                <div className="relative">
                                    {candidaturas.map((c) => <CandidaturaCard key={c.id} c={c} />)}
                                </div>
                            )}
                        </SectionCard>

                        {cargos.length > 0 && (
                            <SectionCard title={`Cargos — ${cargos.length} ${cargos.length === 1 ? 'registro' : 'registros'}`}>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-[13px]">
                                        <thead>
                                            <tr className="border-b border-[var(--color-line)]">
                                                <th className="text-left py-2 pr-4 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Cargo</th>
                                                <th className="text-left py-2 pr-4 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Municipio</th>
                                                <th className="text-left py-2 pr-4 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Periodo</th>
                                                <th className="text-left py-2 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cargos.map((c) => (
                                                <tr key={c.id} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                                    <td className="py-3 pr-4 font-semibold text-[var(--color-ink)]">{c.cargo ?? '—'}</td>
                                                    <td className="py-3 pr-4">
                                                        {c.municipioId ? (
                                                            <Link href={`/municipio/${c.municipioId}`} className="text-[var(--color-primary)] hover:underline font-semibold">{c.municipio ?? '—'}</Link>
                                                        ) : (c.municipio ?? '—')}
                                                    </td>
                                                    <td className="py-3 pr-4 font-[var(--font-mono)] text-[12px] text-[var(--color-ink-soft)]">{fmtPeriodo(c.inicio, c.fin)}</td>
                                                    <td className="py-3"><Badge map={STATUS_LABELS} value={c.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </SectionCard>
                        )}
                    </div>

                    {/* Sidebar */}
                    <div className="xl:col-span-4 space-y-6">
                        <SectionCard title="Resumen">
                            <StatRow label="Total candidaturas" value={candidaturas.length} />
                            <StatRow label="Victorias electorales" value={electas.length} />
                            <StatRow label="Derrotas" value={candidaturas.filter(c => c.outcome === 'lost').length} />
                            <StatRow label="Cargos ejercidos" value={cargos.length} />
                            {totalVotos > 0 && <StatRow label="Votos acumulados" value={fmt(totalVotos)} />}
                        </SectionCard>

                        {candidaturas.length > 0 && (() => {
                            const partidos = [...new Map(candidaturas.filter(c => c.partido).map(c => [c.partido, c])).values()];
                            if (!partidos.length) return null;
                            return (
                                <SectionCard title="Partidos / Movimientos">
                                    <div className="space-y-2">
                                        {partidos.map((c, i) => (
                                            <div key={i} className="flex items-center gap-2 text-[12px]">
                                                <span className="w-3 h-3 rounded-sm flex-shrink-0" style={{ background: c.color ?? 'var(--color-ink-faint)' }} />
                                                <span className="text-[var(--color-ink-soft)] truncate">
                                                    {c.acronym ? <><strong>{c.acronym}</strong> · {c.partido}</> : c.partido}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </SectionCard>
                            );
                        })()}

                        {candidaturas.length > 0 && (() => {
                            const municipios = [...new Map(candidaturas.filter(c => c.municipio).map(c => [c.municipio, c])).values()];
                            if (!municipios.length) return null;
                            return (
                                <SectionCard title="Municipios con presencia">
                                    <div className="space-y-1.5">
                                        {municipios.map((c, i) => (
                                            <div key={i} className="text-[12px]">
                                                {c.municipioId ? (
                                                    <Link href={`/municipio/${c.municipioId}`} className="text-[var(--color-primary)] hover:underline font-semibold">{c.municipio}</Link>
                                                ) : (
                                                    <span className="text-[var(--color-ink-soft)]">{c.municipio}</span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </SectionCard>
                            );
                        })()}

                        <Link href="/" className="flex items-center gap-2 text-[12px] text-[var(--color-primary)] font-semibold hover:underline">
                            ← Volver al mapa
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
