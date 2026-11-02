import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ROW_FIELDS, ROW_STATUS, changedValues, rowStatusKey } from '@/lib/eventosImport';
import { isLowConfidence } from '@/lib/eventosOcr';

const STATUS_REASON = {
    no_autorizo: 'En «Autorizó» no dice SÍ: la persona no autorizó el tratamiento de sus datos y no se importa.',
    duplicada: 'La cédula ya aparece en una fila anterior del archivo: esta no se importa.',
    en_evento: 'Esta persona ya está registrada en el evento: no se importa otra vez.',
};

// One row of the preview: its cells (the wrong ones in red with the message), whether it is imported, and an
// inline form to correct it. Saving validates the row again on the server. Rows read with AI (§13) also bring the
// confidence per cell: below `umbral` the cell is shown in amber ("dudoso") until a person corrects it.
export default function ImportRowCard({ row, eventId, importId, editable, fields = ROW_FIELDS, umbral, narrow = false }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(row.valores || {});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const errors = row.errores || {};
    const status = ROW_STATUS[rowStatusKey(row)];
    const importing = row.status === 'valida' && row.decision !== 'omitir';
    const url = `/eventos/${eventId}/importar/${importId}/filas/${row.id}`;
    const ocr = !!row.confianza;
    const doubtful = f => !row.importada && isLowConfidence(row.confianza, f.confidence ?? f.key, umbral);
    const reason = row.motivo || STATUS_REASON[row.status];

    function send(data, onDone) {
        setBusy(true);
        setError('');
        router.patch(url, data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone?.(),
            onError: errs => setError(Object.values(errs || {}).join(' ')),
            onNetworkError: () => { setError('No hay conexión con el servidor. Intente de nuevo.'); return false; },
            onFinish: () => setBusy(false),
        });
    }

    function save(e) {
        e.preventDefault();
        const changes = changedValues(row.valores, draft);
        if (Object.keys(changes).length === 0) {
            setEditing(false);
            return;
        }
        send(changes, () => setEditing(false));
    }

    return (
        <li className={`px-4 py-3 ${row.status === 'error' ? 'bg-red-50/40' : 'bg-white'}`} aria-label={`Fila ${row.fila}`}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-[18px] font-extrabold text-[var(--color-ink)]">Fila {row.fila_pagina ?? row.fila}</span>
                <span className={`inline-flex items-center px-2.5 py-1 rounded-full border text-[16px] font-bold ${status.cls}`}>{status.label}</span>
                {row.corregida && <span className="text-[16px] font-semibold text-[var(--color-ink-soft)]">Corregida</span>}
                {row.importada && <span className="inline-flex items-center px-2.5 py-1 rounded-full border bg-emerald-600 border-emerald-600 text-white text-[16px] font-bold">Importada</span>}
                {editable && row.status === 'valida' && !row.importada && (
                    <label className="ml-auto flex items-center gap-2 min-h-[44px] cursor-pointer">
                        <input
                            type="checkbox"
                            checked={importing}
                            disabled={busy}
                            onChange={() => send({ decision: importing ? 'omitir' : 'importar' })}
                            className="w-6 h-6 accent-[var(--color-primary)]"
                        />
                        <span className="text-[16px] font-bold text-[var(--color-ink)]">Importar</span>
                    </label>
                )}
            </div>
            {reason && <p className="text-[17px] text-[var(--color-ink-soft)] mt-1">{reason}</p>}

            {editing ? (
                <form onSubmit={save} className="mt-2">
                    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-3 gap-y-2">
                        {fields.filter(f => !f.readOnly).map(f => {
                            const id = `fila-${row.id}-${f.key}`;
                            return (
                                <div key={f.key}>
                                    <label htmlFor={id} className="block text-[16px] font-bold text-[var(--color-ink)]">{f.label}</label>
                                    <input
                                        id={id}
                                        value={draft[f.key] ?? ''}
                                        onChange={e => setDraft(prev => ({ ...prev, [f.key]: e.target.value }))}
                                        inputMode={f.inputMode}
                                        placeholder={f.placeholder}
                                        aria-invalid={errors[f.key] ? 'true' : undefined}
                                        aria-describedby={errors[f.key] ? `${id}-error` : undefined}
                                        className={`w-full min-h-[46px] px-3 py-2 border rounded-lg text-[16px] focus:outline-none focus:border-[var(--color-primary)] ${errors[f.key] ? 'border-red-500 bg-red-50' : doubtful(f) ? 'border-amber-500 bg-amber-50' : 'border-[var(--color-line-strong)]'}`}
                                    />
                                    {errors[f.key] && <p id={`${id}-error`} className="text-[16px] font-semibold text-red-700 mt-0.5">{errors[f.key]}</p>}
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-[16px] text-[var(--color-ink-soft)] mt-2">
                        {ocr
                            ? '«Autorizo» y la firma no se pueden cambiar aquí: valen lo que leyó la IA en la planilla. Si la persona sí autorizó, regístrela en el modo operador.'
                            : `«Autorizó» no se puede cambiar aquí: vale lo que dice la planilla firmada (${row.valores?.autoriza || 'vacío'}).`}
                    </p>
                    <div className="flex flex-wrap gap-2 mt-3">
                        <button type="submit" disabled={busy} className="min-h-[48px] px-5 py-2.5 rounded-xl bg-[var(--color-primary)] text-white text-[16px] font-extrabold disabled:opacity-50">
                            {busy ? 'Guardando…' : 'Guardar y revisar'}
                        </button>
                        <button type="button" disabled={busy} onClick={() => { setDraft(row.valores || {}); setEditing(false); }} className="min-h-[48px] px-5 py-2.5 rounded-xl border-2 border-[var(--color-line-strong)] text-[16px] font-bold text-[var(--color-ink-soft)]">
                            Cancelar
                        </button>
                    </div>
                </form>
            ) : (
                <dl className={`mt-2 grid grid-cols-2 sm:grid-cols-3 ${narrow ? 'xl:grid-cols-3' : 'lg:grid-cols-5'} gap-x-3 gap-y-1.5`}>
                    {fields.map(f => {
                        const value = row.valores?.[f.key];
                        const bad = errors[f.key];
                        const low = !bad && doubtful(f);
                        if (!bad && !low && (value === null || value === undefined || value === '') && !['nombres', 'apellidos', 'cedula', 'celular'].includes(f.key)) return null;
                        return (
                            <div key={f.key} className={`min-w-0 rounded-lg px-2 py-1 ${bad ? 'bg-red-100 border border-red-400' : low ? 'bg-amber-50 border border-amber-400' : ''}`} data-error={bad ? f.key : undefined} data-doubtful={low ? f.key : undefined}>
                                <dt className="text-[16px] font-semibold text-[var(--color-ink-soft)]">{f.label}</dt>
                                <dd className={`text-[18px] break-words ${bad ? 'font-bold text-red-800' : low ? 'font-bold text-amber-900' : 'text-[var(--color-ink)]'}`}>{value || '—'}</dd>
                                {bad && <dd className="text-[16px] font-semibold text-red-700">{bad}</dd>}
                                {low && <dd className="text-[16px] font-semibold text-amber-800">Dudoso: compárelo con la foto</dd>}
                            </div>
                        );
                    })}
                </dl>
            )}

            {error && <p role="alert" className="mt-2 text-[16px] font-semibold text-red-700">{error}</p>}
            {editable && !editing && !row.importada && row.status !== 'no_autorizo' && (
                <button type="button" onClick={() => { setDraft(row.valores || {}); setEditing(true); }} className="mt-2 min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[16px] font-bold">
                    Corregir esta fila
                </button>
            )}
        </li>
    );
}
