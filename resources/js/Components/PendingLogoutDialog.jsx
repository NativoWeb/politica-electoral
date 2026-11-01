import { useRef } from 'react';
import { createRoot } from 'react-dom/client';
import useDialogFocus from '@/lib/useDialogFocus';

export function pendingLogoutMessage(count) {
    if (count === null) {
        return 'No se pudo comprobar si este teléfono tiene registros sin enviar. Si cierra sesión ahora y los tiene, se perderán.';
    }
    const what = count === 1 ? '1 registro sin enviar' : `${count} registros sin enviar`;

    return `Tiene ${what}. Si cierra sesión ahora se perderán.`;
}

// Staying is the default: it has the focus, and Escape or a tap outside also stay.
export default function PendingLogoutDialog({ count, onStay, onLogout }) {
    const dialogRef = useRef(null);
    useDialogFocus(dialogRef);

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[70]" onClick={onStay} />
            <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 pointer-events-none">
                <div
                    ref={dialogRef}
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="pending-logout-title"
                    aria-describedby="pending-logout-message"
                    onKeyDown={e => { if (e.key === 'Escape') onStay(); }}
                    className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[420px] p-6"
                >
                    <h2 id="pending-logout-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">Hay registros sin enviar</h2>
                    <p id="pending-logout-message" className="mt-2 text-[18px] text-[var(--color-ink)] leading-relaxed">{pendingLogoutMessage(count)}</p>
                    <p className="mt-2 text-[16px] text-[var(--color-ink-soft)]">Si se queda, se envían solos cuando haya señal.</p>
                    <div className="mt-5 flex flex-col gap-3">
                        <button type="button" autoFocus onClick={onStay} className="w-full min-h-[52px] px-4 py-3 rounded-xl bg-[var(--color-primary)] text-white text-[18px] font-extrabold">
                            Quedarme y enviar después
                        </button>
                        <button type="button" onClick={onLogout} className="w-full min-h-[52px] px-4 py-3 rounded-xl border-2 border-red-300 text-red-700 text-[18px] font-bold active:bg-red-50">
                            Cerrar sesión de todos modos
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}

// Resolves true only when the user chooses to log out and lose the pending changes.
export function askLogoutWithPending(count) {
    return new Promise((resolve) => {
        const host = document.createElement('div');
        document.body.appendChild(host);
        const root = createRoot(host);
        const close = (logout) => {
            root.unmount();
            host.remove();
            resolve(logout);
        };
        root.render(<PendingLogoutDialog count={count} onStay={() => close(false)} onLogout={() => close(true)} />);
    });
}
