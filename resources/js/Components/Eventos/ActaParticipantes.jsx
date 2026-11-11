import { useMemo, useState } from 'react';
import { emptyParticipante, participanteFromAsistente } from '@/lib/actas';

export const inputCls = 'w-full min-w-0 px-3 py-2.5 border border-[var(--color-line-strong)] rounded-lg text-[15px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:bg-gray-50';

const norm = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// Participants of the acta: written by hand or added with one click from the event's attendees.
export default function ActaParticipantes({ items, onChange, asistentes = [], municipio, disabled, confirmed = false }) {
    const [q, setQ] = useState('');
    const taken = useMemo(() => new Set(items.map(p => p.attendee_id).filter(Boolean)), [items]);
    const matches = useMemo(() => {
        const term = norm(q).trim();
        if (!term) return [];
        return asistentes.filter(a => !taken.has(a.id) && (norm(a.nombre).includes(term) || String(a.cedula ?? '').includes(term))).slice(0, 15);
    }, [q, asistentes, taken]);

    // Changing the name, phone or cédula drops a chosen ficha: it may be another person now.
    const set = (key, field, value) => onChange(items.map(p => (p.key === key
        ? { ...p, [field]: value, ...(['nombre', 'telefono', 'cedula'].includes(field) ? { lider_id: null, sugerencia_rechazada: null } : {}) }
        : p)));

    return (
        <div className="space-y-3">
            {items.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no hay participantes.</p>}
            <ol className="space-y-3" aria-label="Participantes">
                {items.map((p, i) => (
                    <li key={p.key} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[14px] font-extrabold text-[var(--color-ink-soft)]">
                                Participante {i + 1}
                                {(p.lider_id || p.attendee_id) && <span className="ml-2 px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-300 text-emerald-800 text-[12px]">{p.lider_id ? (confirmed ? 'En Votantes' : 'Ficha elegida') : 'Asistente del evento'}</span>}
                            </span>
                            {!disabled && (
                                <button type="button" onClick={() => onChange(items.filter(x => x.key !== p.key))} className="min-h-[44px] px-3 text-[14px] font-bold text-red-700" aria-label={`Quitar a ${p.nombre || `participante ${i + 1}`}`}>
                                    Quitar
                                </button>
                            )}
                        </div>
                        <div className="grid sm:grid-cols-2 gap-2">
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Nombre
                                <input className={inputCls} value={p.nombre} disabled={disabled} maxLength={200} onChange={e => set(p.key, 'nombre', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Perfil
                                <input className={inputCls} value={p.perfil} disabled={disabled} maxLength={200} placeholder="Líder, presidente JAC…" onChange={e => set(p.key, 'perfil', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Municipio
                                <input className={inputCls} value={p.municipio} disabled={disabled} maxLength={120} onChange={e => set(p.key, 'municipio', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Teléfono
                                <input className={inputCls} value={p.telefono} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => set(p.key, 'telefono', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Cédula (si la tiene)
                                <input className={inputCls} value={p.cedula} disabled={disabled} maxLength={20} inputMode="numeric" onChange={e => set(p.key, 'cedula', e.target.value)} />
                            </label>
                        </div>
                    </li>
                ))}
            </ol>
            {!disabled && (
                <div className="space-y-2">
                    <button type="button" onClick={() => onChange([...items, { ...emptyParticipante(), municipio: municipio ?? '' }])} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[15px] font-bold text-[var(--color-primary)]">
                        Agregar participante
                    </button>
                    {asistentes.length > 0 && (
                        <div className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                            <label htmlFor="acta-buscar-asistente" className="block text-[14px] font-semibold text-[var(--color-ink)]">Agregar desde los asistentes del evento</label>
                            <input id="acta-buscar-asistente" className={inputCls} value={q} onChange={e => setQ(e.target.value)} placeholder="Escriba el nombre o la cédula" />
                            {q.trim() && matches.length === 0 && <p className="text-[14px] text-[var(--color-ink-faint)]">Ningún asistente con ese nombre.</p>}
                            {matches.length > 0 && (
                                <ul className="space-y-1" aria-label="Asistentes encontrados">
                                    {matches.map(a => (
                                        <li key={a.id}>
                                            <button type="button" onClick={() => { onChange([...items, participanteFromAsistente(a, municipio)]); setQ(''); }} className="w-full min-h-[44px] px-3 py-2 rounded-lg border border-[var(--color-line)] text-left text-[15px] hover:bg-blue-50">
                                                <span className="font-bold">{a.nombre}</span>
                                                <span className="text-[13px] text-[var(--color-ink-soft)]">{[a.cedula && ` · C.C. ${a.cedula}`, a.telefono && ` · ${a.telefono}`].filter(Boolean).join('')}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
