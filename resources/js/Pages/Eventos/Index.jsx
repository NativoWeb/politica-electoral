import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import CampaignChip from '@/Components/CampaignChip';
import { StatusBadge } from '@/Components/Eventos/EventoBadges';
import EventoFormModal from '@/Components/Eventos/EventoFormModal';
import PlanillaLink from '@/Components/Eventos/PlanillaLink';
import Pagination from '@/Components/Eventos/Pagination';
import { useCampaign } from '@/lib/campaign';
import { EVENT_STATUS, canManageEventos, formatEventRange } from '@/lib/eventos';
import { precacheExtraPagesOnce } from '@/lib/swSession';

const OPERABLE = ['borrador', 'abierto'];

const selectCls = 'w-full px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[15px] bg-white focus:outline-none focus:border-[var(--color-primary)]';
const labelCls = 'block text-[13px] font-bold text-[var(--color-ink-soft)] mb-1';

function clean(params) {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
}

function EventCard({ e, canView, canOperate }) {
    const href = canView ? `/eventos/${e.id}` : (canOperate ? `/eventos/${e.id}/operador` : null);
    const where = [e.municipio?.nombre, e.provincia?.nombre].filter(Boolean).join(', ');
    const body = (
        <>
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={e.status} />
                {e.tipo && <span className="text-[13px] font-semibold text-[var(--color-ink-soft)]">{e.tipo.nombre}</span>}
                <CampaignChip campaignId={e.campaign?.id} />
            </div>
            <p className="mt-1.5 text-[16px] font-extrabold text-[var(--color-ink)] leading-snug">{e.nombre}</p>
            <p className="mt-1 text-[14px] text-[var(--color-ink)] first-letter:uppercase">{formatEventRange(e.starts_at, e.ends_at)}</p>
            <p className="text-[14px] text-[var(--color-ink-soft)]">{[e.sitio_encuentro, where].filter(Boolean).join(' — ')}</p>
            {e.barrios?.length > 0 && <p className="text-[13px] text-[var(--color-ink-faint)] mt-0.5 line-clamp-2">Barrios: {e.barrios.join(', ')}</p>}
        </>
    );

    return (
        <li className="bg-white border border-[var(--color-line)] rounded-xl flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5">
            {href ? (
                <Link href={href} className="flex-1 min-w-[min(14rem,100%)] rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]">{body}</Link>
            ) : (
                <div className="flex-1 min-w-[min(14rem,100%)]">{body}</div>
            )}
            <div className="flex flex-wrap items-center justify-end gap-3 ml-auto">
                {e.asistentes_total !== null && e.asistentes_total !== undefined && (
                    <div className="text-center px-2">
                        <p className="text-[22px] font-extrabold text-[var(--color-primary)] leading-none">{e.asistentes_total}</p>
                        <p className="text-[12px] font-semibold text-[var(--color-ink-faint)] mt-0.5">{e.asistentes_total === 1 ? 'asistente' : 'asistentes'}</p>
                    </div>
                )}
                {canOperate && OPERABLE.includes(e.status) && (
                    <Link href={`/eventos/${e.id}/operador`} className="min-h-[44px] px-3.5 py-2 rounded-xl bg-[var(--color-accent)] text-[var(--color-primary-dark)] text-[14px] font-extrabold flex items-center">
                        Registrar asistentes
                    </Link>
                )}
                {canOperate && e.status !== 'cancelado' && (
                    <PlanillaLink
                        eventId={e.id}
                        className="min-h-[44px] px-3.5 py-2 rounded-xl border-2 border-[var(--color-line-strong)] text-[var(--color-ink)] text-[14px] font-bold flex items-center"
                        messageClassName="text-red-700 max-w-[16rem]"
                    />
                )}
            </div>
        </li>
    );
}

