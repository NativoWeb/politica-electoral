import { useEffect, useState } from 'react';
import CampaignChip from '@/Components/CampaignChip';
import { StatusBadge } from '@/Components/Eventos/EventoBadges';
import PersonaCompromisos from '@/Components/Eventos/PersonaCompromisos';
import { formatEventDate } from '@/lib/eventos';
import { participationLine, personEventsSummary } from '@/lib/eventosIntegracion';

// Events a ficha (lider) attended or organized, of the campaigns the user can see. Loaded when shown.
export default function PersonaEventos({ liderId, compact = false }) {
    const [state, setState] = useState({ loading: true, error: '', data: null });

    useEffect(() => {
        if (!liderId) return undefined;
        let alive = true;
        setState({ loading: true, error: '', data: null });
        fetch(`/eventos/persona/${liderId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async r => {
                if (!r.ok) {
                    const reason = r.status === 403 ? 'No tiene permiso para ver los eventos de esta persona.' : 'No se pudieron cargar los eventos. Intente de nuevo.';
                    throw new Error(reason);
                }
                return r.json();
            })
            .then(body => { if (alive) setState({ loading: false, error: '', data: body.data }); })
            .catch(e => {
                if (!alive) return;
                const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
                setState({ loading: false, error: offline ? 'Sin conexión: los eventos se ven cuando vuelva la señal.' : e.message, data: null });
            });

        return () => { alive = false; };
    }, [liderId]);

    const text = compact ? 'text-[14px]' : 'text-[16px]';

    if (state.loading) return <p className={`${text} text-[var(--color-ink-faint)]`} role="status">Cargando eventos…</p>;
    if (state.error) return <p className={`${text} font-semibold text-red-700`} role="alert">{state.error}</p>;

    const eventos = state.data?.eventos ?? [];

    return (
        <div className="space-y-3">
            <p className={`${text} font-semibold text-[var(--color-ink)]`}>{personEventsSummary(state.data)}</p>
            {eventos.length > 0 && (
                <ul className="space-y-2" aria-label="Eventos de la persona">
                    {eventos.map(e => (
                        <li key={`${e.id}-${e.participacion}`} className="p-3 rounded-xl border border-[var(--color-line)] bg-white">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={e.status} />
                                {e.tipo && <span className="text-[13px] font-semibold text-[var(--color-ink-soft)]">{e.tipo}</span>}
                                <CampaignChip campaignId={e.campaign_id} />
                            </div>
                            <p className={`mt-1 ${text} font-extrabold text-[var(--color-ink)] leading-snug`}>{e.nombre}</p>
                            <p className="text-[14px] text-[var(--color-ink)] first-letter:uppercase">{formatEventDate(e.starts_at)}</p>
                            <p className="text-[14px] text-[var(--color-ink-soft)]">
                                {[e.municipio, e.barrios?.length ? `Barrios: ${e.barrios.join(', ')}` : null].filter(Boolean).join(' — ')}
                            </p>
                            <p className="text-[14px] font-semibold text-[var(--color-primary)] mt-0.5">{participationLine(e)}</p>
                            <a href={e.url} className="inline-flex items-center min-h-[44px] mt-1 text-[15px] font-bold text-[var(--color-primary)] underline">
                                Ver el evento
                            </a>
                        </li>
                    ))}
                </ul>
            )}
            <PersonaCompromisos liderId={liderId} compact={compact} />
        </div>
    );
}
