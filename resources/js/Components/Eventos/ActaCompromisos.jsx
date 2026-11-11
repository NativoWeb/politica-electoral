import { emptyCompromiso, emptyResponsable } from '@/lib/actas';
import CompromisoEstado from '@/Components/Eventos/CompromisoEstado';
import { inputCls } from '@/Components/Eventos/ActaParticipantes';

// COMPROMISOS of the acta: description, one or more responsables (name + phone) and an optional deadline. The estado
// is followed up in the Compromisos screen; here it is only shown.
export default function ActaCompromisos({ items, onChange, disabled, confirmed = false }) {
    const set = (key, patch) => onChange(items.map(c => (c.key === key ? { ...c, ...patch } : c)));
    const setResp = (c, rKey, field, value) => set(c.key, { responsables: c.responsables.map(r => (r.key === rKey ? { ...r, [field]: value, lider_id: null, sugerencia_rechazada: null } : r)) });

    return (
        <div className="space-y-3">
            {items.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no hay compromisos.</p>}
            {items.map((c, i) => (
                <div key={c.key} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-[14px] font-extrabold text-[var(--color-ink-soft)] flex items-center gap-2">
                            Compromiso {i + 1}
                            {confirmed && c.id && <CompromisoEstado estado={c.estado} vencido={c.vencido} />}
                        </span>
                        {!disabled && !(c.avances > 0) && (
                            <button type="button" onClick={() => onChange(items.filter(x => x.key !== c.key))} className="min-h-[44px] px-3 text-[14px] font-bold text-red-700" aria-label={`Quitar el compromiso ${i + 1}`}>
                                Quitar
                            </button>
                        )}
                    </div>
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Compromiso
                        <textarea className={`${inputCls} min-h-[80px]`} value={c.descripcion} disabled={disabled} maxLength={5000} onChange={e => set(c.key, { descripcion: e.target.value })} />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] w-full sm:w-[220px]">Fecha límite (opcional)
                            <input type="date" className={inputCls} value={c.fecha_limite} disabled={disabled} onChange={e => set(c.key, { fecha_limite: e.target.value })} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] w-full sm:w-[220px]">Recordarme el (opcional)
                            <input type="date" className={inputCls} value={c.recordar_el ?? ''} disabled={disabled} onChange={e => set(c.key, { recordar_el: e.target.value })} />
                        </label>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-[14px] font-semibold text-[var(--color-ink)]">Responsables</legend>
                        {c.responsables.map((r, ri) => (
                            <div key={r.key} className="flex flex-wrap sm:flex-nowrap items-end gap-2">
                                <label className="flex-1 min-w-[12rem] block text-[13px] text-[var(--color-ink-soft)]">Nombre
                                    <input className={inputCls} value={r.nombre} disabled={disabled} maxLength={200} onChange={e => setResp(c, r.key, 'nombre', e.target.value)} />
                                </label>
                                <label className="w-full sm:w-48 block text-[13px] text-[var(--color-ink-soft)]">Teléfono
                                    <input className={inputCls} value={r.telefono} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => setResp(c, r.key, 'telefono', e.target.value)} />
                                </label>
                                {r.lider_id && <span className="mb-2 px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-300 text-emerald-800 text-[12px] font-bold">{confirmed ? 'En Votantes' : 'Ficha elegida'}</span>}
                                {!disabled && c.responsables.length > 1 && (
                                    <button type="button" onClick={() => set(c.key, { responsables: c.responsables.filter(x => x.key !== r.key) })} className="min-h-[44px] px-3 text-[14px] font-bold text-red-700" aria-label={`Quitar responsable ${ri + 1}`}>
                                        Quitar
                                    </button>
                                )}
                            </div>
                        ))}
                        {!disabled && (
                            <button type="button" onClick={() => set(c.key, { responsables: [...c.responsables, emptyResponsable()] })} className="min-h-[44px] px-3 text-[14px] font-bold text-[var(--color-primary)] underline">
                                Agregar otro responsable
                            </button>
                        )}
                    </fieldset>
                </div>
            ))}
            {!disabled && (
                <button type="button" onClick={() => onChange([...items, emptyCompromiso()])} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[15px] font-bold text-[var(--color-primary)]">
                    Agregar compromiso
                </button>
            )}
        </div>
    );
}
