import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { deleteActaMessage, failure, request } from '@/lib/actas';

// "Eliminar acta" of a CONFIRMED acta (permission delete.actas): asks the server what would be undone, shows it and
// deletes on confirmation.
export default function ActaEliminarConfirmada({ url, onDeleted, onError }) {
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);

    async function ask() {
        onError('');
        setBusy(true);
        const res = await request(`${url}/eliminar`);
        setBusy(false);
        if (!res.ok) { onError(failure(res, 'No se pudo revisar qué se eliminaría.')); return; }
        setPreview(res.body?.data ?? {});
    }

    async function remove() {
        setPreview(null);
        setBusy(true);
        const res = await request(url, { method: 'DELETE' });
        setBusy(false);
        if (!res.ok) { onError(failure(res, 'No se pudo eliminar el acta.')); return; }
        onDeleted(res.body?.message);
    }

    return (
        <>
            <button type="button" disabled={busy} onClick={ask} className="min-h-[44px] px-4 py-2.5 bg-white border-2 border-red-300 rounded-xl text-[15px] font-bold text-red-700 disabled:opacity-50">
                {busy ? 'Un momento…' : 'Eliminar acta'}
            </button>
            {preview && (
                <ConfirmDialog
                    title="¿Eliminar el acta confirmada?"
                    message={deleteActaMessage(preview)}
                    confirmLabel="Sí, eliminar"
                    cancelLabel="No, mantener"
                    danger
                    onConfirm={remove}
                    onCancel={() => setPreview(null)}
                />
            )}
        </>
    );
}
