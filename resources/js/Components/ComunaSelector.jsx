import { useEffect, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';

const SOURCE_LABEL = { auto: 'Automática (por el barrio)', manual: 'Elegida a mano' };

// The comuna of a ficha (lider). Only rendered when its municipio has comunas. Choosing one saves it right away as
// 'manual' (the automatic assignment never overwrites it); "Volver a automática" lets the barrio decide again. The fichas
// make it editable only outside their edit mode, so "Cancelar" never leaves a saved change behind.
export default function ComunaSelector({ liderId, comunas = [], comunaId = null, source = null, editable = false, onSaved }) {
    const [value, setValue] = useState(comunaId);
    const [origin, setOrigin] = useState(source);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => { setValue(comunaId); setOrigin(source); setError(''); }, [liderId, comunaId, source]);

    if (!comunas.length) return null;

    const current = comunas.find(c => c.id === value);
    const groups = [
        ['Comunas', comunas.filter(c => c.tipo === 'comuna')],
        ['Corregimientos', comunas.filter(c => c.tipo === 'corregimiento')],
    ].filter(([, list]) => list.length);

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

    const badge = origin && (
        <span className={`ml-2 px-1.5 py-0.5 rounded text-[10px] font-bold ${origin === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'}`}>
            {origin === 'manual' ? 'manual' : 'automática'}
        </span>
    );

    if (!editable) {
        return (
            <div className="flex items-start justify-between gap-3 py-2 border-b border-[var(--color-line)]" data-testid="comuna-readonly">
                <span className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Comuna</span>
                <span className="text-[14px] text-[var(--color-ink)] text-right">
                    {current?.nombre || 'Sin comuna'}
                    {current && badge}
                </span>
            </div>
        );
    }

    return (
        <div>
            <label htmlFor={`comuna-${liderId}`} className="block text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">
                Comuna / corregimiento {badge}
            </label>
            <div className="flex gap-2">
                <select
                    id={`comuna-${liderId}`}
                    value={value || ''}
                    disabled={saving}
                    onChange={e => save({ comuna_id: e.target.value || null })}
                    className="flex-1 min-w-0 px-3 py-2 border border-[var(--color-line)] rounded-lg text-[14px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:opacity-60"
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
                        className="px-3 py-2 text-[12px] font-bold text-[var(--color-primary)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50 disabled:opacity-60 whitespace-nowrap"
                    >
                        Volver a automática
                    </button>
                )}
            </div>
            <p className="text-[11px] text-[var(--color-ink-faint)] mt-1">
                {saving ? 'Guardando…' : (origin ? SOURCE_LABEL[origin] : 'Se guarda al elegirla.')}
            </p>
            {error && <p role="alert" className="text-[12px] text-red-600 mt-1">{error}</p>}
        </div>
    );
}
