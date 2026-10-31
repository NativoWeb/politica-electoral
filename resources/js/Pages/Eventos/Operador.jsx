import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AttendeeFields, { ConsentBox } from '@/Components/Eventos/AttendeeFields';
import { StatusBadge } from '@/Components/Eventos/EventoBadges';
import { formatEventRange, formatShortDate, placeLine } from '@/lib/eventos';
import useAttendeeForm from '@/lib/useAttendeeForm';

export default function Operador({ evento, consent, registrados, mis_registros: misRegistros = [], client_uuid: clientUuid }) {
    const { props } = usePage();
    const perms = props.auth?.user?.permissions || [];
    const canSeeDetail = perms.includes('*') || perms.includes('view.eventos') || perms.includes('manage.eventos');
    const [accepts, setAccepts] = useState(!!evento.accepts_registrations);
    const [total, setTotal] = useState(registrados ?? 0);
    const [sessionCount, setSessionCount] = useState(0);
    const [recent, setRecent] = useState(misRegistros);
    const [success, setSuccess] = useState(null);
    const firstFieldRef = useRef(null);
    const noticeRef = useRef(null);

    const form = useAttendeeForm({
        url: `/eventos/${evento.id}/asistentes`,
        consentId: consent?.id,
        initialUuid: clientUuid,
        onSaved: ({ data, message, payload }) => {
            const duplicate = !!data?.duplicate;
            const name = `${payload.nombres} ${payload.apellidos}`;
            setSuccess({ duplicate, name, message });
            if (!duplicate) {
                setSessionCount(n => n + 1);
                setTotal(n => n + 1);
                setRecent(list => [{ id: data?.id ?? payload.client_uuid, nombres: payload.nombres, apellidos: payload.apellidos, created_at: new Date().toISOString() }, ...list].slice(0, 20));
            }
            firstFieldRef.current?.focus();
        },
        onClosed: () => setAccepts(false),
    });

    useEffect(() => {
        if (form.notice) noticeRef.current?.focus();
    }, [form.notice]);

    async function onSubmit(e) {
        e.preventDefault();
        setSuccess(null);
        await form.submit();
    }

    const breadcrumb = [{ label: 'EVENTOS', href: '/eventos' }, { label: evento.nombre }, { label: 'MODO OPERADOR' }];

    return (
        <AppLayout title="Modo operador" breadcrumb={breadcrumb}>
            <Head title={`Modo operador — ${evento.nombre}`} />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <div className="max-w-3xl flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[13px] font-bold tracking-wide text-white/70">MODO OPERADOR</p>
                        <h1 className="text-[19px] lg:text-[22px] font-extrabold leading-tight">{evento.nombre}</h1>
                        <p className="text-[14px] text-white/75 mt-0.5 first-letter:uppercase">{formatEventRange(evento.starts_at, evento.ends_at)}</p>
                        <p className="text-[14px] text-white/75">{placeLine(evento)}</p>
                    </div>
                    <Link href={canSeeDetail ? `/eventos/${evento.id}` : '/eventos'} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold active:bg-white/25 flex items-center">
                        {canSeeDetail ? 'Ver el evento' : 'Volver a eventos'}
                    </Link>
                </div>
            </div>

            <div className="p-4 max-w-3xl space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
                        <p className="text-[14px] font-bold text-[var(--color-ink-soft)]">Registrados por usted en esta sesión</p>
                        <p className="text-[34px] font-extrabold text-[var(--color-primary)] leading-none mt-1" aria-live="polite" data-testid="session-count">{sessionCount}</p>
                    </div>
                    <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
                        <p className="text-[14px] font-bold text-[var(--color-ink-soft)]">Total del evento</p>
                        <p className="text-[34px] font-extrabold text-[var(--color-ink)] leading-none mt-1">{total}</p>
                    </div>
                </div>

                {!accepts ? (
                    <div role="alert" className="bg-white border-2 border-red-300 rounded-2xl p-5">
                        <div className="flex items-center gap-2 mb-2"><StatusBadge status={evento.status} /></div>
                        <h2 className="text-[20px] font-extrabold text-red-800">Este evento ya no recibe registros</h2>
                        <p className="mt-2 text-[17px] text-[var(--color-ink-soft)]">Solo se puede registrar asistentes en eventos en borrador o abiertos.</p>
                        {form.notice && <p className="mt-2 text-[16px] text-red-800">{form.notice}</p>}
                    </div>
                ) : (
                    <form onSubmit={onSubmit} noValidate className="bg-white border border-[var(--color-line)] rounded-2xl p-5 space-y-6">
                        {success && (
                            <div role="status" className={`rounded-2xl border-2 p-5 text-center ${success.duplicate ? 'bg-amber-50 border-amber-400' : 'bg-emerald-50 border-emerald-400'}`}>
                                <p className={`text-[24px] font-extrabold ${success.duplicate ? 'text-amber-900' : 'text-emerald-800'}`}>
                                    {success.duplicate ? 'Ya estaba registrado' : '¡Registrado!'}
                                </p>
                                <p className="mt-1 text-[18px] text-[var(--color-ink)]">
                                    {success.duplicate ? `${success.name}: ${success.message}` : `${success.name} quedó registrado en el evento.`}
                                </p>
                                <p className="mt-1 text-[15px] text-[var(--color-ink-soft)]">El formulario está listo para la siguiente persona.</p>
                            </div>
                        )}

                        <div>
                            <h2 className="text-[20px] font-extrabold text-[var(--color-ink)]">Registrar asistente</h2>
                            <p className="mt-1 text-[15px] text-[var(--color-ink-soft)]">Los campos con <span className="text-red-600 font-bold">*</span> son obligatorios. Lea el texto de autorización a la persona antes de marcar la casilla.</p>
                        </div>

                        <AttendeeFields form={form} barrios={evento.barrios} firstFieldRef={firstFieldRef} />

                        <ConsentBox
                            consent={consent}
                            checked={form.data.autoriza_datos}
                            onChange={v => form.set('autoriza_datos', v)}
                            error={form.errors.autoriza_datos}
                            provisional={consent && !consent.approved}
                            expandable
                        />

                        {form.notice && (
                            <div ref={noticeRef} tabIndex={-1} role="alert" className="px-4 py-3 rounded-xl bg-red-50 border-2 border-red-300 text-[17px] font-semibold text-red-800">
                                {form.notice}
                            </div>
                        )}

                        <button
                            type="submit"
                            disabled={form.saving}
                            className="w-full min-h-[60px] px-4 py-4 rounded-2xl bg-[var(--color-primary)] text-white text-[20px] font-extrabold disabled:opacity-60 active:bg-[var(--color-primary-dark)]"
                        >
                            {form.saving ? 'Guardando...' : 'Guardar asistente'}
                        </button>
                    </form>
                )}

                {recent.length > 0 && (
                    <section className="bg-white border border-[var(--color-line)] rounded-2xl p-4">
                        <h2 className="text-[16px] font-extrabold text-[var(--color-ink)] mb-2">Sus últimos registros</h2>
                        <ul className="divide-y divide-[var(--color-line)]">
                            {recent.map(r => (
                                <li key={r.id} className="py-2.5 flex items-center justify-between gap-3 text-[15px]">
                                    <span className="font-semibold text-[var(--color-ink)] truncate">{r.nombres} {r.apellidos}</span>
                                    <span className="text-[13px] text-[var(--color-ink-faint)] flex-shrink-0">{formatShortDate(r.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
