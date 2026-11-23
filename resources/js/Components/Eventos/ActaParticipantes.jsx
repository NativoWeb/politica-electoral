import { useEffect, useMemo, useState } from 'react';
import { FALTANTES, emptyParticipante, fieldId, participanteFaltantes, participanteFromAsistente } from '@/lib/actas';
import { apiFetch } from '@/lib/api';

export const inputCls = 'w-full min-w-0 px-3 py-2.5 border border-[var(--color-line-strong)] rounded-lg text-[15px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:bg-gray-50';
export const invalidCls = 'border-red-500 ring-2 ring-red-200';

// Id, aria-invalid and style of a field; error = {key, field, message} of the row the server refused (rowError).
export function fieldProps(error, rowKey, field, extra = '') {
    const bad = error?.key === rowKey && error.field === field;
    return {
        id: fieldId(rowKey, field),
        'aria-invalid': bad || undefined,
        'aria-describedby': bad ? fieldId(rowKey, 'error') : undefined,
        className: [inputCls, extra, bad ? invalidCls : ''].filter(Boolean).join(' '),
    };
}

export function RowError({ error, rowKey }) {
    if (error?.key !== rowKey) return null;
    return <p id={fieldId(rowKey, 'error')} className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">{error.message}</p>;
}

const norm = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// Participants of the acta: written by hand or added with one click from the event's attendees.
// searchUrl: where people already in Votantes are searched (an organization's acta uses /empresarios/buscar/personas).
export default function ActaParticipantes({ items, onChange, asistentes = [], municipio, disabled, confirmed = false, searchUrl = '/eventos/buscar/organizadores', error = null }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState([]);
    const [searching, setSearching] = useState(false);
    const taken = useMemo(() => new Set(items.map(p => p.attendee_id).filter(Boolean)), [items]);
    const takenLideres = useMemo(() => new Set(items.map(p => p.lider_id).filter(Boolean)), [items]);
    const matches = useMemo(() => {
        const term = norm(q).trim();
        if (!term) return [];
        return asistentes.filter(a => !taken.has(a.id) && (norm(a.nombre).includes(term) || String(a.cedula ?? '').includes(term))).slice(0, 8);
    }, [q, asistentes, taken]);

    // People already in Votantes (the campaign's records in the user's territory), searched as the name is typed.
    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) { setFound([]); setSearching(false); return undefined; }
        setSearching(true);
        const timer = setTimeout(() => {
            apiFetch(`${searchUrl}?q=${encodeURIComponent(term)}`, { method: 'GET' })
                .then(r => (r.ok ? r.json() : { data: [] }))
                .then(body => setFound(body.data ?? []))
                .catch(() => setFound([]))
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(timer);
    }, [q, searchUrl]);

    const attendeeLideres = useMemo(() => new Set(asistentes.map(a => a.lider_id).filter(Boolean)), [asistentes]);
    const enSistema = found.filter(l => !takenLideres.has(l.id) && !attendeeLideres.has(l.id));
    const addLider = l => {
        onChange([...items, { ...emptyParticipante(), nombre: l.nombre ?? '', telefono: l.telefono ?? '', cedula: l.cedula ?? '', municipio: l.municipio ?? municipio ?? '', perfil: l.cargo ?? '', lider_id: l.id }]);
        setQ('');
    };
    const addNuevo = () => {
        onChange([...items, { ...emptyParticipante(), nombre: q.trim(), municipio: municipio ?? '' }]);
        setQ('');
    };

    // Changing the name, phone or cédula drops a chosen ficha: it may be another person now. A participant of a
    // confirmed acta keeps its ficha (a correction only fixes the text and fills what the ficha lacks).
    const linked = p => confirmed && !!p.id;
    const set = (key, field, value) => onChange(items.map(p => (p.key === key
        ? { ...p, [field]: value, ...(['nombre', 'telefono', 'cedula'].includes(field) && !linked(p) ? { lider_id: null, sugerencia_rechazada: null, por_completar: undefined } : {}) }
        : p)));
    const incompletos = items.filter(p => p.nombre.trim() && participanteFaltantes(p).length > 0).length;

    return (
        <div className="space-y-3">
            {items.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no hay participantes.</p>}
            {incompletos > 0 && (
                <p className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[16px] font-semibold text-amber-900">
                    {incompletos === 1 ? '1 participante con datos por completar' : `${incompletos} participantes con datos por completar`}
                </p>
            )}
            <ol className="space-y-3" aria-label="Participantes">
                {items.map((p, i) => {
                    const faltan = p.nombre.trim() ? participanteFaltantes(p) : [];
                    return (
                    <li key={p.key} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="flex flex-wrap items-center gap-2 text-[14px] font-extrabold text-[var(--color-ink-soft)]">
                                Participante {i + 1}
                                {linked(p)
                                    ? <span className="px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-300 text-emerald-800 text-[14px]">Vinculado a su ficha</span>
                                    : (p.lider_id || p.attendee_id) && <span className="px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-300 text-emerald-800 text-[12px]">{p.lider_id ? 'Ficha elegida' : 'Asistente del evento'}</span>}
                                {faltan.length > 0 && <span className="px-2 py-0.5 rounded-full bg-amber-50 border border-amber-300 text-amber-900 text-[14px]">Falta: {faltan.map(k => FALTANTES[k]).join(', ')}</span>}
                            </span>
                            {!disabled && (
                                <button type="button" onClick={() => onChange(items.filter(x => x.key !== p.key))} className="min-h-[48px] px-3 text-[16px] font-bold text-red-700" aria-label={`Quitar a ${p.nombre || `participante ${i + 1}`}`}>
                                    Quitar
                                </button>
                            )}
                        </div>
                        <div className="grid sm:grid-cols-2 gap-2">
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Nombre
                                <input {...fieldProps(error, p.key, 'nombre')} value={p.nombre} disabled={disabled} maxLength={200} onChange={e => set(p.key, 'nombre', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Perfil
                                <input {...fieldProps(error, p.key, 'perfil')} value={p.perfil} disabled={disabled} maxLength={200} placeholder="Líder, presidente JAC…" onChange={e => set(p.key, 'perfil', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Municipio
                                <input {...fieldProps(error, p.key, 'municipio')} value={p.municipio} disabled={disabled} maxLength={120} onChange={e => set(p.key, 'municipio', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Teléfono
                                <input {...fieldProps(error, p.key, 'telefono')} value={p.telefono} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => set(p.key, 'telefono', e.target.value)} />
                            </label>
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Cédula (si la tiene)
                                <input {...fieldProps(error, p.key, 'cedula')} value={p.cedula} disabled={disabled} maxLength={20} inputMode="numeric" onChange={e => set(p.key, 'cedula', e.target.value)} />
                            </label>
                        </div>
                        <RowError error={error} rowKey={p.key} />
                    </li>
                    );
                })}
            </ol>
            {!disabled && (
                <div className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                    <label htmlFor="acta-buscar-participante" className="block text-[15px] font-bold text-[var(--color-ink)]">Agregar participante</label>
                    <p className="text-[13px] text-[var(--color-ink-soft)]">Escriba el nombre o la cédula. Si la persona ya está en el sistema, elíjala de la lista; si no, agréguela como persona nueva.</p>
                    <input id="acta-buscar-participante" className={inputCls} value={q} onChange={e => setQ(e.target.value)} placeholder="Ej.: Nelson Díaz o 91466940" autoComplete="off" />
                    {q.trim().length >= 2 && (
                        <div className="space-y-2" aria-live="polite">
                            {matches.length > 0 && (
                                <ResultList title="Asistentes del evento" items={matches} onPick={a => { onChange([...items, participanteFromAsistente(a, municipio)]); setQ(''); }} />
                            )}
                            {enSistema.length > 0 && (
                                <ResultList title="Ya están en el sistema" items={enSistema} onPick={addLider} />
                            )}
                            {searching && <p className="text-[14px] text-[var(--color-ink-faint)]">Buscando en el sistema…</p>}
                            {!searching && matches.length === 0 && enSistema.length === 0 && <p className="text-[14px] text-[var(--color-ink-faint)]">No está en el sistema.</p>}
                            <button type="button" onClick={addNuevo} className="w-full min-h-[44px] px-3 py-2 rounded-lg border-2 border-dashed border-[var(--color-primary)] text-left text-[15px] font-bold text-[var(--color-primary)]">
                                + Agregar «{q.trim()}» como persona nueva
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function ResultList({ title, items, onPick }) {
    return (
        <div>
            <p className="text-[12px] font-extrabold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">{title}</p>
            <ul className="space-y-1" aria-label={title}>
                {items.map(a => (
                    <li key={a.id}>
                        <button type="button" onClick={() => onPick(a)} className="w-full min-h-[44px] px-3 py-2 rounded-lg border border-[var(--color-line)] text-left text-[15px] hover:bg-blue-50">
                            <span className="font-bold">{a.nombre}</span>
                            <span className="text-[13px] text-[var(--color-ink-soft)]">{[a.cargo && ` · ${a.cargo}`, a.municipio && ` · ${a.municipio}`, a.cedula && ` · C.C. ${a.cedula}`, a.telefono && ` · ${a.telefono}`].filter(Boolean).join('')}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
