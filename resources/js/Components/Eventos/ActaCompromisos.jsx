import { COMPROMISO_FALTANTES, compromisoFaltantes, emptyCompromiso, emptyResponsable } from '@/lib/actas';
import CompromisoEstado from '@/Components/Eventos/CompromisoEstado';
import { RowError, fieldProps } from '@/Components/Eventos/ActaParticipantes';

// COMPROMISOS of the acta: description, one or more responsables (name + phone) and an optional deadline. The estado
// is followed up in the Compromisos screen; here it is only shown.
export default function ActaCompromisos({ items, onChange, disabled, confirmed = false, error = null }) {
    const set = (key, patch) => onChange(items.map(c => (c.key === key ? { ...c, ...patch } : c)));
    const setResp = (c, rKey, field, value) => set(c.key, { responsables: c.responsables.map(r => (r.key === rKey ? { ...r, [field]: value, lider_id: null, sugerencia_rechazada: null } : r)) });
    const incompletos = items.filter(c => c.descripcion.trim() && compromisoFaltantes(c).length > 0).length;

    return (
        <div className="space-y-3">
            {items.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no hay compromisos.</p>}
            {incompletos > 0 && (
                <p className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[16px] font-semibold text-amber-900">
                    {incompletos === 1 ? '1 compromiso sin fecha límite o sin responsable' : `${incompletos} compromisos sin fecha límite o sin responsable`}
                </p>
            )}
            {items.map((c, i) => {
                const faltan = c.descripcion.trim() ? compromisoFaltantes(c) : [];
                const avances = Number(c.avances) || 0;
                return (
                <div key={c.key} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-[14px] font-extrabold text-[var(--color-ink-soft)] flex flex-wrap items-center gap-2">
                            Compromiso {i + 1}
                            {confirmed && c.id && <CompromisoEstado estado={c.estado} vencido={c.vencido} />}
                            {faltan.map(k => <span key={k} className="px-2 py-0.5 rounded-full bg-amber-50 border border-amber-300 text-amber-900 text-[14px]">{COMPROMISO_FALTANTES[k]}</span>)}
                        </span>
                        {!disabled && (
                            <button
                                type="button"
                                disabled={avances > 0}
                                aria-describedby={avances > 0 ? `compromiso-avances-${c.key}` : undefined}
                                onClick={() => onChange(items.filter(x => x.key !== c.key))}
                                className="min-h-[48px] px-3 text-[16px] font-bold text-red-700 disabled:text-gray-400 disabled:cursor-not-allowed"
                                aria-label={`Quitar el compromiso ${i + 1}`}
                            >
                                Quitar
                            </button>
                        )}
                    </div>
                    <RowError error={error} rowKey={c.key} />
                    {c.bloqueo && (
                        <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">{c.bloqueo}</p>
                    )}
                    {!disabled && avances > 0 && !c.bloqueo && (
                        <p id={`compromiso-avances-${c.key}`} className="text-[16px] text-[var(--color-ink-soft)]">
                            {avances === 1 ? 'Tiene 1 avance' : `Tiene ${avances} avances`}: no se puede quitar. Si ya no aplica, márquelo como «No cumplido» en Seguimiento.
                        </p>
                    )}
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Compromiso
                        <textarea {...fieldProps(error, c.key, 'descripcion', 'min-h-[80px]')} value={c.descripcion} disabled={disabled} maxLength={5000} onChange={e => set(c.key, { descripcion: e.target.value })} />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] w-full sm:w-[220px]">Fecha límite (opcional)
                            <input type="date" {...fieldProps(error, c.key, 'fecha_limite')} value={c.fecha_limite} disabled={disabled} onChange={e => set(c.key, { fecha_limite: e.target.value })} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] w-full sm:w-[220px]">Recordarme el (opcional)
                            <input type="date" {...fieldProps(error, c.key, 'recordar_el')} value={c.recordar_el ?? ''} disabled={disabled} onChange={e => set(c.key, { recordar_el: e.target.value })} />
                        </label>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-[14px] font-semibold text-[var(--color-ink)]">Responsables</legend>
                        {c.responsables.map((r, ri) => (
                            <div key={r.key} className="flex flex-wrap sm:flex-nowrap items-end gap-2">
                                <label className="flex-1 min-w-[12rem] block text-[13px] text-[var(--color-ink-soft)]">Nombre
                                    <input {...fieldProps(error, c.key, `responsable:${r.key}`)} value={r.nombre} disabled={disabled} maxLength={200} onChange={e => setResp(c, r.key, 'nombre', e.target.value)} />
                                </label>
                                <label className="w-full sm:w-48 block text-[13px] text-[var(--color-ink-soft)]">Teléfono
                                    <input {...fieldProps(error, c.key, `responsable-telefono:${r.key}`)} value={r.telefono} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => setResp(c, r.key, 'telefono', e.target.value)} />
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
                            <button type="button" onClick={() => set(c.key, { responsables: [...c.responsables, emptyResponsable()] })} className="min-h-[48px] px-3 text-[16px] font-bold text-[var(--color-primary)] underline">
                                Agregar otro responsable
                            </button>
                        )}
                    </fieldset>
                </div>
                );
            })}
            {!disabled && (
                <button type="button" onClick={() => onChange([...items, emptyCompromiso()])} className="min-h-[48px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[16px] font-bold text-[var(--color-primary)]">
                    Agregar compromiso
                </button>
            )}
        </div>
    );
}
