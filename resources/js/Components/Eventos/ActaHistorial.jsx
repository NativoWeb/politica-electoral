import { formatDateTime } from '@/lib/actas';

// «Historial de cambios» of a confirmed acta: its confirmation and each correction, newest first.
export default function ActaHistorial({ items }) {
    if (items.length === 0) {
        return <p className="text-[16px] text-[var(--color-ink-faint)]">Todavía no hay cambios registrados.</p>;
    }
    return (
        <ol className="space-y-2" aria-label="Historial de cambios">
            {items.map(h => (
                <li key={h.key} className="border border-[var(--color-line)] rounded-xl p-3">
                    <p className="text-[16px] font-extrabold text-[var(--color-ink)]">{h.titulo}</p>
                    <p className="text-[16px] text-[var(--color-ink-soft)]">
                        {[formatDateTime(h.fecha), h.usuario && `por ${h.usuario}`].filter(Boolean).join(' ')}
                    </p>
                    {h.resumen && <p className="text-[16px] text-[var(--color-ink)] mt-1">{h.resumen}</p>}
                </li>
            ))}
        </ol>
    );
}
