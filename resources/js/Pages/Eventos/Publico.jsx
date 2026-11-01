import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AttendeeFields, { ConsentBox } from '@/Components/Eventos/AttendeeFields';
import { formatEventRange, placeLine } from '@/lib/eventos';
import useAttendeeForm from '@/lib/useAttendeeForm';

function PublicShell({ title, children }) {
    return (
        <div className="min-h-screen bg-[var(--color-bg)] text-[var(--color-ink)]">
            <Head title={title} />
            <header className="bg-[var(--color-primary)] text-white safe-top">
                <div className="max-w-xl mx-auto px-4 py-3 flex items-center gap-3">
                    <div className="w-7 h-5 rounded-[3px] overflow-hidden flex flex-col flex-shrink-0" aria-hidden="true">
                        <span className="flex-[2] bg-[#FCD116]" />
                        <span className="flex-1 bg-[#003893]" />
                        <span className="flex-1 bg-[#CE1126]" />
                    </div>
                    <p className="text-[18px] font-extrabold tracking-wide">Registro de asistencia</p>
                </div>
            </header>
            <main className="max-w-xl mx-auto px-4 py-5 pb-16 text-[18px]">{children}</main>
        </div>
    );
}

function EventHeader({ evento }) {
    return (
        <section className="bg-white border border-[var(--color-line)] rounded-2xl p-5 mb-5">
            {evento.tipo && <p className="text-[18px] font-bold uppercase tracking-wide text-[var(--color-ink-soft)]">{evento.tipo}</p>}
            <h1 className="text-[24px] leading-tight font-extrabold text-[var(--color-primary)] mt-1">{evento.nombre}</h1>
            <dl className="mt-3 space-y-2 text-[18px]">
                <div>
                    <dt className="sr-only">Fecha y hora</dt>
                    <dd className="font-semibold first-letter:uppercase">{formatEventRange(evento.starts_at, evento.ends_at)}</dd>
                </div>
                <div>
                    <dt className="sr-only">Lugar</dt>
                    <dd className="text-[var(--color-ink-soft)]">{placeLine(evento)}</dd>
                </div>
            </dl>
        </section>
    );
}

export default function Publico({ estado, evento, barrios = [], consent, form_token: formToken, client_uuid: clientUuid, submitted, message }) {
    const [closed, setClosed] = useState(estado !== 'abierto');
    const [thanks, setThanks] = useState(submitted ? (message || '¡Gracias, quedó registrado!') : '');
    const firstFieldRef = useRef(null);
    const thanksRef = useRef(null);
    const noticeRef = useRef(null);
    const honeypot = useRef(null);

    const form = useAttendeeForm({
        url: window.location.pathname,
        consentId: consent?.id,
        initialUuid: clientUuid,
        extraPayload: () => ({ form_token: formToken, website: honeypot.current?.value ?? '' }),
        onSaved: json => setThanks(json.message || '¡Gracias, quedó registrado!'),
        onClosed: () => setClosed(true),
    });

    useEffect(() => {
        if (thanks) thanksRef.current?.focus();
    }, [thanks]);

    useEffect(() => {
        if (form.notice) noticeRef.current?.focus();
    }, [form.notice]);

    const title = evento?.nombre ? `Registro — ${evento.nombre}` : 'Registro de asistencia';

    if (closed) {
        return (
            <PublicShell title={title}>
                {evento && <EventHeader evento={evento} />}
                <div role="status" className="bg-white border-2 border-[var(--color-line-strong)] rounded-2xl p-6 text-center">
                    <h2 className="text-[22px] font-extrabold text-[var(--color-primary)]">Este evento ya no recibe registros</h2>
                    <p className="mt-3 text-[18px] leading-relaxed text-[var(--color-ink-soft)]">
                        El registro por QR está cerrado. Si usted asistió, puede acercarse a los organizadores del evento para que lo registren.
                    </p>
                </div>
            </PublicShell>
        );
    }

    if (thanks) {
        return (
            <PublicShell title={title}>
                <EventHeader evento={evento} />
                <div className="bg-emerald-50 border-2 border-emerald-400 rounded-2xl p-6 text-center">
                    <svg className="w-16 h-16 mx-auto text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <h2 ref={thanksRef} tabIndex={-1} className="mt-3 text-[26px] font-extrabold text-emerald-800 focus:outline-none">{thanks}</h2>
                    <p className="mt-2 text-[18px] text-emerald-900">Gracias por asistir.</p>
                </div>
                <button
                    type="button"
                    onClick={() => { setThanks(''); form.reset(); }}
                    className="mt-5 w-full min-h-[56px] px-4 py-4 rounded-2xl border-2 border-[var(--color-primary)] bg-white text-[19px] font-extrabold text-[var(--color-primary)] active:bg-blue-50"
                >
                    Registrar a otra persona
                </button>
            </PublicShell>
        );
    }

    async function onSubmit(e) {
        e.preventDefault();
        await form.submit();
    }

    return (
        <PublicShell title={title}>
            <EventHeader evento={evento} />
            {evento.descripcion && <p className="mb-5 text-[18px] leading-relaxed text-[var(--color-ink-soft)] whitespace-pre-line">{evento.descripcion}</p>}

            <form onSubmit={onSubmit} noValidate className="bg-white border border-[var(--color-line)] rounded-2xl p-5 space-y-6">
                <div>
                    <h2 className="text-[21px] font-extrabold text-[var(--color-ink)]">Sus datos</h2>
                    <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">Los campos con <span className="text-red-600 font-bold">*</span> son obligatorios.</p>
                </div>

                <AttendeeFields form={form} barrios={barrios} firstFieldRef={firstFieldRef} />

                <div aria-hidden="true" className="absolute -left-[10000px] w-px h-px overflow-hidden">
                    <label htmlFor="website">No llenar este campo</label>
                    <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" ref={honeypot} defaultValue="" />
                </div>

                <ConsentBox
                    consent={consent}
                    checked={form.data.autoriza_datos}
                    onChange={v => form.set('autoriza_datos', v)}
                    error={form.errors.autoriza_datos}
                    expandable
                />

                {form.notice && (
                    <div ref={noticeRef} tabIndex={-1} role="alert" className="px-4 py-3 rounded-xl bg-red-50 border-2 border-red-300 text-[17px] font-semibold text-red-800">
                        {form.notice}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={!form.data.autoriza_datos || form.saving}
                    className="w-full min-h-[60px] px-4 py-4 rounded-2xl bg-[var(--color-primary)] text-white text-[20px] font-extrabold disabled:bg-gray-300 disabled:text-gray-600 active:bg-[var(--color-primary-dark)]"
                >
                    {form.saving ? 'Enviando...' : 'Registrarme'}
                </button>
                {!form.data.autoriza_datos && (
                    <p className="-mt-3 text-center text-[18px] text-[var(--color-ink-soft)]">Para registrarse, marque la casilla de autorización.</p>
                )}
            </form>
        </PublicShell>
    );
}
