import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useId, useRef, useState } from 'react';

const DEFAULT_PREFIX = '+57';

function useCountdown(deadline) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!deadline) return undefined;
        setNow(Date.now());
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [deadline]);

    if (!deadline) return 0;
    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

function formatRemaining(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function formatDate(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function buildWhatsAppUrl(agentNumber, code) {
    const digits = (agentNumber || '').replace(/\D/g, '');
    if (!digits) return null;
    return `https://wa.me/${digits}?text=${encodeURIComponent(`VINCULAR ${code}`)}`;
}

function requestErrorMessage(error) {
    const status = error?.response?.status;
    if (!error?.response) return 'No hay conexión con el servidor. Revisa tu internet e inténtalo de nuevo.';
    if (status === 419) return 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.';
    if (status === 429) return 'Demasiados intentos. Espera un minuto antes de volver a intentarlo.';
    return 'No se pudo completar la acción. Inténtalo de nuevo.';
}

const btnSolid = 'inline-flex items-center justify-center gap-2 min-h-[48px] px-5 rounded-xl text-white text-[15px] font-bold transition-colors focus:outline-none focus-visible:ring-4 focus-visible:ring-[var(--color-primary-light)]/30 disabled:opacity-50 disabled:cursor-not-allowed';
const btnPrimary = `${btnSolid} bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)]`;
const btnWhatsApp = `${btnSolid} bg-[#128C4A] hover:bg-[#0f7a40]`;
const btnDanger = `${btnSolid} bg-[var(--color-danger)] hover:bg-red-700`;
const btnOutline = 'inline-flex items-center justify-center gap-2 min-h-[48px] px-5 rounded-xl border-2 border-[var(--color-line-strong)] bg-white text-[15px] font-bold transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-[var(--color-primary-light)]/30 disabled:opacity-50 disabled:cursor-not-allowed';
const btnSecondary = `${btnOutline} text-[var(--color-ink)]`;

function WhatsAppGlyph({ className = 'w-5 h-5' }) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
            <path d="M12 0a12 12 0 0 0-10.4 18L0 24l6.2-1.6A12 12 0 1 0 12 0Zm0 21.8a9.8 9.8 0 0 1-5-1.4l-.4-.2-3.7 1 1-3.7-.2-.4A9.8 9.8 0 1 1 12 21.8Zm5.4-7.3c-.3-.2-1.7-.9-2-1s-.5-.1-.7.2-.7 1-.9 1.2-.3.2-.6.1c-.3-.2-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5s.1-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.5 1.1 2.9 1.2 3.1 2.1 3.2 5.1 4.5c.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4s.3-1.3.2-1.4-.2-.2-.5-.4Z" />
        </svg>
    );
}

function LinkedState({ whatsapp }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const confirmRef = useRef(null);
    const triggerRef = useRef(null);

    useEffect(() => {
        if (confirming) confirmRef.current?.focus();
    }, [confirming]);

    async function unlink() {
        setBusy(true);
        setError(null);
        try {
            await axios.delete('/perfil/whatsapp', { headers: { Accept: 'application/json' } });
            router.reload({ only: ['auth'] });
        } catch (e) {
            setError(requestErrorMessage(e));
            setBusy(false);
        }
    }

    function cancel() {
        setConfirming(false);
        setError(null);
        requestAnimationFrame(() => triggerRef.current?.focus());
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-4 p-4 rounded-xl bg-[var(--color-good-light)]/60 border border-emerald-200">
                <span className="w-12 h-12 rounded-full bg-emerald-600 text-white flex items-center justify-center flex-shrink-0">
                    <WhatsAppGlyph className="w-6 h-6" />
                </span>
                <div className="min-w-0">
                    <p className="text-[16px] font-bold text-emerald-900">
                        Vinculado: <span className="font-mono tracking-wider">•••• {whatsapp.phoneLast4}</span>
                    </p>
                    {whatsapp.verifiedAt && (
                        <p className="text-[13px] text-emerald-800">desde el {formatDate(whatsapp.verifiedAt)}</p>
                    )}
                </div>
            </div>

            <p className="text-[14px] text-[var(--color-ink-soft)]">
                Puedes escribirle al asistente desde ese número y responderá con tus permisos y tu territorio.
            </p>

            {!confirming ? (
                <button ref={triggerRef} type="button" onClick={() => setConfirming(true)} className={`${btnOutline} text-[var(--color-danger)] w-full sm:w-auto`}>
                    Desvincular
                </button>
            ) : (
                <div role="alertdialog" aria-labelledby="wa-unlink-title" aria-describedby="wa-unlink-desc" className="p-4 rounded-xl border-2 border-red-200 bg-red-50 space-y-3">
                    <p id="wa-unlink-title" className="text-[15px] font-bold text-red-900">¿Desvincular este número?</p>
                    <p id="wa-unlink-desc" className="text-[14px] text-red-800">
                        El asistente dejará de reconocerte desde •••• {whatsapp.phoneLast4} y se cerrarán sus sesiones activas.
                    </p>
                    <div className="flex flex-col-reverse sm:flex-row gap-2">
                        <button type="button" onClick={cancel} disabled={busy} className={btnSecondary}>Cancelar</button>
                        <button ref={confirmRef} type="button" onClick={unlink} disabled={busy} className={btnDanger}>
                            {busy ? 'Desvinculando…' : 'Sí, desvincular'}
                        </button>
                    </div>
                </div>
            )}

            {error && <p role="alert" className="text-[14px] font-semibold text-[var(--color-danger)]">{error}</p>}
        </div>
    );
}

