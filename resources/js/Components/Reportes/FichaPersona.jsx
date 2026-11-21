import { useCallback, useEffect, useRef, useState } from 'react';
import PersonaPanel from '@/Components/PersonaPanel';
import VotanteDetail from '@/Components/VotanteDetail';
import useDialogFocus from '@/lib/useDialogFocus';
import { apiFetch as baseFetch } from '@/lib/api';

// VotanteDetail expects the apiFetch of the Votantes page: JSON back, a thrown {status, ...body} on error.
async function votantesFetch(url, options = {}) {
    const res = await baseFetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw { status: res.status, ...data };
    return data;
}

function FichaVotante({ personId, onClose, onSaved, municipios }) {
    const ref = useRef(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [toast, setToast] = useState(null);
    useDialogFocus(ref);

    const cargar = useCallback(() => {
        setLoading(true);
        setError(false);
        return votantesFetch(`/votantes/${personId}`)
            .then(res => setData(res.data))
            .catch(() => { setData(null); setError(true); })
            .finally(() => setLoading(false));
    }, [personId]);

    useEffect(() => { cargar(); }, [cargar]);

    const showToast = useCallback((message, type = 'success') => {
        setToast({ message, type });
        setTimeout(() => setToast(null), 4000);
    }, []);

    return (
        <>
            <div className="fixed inset-0 z-40 bg-ink/60" onClick={onClose} aria-hidden="true" />
            <div ref={ref} role="dialog" aria-modal="true" aria-label="Ficha de la persona" className="fixed inset-0 lg:left-auto lg:w-[520px] z-50 bg-white overflow-y-auto shadow-2xl">
                {toast && (
                    <p role="status" className={`sticky top-0 z-10 px-5 py-3 text-lg font-semibold text-white ${toast.type === 'error' ? 'bg-danger' : 'bg-good'}`}>{toast.message}</p>
                )}
                {error ? (
                    <div className="p-6 space-y-4">
                        <p role="alert" className="text-lg text-ink">No pudimos abrir la ficha. Revisa tu conexión.</p>
                        <div className="flex flex-wrap gap-3">
                            <button type="button" onClick={cargar} className="min-h-14 px-6 text-lg font-semibold rounded-md bg-primary text-white">Intentar de nuevo</button>
                            <button type="button" onClick={onClose} className="min-h-14 px-6 text-lg font-semibold rounded-md border-2 border-primary text-primary">Cerrar</button>
                        </div>
                    </div>
                ) : (
                    <VotanteDetail
                        data={data}
                        loading={loading}
                        creating={false}
                        onClose={onClose}
                        onSaved={() => { onSaved(); showToast('Guardado'); cargar(); }}
                        onDeleted={() => { onSaved(); onClose(); }}
                        apiFetch={votantesFetch}
                        municipios={municipios}
                        showToast={showToast}
                    />
                )}
            </div>
        </>
    );
}

// The ficha opened from the list: the Mapa Político one for who sees the Mapa, otherwise the Votantes one.
// Both decide by themselves whether the record is read-only (another grupo político, or no write.data).
export default function FichaPersona({ personId, puedeMapa, onClose, onSaved, cargosPorTipo, cargoCategorias, municipios = [] }) {
    if (puedeMapa) {
        return (
            <PersonaPanel
                personId={personId}
                onClose={onClose}
                onSaved={onSaved}
                cargosPorTipo={cargosPorTipo ?? {}}
                cargoCategorias={cargoCategorias ?? []}
                municipios={municipios}
            />
        );
    }
    return <FichaVotante personId={personId} onClose={onClose} onSaved={onSaved} municipios={municipios} />;
}
