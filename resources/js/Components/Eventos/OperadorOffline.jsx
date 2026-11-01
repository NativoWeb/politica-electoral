import { formatShortDate } from '@/lib/eventos';

export function ConnectionStatus({ online, syncing, pendingCount }) {
    if (online) {
        return (
            <div role="status" data-testid="connection-status" className="flex items-start gap-3 rounded-2xl border-2 border-emerald-300 bg-emerald-50 px-4 py-3">
                <span className="mt-1.5 h-3 w-3 flex-shrink-0 rounded-full bg-emerald-500" aria-hidden="true" />
                <div>
                    <p className="text-[18px] font-extrabold text-emerald-900">Con señal</p>
                    <p className="text-[18px] text-emerald-900">
                        {syncing
                            ? 'Enviando los registros guardados en el teléfono...'
                            : 'Los registros se envían al momento.'}
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div role="status" data-testid="connection-status" className="flex items-start gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-3">
            <span className="mt-1.5 h-3 w-3 flex-shrink-0 rounded-full bg-amber-500 animate-pulse" aria-hidden="true" />
            <div>
                <p className="text-[18px] font-extrabold text-amber-900">Sin señal</p>
                <p className="text-[18px] text-amber-900">
                    Puede seguir registrando: se guardan en el teléfono y se envían solos al volver la señal
                    {pendingCount > 0 ? ` (${pendingCount} por enviar).` : '.'}
                </p>
            </div>
        </div>
    );
}

function personName(change) {
    const p = change.data?.payload ?? {};
    return [p.nombres, p.apellidos].filter(Boolean).join(' ') || 'Registro sin nombre';
}

// What the operator has to do so the phone can send what it kept (see blockedReason in useEventQueue).
const BLOCKED = {
    session: {
        text: 'Su sesión se cerró. Se enviarán cuando inicie sesión de nuevo con su usuario en este teléfono: no se pierden.',
        link: { href: '/login', label: 'Iniciar sesión' },
    },
    owner: {
        text: 'Este navegador tiene abierta la sesión de otra cuenta. Inicie sesión con su cuenta para enviarlos: no se pierden.',
        link: { href: '/login', label: 'Iniciar sesión' },
    },
    signed_out: {
        text: 'Este navegador ya no tiene abierta su sesión. Inicie sesión con su cuenta para enviarlos.',
        link: { href: '/login', label: 'Iniciar sesión' },
    },
    password: {
        text: 'El servidor pidió una acción antes de recibirlos (por ejemplo, cambiar la contraseña).',
        link: { href: '/forzar-cambio-password', label: 'Cambiar contraseña' },
    },
};

export function PendingAttendees({ pending, rejected, blocked, online = true, canCorrect, correctingId, onCorrect, onDiscard }) {
    if (!pending.length && !rejected.length) return null;

    return (
        <section aria-labelledby="pendientes-title" className="space-y-3">
            {pending.length > 0 && (
                <div className="bg-white border-2 border-amber-300 rounded-2xl p-4">
                    <h2 id="pendientes-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">
                        Pendientes de enviar ({pending.length})
                    </h2>
                    {BLOCKED[blocked] ? (
                        <div role="alert" className="mt-1">
                            <p className="text-[18px] font-semibold text-amber-900">{BLOCKED[blocked].text}</p>
                            <a href={BLOCKED[blocked].link.href} className="mt-2 inline-flex items-center min-h-[44px] px-4 py-2 rounded-xl bg-[var(--color-primary)] text-white text-[18px] font-bold">
                                {BLOCKED[blocked].link.label}
                            </a>
                        </div>
                    ) : (
                        <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">
                            {online
                                ? 'Están guardados en este teléfono y se envían solos en cuanto el servidor los reciba. No cierre la sesión mientras tanto.'
                                : 'Están guardados en este teléfono. Se envían solos cuando haya señal; no cierre la sesión mientras tanto.'}
                        </p>
                    )}
                    <ul className="mt-2 divide-y divide-[var(--color-line)]">
                        {pending.map(c => (
                            <li key={c.id} className="py-2.5 text-[18px]">
                                <p className="font-semibold text-[var(--color-ink)] break-words">{personName(c)}</p>
                                <p className="text-amber-800">Guardado {formatShortDate(c.createdAt)}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {rejected.length > 0 && (
                <div className="bg-white border-2 border-red-300 rounded-2xl p-4">
                    <h2 className="text-[18px] font-extrabold text-red-800">No se pudieron guardar ({rejected.length})</h2>
                    <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">
                        {canCorrect
                            ? 'El servidor no los aceptó. Pulse "Corregir" para pasarlos al formulario, arreglar el dato y guardarlos otra vez.'
                            : 'El servidor no los aceptó y el evento ya no recibe registros: solo se pueden descartar.'}
                    </p>
                    <ul className="mt-2 divide-y divide-[var(--color-line)]">
                        {rejected.map(c => (
                            <li key={c.id} className="py-3">
                                <p className="text-[18px] font-bold text-[var(--color-ink)]">{personName(c)}</p>
                                <p className="text-[18px] text-red-800">{c.rejectedReason || 'El servidor no aceptó el registro.'}</p>
                                {c.id === correctingId && (
                                    <p className="mt-1 text-[18px] font-semibold text-[var(--color-primary)]">En el formulario: sale de esta lista al guardarlo.</p>
                                )}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {canCorrect && c.id !== correctingId && <button type="button" onClick={() => onCorrect(c)} className="min-h-[44px] px-4 py-2 rounded-xl bg-[var(--color-primary)] text-white text-[18px] font-bold">
                                        Corregir
                                    </button>}
                                    <button type="button" onClick={() => onDiscard(c)} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-line-strong)] text-[18px] font-bold text-[var(--color-ink)]">
                                        Descartar
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
