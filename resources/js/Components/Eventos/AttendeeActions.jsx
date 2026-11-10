import { useState } from 'react';
import { attendeeActions } from '@/lib/eventos';

const LABELS = {
    ficha: 'Ver ficha',
    edit: 'Editar datos',
    approve: 'Pasar a Votantes',
    discard: 'Descartar',
    recover: 'Recuperar',
    unlink: 'Desvincular',
    delete: 'Eliminar',
};

const STYLES = {
    ficha: 'border-[var(--color-primary)] bg-white text-[var(--color-primary)]',
    approve: 'border-emerald-600 bg-emerald-600 text-white',
    delete: 'border-red-300 bg-white text-red-700',
};

// "Acciones" button that unfolds the attendee's actions under the row (large targets, comfortable on a phone).
export default function AttendeeActions({ attendee, editable, fichaVisible, disabled, onAction }) {
    const [open, setOpen] = useState(false);
    const actions = attendeeActions(attendee, { editable, fichaVisible });
    if (!actions.length) return null;
    const name = `${attendee.nombres} ${attendee.apellidos}`;
    const panelId = `acciones-${attendee.id}`;

    return (
        <div className="mt-2 sm:mt-0 flex flex-col items-start sm:items-end">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                aria-label={`Acciones de ${name}`}
                onClick={() => setOpen(o => !o)}
                className="min-h-[40px] px-3 py-1.5 rounded-lg border border-[var(--color-line-strong)] text-[14px] font-semibold text-[var(--color-ink-soft)]"
            >
                Acciones {open ? '▴' : '▾'}
            </button>
            {open && (
                <div id={panelId} role="group" aria-label={`Acciones de ${name}`} className="mt-2 flex flex-wrap gap-2 sm:justify-end">
                    {actions.map(key => (
                        <button
                            key={key}
                            type="button"
                            disabled={disabled}
                            onClick={() => { setOpen(false); onAction(key, attendee); }}
                            className={`min-h-[44px] px-3.5 py-2 rounded-xl border-2 text-[14px] font-bold disabled:opacity-50 ${STYLES[key] ?? 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink)]'}`}
                        >
                            {LABELS[key]}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
