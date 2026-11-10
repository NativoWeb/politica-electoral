import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Suspense, lazy, useEffect, useMemo, useState } from 'react';
import CampaignChip from '@/Components/CampaignChip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import AttendeeActions from '@/Components/Eventos/AttendeeActions';
import AttendeeEditModal from '@/Components/Eventos/AttendeeEditModal';
import { LinkStatusBadge, StatusBadge } from '@/Components/Eventos/EventoBadges';
import EventoFormModal from '@/Components/Eventos/EventoFormModal';
import EventoQr from '@/Components/Eventos/EventoQr';
import ImportLoads from '@/Components/Eventos/ImportLoads';
import Pagination from '@/Components/Eventos/Pagination';
import PlanillaLink from '@/Components/Eventos/PlanillaLink';
import { attendeesExcelUrl } from '@/lib/eventosIntegracion';
import { useCampaign } from '@/lib/campaign';
import { APPROVE_MESSAGE, allSelected, canManageEventos, canSeeFicha, deleteAttendeesMessage, eventErrorMessage, formatEventRange, formatShortDate, isSelectable, placeLine, pruneSelection, sourceLabel, toggleAll, toggleSelected } from '@/lib/eventos';

// The ficha of the Mapa Político, loaded only when someone opens one.
const PersonaPanel = lazy(() => import('@/Components/PersonaPanel'));

const ATTENDEE_URLS = {
    approve: 'asistentes/aprobar',
    discard: 'asistentes/descartar',
    recover: 'asistentes/recuperar',
    unlink: 'asistentes/desvincular',
    delete: 'asistentes/eliminar',
};

const plural = (n, one, many) => (n === 1 ? `1 ${one}` : `${n} ${many}`);

const STATUS_ACTIONS = {
    open: {
        url: 'abrir', label: 'Abrir registro', title: '¿Abrir el registro del evento?',
        message: 'El QR empezará a recibir registros de los asistentes.', confirm: 'Sí, abrir', cls: 'bg-emerald-600 text-white border-emerald-600',
    },
    reopen: {
        url: 'abrir', label: 'Abrir de nuevo', title: '¿Abrir de nuevo el registro?',
        message: 'El QR volverá a recibir registros de los asistentes.', confirm: 'Sí, abrir', cls: 'bg-emerald-600 text-white border-emerald-600',
    },
    close: {
        url: 'cerrar', label: 'Cerrar registro', title: '¿Cerrar el registro?',
        message: 'El QR dejará de recibir registros. Puede volver a abrirlo después.', confirm: 'Sí, cerrar', cls: 'bg-white text-[var(--color-primary)] border-[var(--color-primary)]',
    },
    cancel: {
        url: 'cancelar', label: 'Cancelar evento', title: '¿Cancelar el evento?', danger: true,
        message: 'El evento quedará cancelado y el QR dejará de funcionar. Esto no se puede deshacer.', confirm: 'Sí, cancelar evento', back: 'No, mantener evento', cls: 'bg-white text-red-700 border-red-300',
    },
    token: {
        url: 'token', label: 'Generar QR nuevo', title: '¿Generar un QR nuevo?', danger: true,
        message: 'El QR anterior dejará de funcionar. Si ya lo imprimió o lo compartió, tendrá que reemplazarlo.', confirm: 'Sí, generar QR nuevo', cls: 'bg-white text-[var(--color-ink-soft)] border-[var(--color-line-strong)]',
    },
};

const ACTIONS_BY_STATUS = {
    borrador: ['open', 'cancel', 'token'],
    abierto: ['close', 'cancel', 'token'],
    cerrado: ['reopen', 'cancel', 'token'],
    cancelado: [],
};

const LINK_FILTERS = [
    { value: null, label: 'Todos' },
    { value: 'pendiente', label: 'Pendientes' },
    { value: 'vinculado', label: 'Ya estaban' },
    { value: 'aprobado', label: 'Aprobados' },
    { value: 'descartado', label: 'Descartados' },
];

