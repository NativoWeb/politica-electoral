import { useEffect, useRef, useState } from 'react';
import CompromisoEstado from '@/Components/Eventos/CompromisoEstado';
import { resetCampanaCache } from '@/Components/Eventos/CompromisosCampana';
import { inputCls } from '@/Components/Eventos/ActaParticipantes';
import { ESTADOS, failure, formatDate, formatDateTime, request } from '@/lib/actas';
import useDialogFocus from '@/lib/useDialogFocus';

function avanceTitle(a) {
    if (a.estado_nuevo) return `Cambió el estado: ${ESTADOS[a.estado_anterior] ?? '—'} → ${ESTADOS[a.estado_nuevo] ?? a.estado_nuevo}`;
    return 'Avance';
}

// A compromiso with its history of avances; with permission, change its estado or add an avance (note, photo or PDF).
export default function CompromisoDetalle({ compromisoId, onClose, onChanged }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [estado, setEstado] = useState('');
    const [nota, setNota] = useState('');
    const [file, setFile] = useState(null);
    const [recordar, setRecordar] = useState('');
    const fileRef = useRef(null);
    const ref = useRef(null);
    useDialogFocus(ref);

    useEffect(() => {
        let alive = true;
        request(`/eventos/compromisos/${compromisoId}`).then(res => {
            if (!alive) return;
            if (res.ok) { setData(res.body.data); setEstado(res.body.data.estado); setRecordar(res.body.data.recordar_el ?? ''); } else setError(failure(res, 'No se pudo abrir el compromiso.'));
        });
        return () => { alive = false; };
    }, [compromisoId]);

    function done(res, message) {
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo guardar.')); return; }
        setError('');
        resetCampanaCache();
        setNotice(message);
        setData(res.body.data);
        setEstado(res.body.data.estado);
        setRecordar(res.body.data.recordar_el ?? '');
        setNota('');
        setFile(null);
        if (fileRef.current) fileRef.current.value = '';
        onChanged?.(res.body.data);
    }

    async function saveReminder() {
        setError('');
        setNotice('');
        setBusy(true);
        const res = await request(`/eventos/compromisos/${compromisoId}/recordatorio`, { method: 'PUT', json: { recordar_el: recordar || null } });
        done(res, res.body?.message ?? 'Recordatorio guardado.');
    }

    async function save(e) {
        e.preventDefault();
        setError('');
        setNotice('');
        const changesEstado = estado !== data.estado;
        if (!changesEstado && !nota.trim() && !file) { setError('Escriba una nota, adjunte un archivo o cambie el estado.'); return; }
        if (file && file.size > 10 * 1024 * 1024) { setError('El archivo pesa más de 10 MB.'); return; }
        setBusy(true);
        if (changesEstado) {
            const res = await request(`/eventos/compromisos/${compromisoId}/estado`, { method: 'PUT', json: { estado, nota: file ? null : (nota.trim() || null) } });
            if (!res.ok || !file) { done(res, res.body?.message ?? 'Estado actualizado.'); return; }
        }
        const form = new FormData();
        if (nota.trim()) form.append('nota', nota.trim());
        if (file) form.append('archivo', file);
        const res = await request(`/eventos/compromisos/${compromisoId}/avances`, { method: 'POST', form });
        done(res, 'Avance registrado.');
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onClose} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
                <div ref={ref} role="dialog" aria-modal="true" aria-labelledby="compromiso-title" onKeyDown={e => { if (e.key === 'Escape') onClose(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[620px] max-h-[92vh] flex flex-col">
                    <div className="px-5 pt-5 pb-3 border-b border-[var(--color-line)] flex items-start justify-between gap-3">
                        <h3 id="compromiso-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">Seguimiento del compromiso</h3>
                        <button type="button" onClick={onClose} className="min-w-[44px] min-h-[44px] rounded-lg border border-[var(--color-line)] text-[15px] font-bold" aria-label="Cerrar">✕</button>
                    </div>
                    <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                        {!data && !error && <p role="status" className="text-[15px] text-[var(--color-ink-faint)]">Cargando…</p>}
                        {error && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{error}</p>}
                        {notice && <p role="status" className="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{notice}</p>}
                        {data && (
                            <>
                                <div className="space-y-1">
                                    <CompromisoEstado estado={data.estado} vencido={data.vencido} />
                                    <p className="text-[16px] text-[var(--color-ink)] whitespace-pre-line">{data.descripcion}</p>
                                    <p className="text-[14px] text-[var(--color-ink-soft)]">
                                        {[data.fecha_limite && `Fecha límite: ${formatDate(data.fecha_limite)}`, data.recordar_el && `Recordar el: ${formatDate(data.recordar_el)}`,data.evento?.nombre && `Evento: ${data.evento.nombre}`, data.organizacion?.nombre && `Organización: ${data.organizacion.nombre}`, data.acta?.titulo && `Acta: ${data.acta.titulo}`].filter(Boolean).join(' · ')}
                                    </p>
                                    {data.responsables?.length > 0 && (
                                        <p className="text-[14px] text-[var(--color-ink-soft)]">
                                            Responsables: {data.responsables.map(r => [r.nombre, r.telefono].filter(Boolean).join(' ')).join(' / ')}
                                        </p>
                                    )}
                                </div>

                                {data.editable && (
                                    <div className="border border-[var(--color-line)] rounded-xl p-3 flex flex-wrap items-end gap-2">
                                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] w-full sm:w-[220px]">Recordarme el
                                            <input type="date" className={inputCls} value={recordar} onChange={e => setRecordar(e.target.value)} />
                                        </label>
                                        <button type="button" disabled={busy || recordar === (data.recordar_el ?? '')} onClick={saveReminder} className="min-h-[44px] px-4 rounded-xl border-2 border-[var(--color-primary)] text-[15px] font-bold text-[var(--color-primary)] disabled:opacity-50">
                                            {recordar ? 'Guardar recordatorio' : 'Quitar recordatorio'}
                                        </button>
                                    </div>
                                )}

                                {data.editable && (
                                    <form onSubmit={save} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                                        <label className="block text-[14px] font-semibold text-[var(--color-ink)] max-w-[260px]">Estado
                                            <select className={inputCls} value={estado} onChange={e => setEstado(e.target.value)}>
                                                {Object.entries(ESTADOS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                            </select>
                                        </label>
                                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Nota del avance
                                            <textarea className={`${inputCls} min-h-[80px]`} value={nota} maxLength={5000} onChange={e => setNota(e.target.value)} />
                                        </label>
                                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Foto o PDF (opcional, hasta 10 MB)
                                            <input ref={fileRef} type="file" accept="image/jpeg,image/png,application/pdf" className="block mt-1 text-[14px]" onChange={e => setFile(e.target.files?.[0] ?? null)} />
                                        </label>
                                        <button type="submit" disabled={busy} className="min-h-[48px] px-5 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold disabled:opacity-50">
                                            {busy ? 'Guardando…' : 'Guardar avance'}
                                        </button>
                                    </form>
                                )}

                                <div>
                                    <h4 className="text-[15px] font-extrabold text-[var(--color-ink)] mb-2">Historial ({data.avances.length})</h4>
                                    {data.avances.length === 0 && <p className="text-[14px] text-[var(--color-ink-faint)]">Todavía no hay avances.</p>}
                                    <ol className="space-y-2" aria-label="Historial de avances">
                                        {data.avances.map(a => (
                                            <li key={a.id} className="border-l-4 border-[var(--color-primary)] pl-3 py-1">
                                                <p className="text-[14px] font-bold text-[var(--color-ink)]">{avanceTitle(a)}</p>
                                                {a.nota && <p className="text-[14px] text-[var(--color-ink)] whitespace-pre-line">{a.nota}</p>}
                                                {a.archivo && <a href={a.archivo.url} className="inline-flex min-h-[44px] items-center text-[14px] font-bold text-[var(--color-primary)] underline">Ver adjunto: {a.archivo.nombre}</a>}
                                                <p className="text-[12px] text-[var(--color-ink-faint)]">{[a.usuario, formatDateTime(a.created_at)].filter(Boolean).join(' · ')}</p>
                                            </li>
                                        ))}
                                    </ol>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
