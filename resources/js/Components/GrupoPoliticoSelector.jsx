import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';

const FALLO = 'No se pudo cambiar el grupo político. Intente de nuevo.';

// Superadmin only: which grupo político the whole app shows. The server keeps the choice in the session and
// checks it; after saving, the current page reloads with the new data (prefetched pages are discarded).
export default function GrupoPoliticoSelector({ vista, className = '' }) {
    const id = useId();
    const [saving, setSaving] = useState(false);

    async function change(e) {
        const value = e.target.value || null;
        setSaving(true);
        try {
            const res = await apiFetch('/grupo-politico/vista', { method: 'POST', body: JSON.stringify({ campaign_id: value }) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                showGlobalToast(data.message || FALLO, 'error');
                setSaving(false);
                return;
            }
            router.flushAll?.();
            router.reload({ onFinish: () => setSaving(false) });
        } catch {
            showGlobalToast('Sin conexión: no se pudo cambiar el grupo político.', 'error');
            setSaving(false);
        }
    }

    return (
        // Same size as the plain chip on desktop; on phones 16 px and 44 px tall so iOS does not zoom in.
        <div className={`inline-flex items-center gap-1 min-w-0 pl-2.5 pr-1 rounded-full bg-white/10 border border-white/25 ${className}`}>
            <label htmlFor={id} className="sr-only sm:not-sr-only text-[12px] text-white/80 flex-shrink-0">Grupo político:</label>
            <select
                id={id}
                value={vista.selected ?? ''}
                onChange={change}
                disabled={saving}
                aria-busy={saving}
                className="min-w-0 max-w-full min-h-[44px] sm:min-h-0 truncate bg-transparent text-white font-semibold text-[16px] sm:text-[12px] py-1 pr-0.5 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70 disabled:opacity-60 cursor-pointer"
            >
                <option value="" className="text-[var(--color-ink)]">Todos los grupos</option>
                {vista.options.map(o => <option key={o.id} value={o.id} className="text-[var(--color-ink)]">{o.name}</option>)}
            </select>
        </div>
    );
}
