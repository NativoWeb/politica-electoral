import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { apiFetch } from '@/lib/api';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';

const inputCls = 'w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[16px] bg-white focus:outline-none focus:border-[var(--color-primary)]';
const btnCls = 'min-h-[44px] px-4 py-2 rounded-lg text-[15px] font-bold disabled:opacity-40';
const primaryCls = `${btnCls} bg-[var(--color-primary)] text-white`;
const secondaryCls = `${btnCls} border-2 border-gray-200 text-[var(--color-ink-soft)]`;

export function typeAdminFailure(status, body) {
    if (status === 403) return 'No tiene permiso para modificar este tipo de evento.';
    if (status === 404) return 'Ese tipo de evento ya no existe. Recargue la página.';
    if (status === 419) return 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.';
    if (status === 422) return body?.errors?.nombre?.[0] || body?.message || 'Revise el nombre del tipo.';

    return 'No se pudo guardar el cambio. Intente de nuevo en un momento.';
}

function TipoRow({ tipo, busy, onRename, onRemove }) {
    const [mode, setMode] = useState('view');
    const [nombre, setNombre] = useState(tipo.nombre);
    const [failure, setFailure] = useState('');
    const inputId = `tipo-admin-${tipo.id}`;

    async function run(action) {
        setFailure('');
        const error = await action();
        if (error) setFailure(error);
        else setMode('view');
    }

    function rename() {
        const text = nombre.trim();
        if (!text) { setFailure('Escriba el nombre del tipo de evento.'); return; }
        if (text === tipo.nombre) { setMode('view'); return; }
        run(() => onRename(tipo, text));
    }

    if (mode === 'edit') {
        return (
            <li className="py-3 space-y-2">
                <label htmlFor={inputId} className="block text-[14px] font-bold text-[var(--color-ink-soft)]">Nuevo nombre para «{tipo.nombre}»</label>
                <input
                    id={inputId}
                    autoFocus
                    className={inputCls}
                    value={nombre}
                    maxLength={80}
                    onChange={e => setNombre(e.target.value)}
                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); rename(); } }}
                />
                {failure && <p role="alert" className="text-[13px] text-red-600">{failure}</p>}
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={rename} disabled={busy || !nombre.trim()} className={primaryCls}>{busy ? 'Guardando...' : 'Guardar'}</button>
                    <button type="button" onClick={() => { setMode('view'); setNombre(tipo.nombre); setFailure(''); }} disabled={busy} className={secondaryCls}>Cancelar</button>
                </div>
            </li>
        );
    }

    if (mode === 'confirm') {
        return (
            <li className="py-3 space-y-2">
                <p className="text-[15px] text-[var(--color-ink)]">¿Eliminar «{tipo.nombre}»? Si ya hay eventos con este tipo, solo se desactiva y esos eventos lo conservan.</p>
                {failure && <p role="alert" className="text-[13px] text-red-600">{failure}</p>}
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => run(() => onRemove(tipo))} disabled={busy} className={`${btnCls} bg-red-600 text-white`}>{busy ? 'Eliminando...' : 'Sí, eliminar'}</button>
                    <button type="button" autoFocus onClick={() => { setMode('view'); setFailure(''); }} disabled={busy} className={secondaryCls}>No, conservar</button>
                </div>
            </li>
        );
    }

    return (
        <li className="py-3 flex flex-wrap items-center gap-2">
            <span className="flex-1 min-w-[8rem] text-[16px] text-[var(--color-ink)] break-words">{tipo.nombre}</span>
            {tipo.editable ? (
                <span className="flex gap-2">
                    <button type="button" aria-label={`Editar ${tipo.nombre}`} onClick={() => { setNombre(tipo.nombre); setMode('edit'); }} disabled={busy} className={secondaryCls}>Editar</button>
                    <button type="button" aria-label={`Eliminar ${tipo.nombre}`} onClick={() => setMode('confirm')} disabled={busy} className={`${btnCls} border-2 border-red-200 text-red-700`}>Eliminar</button>
                </span>
            ) : (
                <span className="text-[13px] text-[var(--color-ink-faint)]">General: no se puede modificar</span>
            )}
        </li>
    );
}

// Rename or delete the usable event types; only the own campaign's (and the global ones for the superadmin) are editable.
export default function TipoAdminModal({ tipos, onRenamed, onRemoved, onClose }) {
    const dialogRef = useRef(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    useEscapeKey(onClose, dialogRef);
    useDialogFocus(dialogRef);

    async function request(url, options) {
        setBusy(true);
        setNotice('');
        try {
            const res = await apiFetch(url, options);
            const body = await res.json().catch(() => null);
            if (!res.ok || !body?.success) return { error: typeAdminFailure(res.status, body) };

            return { body };
        } catch {
            return { error: 'No hay conexión con el servidor. Intente de nuevo.' };
        } finally {
            setBusy(false);
        }
    }

    async function rename(tipo, nombre) {
        const { error, body } = await request(`/eventos/tipos/${tipo.id}`, { method: 'PUT', body: JSON.stringify({ nombre }) });
        if (error) return error;
        onRenamed({ ...tipo, ...body.data });
        setNotice('Tipo de evento actualizado.');

        return null;
    }

    async function remove(tipo) {
        const { error, body } = await request(`/eventos/tipos/${tipo.id}`, { method: 'DELETE' });
        if (error) return error;
        onRemoved(tipo, !!body.deactivated);
        setNotice(body.message || 'Tipo de evento eliminado.');

        return null;
    }

    return createPortal(
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onClose} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-2 sm:p-4 pointer-events-none">
                <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="tipo-admin-title" className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[480px] max-h-[calc(100dvh-1rem)] flex flex-col overflow-hidden">
                    <div className="bg-[var(--color-primary)] text-white px-5 py-4 flex items-center justify-between gap-3 flex-shrink-0">
                        <h2 id="tipo-admin-title" className="text-[18px] font-extrabold">TIPOS DE EVENTO</h2>
                        <button type="button" onClick={onClose} aria-label="Cerrar tipos de evento" title="Cerrar" className="w-11 h-11 rounded-full bg-white/15 flex items-center justify-center flex-shrink-0 active:bg-white/30">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div className="overflow-y-auto px-4 sm:px-5 py-3">
                        <p aria-live="polite" className="text-[14px] font-bold text-emerald-700 min-h-[1.25rem]">{notice}</p>
                        {tipos.length === 0
                            ? <p className="py-4 text-[15px] text-[var(--color-ink-faint)]">No hay tipos de evento.</p>
                            : (
                                <ul className="divide-y divide-[var(--color-line)]">
                                    {tipos.map(t => <TipoRow key={t.id} tipo={t} busy={busy} onRename={rename} onRemove={remove} />)}
                                </ul>
                            )}
                    </div>
                    <div className="px-4 sm:px-5 py-3 border-t border-[var(--color-line)] flex justify-end flex-shrink-0">
                        <button type="button" onClick={onClose} className={secondaryCls}>Listo</button>
                    </div>
                </div>
            </div>
        </>,
        document.body,
    );
}