function CodeState({ pending, agentNumber, onRegenerate, onReset, regenerating }) {
    const remaining = useCountdown(pending.deadline);
    const expired = remaining === 0;
    const [checking, setChecking] = useState(false);
    const [notYet, setNotYet] = useState(false);
    const waUrl = buildWhatsAppUrl(agentNumber, pending.code);

    function checkLinked() {
        setChecking(true);
        setNotYet(false);
        router.reload({
            only: ['auth'],
            onSuccess: (page) => {
                if (!page.props.auth?.user?.whatsapp?.linked) setNotYet(true);
            },
            onFinish: () => setChecking(false),
        });
    }

    return (
        <div className="space-y-5">
            <div aria-live="polite" aria-atomic="true">
                {expired ? (
                    <div className="p-5 rounded-xl border-2 border-dashed border-[var(--color-line-strong)] text-center">
                        <p className="text-[16px] font-bold text-[var(--color-ink)]">El código venció</p>
                        <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Genera uno nuevo para {pending.phone}.</p>
                    </div>
                ) : (
                    <div className="p-5 rounded-xl bg-[var(--color-primary)] text-white text-center">
                        <p className="text-[12px] font-bold uppercase tracking-[0.2em] text-white/60">Tu código</p>
                        <p className="mt-2 font-mono font-bold text-[44px] sm:text-[52px] leading-none tracking-[0.18em] tabular-nums" aria-label={`Código ${pending.code.split('').join(' ')}`}>
                            {pending.code.slice(0, 3)}<span className="inline-block w-3" aria-hidden="true" />{pending.code.slice(3)}
                        </p>
                        <p className="mt-3 text-[14px] text-white/80">
                            Vence en <span className="font-bold text-[var(--color-accent)] tabular-nums" aria-hidden="true">{formatRemaining(remaining)}</span>
                            <span className="sr-only">{Math.ceil(remaining / 60)} minutos</span>
                        </p>
                    </div>
                )}
            </div>

            {!expired && (
                <ol className="space-y-2 text-[15px] text-[var(--color-ink)]">
                    <li className="flex gap-3">
                        <span className="w-6 h-6 rounded-full bg-[var(--color-accent)] text-[var(--color-primary-dark)] text-[13px] font-bold flex items-center justify-center flex-shrink-0">1</span>
                        <span>
                            Envía este mensaje al asistente <strong>desde ESE número</strong> ({pending.phone}):{' '}
                            <code className="px-1.5 py-0.5 rounded bg-gray-100 font-mono text-[14px] whitespace-nowrap">VINCULAR {pending.code}</code>
                        </span>
                    </li>
                    <li className="flex gap-3">
                        <span className="w-6 h-6 rounded-full bg-[var(--color-accent)] text-[var(--color-primary-dark)] text-[13px] font-bold flex items-center justify-center flex-shrink-0">2</span>
                        <span>Cuando el asistente te confirme, toca «Ya lo envié».</span>
                    </li>
                </ol>
            )}

            <div className="flex flex-col sm:flex-row gap-2">
                {expired ? (
                    <button type="button" onClick={onRegenerate} disabled={regenerating} className={btnPrimary}>
                        {regenerating ? 'Generando…' : 'Generar nuevo código'}
                    </button>
                ) : (
                    <>
                        {waUrl ? (
                            <a href={waUrl} target="_blank" rel="noopener noreferrer" className={btnWhatsApp}>
                                <WhatsAppGlyph />
                                Abrir WhatsApp
                            </a>
                        ) : (
                            <p className="text-[14px] text-[var(--color-ink-soft)]">
                                El número del asistente no está configurado. Pide al administrador que lo configure.
                            </p>
                        )}
                        <button type="button" onClick={checkLinked} disabled={checking} className={btnSecondary}>
                            {checking ? 'Comprobando…' : 'Ya lo envié'}
                        </button>
                    </>
                )}
                <button type="button" onClick={onReset} className="min-h-[48px] px-3 text-[14px] font-semibold text-[var(--color-primary-light)] hover:underline sm:ml-auto">
                    Usar otro número
                </button>
            </div>

            {notYet && (
                <p role="status" className="text-[14px] font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3">
                    Aún no recibimos tu mensaje. Revisa que lo hayas enviado desde {pending.phone} con el código exacto y vuelve a intentarlo.
                </p>
            )}
        </div>
    );
}

