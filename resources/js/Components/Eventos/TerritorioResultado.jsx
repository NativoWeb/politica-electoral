import CampaignChip from '@/Components/CampaignChip';
import { LinkStatusBadge, StatusBadge } from '@/Components/Eventos/EventoBadges';
import Pagination from '@/Components/Eventos/Pagination';
import { formatEventDate } from '@/lib/eventos';
import { SOURCE_LABELS, territoryExcelUrl } from '@/lib/eventosIntegracion';

const ONLY = ['resultado', 'filters'];

function Kpi({ label, value }) {
    return (
        <div className="bg-white border border-[var(--color-line)] rounded-xl px-4 py-3">
            <p className="text-[26px] font-extrabold text-[var(--color-ink)] leading-none">{(value ?? 0).toLocaleString('es-CO')}</p>
            <p className="text-[14px] font-semibold text-[var(--color-ink-soft)] mt-1">{label}</p>
        </div>
    );
}

function Section({ id, title, count, children, footer }) {
    return (
        <section aria-labelledby={id} className="bg-white border border-[var(--color-line)] rounded-2xl overflow-hidden">
            <h2 id={id} className="px-4 py-3 border-b border-[var(--color-line)] text-[17px] font-extrabold text-[var(--color-ink)]">
                {title} ({(count ?? 0).toLocaleString('es-CO')})
            </h2>
            {children}
            {footer && <div className="px-4 py-3 border-t border-[var(--color-line)]">{footer}</div>}
        </section>
    );
}

function Empty({ children }) {
    return <p className="px-4 py-6 text-center text-[16px] text-[var(--color-ink-faint)]">{children}</p>;
}

// Lideres, events and attendees of one municipio (and barrio) with their totals and the Excel of the three lists.
export default function TerritorioResultado({ resultado, filters, lugar }) {
    const { kpis, lideres, eventos = [], asistentes } = resultado;
    const conBarrio = !!filters?.barrio;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-[17px] font-bold text-[var(--color-ink)]">{lugar}</p>
                <a href={territoryExcelUrl(filters)} className="min-h-[48px] px-4 py-2.5 rounded-xl bg-[var(--color-primary)] text-white text-[16px] font-bold flex items-center">
                    Descargar Excel
                </a>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <Kpi label="Líderes" value={kpis.lideres} />
                <Kpi label={conBarrio ? 'Eventos en el barrio' : 'Eventos'} value={kpis.eventos} />
                <Kpi label="Registros de asistencia" value={kpis.asistentes} />
                <Kpi label="Personas distintas" value={kpis.personas} />
                <Kpi label="Ya en Votantes" value={kpis.en_votantes} />
            </div>

            <Section
                id="t-eventos"
                title={conBarrio ? 'Eventos realizados en el barrio' : 'Eventos realizados'}
                count={kpis.eventos}
                footer={eventos.length < (kpis.eventos ?? 0) ? <p className="text-[15px] text-[var(--color-ink-soft)]">Se muestran los {eventos.length} más recientes. El Excel trae todos.</p> : null}
            >
                {eventos.length > 0 ? (
                    <ul className="divide-y divide-[var(--color-line)]" aria-label="Eventos del territorio">
                        {eventos.map(e => (
                            <li key={e.id} className="px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={e.status} />
                                        {e.tipo && <span className="text-[14px] font-semibold text-[var(--color-ink-soft)]">{e.tipo}</span>}
                                        <CampaignChip campaignId={e.campaign_id} />
                                    </div>
                                    <a href={e.url} className="block mt-1 text-[16px] font-extrabold text-[var(--color-primary)] underline">{e.nombre}</a>
                                    <p className="text-[15px] text-[var(--color-ink)] first-letter:uppercase">{formatEventDate(e.starts_at)}</p>
                                </div>
                                <p className="text-[16px] font-bold text-[var(--color-ink)]">
                                    {e.asistentes === 1 ? '1 asistente' : `${e.asistentes} asistentes`}
                                    {e.asistentes_barrio !== null && e.asistentes_barrio !== undefined && (
                                        <span className="block text-[14px] font-semibold text-[var(--color-ink-soft)]">{e.asistentes_barrio} del barrio</span>
                                    )}
                                </p>
                            </li>
                        ))}
                    </ul>
                ) : <Empty>{conBarrio ? 'No hay eventos registrados en este barrio.' : 'No hay eventos registrados en este municipio.'}</Empty>}
            </Section>

            <Section
                id="t-asistentes"
                title="Asistentes"
                count={asistentes?.total}
                footer={asistentes?.last_page > 1 ? <Pagination paginator={asistentes} label="Páginas de asistentes" only={ONLY} /> : null}
            >
                {asistentes?.data?.length > 0 ? (
                    <ul className="divide-y divide-[var(--color-line)]" aria-label="Asistentes del territorio">
                        {asistentes.data.map(a => (
                            <li key={a.id} className="px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[16px] font-bold text-[var(--color-ink)]">{`${a.nombres} ${a.apellidos}`}</span>
                                    <LinkStatusBadge status={a.link_status} />
                                    <CampaignChip campaignId={a.campaign_id} />
                                </div>
                                <p className="text-[15px] text-[var(--color-ink-soft)]">
                                    {[a.cedula && `C.C. ${a.cedula}`, a.celular, a.barrio && `Barrio ${a.barrio}`, SOURCE_LABELS[a.source]].filter(Boolean).join(' · ')}
                                </p>
                                <p className="text-[15px] text-[var(--color-ink-soft)]">
                                    Evento: <a href={`/eventos/${a.evento.id}`} className="font-semibold text-[var(--color-primary)] underline">{a.evento.nombre}</a>
                                </p>
                            </li>
                        ))}
                    </ul>
                ) : <Empty>{conBarrio ? 'Nadie de este barrio aparece en los eventos.' : 'Todavía no hay asistentes en los eventos de este municipio.'}</Empty>}
            </Section>

            <Section
                id="t-lideres"
                title="Líderes"
                count={lideres?.total}
                footer={lideres?.last_page > 1 ? <Pagination paginator={lideres} label="Páginas de líderes" only={ONLY} /> : null}
            >
                {lideres?.data?.length > 0 ? (
                    <ul className="divide-y divide-[var(--color-line)]" aria-label="Líderes del territorio">
                        {lideres.data.map(l => (
                            <li key={l.id} className="px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[16px] font-bold text-[var(--color-ink)]">{l.nombre}</span>
                                    <CampaignChip campaignId={l.campaign_id} />
                                </div>
                                <p className="text-[15px] text-[var(--color-ink-soft)]">
                                    {[l.cargo, l.partido, l.barrio && `Barrio ${l.barrio}`, l.telefono, l.cedula && `C.C. ${l.cedula}`].filter(Boolean).join(' · ')}
                                </p>
                            </li>
                        ))}
                    </ul>
                ) : <Empty>{conBarrio ? 'No hay líderes registrados en este barrio.' : 'No hay líderes registrados en este municipio.'}</Empty>}
            </Section>
        </div>
    );
}
