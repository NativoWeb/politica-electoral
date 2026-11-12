import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { failure, formatDate, request } from '@/lib/actas';

// "Actas" tab of an organization: its actas de reunión and the buttons to write one or load it from Word
// (both open the acta page, where a .docx fills the form).
export default function ActasOrganizacion({ orgId, editable }) {
    const [state, setState] = useState({ loading: true, error: '', actas: [] });

    useEffect(() => {
        let alive = true;
        request(`/empresarios/${orgId}/actas`).then(res => {
            if (!alive) return;
            setState(res.ok
                ? { loading: false, error: '', actas: res.body?.data ?? [] }
                : { loading: false, error: failure(res, 'No se pudieron cargar las actas.'), actas: [] });
        });
        return () => { alive = false; };
    }, [orgId]);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                {editable && (
                    <>
                        <Link href={`/empresarios/${orgId}/actas/nueva`} className="min-h-[48px] px-4 py-2 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold flex items-center">Nueva acta</Link>
                        <Link href={`/empresarios/${orgId}/actas/nueva`} className="min-h-[48px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[15px] font-bold flex items-center">Cargar acta (Word)</Link>
                    </>
                )}
                <Link href="/eventos/compromisos?origen=organizacion" className="min-h-[48px] px-4 py-2 rounded-xl border-2 border-[var(--color-line-strong)] text-[15px] font-bold text-[var(--color-ink)] flex items-center">Ver compromisos</Link>
            </div>
            {state.loading && <p className="text-[15px] text-[var(--color-ink-faint)]" role="status">Cargando actas…</p>}
            {state.error && <p className="text-[15px] font-semibold text-red-700" role="alert">{state.error}</p>}
            {!state.loading && !state.error && state.actas.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no tiene actas.</p>}
            {state.actas.length > 0 && (
                <ul className="divide-y divide-[var(--color-line)]" aria-label="Actas de la organización">
                    {state.actas.map(a => (
                        <li key={a.id} className="py-2">
                            <Link href={`/empresarios/${orgId}/actas/${a.id}`} className="block min-h-[44px] py-1">
                                <span className="block text-[15px] font-bold text-[var(--color-primary)] underline">{a.titulo}</span>
                                <span className="block text-[14px] text-[var(--color-ink-soft)]">
                                    {[a.estado === 'confirmada' ? 'Confirmada' : 'Borrador', formatDate(a.fecha), `${a.compromisos} compromisos`, a.abiertos ? `${a.abiertos} sin cumplir` : null].filter(Boolean).join(' · ')}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