export default function WhatsAppLink({ whatsapp, agentNumber }) {
    const [prefix, setPrefix] = useState(DEFAULT_PREFIX);
    const [number, setNumber] = useState('');
    const [fieldError, setFieldError] = useState(null);
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [pending, setPending] = useState(null);
    const numberRef = useRef(null);
    const ids = useId();

    const linked = Boolean(whatsapp?.linked);

    useEffect(() => {
        if (linked) setPending(null);
    }, [linked]);

    async function generate(phone) {
        setBusy(true);
        setFieldError(null);
        setFormError(null);
        try {
            const { data, headers } = await axios.post(
                '/perfil/whatsapp/codigo',
                { phone },
                { headers: { Accept: 'application/json' } },
            );
            const serverNow = Date.parse(headers?.date ?? '');
            const expiresAt = Date.parse(data.expires_at);
            const ttl = Number.isNaN(serverNow) ? expiresAt - Date.now() : expiresAt - serverNow;
            setPending({ code: data.code, phone, deadline: Date.now() + Math.max(0, ttl) });
        } catch (e) {
            const phoneErrors = e?.response?.status === 422 ? e.response.data?.errors?.phone : null;
            if (phoneErrors?.length) {
                setPending(null);
                setFieldError(phoneErrors[0]);
                requestAnimationFrame(() => numberRef.current?.focus());
            } else {
                setFormError(requestErrorMessage(e));
            }
        } finally {
            setBusy(false);
        }
    }

    function handleSubmit(e) {
        e.preventDefault();
        const cleanPrefix = `+${prefix.replace(/\D/g, '')}`;
        const digits = number.replace(/\D/g, '');
        if (!digits) {
            setFieldError('Escribe tu número de WhatsApp.');
            numberRef.current?.focus();
            return;
        }
        generate(`${cleanPrefix}${digits}`);
    }

    function reset() {
        setPending(null);
        setFormError(null);
        requestAnimationFrame(() => numberRef.current?.focus());
    }

    if (linked) return <LinkedState whatsapp={whatsapp} />;

    if (pending) {
        return (
            <>
                <CodeState
                    pending={pending}
                    agentNumber={agentNumber}
                    onRegenerate={() => generate(pending.phone)}
                    onReset={reset}
                    regenerating={busy}
                />
                {formError && <p role="alert" className="mt-3 text-[14px] font-semibold text-[var(--color-danger)]">{formError}</p>}
            </>
        );
    }

    const errorId = `${ids}-error`;
    const hintId = `${ids}-hint`;

    return (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
            <p className="text-[15px] text-[var(--color-ink-soft)]">
                Vincula tu número para consultar y registrar información hablando con el asistente por WhatsApp.
            </p>

            <div>
                <label htmlFor={`${ids}-number`} className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1.5">
                    Tu número de WhatsApp
                </label>
                <div className={`flex rounded-xl border-2 bg-white overflow-hidden focus-within:border-[var(--color-primary)] ${fieldError ? 'border-[var(--color-danger)]' : 'border-[var(--color-line-strong)]'}`}>
                    <label htmlFor={`${ids}-prefix`} className="sr-only">Indicativo del país</label>
                    <input
                        id={`${ids}-prefix`}
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel-country-code"
                        value={prefix}
                        onChange={(e) => setPrefix(e.target.value.replace(/[^\d+]/g, '').slice(0, 4))}
                        className="w-[4.5rem] px-3 py-3 text-[16px] font-semibold text-[var(--color-ink)] bg-gray-50 border-r-2 border-[var(--color-line)] focus:outline-none focus:bg-white"
                    />
                    <input
                        id={`${ids}-number`}
                        ref={numberRef}
                        type="tel"
                        inputMode="numeric"
                        autoComplete="tel-national"
                        placeholder="300 123 4567"
                        value={number}
                        onChange={(e) => { setNumber(e.target.value); if (fieldError) setFieldError(null); }}
                        aria-invalid={fieldError ? 'true' : 'false'}
                        aria-describedby={fieldError ? `${errorId} ${hintId}` : hintId}
                        className="flex-1 min-w-0 px-3 py-3 text-[16px] text-[var(--color-ink)] placeholder:text-gray-400 focus:outline-none"
                    />
                </div>
                <p id={hintId} className="text-[12px] text-[var(--color-ink-faint)] mt-1.5">
                    Cambia el indicativo si tu número no es de Colombia.
                </p>
                {fieldError && (
                    <p id={errorId} role="alert" className="text-[13px] font-semibold text-[var(--color-danger)] mt-1">{fieldError}</p>
                )}
            </div>

            <button type="submit" disabled={busy} className={`${btnPrimary} w-full sm:w-auto`}>
                {busy ? 'Generando…' : 'Generar código'}
            </button>

            {formError && <p role="alert" className="text-[14px] font-semibold text-[var(--color-danger)]">{formError}</p>}
        </form>
    );
}
