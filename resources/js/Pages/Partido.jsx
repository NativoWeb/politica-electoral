import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import SectionCard from '@/Components/SectionCard';
import { fmt } from '@/lib/electoral';

const OUTCOME_LABELS = {
    elected:      { label: 'ELECTO',       bg: 'var(--color-good-light)',    text: 'var(--color-good)' },
    lost:         { label: 'NO ELECTO',    bg: '#FEF3C7',                    text: '#92400E' },
    withdrawn:    { label: 'RETIRADO',     bg: '#F3F4F6',                    text: '#6B7280' },
    disqualified: { label: 'INHABILITADO', bg: 'var(--color-danger-light)',  text: 'var(--color-danger)' },
};
const STATUS_ORG_LABELS = {
    active:    { label: 'ACTIVO',     bg: 'var(--color-good-light)',   text: 'var(--color-good)' },
    inactive:  { label: 'INACTIVO',   bg: '#F3F4F6',                   text: '#6B7280' },
    dissolved: { label: 'DISUELTO',   bg: 'var(--color-danger-light)', text: 'var(--color-danger)' },
    suspended: { label: 'SUSPENDIDO', bg: '#FEF3C7',                   text: '#92400E' },
};

function Badge({ map, value }) {
    const meta = map[value] ?? { label: value ?? '—', bg: '#F3F4F6', text: '#6B7280' };
    return <span className="inline-block px-2 py-0.5 rounded text-[10px] font-bold tracking-wide" style={{ background: meta.bg, color: meta.text }}>{meta.label}</span>;
}

const TYPE_LABELS = { party: 'Partido politico', movement: 'Movimiento politico', coalition: 'Coalicion', other: 'Otra organizacion' };

