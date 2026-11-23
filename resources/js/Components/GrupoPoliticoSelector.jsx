import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';

const FALLO = 'No se pudo cambiar el grupo político. Intente de nuevo.';
const TODOS = 'Todos los grupos';

// The groups applied now: the server sends a list, or a single id from a session saved before the list; null = all.
function appliedIds(selected, options) {
    const list = Array.isArray(selected) ? selected : selected ? [selected] : [];
    const known = list.filter(id => options.some(o => o.id === id));
    return known.length === 0 || known.length === options.length ? null : known;
}

export function resumenGrupos(selected, options) {
    const ids = appliedIds(selected, options);
    if (!ids) return TODOS;
    if (ids.length === 1) return options.find(o => o.id === ids[0])?.name ?? TODOS;
    return `${ids.length} grupos`;
}

// Superadmin only: which grupos políticos the whole app shows, one or several marked, or every one. The server keeps
// the choice in the session and checks it; after saving, the current page reloads with the new data (prefetched pages
// are discarded). Unmarking every box leaves Aplicar off: «Todos los grupos» is the way to see them all.
export default function GrupoPoliticoSelector({ vista, className = '' }) {
    const options = vista.options ?? [];
    const applied = appliedIds(vista.selected, options);
    const uid = useId();
    const panelId = `${uid}-panel`;
    const titleId = `${uid}-titulo`;
    const buttonRef = useRef(null);
    const panelRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [marked, setMarked] = useState(() => new Set());
    const [saving, setSaving] = useState(false);

    const allMarked = marked.size === options.length;
    const noneMarked = marked.size === 0;
    const resumen = resumenGrupos(vista.selected, options);
    const nombres = applied ? options.filter(o => applied.includes(o.id)).map(o => o.name).join(', ') : TODOS;

    function openPanel() {
        setMarked(new Set(applied ?? options.map(o => o.id)));
        setOpen(true);
    }

    function close(returnFocus = true) {
        setOpen(false);
        if (returnFocus) buttonRef.current?.focus();
    }

    useEffect(() => {
        if (!open) return undefined;
        panelRef.current?.querySelector('input[type="checkbox"]')?.focus();
        function onPointer(e) {
            if (!panelRef.current?.contains(e.target) && !buttonRef.current?.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('touchstart', onPointer);
        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('touchstart', onPointer);
        };
    }, [open]);

    function onPanelKeyDown(e) {
        if (e.key === 'Escape') {
            e.stopPropagation();
            close();
        }
    }

    function toggle(id) {
        setMarked(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }

    function toggleAll() {
        setMarked(allMarked ? new Set() : new Set(options.map(o => o.id)));
    }

    async function apply() {
        if (noneMarked || saving) return;
        const ids = allMarked ? [] : options.filter(o => marked.has(o.id)).map(o => o.id);
        const current = applied ?? [];
        if (ids.length === current.length && ids.every(id => current.includes(id))) {
            close();
            return;
        }
        setSaving(true);
        try {
            const res = await apiFetch('/grupo-politico/vista', { method: 'POST', body: JSON.stringify({ campaign_ids: ids }) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                showGlobalToast(data.message || FALLO, 'error');
                setSaving(false);
                return;
            }
            close();
            router.flushAll?.();
            router.reload({ onFinish: () => setSaving(false) });
        } catch {
            showGlobalToast('Sin conexión: no se pudo cambiar el grupo político.', 'error');
            setSaving(false);
        }
    }

    return (
        <div className={`relative min-w-0 ${className}`}>
            {/* Same size as the plain chip on desktop; on phones 16 px and 44 px tall so iOS does not zoom in. */}
            <button
                ref={buttonRef}
                type="button"
                onClick={() => (open ? close() : openPanel())}
                disabled={saving}
                aria-busy={saving}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                aria-label={`Grupo político: ${nombres}`}
                title={`Grupo político: ${nombres}`}
                className="inline-flex items-center gap-1 max-w-full min-w-0 min-h-[44px] sm:min-h-0 pl-2.5 pr-1.5 py-1 rounded-full bg-white/10 border border-white/25 text-white hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70 disabled:opacity-60 cursor-pointer"
            >
                <span className="hidden sm:inline text-[12px] text-white/80 flex-shrink-0">Grupo político:</span>
                <span className="font-semibold text-[16px] sm:text-[12px] truncate">{resumen}</span>
                <svg className={`w-3.5 h-3.5 flex-shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                </svg>
            </button>

            {open && (
                <div
                    ref={panelRef}
                    id={panelId}
                    role="dialog"
                    aria-modal="false"
                    aria-labelledby={titleId}
                    onKeyDown={onPanelKeyDown}
                    className="fixed left-4 right-4 top-[52px] sm:absolute sm:left-0 sm:right-auto sm:top-full sm:mt-2 sm:w-[340px] z-50 rounded-xl bg-white text-[var(--color-ink)] shadow-2xl border border-slate-200 overflow-hidden"
                >
                    <p id={titleId} className="px-4 pt-3.5 pb-1 text-[16px] font-bold">Ver grupos políticos</p>
                    <p className="px-4 pb-2 text-[14px] text-slate-600">Lo que marques se aplica a toda la aplicación.</p>
                    <fieldset className="max-h-[55vh] overflow-y-auto border-y border-slate-200 py-1">
                        <legend className="sr-only">Grupos políticos</legend>
                        <label className="flex items-center gap-3 min-h-[48px] px-4 text-[16px] font-semibold cursor-pointer hover:bg-slate-50 border-b border-slate-100">
                            <input
                                type="checkbox"
                                checked={allMarked}
                                ref={el => { if (el) el.indeterminate = !allMarked && !noneMarked; }}
                                onChange={toggleAll}
                                className="w-5 h-5 flex-shrink-0 accent-[var(--color-primary)] cursor-pointer"
                            />
                            <span>{TODOS}</span>
                        </label>
                        {options.map(o => (
                            <label key={o.id} className="flex items-center gap-3 min-h-[48px] px-4 text-[16px] cursor-pointer hover:bg-slate-50">
                                <input
                                    type="checkbox"
                                    checked={marked.has(o.id)}
                                    onChange={() => toggle(o.id)}
                                    className="w-5 h-5 flex-shrink-0 accent-[var(--color-primary)] cursor-pointer"
                                />
                                <span className="min-w-0 break-words">{o.name}</span>
                            </label>
                        ))}
                    </fieldset>
                    <p aria-live="polite" className="px-4 pt-2 text-[14px] text-amber-800 min-h-[1.5rem]">
                        {noneMarked ? 'Marca al menos un grupo o «Todos los grupos».' : ''}
                    </p>
                    <div className="flex justify-end gap-2 px-4 pb-3.5 pt-1">
                        <button
                            type="button"
                            onClick={() => close()}
                            className="min-h-[44px] px-4 rounded-lg text-[16px] font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            onClick={apply}
                            disabled={noneMarked || saving}
                            className="min-h-[44px] px-5 rounded-lg text-[16px] font-bold text-white bg-[var(--color-primary)] hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[var(--color-primary)]"
                        >
                            {saving ? 'Aplicando…' : 'Aplicar'}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
