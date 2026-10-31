import { useRef } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';

export default function ConfirmDialog({ title, message, confirmLabel, danger, onConfirm, onCancel }) {
    const dialogRef = useRef(null);
    useDialogFocus(dialogRef);

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onCancel} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
                <div ref={dialogRef} role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message" onKeyDown={e => { if (e.key === 'Escape') onCancel(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[380px] overflow-hidden">
                    <div className="px-6 pt-6 pb-4 text-center">
                        <h3 id="confirm-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">{title}</h3>
                        <p id="confirm-message" className="text-[15px] text-[var(--color-ink-soft)] mt-2 leading-relaxed">{message}</p>
                    </div>
                    <div className="px-6 pb-6 flex gap-3">
                        <button type="button" autoFocus onClick={onCancel} className="flex-1 px-4 py-3 border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)] rounded-xl active:bg-gray-100">Cancelar</button>
                        <button type="button" onClick={onConfirm} className={`flex-1 px-4 py-3 text-white text-[15px] font-bold rounded-xl ${danger ? 'bg-amber-600 active:bg-amber-700' : 'bg-emerald-600 active:bg-emerald-700'}`}>{confirmLabel}</button>
                    </div>
                </div>
            </div>
        </>
    );
}
