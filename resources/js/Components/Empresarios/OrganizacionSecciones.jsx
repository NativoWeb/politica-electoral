import { useState } from 'react';
import CampaignChip from '@/Components/CampaignChip';
import { groupRows, membersLabel } from '@/lib/empresarios';

const Chevron = ({ open }) => (
    <svg className={`w-5 h-5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
);

function OrgRow({ row, onSelect }) {
    return (
        <li>
            <button type="button" onClick={() => onSelect(row.id)} className={`w-full flex items-center gap-3 px-4 py-3 border-b border-[var(--color-line)] last:border-0 text-left hover:bg-blue-50 ${row.is_active ? '' : 'opacity-60'}`}>
                <span className="flex-1 min-w-0">
                    <span className="flex items-center gap-2">
                        <span className="text-[15px] font-bold text-[var(--color-primary)] uppercase truncate">{row.nombre}</span>
                        <CampaignChip campaignId={row.campaign_id} />
                        {!row.is_active && <span className="px-1.5 py-0.5 rounded bg-gray-200 text-[12px] font-bold text-gray-700">Inactiva</span>}
                    </span>
                    <span className="block text-[14px] text-[var(--color-ink-soft)] truncate">
                        {[row.municipio, row.sector, row.representante && `Repr.: ${row.representante}`].filter(Boolean).join(' · ') || 'Sin datos de ubicación'}
                    </span>
                </span>
                {row.celular && <span className="hidden sm:inline text-[14px] font-semibold text-[var(--color-primary)] flex-shrink-0">{row.celular}</span>}
                <span className="text-[14px] font-bold text-[var(--color-ink)] flex-shrink-0">{membersLabel(row.miembros)}</span>
            </button>
        </li>
    );
}

function SectorAccordion({ sector, rows, onSelect, defaultOpen }) {
    const [open, setOpen] = useState(defaultOpen);
    const miembros = rows.reduce((s, r) => s + (r.miembros || 0), 0);

    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden mb-2 bg-white">
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="w-full flex items-center gap-3 px-4 py-3 min-h-[56px] hover:bg-gray-50">
                <span className="w-1 h-9 rounded-full bg-[var(--color-primary)]/40 flex-shrink-0" />
                <span className="flex-1 text-left">
                    <span className="block text-[15px] font-bold text-[var(--color-ink)] uppercase">{sector}</span>
                    <span className="block text-[13px] text-[var(--color-ink-faint)]">{rows.length} {rows.length === 1 ? 'organización' : 'organizaciones'} · {membersLabel(miembros)}</span>
                </span>
                <Chevron open={open} />
            </button>
            {open && <ul className="border-t border-[var(--color-line)]" aria-label={`Organizaciones de ${sector}`}>{rows.map(r => <OrgRow key={r.id} row={r} onSelect={onSelect} />)}</ul>}
        </div>
    );
}

function TipoSection({ tipo, sectors, onSelect, defaultOpen }) {
    const [open, setOpen] = useState(defaultOpen);
    const total = sectors.reduce((s, [, r]) => s + r.length, 0);
    const color = tipo.value === 'gremio' ? 'bg-teal-100 text-teal-800' : 'bg-blue-100 text-blue-800';

    return (
        <section className="border border-[var(--color-line)] rounded-xl overflow-hidden bg-white" aria-label={tipo.label}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="w-full flex items-center gap-4 px-5 py-4 hover:bg-gray-50">
                <span className="flex-1 text-left">
                    <span className="block text-[17px] font-extrabold text-[var(--color-ink)] uppercase">{tipo.label}</span>
                    <span className="block text-[13px] text-[var(--color-ink-faint)]">{total} {total === 1 ? 'registro' : 'registros'} · {sectors.length} {sectors.length === 1 ? 'sector económico' : 'sectores económicos'}</span>
                </span>
                <span className={`px-3 py-1 rounded-full text-[14px] font-bold ${color}`}>{total}</span>
                <Chevron open={open} />
            </button>
            {open && (
                <div className="border-t border-[var(--color-line)] px-3 py-3">
                    {total === 0
                        ? <p className="px-2 py-3 text-[15px] text-[var(--color-ink-faint)]">No hay {tipo.label.toLowerCase()} con este filtro.</p>
                        : sectors.map(([sector, rows]) => <SectorAccordion key={sector} sector={sector} rows={rows} onSelect={onSelect} defaultOpen={defaultOpen && sectors.length === 1} />)}
                </div>
            )}
        </section>
    );
}

// Results grouped as in Mapa Político: a collapsible section per tipo (Empresas, Gremios) with an accordion per
// sector económico. With a search or a single tipo, everything opens.
export default function OrganizacionSecciones({ rows, onSelect, openAll = false }) {
    return (
        <div className="space-y-3">
            {groupRows(rows).map(([tipo, sectors]) => <TipoSection key={tipo.value} tipo={tipo} sectors={sectors} onSelect={onSelect} defaultOpen={openAll} />)}
        </div>
    );
}