function Kpi({ label, value, tone }) {
    return (
        <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
            <p className="text-[13px] font-bold text-[var(--color-ink-soft)] leading-tight">{label}</p>
            <p className={`text-[28px] font-extrabold leading-none mt-1.5 ${tone}`}>{value ?? 0}</p>
        </div>
    );
}

function PeopleList({ title, items, render }) {
    if (!items?.length) return null;
    return (
        <div>
            <h3 className="text-[14px] font-extrabold text-[var(--color-ink)] mb-1.5">{title}</h3>
            <ul className="space-y-1">
                {items.map(item => <li key={item.id} className="text-[14px] text-[var(--color-ink-soft)]">{render(item)}</li>)}
            </ul>
        </div>
    );
}

function AttendeeRow({ a, selectable, checked, onToggle, fichaVisible, busy, onAction }) {
    const contact = [a.cedula && `C.C. ${a.cedula}`, a.celular && `Cel. ${a.celular}`, a.barrio].filter(Boolean).join(' · ');
    return (
        <li className={`flex items-start gap-3 px-4 py-3 ${checked ? 'bg-blue-50' : 'bg-white'}`}>
            <div className="w-11 flex-shrink-0 flex justify-center pt-0.5">
                {selectable && isSelectable(a) && (
                    <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => onToggle(a.id)}
                        aria-label={`Seleccionar a ${a.nombres} ${a.apellidos}`}
                        className="w-6 h-6 accent-[var(--color-primary)] cursor-pointer"
                    />
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="text-[15px] font-bold text-[var(--color-ink)]">{a.nombres} {a.apellidos}</p>
                    <LinkStatusBadge status={a.link_status} />
                </div>
                <p className="text-[13px] text-[var(--color-ink-soft)] mt-0.5">{contact}</p>
                {(a.ficha || a.nota) && (
                    <p className="text-[13px] text-[var(--color-ink-soft)] mt-0.5">
                        {[a.ficha && `Cargo: ${a.ficha.cargo || '—'}`, a.ficha && `Líder: ${a.ficha.lider ? 'SÍ' : 'NO'}`, a.nota && `Actividad: ${a.nota}`].filter(Boolean).join(' · ')}
                    </p>
                )}
                <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">
                    {sourceLabel(a)} · {formatShortDate(a.created_at)}
                </p>
                <AttendeeActions attendee={a} editable={selectable} fichaVisible={fichaVisible} disabled={busy} onAction={onAction} />
            </div>
        </li>
    );
}

export default function Show({ evento, asistentes, kpis, filters, tipos, provincias, can, cargas }) {
    const { props } = usePage();
    const errors = props.errors || {};
    const success = props.flash?.success;
    const { isOther } = useCampaign();
    const editable = (can?.edit ?? true) && canManageEventos(props.auth?.user) && !isOther(evento.campaign?.id);
    const canOperate = !!can?.register && !isOther(evento.campaign?.id) && ['borrador', 'abierto'].includes(evento.status);
    const rows = useMemo(() => asistentes?.data ?? [], [asistentes]);

    const [selected, setSelected] = useState(() => new Set());
    const [confirm, setConfirm] = useState(null);
    const [editing, setEditing] = useState(false);
    const [actionError, setActionError] = useState('');
    const [search, setSearch] = useState(filters?.q ?? '');
    const [busy, setBusy] = useState(false);
    const [editingAttendee, setEditingAttendee] = useState(null);
    const [fichaId, setFichaId] = useState(null);
    const [notice, setNotice] = useState('');
    const fichaVisible = canSeeFicha(props.auth?.user);

    useEffect(() => {
        setSelected(prev => pruneSelection(prev, rows));
    }, [rows]);

    function runAction(url, data, onDone) {
        setActionError('');
        setBusy(true);
        router.post(`/eventos/${evento.id}/${url}`, data, {
            preserveScroll: true,
            onSuccess: onDone,
            onHttpException: response => { setActionError(eventErrorMessage(response)); return false; },
            onNetworkError: () => { setActionError('No hay conexión con el servidor. Intente de nuevo.'); return false; },
            onFinish: () => setBusy(false),
        });
    }

    function onConfirm() {
        const c = confirm;
        setConfirm(null);
        setNotice('');
        if (ATTENDEE_URLS[c.kind]) {
            runAction(ATTENDEE_URLS[c.kind], { ids: c.ids }, () => setSelected(new Set()));
        } else {
            runAction(STATUS_ACTIONS[c.kind].url, {});
        }
    }

    function onAttendeeAction(kind, a) {
        if (kind === 'ficha') setFichaId(a.lider_id);
        else if (kind === 'edit') setEditingAttendee(a);
        else setConfirm({ kind, ids: [a.id], attendees: [a] });
    }

    function reloadAfter(message) {
        setNotice(message);
        router.reload({ only: ['asistentes', 'kpis', 'cargas'], preserveScroll: true });
    }

    function askBulk(kind) {
        setConfirm({ kind, ids: [...selected], attendees: rows.filter(r => selected.has(r.id)) });
    }

    function applyFilters(next) {
        const params = { ...filters, ...next };
        Object.keys(params).forEach(k => { if (params[k] === null || params[k] === '' || params[k] === undefined) delete params[k]; });
        router.get(`/eventos/${evento.id}`, params, { preserveState: true, preserveScroll: true, replace: true, only: ['asistentes', 'filters'] });
    }

    const errorMessages = [
        errors.status, errors.ids, errors.evento, actionError,
    ].filter(Boolean);

    const attendeeConfirms = confirm && {
        approve: {
            title: `¿Pasar ${plural(confirm.ids?.length, 'asistente', 'asistentes')} a Votantes?`,
            message: APPROVE_MESSAGE,
            confirm: 'Sí, pasar a Votantes',
        },
        discard: {
            title: `¿Descartar ${plural(confirm.ids?.length, 'asistente', 'asistentes')}?`,
            message: 'No pasarán a Votantes. Seguirán en la lista del evento marcados como descartados.',
            confirm: 'Sí, descartar', danger: true,
        },
        recover: {
            title: `¿Recuperar ${plural(confirm.ids?.length, 'asistente', 'asistentes')}?`,
            message: 'Vuelven a quedar pendientes de aprobar.',
            confirm: 'Sí, recuperar',
        },
        unlink: {
            title: `¿Desvincular ${plural(confirm.ids?.length, 'asistente', 'asistentes')}?`,
            message: 'Vuelve a quedar pendiente en el evento. Su ficha en Votantes NO se borra.',
            confirm: 'Sí, desvincular', danger: true,
        },
        delete: {
            title: `¿Eliminar ${plural(confirm.ids?.length, 'asistente', 'asistentes')}?`,
            message: deleteAttendeesMessage(confirm.attendees ?? []),
            confirm: 'Sí, eliminar', danger: true, back: 'No, mantener',
        },
    }[confirm.kind];
    const confirmMeta = confirm && (attendeeConfirms ?? { ...STATUS_ACTIONS[confirm.kind] });

    const pendingOnPage = rows.filter(isSelectable).length;
    const onlyPending = rows.filter(isSelectable).every(r => r.link_status === 'pendiente');
    const selectedRows = rows.filter(r => selected.has(r.id));
    const selectedPending = selectedRows.some(r => r.link_status === 'pendiente');
    const selectedDiscarded = selectedRows.some(r => r.link_status === 'descartado');

    return (
        <AppLayout title={evento.nombre} breadcrumb={[{ label: 'EVENTOS', href: '/eventos' }, { label: evento.nombre }]}>
            <Head title={`${evento.nombre} — Eventos`} />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href="/eventos" className="inline-flex items-center gap-1 min-h-[36px] text-[14px] font-semibold text-white/75 hover:text-white">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    Eventos
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3 mt-1">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={evento.status} />
                            {evento.tipo && <span className="text-[13px] font-semibold text-white/75">{evento.tipo.nombre}</span>}
                            <CampaignChip campaignId={evento.campaign?.id} />
                        </div>
                        <h1 className="text-[20px] lg:text-[24px] font-extrabold leading-tight mt-1.5">{evento.nombre}</h1>
                        <p className="text-[14px] text-white/80 mt-1 first-letter:uppercase">{formatEventRange(evento.starts_at, evento.ends_at)}</p>
                        <p className="text-[14px] text-white/80">{placeLine(evento)}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canOperate && (
                            <Link href={`/eventos/${evento.id}/operador`} className="min-h-[44px] px-4 py-2.5 bg-[var(--color-accent)] text-[var(--color-primary-dark)] rounded-xl text-[15px] font-extrabold flex items-center">
                                Modo operador
                            </Link>
                        )}
                        {can?.register && evento.status !== 'cancelado' && (
                            <PlanillaLink
                                eventId={evento.id}
                                className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center active:bg-white/25"
                                messageClassName="text-amber-200"
                            />
                        )}
                        {editable && evento.status !== 'cancelado' && (
                            <Link href={`/eventos/${evento.id}/importar`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center active:bg-white/25">
                                Cargar Excel
                            </Link>
                        )}
                        {editable && can?.ocr && evento.status !== 'cancelado' && (
                            <Link href={`/eventos/${evento.id}/importar/ocr`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center active:bg-white/25">
                                Leer planillas con IA
                            </Link>
                        )}
                        {editable && evento.status !== 'cancelado' && (
                            <button type="button" onClick={() => setEditing(true)} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold active:bg-white/25">
                                Editar
                            </button>
                        )}
                    </div>
                </div>
            </div>

            <div className="p-4 max-w-6xl space-y-4">
                {(notice || success) && <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{notice || success}</div>}
                {errorMessages.length > 0 && (
                    <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700 space-y-1">
                        {errorMessages.map(m => <p key={m}>{m}</p>)}
                    </div>
                )}

                {editable && ACTIONS_BY_STATUS[evento.status]?.length > 0 && (
                    <div className="flex flex-wrap gap-2" aria-label="Acciones del evento">
                        {ACTIONS_BY_STATUS[evento.status].map(kind => (
                            <button key={kind} type="button" disabled={busy} onClick={() => setConfirm({ kind })} className={`min-h-[48px] px-4 py-2.5 rounded-xl border-2 text-[15px] font-bold disabled:opacity-50 ${STATUS_ACTIONS[kind].cls}`}>
                                {STATUS_ACTIONS[kind].label}
                            </button>
                        ))}
                    </div>
                )}

                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <Kpi label="Total registrados" value={kpis?.total} tone="text-[var(--color-ink)]" />
                    <Kpi label="Ya estaban en la plataforma" value={kpis?.vinculados} tone="text-[var(--color-primary)]" />
                    <Kpi label="Pendientes de aprobar" value={kpis?.pendientes} tone="text-amber-700" />
                    <Kpi label="Aprobados" value={kpis?.aprobados} tone="text-emerald-700" />
                </div>

                <div className="grid lg:grid-cols-[1fr_1fr] gap-4">
                    <section className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3">
                        <h2 className="text-[16px] font-extrabold text-[var(--color-ink)]">Datos del evento</h2>
                        {evento.barrios?.length > 0 && (
                            <div className="flex flex-wrap gap-1.5" aria-label="Barrios">
                                {evento.barrios.map(b => <span key={b} className="px-2.5 py-1 rounded-full bg-blue-50 border border-blue-200 text-[13px] font-semibold text-[var(--color-primary)]">{b}</span>)}
                            </div>
                        )}
                        {evento.descripcion && <p className="text-[14px] text-[var(--color-ink-soft)] whitespace-pre-line">{evento.descripcion}</p>}
                        <PeopleList
                            title="Organizadores"
                            items={evento.organizadores}
                            render={o => <><span className="font-semibold text-[var(--color-ink)]">{o.nombre}</span>{[o.rol, o.cedula && `C.C. ${o.cedula}`, o.telefono].filter(Boolean).map(v => ` · ${v}`).join('')}</>}
                        />
                        <PeopleList
                            title="Figuras políticas"
                            items={evento.figuras}
                            render={f => <><span className="font-semibold text-[var(--color-ink)]">{f.nombre}</span>{f.cargo ? ` · ${f.cargo}` : ''}</>}
                        />
                        <p className="text-[12px] text-[var(--color-ink-faint)]">
                            {evento.created_by?.name ? `Creado por ${evento.created_by.name}` : 'Creado'}{evento.created_at ? ` el ${formatShortDate(evento.created_at)}` : ''}
                        </p>
                    </section>

                    <EventoQr evento={evento} canOpen={editable} />
                </div>

                {editable && <ImportLoads eventId={evento.id} cargas={cargas} onDone={reloadAfter} />}

                <section aria-labelledby="asistentes-title" className="bg-white border border-[var(--color-line)] rounded-2xl overflow-hidden">
                    <div className="px-4 pt-4 pb-3 space-y-3 border-b border-[var(--color-line)]">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="asistentes-title" className="text-[16px] font-extrabold text-[var(--color-ink)]">Asistentes ({asistentes?.total ?? 0})</h2>
                            {(asistentes?.total ?? 0) > 0 && (
                                <a href={attendeesExcelUrl(evento.id, filters)} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-line-strong)] text-[15px] font-bold text-[var(--color-ink)] flex items-center">
                                    Descargar Excel{filters?.link_status || filters?.source || filters?.q ? ' (con el filtro)' : ''}
                                </a>
                            )}
                        </div>
                        <div className="flex gap-1.5 overflow-x-auto -mx-4 px-4 pb-0.5 [scrollbar-width:none]" role="group" aria-label="Filtrar por estado">
                            {LINK_FILTERS.map(f => {
                                const active = (filters?.link_status ?? null) === f.value;
                                return (
                                    <button key={f.label} type="button" aria-pressed={active} onClick={() => applyFilters({ link_status: f.value, page: null })} className={`flex-shrink-0 min-h-[40px] px-3.5 py-1.5 rounded-full border text-[14px] font-semibold ${active ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white' : 'bg-white border-[var(--color-line-strong)] text-[var(--color-ink-soft)]'}`}>
                                        {f.label}
                                    </button>
                                );
                            })}
                        </div>
                        <form onSubmit={e => { e.preventDefault(); applyFilters({ q: search.trim() || null, page: null }); }} className="flex gap-2">
                            <label htmlFor="asistentes-q" className="sr-only">Buscar asistente por nombre o cédula</label>
                            <input id="asistentes-q" value={search} onChange={e => setSearch(e.target.value)} placeholder="Buscar por nombre o cédula" className="flex-1 min-w-0 px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]" />
                            <button type="submit" className="px-4 py-2.5 bg-[var(--color-primary)] text-white rounded-lg text-[15px] font-bold">Buscar</button>
                        </form>
                        {editable && pendingOnPage > 0 && (
                            <label className="flex items-center gap-3 min-h-[44px] cursor-pointer">
                                <input type="checkbox" checked={allSelected(selected, rows)} onChange={() => setSelected(prev => toggleAll(prev, rows))} className="w-6 h-6 accent-[var(--color-primary)]" />
                                <span className="text-[15px] font-semibold text-[var(--color-ink)]">
                                    {onlyPending ? 'Seleccionar todos los pendientes' : 'Seleccionar todos los pendientes y descartados'} de esta página ({pendingOnPage})
                                </span>
                            </label>
                        )}
                    </div>

                    {rows.length > 0 ? (
                        <ul className="divide-y divide-[var(--color-line)]" aria-label="Lista de asistentes">
                            {rows.map(a => (
                                <AttendeeRow key={a.id} a={a} selectable={editable} checked={selected.has(a.id)} onToggle={id => setSelected(prev => toggleSelected(prev, id))} fichaVisible={fichaVisible} busy={busy} onAction={onAttendeeAction} />
                            ))}
                        </ul>
                    ) : (
                        <p className="px-4 py-8 text-center text-[15px] text-[var(--color-ink-faint)]">
                            {filters?.link_status || filters?.q ? 'No hay asistentes con ese filtro.' : 'Todavía no hay asistentes registrados.'}
                        </p>
                    )}

                    {asistentes?.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[var(--color-line)]">
                            <Pagination paginator={asistentes} label="Páginas de asistentes" only={['asistentes', 'filters']} />
                        </div>
                    )}
                </section>
            </div>

            {selected.size > 0 && editable && (
                <div className="fixed left-0 right-0 bottom-[72px] lg:bottom-0 lg:left-[200px] z-30 bg-white border-t-2 border-[var(--color-primary)] shadow-[0_-4px_20px_rgba(0,0,0,0.15)] px-4 py-3 safe-bottom">
                    <div className="max-w-6xl flex flex-wrap items-center gap-2">
                        <p className="text-[15px] font-bold text-[var(--color-ink)] mr-auto" aria-live="polite">{selected.size === 1 ? '1 seleccionado' : `${selected.size} seleccionados`}</p>
                        <button type="button" disabled={busy} onClick={() => askBulk('delete')} className="min-h-[48px] px-4 py-2.5 rounded-xl border-2 border-red-300 bg-white text-[15px] font-bold text-red-700 disabled:opacity-50">Eliminar</button>
                        {selectedDiscarded && (
                            <button type="button" disabled={busy} onClick={() => askBulk('recover')} className="min-h-[48px] px-4 py-2.5 rounded-xl border-2 border-[var(--color-primary)] bg-white text-[15px] font-bold text-[var(--color-primary)] disabled:opacity-50">Recuperar</button>
                        )}
                        {selectedPending && (
                            <>
                                <button type="button" disabled={busy} onClick={() => askBulk('discard')} className="min-h-[48px] px-4 py-2.5 rounded-xl border-2 border-gray-300 bg-white text-[15px] font-bold text-[var(--color-ink-soft)] disabled:opacity-50">Descartar</button>
                                <button type="button" disabled={busy} onClick={() => askBulk('approve')} className="min-h-[48px] px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-[15px] font-bold disabled:opacity-50">Pasar a Votantes</button>
                            </>
                        )}
                    </div>
                </div>
            )}

            {confirm && confirmMeta && (
                <ConfirmDialog
                    title={confirmMeta.title}
                    message={confirmMeta.message}
                    confirmLabel={confirmMeta.confirm}
                    danger={confirmMeta.danger}
                    cancelLabel={confirmMeta.back}
                    onConfirm={onConfirm}
                    onCancel={() => setConfirm(null)}
                />
            )}

            {editingAttendee && (
                <AttendeeEditModal
                    eventId={evento.id}
                    attendee={editingAttendee}
                    onClose={() => setEditingAttendee(null)}
                    onSaved={message => { setEditingAttendee(null); reloadAfter(message); }}
                />
            )}

            {fichaId && (
                <Suspense fallback={null}>
                    <PersonaPanel personId={fichaId} onClose={() => setFichaId(null)} />
                </Suspense>
            )}

            {editing && (
                <EventoFormModal evento={evento} tipos={tipos} provincias={provincias} onClose={() => setEditing(false)} />
            )}
        </AppLayout>
    );
}
