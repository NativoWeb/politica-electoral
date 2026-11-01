import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AttendeeFields, { ConsentBox } from '@/Components/Eventos/AttendeeFields';
import { StatusBadge } from '@/Components/Eventos/EventoBadges';
import { ConnectionStatus, PendingAttendees } from '@/Components/Eventos/OperadorOffline';
import PlanillaLink from '@/Components/Eventos/PlanillaLink';
import { attendeeFormFromPayload, formatEventRange, formatShortDate, newClientUuid, placeLine } from '@/lib/eventos';
import { getEventSnapshot, isEventUuidUsed, queueEventAttendee, rememberEventUuid, saveEventSnapshot } from '@/lib/offlineDb';
import { precacheExtraPages } from '@/lib/swSession';
import useAttendeeForm from '@/lib/useAttendeeForm';
import useEventQueue from '@/lib/useEventQueue';

const NO_RECORDS = [];

// When a registration kept on the phone will go out, by why it was kept (useAttendeeForm onQueued reason).
const QUEUED_WHEN = {
    offline: 'se enviará al recuperar la señal.',
    session: 'se enviará cuando inicie sesión de nuevo.',
    password: 'se enviará cuando cambie su contraseña.',
    server: 'se enviará cuando el servidor responda.',
};
const BLOCKING_REASONS = ['session', 'password'];
const REQUEST_TIMEOUT_MS = 15000;

function hasTypedData(data) {
    const { birth = {}, autoriza_datos: _consent, ...fields } = data;
    return Object.values(fields).some(v => String(v ?? '').trim() !== '') || Object.values(birth).some(Boolean);
}

// A client_uuid already saved or queued on this phone (e.g. the one of a page restored from the cache) is replaced.
async function freshUuid(uuid) {
    try {
        return (await isEventUuidUsed(uuid)) ? newClientUuid() : uuid;
    } catch {
        return newClientUuid();
    }
}

