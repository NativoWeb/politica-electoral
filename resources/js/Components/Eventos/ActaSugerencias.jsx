import { useRef, useState } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';

// "¿Es esta persona?": one question per suggestion (same phone and a word of the name). Yes links the row to the
// ficha when the acta is confirmed; No leaves it as a new person (participant) or as text (responsable).
// allowSkip (saving a borrador): unanswered questions stay pending; otherwise (confirming) all must be answered.
export default function ActaSugerencias({ items, onDone, onCancel, allowSkip = false, doneLabel = 'Guardar acta' }) {
    const [answers, setAnswers] = useState({});
    const ref = useRef(null);
    useDialogFocus(ref);
    const answered = items.every((_, i) => answers[i] !== undefined);
    const answer = (i, yes) => setAnswers(prev => ({ ...prev, [i]: yes }));

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onCancel} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
                <div ref={ref} role="dialog" aria-modal="true" aria-labelledby="sugerencias-title" onKeyDown={e => { if (e.key === 'Escape') onCancel(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[560px] max-h-[90vh] flex flex-col">
                    <div className="px-5 pt-5 pb-3 border-b border-[var(--color-line)]">
                        <h3 id="sugerencias-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">¿Son estas personas?</h3>
                        <p className="text-[15px] text-[var(--color-ink-soft)] mt-1">Encontramos fichas en Votantes con el mismo teléfono y parte del nombre. Confirme cada una.{allowSkip ? ' Puede responder después: se le preguntará antes de confirmar el acta.' : ''}</p>
                    </div>
                    <ul className="flex-1 overflow-y-auto px-5 py-3 space-y-3" aria-label="Personas para confirmar">
                        {items.map((s, i) => (
                            <li key={`${s.kind}-${s.index ?? s.path.join('-')}`} className="border border-[var(--color-line)] rounded-xl p-3">
                                <p className="text-[13px] font-bold text-[var(--color-ink-soft)]">{s.kind === 'participante' ? 'Participante' : 'Responsable de un compromiso'}: {s.nombre}</p>
                                <p className="text-[16px] font-extrabold text-[var(--color-ink)] mt-1">{s.lider?.nombre}</p>
                                <p className="text-[14px] text-[var(--color-ink-soft)]">{[s.lider?.cargo, s.lider?.municipio].filter(Boolean).join(' · ')}</p>
                                <div className="flex gap-2 mt-2" role="group" aria-label={`¿${s.nombre} es ${s.lider?.nombre}?`}>
                                    <button type="button" aria-pressed={answers[i] === true} onClick={() => answer(i, true)} className={`flex-1 min-h-[44px] rounded-xl border-2 text-[15px] font-bold ${answers[i] === true ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-emerald-600 text-emerald-700'}`}>Sí, es esta persona</button>
                                    <button type="button" aria-pressed={answers[i] === false} onClick={() => answer(i, false)} className={`flex-1 min-h-[44px] rounded-xl border-2 text-[15px] font-bold ${answers[i] === false ? 'bg-gray-700 border-gray-700 text-white' : 'border-gray-300 text-[var(--color-ink-soft)]'}`}>No es</button>
                                </div>
                            </li>
                        ))}
                    </ul>
                    <div className="px-5 py-4 border-t border-[var(--color-line)] flex gap-3">
                        <button type="button" onClick={onCancel} className="flex-1 min-h-[48px] border-2 border-gray-200 rounded-xl text-[15px] font-bold text-[var(--color-ink-soft)]">Volver al acta</button>
                        <button type="button" disabled={!allowSkip && !answered} onClick={() => onDone(items.map((s, i) => ({ ...s, yes: answers[i] })))} className="flex-1 min-h-[48px] bg-emerald-600 text-white rounded-xl text-[15px] font-bold disabled:opacity-50">
                            {doneLabel}
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}