export default function Index({ eventos, filters = {}, provincias = [], tipos = [], statuses = [], can = {} }) {
    const { props } = usePage();
    const success = props.flash?.success;
    const canCreate = (can.manage ?? true) && canManageEventos(props.auth?.user);
    const { isOther, list: campaigns, hasMultiple } = useCampaign();
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');
    const [barrio, setBarrio] = useState(filters.barrio ?? '');
    const rows = eventos?.data ?? [];

    const municipios = useMemo(() => {
        if (filters.provincia) return provincias.find(p => p.id === filters.provincia)?.municipios ?? [];
        return provincias.flatMap(p => p.municipios);
    }, [provincias, filters.provincia]);

    const canRegister = !!can.register;
    // The operator mode of the events this user can run is kept on the phone, to open it later without signal.
    const operatorUrls = useMemo(
        () => (canRegister ? rows.filter(e => OPERABLE.includes(e.status) && !isOther(e.campaign?.id)).map(e => `/eventos/${e.id}/operador`) : []),
        [rows, canRegister, isOther],
    );
    const operatorUrlsKey = operatorUrls.join(',');
    useEffect(() => {
        if (operatorUrlsKey && navigator.onLine) precacheExtraPagesOnce(operatorUrlsKey.split(','));
    }, [operatorUrlsKey]);

    const knownBarrios = useMemo(() => [...new Set(rows.flatMap(e => e.barrios ?? []))], [rows]);
    const activeCount = ['provincia', 'municipio', 'barrio', 'tipo', 'status', 'desde', 'hasta', 'campana'].filter(k => filters[k]).length;

    function apply(next) {
        router.get('/eventos', clean({ ...filters, ...next, page: undefined }), { preserveState: true, preserveScroll: true, replace: true });
    }

    function clearAll() {
        setQ('');
        setBarrio('');
        router.get('/eventos', {}, { preserveState: true, replace: true });
    }

    return (
        <AppLayout title="Eventos" breadcrumb={[{ label: 'EVENTOS' }]}>
            <Head title="Eventos especiales" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">EVENTOS</h1>
                    <p className="text-[12px] text-white/60 mt-0.5">
                        {eventos?.total ?? 0} {eventos?.total === 1 ? 'evento' : 'eventos'}{activeCount || filters.q ? ' con los filtros elegidos' : ''}
                    </p>
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    {can.view && (
                        <Link href="/eventos/territorio" className="min-h-[44px] px-4 py-2.5 bg-white/15 text-white text-[15px] font-bold rounded-xl active:bg-white/25 border border-white/20 flex items-center flex-shrink-0">
                            Ver por territorio
                        </Link>
                    )}
                    {can.view && (
                        <Link href="/eventos/compromisos" className="min-h-[44px] px-4 py-2.5 bg-white/15 text-white text-[15px] font-bold rounded-xl active:bg-white/25 border border-white/20 flex items-center flex-shrink-0">
                            Compromisos
                        </Link>
                    )}
                    {canCreate && (
                        <button type="button" onClick={() => setCreating(true)} className="min-h-[44px] px-4 py-2.5 bg-white/15 text-white text-[15px] font-bold rounded-xl active:bg-white/25 border border-white/20 flex-shrink-0">
                            + Nuevo evento
                        </button>
                    )}
                </div>
            </div>

            <div className="bg-white border-b border-[var(--color-line)] px-4 lg:px-6 py-2.5 lg:py-3 space-y-2">
                <form onSubmit={e => { e.preventDefault(); apply({ q: q.trim() || null }); }} className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setFiltersOpen(!filtersOpen)}
                        aria-expanded={filtersOpen}
                        aria-controls="eventos-filtros"
                        className="lg:hidden flex items-center gap-2 px-3 py-2.5 min-h-[44px] bg-[var(--color-primary)] text-white rounded-lg text-[14px] font-bold flex-shrink-0"
                    >
                        Filtros
                        {activeCount > 0 && <span className="min-w-[20px] h-5 px-1.5 bg-amber-400 text-[var(--color-primary)] rounded-full text-[11px] font-extrabold flex items-center justify-center">{activeCount}</span>}
                        <svg className={`w-4 h-4 transition-transform ${filtersOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <label htmlFor="eventos-q" className="sr-only">Buscar evento por nombre</label>
                    <input id="eventos-q" value={q} onChange={e => setQ(e.target.value)} placeholder="Buscar evento por nombre" className="flex-1 min-w-0 px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]" />
                    <button type="submit" className="min-h-[44px] px-4 py-2.5 bg-[var(--color-primary)] text-white rounded-lg text-[14px] font-bold flex-shrink-0">Buscar</button>
                </form>

                <div id="eventos-filtros" className={`${filtersOpen ? 'grid' : 'hidden'} lg:grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 gap-2 lg:gap-3 pb-1`}>
                    <div>
                        <label htmlFor="f-provincia" className={labelCls}>Provincia</label>
                        <select id="f-provincia" className={selectCls} value={filters.provincia ?? ''} onChange={e => apply({ provincia: e.target.value || null, municipio: null })}>
                            <option value="">Todas</option>
                            {provincias.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="f-municipio" className={labelCls}>Municipio</label>
                        <select id="f-municipio" className={selectCls} value={filters.municipio ?? ''} onChange={e => apply({ municipio: e.target.value || null })}>
                            <option value="">Todos</option>
                            {municipios.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="f-barrio" className={labelCls}>Barrio</label>
                        <input
                            id="f-barrio"
                            className={selectCls}
                            value={barrio}
                            onChange={e => setBarrio(e.target.value)}
                            onBlur={() => { if ((filters.barrio ?? '') !== barrio.trim()) apply({ barrio: barrio.trim() || null }); }}
                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); apply({ barrio: barrio.trim() || null }); } }}
                            placeholder="Cualquiera"
                        />
                    </div>
                    <div>
                        <label htmlFor="f-tipo" className={labelCls}>Tipo</label>
                        <select id="f-tipo" className={selectCls} value={filters.tipo ?? ''} onChange={e => apply({ tipo: e.target.value || null })}>
                            <option value="">Todos</option>
                            {tipos.map(t => <option key={t.id} value={t.id}>{t.nombre}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="f-status" className={labelCls}>Estado</label>
                        <select id="f-status" className={selectCls} value={filters.status ?? ''} onChange={e => apply({ status: e.target.value || null })}>
                            <option value="">Todos</option>
                            {statuses.map(s => <option key={s} value={s}>{EVENT_STATUS[s]?.label ?? s}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="f-desde" className={labelCls}>Desde</label>
                        <input id="f-desde" type="date" className={selectCls} value={filters.desde ?? ''} onChange={e => apply({ desde: e.target.value || null })} />
                    </div>
                    <div>
                        <label htmlFor="f-hasta" className={labelCls}>Hasta</label>
                        <input id="f-hasta" type="date" className={selectCls} value={filters.hasta ?? ''} onChange={e => apply({ hasta: e.target.value || null })} />
                    </div>
                    {hasMultiple && (
                        <div>
                            <label htmlFor="f-campana" className={labelCls}>Grupo político</label>
                            <select id="f-campana" className={selectCls} value={filters.campana ?? ''} onChange={e => apply({ campana: e.target.value || null })}>
                                <option value="">Todos</option>
                                {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                    )}
                    {(activeCount > 0 || filters.q) && (
                        <div className="flex items-end">
                            <button type="button" onClick={clearAll} className="min-h-[44px] px-3 py-2 text-[14px] font-bold text-red-600 underline">Quitar filtros</button>
                        </div>
                    )}
                </div>
            </div>

            <div className="p-4 lg:px-6 space-y-3">
                {success && <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{success}</div>}

                {rows.length > 0 ? (
                    <ul className="space-y-2" aria-label="Eventos">
                        {rows.map(e => (
                            <EventCard key={e.id} e={e} canView={!!can.view} canOperate={canRegister && !isOther(e.campaign?.id)} />
                        ))}
                    </ul>
                ) : (
                    <div className="text-center py-10 px-4 bg-white border border-dashed border-[var(--color-line-strong)] rounded-xl">
                        <p className="text-[16px] font-bold text-[var(--color-ink)]">{activeCount || filters.q ? 'No hay eventos con esos filtros.' : 'Todavía no hay eventos.'}</p>
                        {canCreate && !activeCount && !filters.q && (
                            <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Pulse "+ Nuevo evento" para crear el primero.</p>
                        )}
                    </div>
                )}

                <Pagination paginator={eventos} label="Páginas de eventos" />
            </div>

            {creating && (
                <EventoFormModal tipos={tipos} provincias={provincias} knownBarrios={knownBarrios} onClose={() => setCreating(false)} />
            )}
        </AppLayout>
    );
}