export default function Operador({ evento: serverEvento, consent: serverConsent, registrados, mis_registros: misRegistros, client_uuid: clientUuid }) {
    const { props } = usePage();
    const perms = props.auth?.user?.permissions || [];
    const canSeeDetail = perms.includes('*') || perms.includes('view.eventos') || perms.includes('manage.eventos');
    const [snapshot, setSnapshot] = useState(null);
    const evento = snapshot?.evento ?? serverEvento;
    const consent = snapshot ? snapshot.consent : serverConsent;
    const [accepts, setAccepts] = useState(!!serverEvento.accepts_registrations);
    const [total, setTotal] = useState(registrados ?? 0);
    // Saved with signal in this session, plus the client_uuids kept on the phone (those the server refuses stop counting).
    const [savedCount, setSavedCount] = useState(0);
    const [queuedUuids, setQueuedUuids] = useState(() => new Set());
    const [recent, setRecent] = useState(misRegistros ?? NO_RECORDS);
    const [success, setSuccess] = useState(null);
    // The refused registration being corrected: it leaves the list only once the corrected one is saved or queued.
    const [correctingId, setCorrectingId] = useState(null);
    const firstFieldRef = useRef(null);
    const noticeRef = useRef(null);
    const eventQueue = useEventQueue(serverEvento.id);
    const { online, queue } = eventQueue;

    const rejectedUuids = new Set(queue.rejected.map(c => c.data?.payload?.client_uuid));
    const sessionCount = savedCount + [...queuedUuids].filter(uuid => !rejectedUuids.has(uuid)).length;

    const pendingCount = queue.pending.length;
    const lastPendingCount = useRef(pendingCount);
    useEffect(() => {
        // Once the phone sent what it kept, "se enviará al recuperar la señal" is no longer true.
        if (pendingCount === 0 && lastPendingCount.current > 0) setSuccess(prev => (prev?.queued ? null : prev));
        lastPendingCount.current = pendingCount;
    }, [pendingCount]);

    useEffect(() => setTotal(registrados ?? 0), [registrados]);
    useEffect(() => setRecent(misRegistros ?? NO_RECORDS), [misRegistros]);

    useEffect(() => {
        let active = true;
        if (navigator.onLine) {
            // Kept for later without signal: the event, the consent text shown and this page itself.
            saveEventSnapshot(serverEvento, serverConsent).catch(err => console.warn('No se pudo guardar el evento en el teléfono', err));
            precacheExtraPages([`/eventos/${serverEvento.id}/operador`]);
        } else {
            getEventSnapshot(serverEvento.id)
                .then(saved => { if (active && saved) setSnapshot(saved); })
                .catch(err => console.warn('No se pudo leer el evento guardado en el teléfono', err));
        }
        return () => { active = false; };
    }, [serverEvento, serverConsent]);

    const form = useAttendeeForm({
        url: `/eventos/${evento.id}/asistentes`,
        consentId: consent?.id,
        initialUuid: clientUuid,
        timeoutMs: REQUEST_TIMEOUT_MS,
        prepareUuid: freshUuid,
        queueOffline: payload => queueEventAttendee(evento.id, payload),
        onQueued: ({ payload, reason }) => {
            finishCorrection();
            setSuccess({ queued: true, reason, name: `${payload.nombres} ${payload.apellidos}` });
            setQueuedUuids(prev => new Set(prev).add(payload.client_uuid));
            if (BLOCKING_REASONS.includes(reason)) eventQueue.markBlocked(reason);
            eventQueue.refresh();
            firstFieldRef.current?.focus();
        },
        onSaved: ({ data, message, payload }) => {
            const duplicate = !!data?.duplicate;
            const name = `${payload.nombres} ${payload.apellidos}`;
            rememberEventUuid(evento.id, payload.client_uuid).catch(err => console.warn('No se pudo anotar el registro en el teléfono', err));
            finishCorrection();
            setSuccess({ duplicate, name, message });
            if (!duplicate) {
                setSavedCount(n => n + 1);
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

    function finishCorrection() {
        if (!correctingId) return;
        eventQueue.discard(correctingId).catch(err => console.warn('No se pudo quitar de la lista el registro corregido', err));
        setCorrectingId(null);
    }

    function correct(change) {
        if (hasTypedData(form.data) && !window.confirm('El formulario tiene datos escritos. ¿Reemplazarlos por el registro que va a corregir?')) return;
        setSuccess(null);
        setCorrectingId(change.id);
        form.load(attendeeFormFromPayload(change.data?.payload, { consentId: consent?.id }));
        firstFieldRef.current?.focus();
    }

    async function discard(change) {
        const p = change.data?.payload ?? {};
        if (!window.confirm(`¿Descartar el registro de ${p.nombres ?? ''} ${p.apellidos ?? ''}? No se enviará.`)) return;
        try {
            await eventQueue.discard(change.id);
            if (change.id === correctingId) setCorrectingId(null);
        } catch (err) {
            window.alert(`No se pudo descartar: ${err?.message || 'error del almacenamiento'}.`);
        }
    }

    const breadcrumb = [{ label: 'EVENTOS', href: '/eventos' }, { label: evento.nombre }, { label: 'MODO OPERADOR' }];

    return (
        <AppLayout title="Modo operador" breadcrumb={breadcrumb}>
            <Head title={`Modo operador — ${evento.nombre}`} />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <div className="max-w-3xl flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[18px] font-bold tracking-wide text-white/80">MODO OPERADOR</p>
                        <h1 className="text-[19px] lg:text-[22px] font-extrabold leading-tight">{evento.nombre}</h1>
                        <p className="text-[18px] text-white/85 mt-0.5 first-letter:uppercase">{formatEventRange(evento.starts_at, evento.ends_at)}</p>
                        <p className="text-[18px] text-white/85">{placeLine(evento)}</p>
                    </div>
                    <div className="flex flex-wrap items-start gap-2">
                        <Link href={canSeeDetail ? `/eventos/${evento.id}` : '/eventos'} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[18px] font-bold active:bg-white/25 flex items-center">
                            {canSeeDetail ? 'Ver el evento' : 'Volver a eventos'}
                        </Link>
                        {evento.status !== 'cancelado' && (
                            <PlanillaLink
                                eventId={evento.id}
                                className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[18px] font-bold active:bg-white/25 flex items-center"
                                messageClassName="text-amber-200"
                            />
                        )}
                    </div>
                </div>
            </div>

            <div className="p-4 max-w-3xl space-y-4">
                <ConnectionStatus online={online} syncing={eventQueue.syncing} pendingCount={queue.pending.length} />
                {eventQueue.storageError && (
                    <p role="alert" className="px-4 py-3 rounded-xl bg-red-50 border-2 border-red-300 text-[18px] font-semibold text-red-800">
                        El teléfono no deja guardar registros sin señal ({eventQueue.storageError}). Mientras tanto, registre solo con señal.
                    </p>
                )}

                <div className="grid grid-cols-2 gap-3">
                    <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
                        <p className="text-[16px] font-bold text-[var(--color-ink-soft)]">Registrados por usted en esta sesión</p>
                        <p className="text-[34px] font-extrabold text-[var(--color-primary)] leading-none mt-1" aria-live="polite" data-testid="session-count">{sessionCount}</p>
                    </div>
                    <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
                        <p className="text-[16px] font-bold text-[var(--color-ink-soft)]">Total del evento</p>
                        <p className="text-[34px] font-extrabold text-[var(--color-ink)] leading-none mt-1" data-testid="event-total">{total + queue.pending.length}</p>
                        {queue.pending.length > 0 && <p className="text-[16px] text-amber-800 mt-1">{queue.pending.length} sin enviar</p>}
                    </div>
                </div>

                {!accepts ? (
                    <div role="alert" className="bg-white border-2 border-red-300 rounded-2xl p-5">
                        <div className="flex items-center gap-2 mb-2"><StatusBadge status={evento.status} /></div>
                        <h2 className="text-[20px] font-extrabold text-red-800">Este evento ya no recibe registros</h2>
                        <p className="mt-2 text-[18px] text-[var(--color-ink-soft)]">Solo se puede registrar asistentes en eventos en borrador o abiertos.</p>
                        {form.notice && <p className="mt-2 text-[18px] text-red-800">{form.notice}</p>}
                    </div>
                ) : (
                    <form onSubmit={onSubmit} noValidate className="bg-white border border-[var(--color-line)] rounded-2xl p-5 space-y-6">
                        {success?.queued && (
                            <div role="status" className="rounded-2xl border-2 p-5 text-center bg-sky-50 border-sky-400">
                                <p className="text-[24px] font-extrabold text-sky-900">Guardado en el teléfono</p>
                                <p className="mt-1 text-[18px] text-[var(--color-ink)]">{success.name}: {QUEUED_WHEN[success.reason] ?? QUEUED_WHEN.offline}</p>
                                <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">El formulario está listo para la siguiente persona.</p>
                            </div>
                        )}
                        {success && !success.queued && (
                            <div role="status" className={`rounded-2xl border-2 p-5 text-center ${success.duplicate ? 'bg-amber-50 border-amber-400' : 'bg-emerald-50 border-emerald-400'}`}>
                                <p className={`text-[24px] font-extrabold ${success.duplicate ? 'text-amber-900' : 'text-emerald-800'}`}>
                                    {success.duplicate ? 'Ya estaba registrado' : '¡Registrado!'}
                                </p>
                                <p className="mt-1 text-[18px] text-[var(--color-ink)]">
                                    {success.duplicate ? `${success.name}: ${success.message}` : `${success.name} quedó registrado en el evento.`}
                                </p>
                                <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">El formulario está listo para la siguiente persona.</p>
                            </div>
                        )}

                        <div>
                            <h2 className="text-[20px] font-extrabold text-[var(--color-ink)]">Registrar asistente</h2>
                            <p className="mt-1 text-[18px] text-[var(--color-ink-soft)]">Los campos con <span className="text-red-600 font-bold">*</span> son obligatorios. Lea el texto de autorización a la persona antes de marcar la casilla.</p>
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
                            <div ref={noticeRef} tabIndex={-1} role="alert" className="px-4 py-3 rounded-xl bg-red-50 border-2 border-red-300 text-[18px] font-semibold text-red-800">
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

                <PendingAttendees
                    pending={queue.pending}
                    rejected={queue.rejected}
                    blocked={eventQueue.blocked}
                    online={online}
                    canCorrect={accepts}
                    correctingId={correctingId}
                    onCorrect={correct}
                    onDiscard={discard}
                />

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
