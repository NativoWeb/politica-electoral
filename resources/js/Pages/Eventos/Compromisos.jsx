import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CampaignChip from '@/Components/CampaignChip';
import CompromisoDetalle from '@/Components/Eventos/CompromisoDetalle';
import CompromisoEstado from '@/Components/Eventos/CompromisoEstado';
import { inputCls } from '@/Components/Eventos/ActaParticipantes';
import Pagination from '@/Components/Eventos/Pagination';
import { compromisosQuery, formatDate } from '@/lib/actas';

const EMPTY = { estado: null, vencidos: null, responsable: null, municipio: null, origen: null, evento: null, organizacion: null, desde: null, hasta: null, q: null };

// Every compromiso of the actas the user can see (of events and of organizations, each with its permission), with
// filters, Excel and the follow-up of each one.
export default function Compromisos({ compromisos, filters, eventos, organizaciones = [], origenes = { evento: true, organizacion: false }, municipios, estados }) {
    const bothOrigins = !!(origenes?.evento && origenes?.organizacion);
    // Who only has Empresarios goes back there, not to Eventos (which would send them away).
    const back = origenes?.evento ? { href: '/eventos', label: 'Eventos' } : { href: '/empresarios', label: 'Empresarios' };
    const [draft, setDraft] = useState({ ...EMPTY, ...filters });
    // ?abrir={id} (links of the bell and the e-mail) opens that compromiso.
    const [open, setOpen] = useState(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('abrir')));
    const rows = compromisos?.data ?? [];
    const active = Object.values(filters ?? {}).some(v => v !== null && v !== '');

    function apply(next) {
        const params = { ...next };
        Object.keys(params).forEach(k => { if (params[k] === null || params[k] === '' || params[k] === undefined) delete params[k]; });
        router.get('/eventos/compromisos', params, { preserveState: true, preserveScroll: true, replace: true, only: ['compromisos', 'filters'] });
    }

    const field = (name, value) => setDraft(d => ({ ...d, [name]: value || null }));

    return (
        <AppLayout title="Compromisos" breadcrumb={[{ label: back.label.toUpperCase(), href: back.href }, { label: 'Compromisos' }]}>
            <Head title={`Compromisos — ${back.label}`} />
            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href={back.href} className="inline-flex items-center min-h-[36px] text-[14px] font-semibold text-white/75 hover:text-white">← {back.label}</Link>
                <div className="flex flex-wrap items-center justify-between gap-3 mt-1">
                    <h1 className="text-[20px] lg:text-[24px] font-extrabold leading-tight">Compromisos de las actas</h1>
                    {(compromisos?.total ?? 0) > 0 && (
                        <a href={`/eventos/compromisos.xlsx${compromisosQuery(filters)}`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">
                            Descargar Excel{active ? ' (con el filtro)' : ''}
                        </a>
                    )}
                </div>
            </div>

            <div className="p-4 lg:px-6 space-y-4">
                <form onSubmit={e => { e.preventDefault(); apply(draft); }} className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3" aria-label="Filtros de compromisos">
                    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Estado
                            <select className={inputCls} value={draft.estado ?? ''} onChange={e => field('estado', e.target.value)}>
                                <option value="">Todos</option>
                                {Object.entries(estados ?? {}).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Responsable
                            <input className={inputCls} value={draft.responsable ?? ''} maxLength={100} onChange={e => field('responsable', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Municipio
                            <select className={inputCls} value={draft.municipio ?? ''} onChange={e => field('municipio', e.target.value)}>
                                <option value="">Todos</option>
                                {(municipios ?? []).map(m => <option key={m.id} value={m.id}>{m.nombre}</option>)}
                            </select>
                        </label>
                        {bothOrigins && (
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Origen
                                <select className={inputCls} value={draft.origen ?? ''} onChange={e => field('origen', e.target.value)}>
                                    <option value="">Eventos y organizaciones</option>
                                    <option value="evento">Solo eventos</option>
                                    <option value="organizacion">Solo empresas y gremios</option>
                                </select>
                            </label>
                        )}
                        {origenes?.evento && draft.origen !== 'organizacion' && (
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Evento
                                <select className={inputCls} value={draft.evento ?? ''} onChange={e => field('evento', e.target.value)}>
                                    <option value="">Todos</option>
                                    {(eventos ?? []).map(ev => <option key={ev.id} value={ev.id}>{ev.nombre}</option>)}
                                </select>
                            </label>
                        )}
                        {origenes?.organizacion && draft.origen !== 'evento' && (
                            <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Empresa o gremio
                                <select className={inputCls} value={draft.organizacion ?? ''} onChange={e => field('organizacion', e.target.value)}>
                                    <option value="">Todas</option>
                                    {organizaciones.map(o => <option key={o.id} value={o.id}>{o.nombre}</option>)}
                                </select>
                            </label>
                        )}
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Reunión desde
                            <input type="date" className={inputCls} value={draft.desde ?? ''} onChange={e => field('desde', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Reunión hasta
                            <input type="date" className={inputCls} value={draft.hasta ?? ''} onChange={e => field('hasta', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Buscar en el compromiso
                            <input className={inputCls} value={draft.q ?? ''} maxLength={100} onChange={e => field('q', e.target.value)} />
                        </label>
                        <label className="flex items-center gap-3 min-h-[44px] mt-5 text-[15px] font-semibold text-[var(--color-ink)] cursor-pointer">
                            <input type="checkbox" checked={!!draft.vencidos} onChange={e => field('vencidos', e.target.checked ? '1' : null)} className="w-6 h-6 accent-[var(--color-primary)]" />
                            Solo vencidos
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="min-h-[44px] px-5 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold">Filtrar</button>
                        {active && <button type="button" onClick={() => { setDraft(EMPTY); apply({}); }} className="min-h-[44px] px-5 rounded-xl border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)]">Quitar filtros</button>}
                    </div>
                </form>

                <section aria-labelledby="compromisos-title" className="bg-white border border-[var(--color-line)] rounded-2xl overflow-hidden">
                    <h2 id="compromisos-title" className="px-4 pt-4 pb-3 text-[16px] font-extrabold text-[var(--color-ink)] border-b border-[var(--color-line)]">
                        Compromisos ({compromisos?.total ?? 0})
                    </h2>
                    {compromisos?.last_page > 1 && <div className="px-4 py-2 border-b border-[var(--color-line)]"><Pagination paginator={compromisos} label="Páginas de compromisos (arriba)" only={['compromisos', 'filters']} /></div>}
                    {rows.length === 0 ? (
                        <p className="px-4 py-8 text-center text-[15px] text-[var(--color-ink-faint)]">{active ? 'No hay compromisos con ese filtro.' : 'Todavía no hay compromisos en las actas.'}</p>
                    ) : (
                        <ul className="divide-y divide-[var(--color-line)]" aria-label="Lista de compromisos">
                            {rows.map(c => (
                                <li key={c.id} className="px-4 py-3">
                                    <button type="button" onClick={() => setOpen(c.id)} className="w-full text-left rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <CompromisoEstado estado={c.estado} vencido={c.vencido} />
                                            <CampaignChip campaignId={c.campaign_id} />
                                            {c.avances > 0 && <span className="text-[13px] text-[var(--color-ink-soft)]">{c.avances} avances</span>}
                                        </span>
                                        <span className="block mt-1 text-[15px] text-[var(--color-ink)] line-clamp-3">{c.descripcion}</span>
                                        <span className="block text-[14px] text-[var(--color-ink-soft)]">
                                            {c.responsables.length > 0 ? `Responsables: ${c.responsables.map(r => [r.nombre, r.telefono].filter(Boolean).join(' ')).join(' / ')}` : 'Sin responsable'}
                                        </span>
                                        <span className="block text-[13px] text-[var(--color-ink-faint)]">
                                            {[c.fecha_limite && `Fecha límite: ${formatDate(c.fecha_limite)}`, c.evento?.nombre, c.organizacion && `Organización: ${c.organizacion.nombre}`, (c.evento ?? c.organizacion)?.municipio, c.acta?.fecha && `Reunión: ${formatDate(c.acta.fecha)}`].filter(Boolean).join(' · ')}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {compromisos?.last_page > 1 && <div className="px-4 py-3 border-t border-[var(--color-line)]"><Pagination paginator={compromisos} label="Páginas de compromisos" only={['compromisos', 'filters']} /></div>}
                </section>
            </div>

            {open && (
                <CompromisoDetalle
                    compromisoId={open}
                    onClose={() => setOpen(null)}
                    onChanged={() => router.reload({ only: ['compromisos'], preserveScroll: true })}
                />
            )}
        </AppLayout>
    );
}
