import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { failure, formatDate, request } from '@/lib/actas';

// "Actas de reunión" of the event's detail: the list (view.eventos) and the button to write or load one (editable).
export default function ActasEvento({ eventId, editable }) {
    const [state, setState] = useState({ loading: true, error: '', actas: [] });

    useEffect(() => {
        let alive = true;
        request(`/eventos/${eventId}/actas`).then(res => {
            if (!alive) return;
            setState(res.ok
                ? { loading: false, error: '', actas: res.body?.data ?? [] }
                : { loading: false, error: failure(res, 'No se pudieron cargar las actas.'), actas: [] });
        });
        return () => { alive = false; };
    }, [eventId]);

    return (
        <section aria-labelledby="actas-title" className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="actas-title" className="text-[16px] font-extrabold text-[var(--color-ink)]">Actas de reunión</h2>
                <div className="flex flex-wrap gap-2">
                    <Link href="/eventos/compromisos" className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-line-strong)] text-[15px] font-bold text-[var(--color-ink)] flex items-center">
                        Ver compromisos
                    </Link>
                    {editable && (
                        <Link href={`/eventos/${eventId}/actas/nueva`} className="min-h-[44px] px-4 py-2 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold flex items-center">
                            Acta de reunión
                        </Link>
                    )}
                </div>
            </div>
            {state.loading && <p className="text-[15px] text-[var(--color-ink-faint)]" role="status">Cargando actas…</p>}
            {state.error && <p className="text-[15px] font-semibold text-red-700" role="alert">{state.error}</p>}
            {!state.loading && !state.error && state.actas.length === 0 && (
                <p className="text-[15px] text-[var(--color-ink-faint)]">Este evento todavía no tiene actas.</p>
            )}
            {state.actas.length > 0 && (
                <ul className="divide-y divide-[var(--color-line)]" aria-label="Actas del evento">
                    {state.actas.map(a => (
                        <li key={a.id} className="py-2">
                            <Link href={`/eventos/${eventId}/actas/${a.id}`} className="block min-h-[44px] py-1 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]">
                                <span className="block text-[15px] font-bold text-[var(--color-primary)] underline">{a.titulo}</span>
                                <span className="block text-[14px] text-[var(--color-ink-soft)]">
                                    {[a.estado === 'confirmada' ? 'Confirmada' : 'Borrador', formatDate(a.fecha),`${a.compromisos} compromisos`, a.abiertos ? `${a.abiertos} sin cumplir` : null, a.created_by && `por ${a.created_by}`].filter(Boolean).join(' · ')}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
