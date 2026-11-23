import { useRef } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';

// What a correction of a confirmed acta will do, asked before saving it.
export default function ActaCorreccionResumen({ lines, busy, onConfirm, onCancel }) {
    const ref = useRef(null);
    useDialogFocus(ref);

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={busy ? undefined : onCancel} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
                <div ref={ref} role="alertdialog" aria-modal="true" aria-labelledby="correccion-title" aria-describedby="correccion-lines" onKeyDown={e => { if (e.key === 'Escape' && !busy) onCancel(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[480px] max-h-[90vh] flex flex-col">
                    <div className="px-6 pt-6 pb-3">
                        <h3 id="correccion-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">¿Guardar la corrección del acta?</h3>
                    </div>
                    <ul id="correccion-lines" className="flex-1 overflow-y-auto px-6 space-y-2 list-disc pl-10">
                        {lines.map(line => <li key={line} className="text-[16px] text-[var(--color-ink)] leading-relaxed">{line}</li>)}
                    </ul>
                    <div className="px-6 py-5 flex gap-3">
                        <button type="button" autoFocus disabled={busy} onClick={onCancel} className="flex-1 min-h-[48px] px-4 border-2 border-gray-200 text-[16px] font-bold text-[var(--color-ink-soft)] rounded-xl disabled:opacity-50">Volver al acta</button>
                        <button type="button" disabled={busy} onClick={onConfirm} className="flex-1 min-h-[48px] px-4 bg-emerald-600 text-white text-[16px] font-bold rounded-xl disabled:opacity-50">{busy ? 'Guardando…' : 'Sí, guardar corrección'}</button>
                    </div>
                </div>
            </div>
        </>
    );
}
