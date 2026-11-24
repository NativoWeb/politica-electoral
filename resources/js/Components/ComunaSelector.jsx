import { useEffect, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';
import { FUENTE_PUESTOS, comunaOrigen, gruposComunas } from '@/lib/comunas';

// The comuna of a ficha (lider). Only rendered when its municipio has comunas. Choosing one saves it right away as
// 'manual' (the automatic assignment never overwrites it); "Volver a automática" lets the cascade (barrio, text written,
// puesto de votación) decide again. The fichas make it editable only outside their edit mode, so "Cancelar" never leaves
// a saved change behind.
export default function ComunaSelector({ liderId, comunas = [], comunaId = null, source = null, editable = false, onSaved }) {
    const [value, setValue] = useState(comunaId);
    const [origin, setOrigin] = useState(source);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => { setValue(comunaId); setOrigin(source); setError(''); }, [liderId, comunaId, source]);

    if (!comunas.length) return null;

    const current = comunas.find(c => c.id === value);
    const groups = gruposComunas(comunas);
    const origen = comunaOrigen(origin);

    async function save(body) {
        setSaving(true);
        setError('');
        try {
            const r = await apiFetch(`/votantes/${liderId}/comuna`, { method: 'PATCH', body: JSON.stringify(body) });
            const res = await r.json().catch(() => null);
            if (!r.ok || !res?.success) {
                const msg = (res?.errors && Object.values(res.errors)[0]?.[0]) || res?.message || 'No se pudo guardar la comuna.';
                setError(msg);
                showGlobalToast(msg, 'error');
                return;
            }
            setValue(res.comuna_id);
            setOrigin(res.comuna_source);
            showGlobalToast(res.message || 'Comuna actualizada.');
            onSaved?.(res);
        } catch {
            const msg = 'Sin conexión: la comuna no se guardó.';
            setError(msg);
            showGlobalToast(msg, 'error');
        } finally {
            setSaving(false);
        }
    }

    // A manual «Sin comuna» is a decision too: it shows its origin. An automatic one without comuna has nothing to say.
    const badge = origen && (current || origin === 'manual') && (
        <span
            data-testid="comuna-origen"
            className={`inline-block px-2 py-0.5 rounded text-[16px] font-semibold whitespace-nowrap ${origin === 'manual' ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900'}`}
        >
            {origen.etiqueta}
        </span>
    );

    const notaPuesto = origin === 'puesto' && current && (
        <p data-testid="comuna-fuente" className="text-[14px] text-[var(--color-ink-soft)] mt-1">
            Comuna según el puesto de votación. {FUENTE_PUESTOS}
        </p>
    );

    if (!editable) {
        return (
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 py-2 border-b border-[var(--color-line)]" data-testid="comuna-readonly">
                <span className="text-[14px] uppercase tracking-wider text-[var(--color-ink-faint)] flex-shrink-0 w-[140px]">Comuna</span>
                <span className="flex flex-wrap items-center justify-end gap-x-2 gap-y-1 text-[16px] text-[var(--color-ink)] text-right">
                    {current?.nombre || 'Sin comuna'}
                    {badge}
                </span>
                {notaPuesto && <div className="basis-full text-right">{notaPuesto}</div>}
            </div>
        );
    }

    return (
        <div>
            <div className="flex flex-wrap items-center gap-2 mb-1">
                <label htmlFor={`comuna-${liderId}`} className="text-[14px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                    Comuna / corregimiento
                </label>
                {badge}
            </div>
            {notaPuesto}
            <div className="flex flex-wrap gap-2 mt-1">
                <select
                    id={`comuna-${liderId}`}
                    value={value || ''}
                    disabled={saving}
                    onChange={e => save({ comuna_id: e.target.value || null })}
                    className="flex-1 min-w-[12rem] min-h-[48px] px-3 py-2 border border-[var(--color-line)] rounded-lg text-[16px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:opacity-60"
                >
                    <option value="">Sin comuna</option>
                    {groups.map(([label, list]) => (
                        <optgroup key={label} label={label}>
                            {list.map(c => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                        </optgroup>
                    ))}
                </select>
                {origin === 'manual' && (
                    <button
                        type="button"
                        disabled={saving}
                        onClick={() => save({ automatica: true })}
                        className="min-h-[48px] px-4 py-2 text-[16px] font-bold text-[var(--color-primary)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50 disabled:opacity-60 whitespace-nowrap"
                    >
                        Volver a automática
                    </button>
                )}
            </div>
            <p className="text-[16px] text-[var(--color-ink-soft)] mt-1" aria-live="polite">
                {saving ? 'Guardando…' : (origen && (current || origin === 'manual') ? origen.detalle : 'Se guarda al elegirla.')}
            </p>
            {error && <p role="alert" className="text-[16px] text-red-700 mt-1">{error}</p>}
        </div>
    );
}
