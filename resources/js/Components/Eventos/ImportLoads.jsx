import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { apiFetch } from '@/lib/api';
import { eventErrorMessage, loadLabel, undoLoadMessage } from '@/lib/eventos';

// Confirmed Excel / OCR loads of the event, each with "Deshacer carga": the dialog shows what the server would delete.
export default function ImportLoads({ eventId, cargas, onDone }) {
    const [confirm, setConfirm] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [failure, setFailure] = useState('');

    if (!cargas?.length) return null;

    async function call(url, options) {
        try {
            const response = await apiFetch(url, options);
            const json = await response.json().catch(() => null);
            return response.ok ? { json } : { error: eventErrorMessage({ status: response.status, data: json }) };
        } catch {
            return { error: 'No hay conexión con el servidor. Intente de nuevo.' };
        }
    }

    async function ask(carga) {
        setFailure('');
        setBusyId(carga.id);
        const { json, error } = await call(`/eventos/${eventId}/cargas/${carga.id}/deshacer`, { method: 'GET' });
        setBusyId(null);
        if (error) setFailure(error);
        else setConfirm({ carga, preview: json?.data });
    }

    async function undo() {
        const { carga } = confirm;
        setConfirm(null);
        setBusyId(carga.id);
        const { json, error } = await call(`/eventos/${eventId}/cargas/${carga.id}`, { method: 'DELETE' });
        setBusyId(null);
        if (error) setFailure(error);
        else onDone(json?.message || 'Carga deshecha.');
    }

    return (
        <section aria-labelledby="cargas-title" className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-2">
            <h2 id="cargas-title" className="text-[16px] font-extrabold text-[var(--color-ink)]">Cargas de asistentes</h2>
            {failure && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{failure}</p>}
            <ul className="divide-y divide-[var(--color-line)]">
                {cargas.map(c => (
                    <li key={c.id} className="py-2 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[14px] text-[var(--color-ink)] min-w-0">{loadLabel(c)}</p>
                        <button
                            type="button"
                            disabled={busyId !== null}
                            onClick={() => ask(c)}
                            className="min-h-[44px] px-3 py-2 rounded-xl border-2 border-red-300 bg-white text-[14px] font-bold text-red-700 disabled:opacity-50"
                        >
                            {busyId === c.id ? 'Un momento…' : 'Eliminar todos los asistentes de esta carga'}
                        </button>
                    </li>
                ))}
            </ul>
            {confirm && (
                <ConfirmDialog
                    title={`¿Deshacer la carga de ${confirm.preview?.asistentes === 1 ? '1 persona' : `${confirm.preview?.asistentes ?? 0} personas`}?`}
                    message={undoLoadMessage(confirm.preview)}
                    confirmLabel="Sí, eliminar la carga"
                    cancelLabel="No, mantener"
                    danger
                    onConfirm={undo}
                    onCancel={() => setConfirm(null)}
                />
            )}
        </section>
    );
}