export default function Partido({ partido, candidatos, municipios, aliases }) {
    const electos = candidatos.filter(c => c.electo);
    const totalVotos = candidatos.reduce((s, c) => s + c.votos, 0);
    const colorHex = partido.color_hex ?? null;

    return (
        <AppLayout title={partido.name} breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: partido.name }]}>
            <Head title={`${partido.acronym ?? partido.name} — Inteligencia Electoral`} />

            {/* Hero */}
            <div className="bg-[var(--color-primary)] text-white">
                <div className="px-6 py-6">
                    <div className="flex flex-wrap items-center gap-5">
                        <div
                            className="w-16 h-16 rounded-lg flex items-center justify-center text-white text-[15px] font-extrabold flex-shrink-0 border-2 border-white/20 shadow"
                            style={{ background: colorHex ?? 'var(--color-primary-light)' }}
                        >
                            {partido.acronym
                                ? <span className="text-[13px] leading-tight text-center px-1">{partido.acronym}</span>
                                : <span className="text-[22px]">{partido.name.charAt(0)}</span>
                            }
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">
                                {TYPE_LABELS[partido.type] ?? 'Organizacion politica'}
                            </p>
                            <h1 className="text-[24px] font-extrabold leading-tight">{partido.name}</h1>
                            <div className="flex items-center gap-3 mt-1.5 flex-wrap">
                                {partido.acronym && <span className="text-[12px] text-white/50 font-[var(--font-mono)]">{partido.acronym}</span>}
                                {colorHex && (
                                    <span className="flex items-center gap-1.5 text-[12px] text-white/50">
                                        <span className="w-3 h-3 rounded-sm border border-white/30" style={{ background: colorHex }} />
                                        {colorHex}
                                    </span>
                                )}
                                <Badge map={STATUS_ORG_LABELS} value={partido.status} />
                            </div>
                        </div>
                        <div className="flex gap-8 flex-shrink-0">
                            {[
                                { v: candidatos.length, l: 'Candidatos' },
                                { v: electos.length, l: 'Electos', accent: true },
                                ...(totalVotos > 0 ? [{ v: fmt(totalVotos), l: 'Votos' }] : []),
                                { v: municipios.length, l: 'Municipios' },
                            ].map((s, i) => (
                                <div key={i} className="text-center">
                                    <p className={`text-[28px] font-extrabold font-[var(--font-mono)] leading-tight ${s.accent ? 'text-[var(--color-accent)]' : ''}`}>{s.v}</p>
                                    <p className="text-[10px] text-white/40 uppercase tracking-wide">{s.l}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <div className="p-4 lg:p-6 space-y-6">
                <SectionCard title={`Candidatos — Top ${candidatos.length} por votos`}>
                    {candidatos.length === 0 ? (
                        <p className="text-[13px] text-[var(--color-ink-faint)] py-4">Sin candidatos registrados.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-[13px]">
                                <thead>
                                    <tr className="border-b border-[var(--color-line)]">
                                        {['Candidato', 'Cargo', 'Municipio', 'Evento', 'Votos', 'Resultado'].map(h => (
                                            <th key={h} className="text-left py-2 pr-4 last:pr-0 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {candidatos.map(c => (
                                        <tr key={`${c.id}-${c.evento}`} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                            <td className="py-3 pr-4">
                                                <Link href={`/persona/${c.id}`} className="font-semibold text-[var(--color-primary)] hover:underline">{c.name}</Link>
                                            </td>
                                            <td className="py-3 pr-4 text-[var(--color-ink-soft)]">{c.cargo ?? '—'}</td>
                                            <td className="py-3 pr-4">
                                                {c.municipioId ? (
                                                    <Link href={`/municipio/${c.municipioId}`} className="text-[var(--color-primary)] hover:underline font-semibold">{c.municipio ?? '—'}</Link>
                                                ) : <span className="text-[var(--color-ink-soft)]">{c.municipio ?? '—'}</span>}
                                            </td>
                                            <td className="py-3 pr-4 text-[var(--color-ink-soft)] text-[12px]">{c.evento ?? '—'}</td>
                                            <td className="py-3 pr-4 font-[var(--font-mono)] text-[12px] text-[var(--color-ink)] font-semibold tabular-nums">{c.votos > 0 ? fmt(c.votos) : '—'}</td>
                                            <td className="py-3"><Badge map={OUTCOME_LABELS} value={c.electo ? 'elected' : 'lost'} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>

                <SectionCard title={`Presencia territorial — ${municipios.length} ${municipios.length === 1 ? 'municipio' : 'municipios'}`}>
                    {municipios.length === 0 ? (
                        <p className="text-[13px] text-[var(--color-ink-faint)] py-4">Sin presencia territorial registrada.</p>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            {municipios.map(m => {
                                const maxTotal = Math.max(...municipios.map(x => x.total), 1);
                                const pctBar = Math.round((m.total / maxTotal) * 100);
                                return (
                                    <Link key={m.id} href={`/municipio/${m.id}`} className="border border-[var(--color-line)] rounded-lg p-4 hover:border-[var(--color-primary)] hover:shadow-sm transition-all group">
                                        <p className="text-[13px] font-bold text-[var(--color-ink)] group-hover:text-[var(--color-primary)] truncate mb-1">{m.name}</p>
                                        <div className="w-full bg-[var(--color-line)] rounded-full h-1 mb-2">
                                            <div className="h-1 rounded-full bg-[var(--color-primary)]" style={{ width: `${pctBar}%` }} />
                                        </div>
                                        <div className="flex items-center justify-between text-[11px] text-[var(--color-ink-faint)]">
                                            <span><strong className="text-[var(--color-ink)] text-[13px] font-[var(--font-mono)]">{fmt(m.total)}</strong> votos</span>
                                            <span>{m.candidaturas} candidatura{m.candidaturas !== 1 ? 's' : ''}</span>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </SectionCard>

                {aliases?.length > 0 && (
                    <SectionCard title="Variaciones de nombre">
                        <div className="flex flex-wrap gap-2">
                            {aliases.map((alias, i) => (
                                <span key={i} className="inline-block px-3 py-1 bg-[var(--color-bg)] border border-[var(--color-line)] rounded text-[12px] text-[var(--color-ink-soft)] font-[var(--font-mono)]">{alias}</span>
                            ))}
                        </div>
                    </SectionCard>
                )}

                <Link href="/" className="inline-flex items-center gap-2 text-[12px] text-[var(--color-primary)] font-semibold hover:underline">
                    ← Volver al inicio
                </Link>
            </div>
        </AppLayout>
    );
}
