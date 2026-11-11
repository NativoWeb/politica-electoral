import { useEffect, useState } from 'react';
import CompromisoEstado from '@/Components/Eventos/CompromisoEstado';
import CompromisoDetalle from '@/Components/Eventos/CompromisoDetalle';
import { failure, formatDate, request } from '@/lib/actas';

// "Compromisos" of a ficha: the compromisos of the actas where this person is a confirmed responsable.
export default function PersonaCompromisos({ liderId, compact = false }) {
    const [state, setState] = useState({ loading: true, error: '', items: [] });
    const [open, setOpen] = useState(null);

    useEffect(() => {
        if (!liderId) return undefined;
        let alive = true;
        request(`/eventos/persona/${liderId}/compromisos`).then(res => {
            if (!alive) return;
            setState(res.ok
                ? { loading: false, error: '', items: res.body?.data ?? [] }
                : { loading: false, error: failure(res, 'No se pudieron cargar los compromisos.'), items: [] });
        });
        return () => { alive = false; };
    }, [liderId]);

    const text = compact ? 'text-[14px]' : 'text-[16px]';

    return (
        <section aria-label="Compromisos de la persona" className="space-y-2 pt-2">
            <h3 className={`${text} font-extrabold text-[var(--color-ink)]`}>Compromisos</h3>
            {state.loading && <p className={`${text} text-[var(--color-ink-faint)]`} role="status">Cargando compromisos…</p>}
            {state.error && <p className={`${text} font-semibold text-red-700`} role="alert">{state.error}</p>}
            {!state.loading && !state.error && state.items.length === 0 && (
                <p className={`${text} text-[var(--color-ink-faint)]`}>No es responsable de ningún compromiso.</p>
            )}
            {state.items.length > 0 && (
                <ul className="space-y-2">
                    {state.items.map(c => (
                        <li key={c.id}>
                            <button type="button" onClick={() => setOpen(c.id)} className="w-full text-left p-3 rounded-xl border border-[var(--color-line)] bg-white hover:bg-blue-50">
                                <CompromisoEstado estado={c.estado} vencido={c.vencido} />
                                <p className={`${text} text-[var(--color-ink)] mt-1 line-clamp-3`}>{c.descripcion}</p>
                                <p className="text-[13px] text-[var(--color-ink-soft)]">
                                    {[c.fecha_limite && `Fecha límite: ${formatDate(c.fecha_limite)}`, c.evento?.nombre].filter(Boolean).join(' · ')}
                                </p>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {open && (
                <CompromisoDetalle
                    compromisoId={open}
                    onClose={() => setOpen(null)}
                    onChanged={d => setState(s => ({ ...s, items: s.items.map(c => (c.id === d.id ? { ...c, estado: d.estado, vencido: d.vencido } : c)) }))}
                />
            )}
        </section>
    );
}
