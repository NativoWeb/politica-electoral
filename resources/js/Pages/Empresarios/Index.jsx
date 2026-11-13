import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import SearchableDropdown from '@/Components/SearchableDropdown';
import OrganizacionSecciones from '@/Components/Empresarios/OrganizacionSecciones';
import OrganizacionPanel from '@/Components/Empresarios/OrganizacionPanel';
import CatalogosModal from '@/Components/Empresarios/CatalogosModal';
import { failure, request } from '@/lib/actas';
import { filtersQuery } from '@/lib/empresarios';
import { useCampaign } from '@/lib/campaign';

const AREA_METROPOLITANA = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Girón', 'Rionegro', 'Lebrija'];

// Empresarios y gremios: filters on top, results grouped by tipo and sector económico, ficha in a modal.
export default function EmpresariosIndex({ data = [], truncated, filters = {}, municipios = [], provincias = [], catalogos: initialCatalogos = {}, gremios = [], roles = [], rangos = [], can = {} }) {
    const campaign = useCampaign();
    const [search, setSearch] = useState(filters.buscar ?? '');
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [open, setOpen] = useState(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('abrir')));
    const [creating, setCreating] = useState(false);
    const [catalogos, setCatalogos] = useState(initialCatalogos);
    const [showCatalogos, setShowCatalogos] = useState(false);
    const [uploadError, setUploadError] = useState('');
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef(null);

    function apply(overrides = {}) {
        const params = { ...filters, buscar: search || null, ...overrides };
        Object.keys(params).forEach(k => { if (params[k] === null || params[k] === undefined || params[k] === '') delete params[k]; });
        router.get('/empresarios', params, { preserveState: true, preserveScroll: true, replace: true, only: ['data', 'filters', 'truncated'] });
    }
    const reload = () => router.reload({ only: ['data', 'gremios', 'truncated'], preserveScroll: true });

    const munOptions = filters.provincia
        ? municipios.filter(m => (filters.provincia === 'Área Metropolitana' ? AREA_METROPOLITANA.includes(m.name) : m.provincia === filters.provincia))
        : municipios;
    const nameOf = (list, id) => list.find(x => x.id === id);
    const activeCount = ['provincia', 'municipio', 'tipo', 'sector_economico', 'sector', 'gremio', 'buscar', 'estado', 'campana'].filter(k => filters[k]).length;
    const openAll = Boolean(filters.buscar || filters.tipo || filters.gremio || filters.sector_economico);

    async function upload(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        setUploadError('');
        setUploading(true);
        const form = new FormData();
        form.append('archivo', file);
        const res = await request('/empresarios/carga', { method: 'POST', form });
        setUploading(false);
        if (!res.ok) { setUploadError(res.body?.errors?.archivo?.[0] ?? failure(res, 'No se pudo leer el archivo.')); return; }
        router.visit(res.body.data.url);
    }

    const dropdown = (label, options, selectedId, key, list) => (
        <SearchableDropdown
            label={label}
            options={options.map(o => o.nombre)}
            selected={selectedId ? nameOf(list, selectedId)?.nombre : undefined}
            onChange={val => apply({ [key]: list.find(o => o.nombre === val)?.id ?? null })}
            multi={false}
            placeholder="Todos"
        />
    );

    return (
        <AppLayout title="Empresarios y gremios" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'EMPRESARIOS' }]}>
            <Head title="Empresarios y gremios — Inteligencia Electoral" />
            <div className="sticky top-0 z-20 bg-white shadow-sm">
                <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-2 lg:py-4 flex flex-wrap items-center justify-between gap-2">
                    <div className="min-w-0">
                        <h1 className="text-[18px] lg:text-[22px] font-extrabold">EMPRESARIOS Y GREMIOS</h1>
                        <p className="hidden lg:block text-[13px] text-white/60 mt-0.5">Empresas, gremios, sus miembros y las actas de reunión</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <a href={`/empresarios/exportar.xlsx${filtersQuery(filters)}`} className="min-h-[44px] px-3 py-2 bg-white/15 border border-white/20 rounded-lg text-[14px] font-bold flex items-center">Excel</a>
                        <a href={`/empresarios/exportar.pdf${filtersQuery(filters)}`} className="min-h-[44px] px-3 py-2 bg-white/15 border border-white/20 rounded-lg text-[14px] font-bold flex items-center">PDF</a>
                        {can.manage && (
                            <>
                                <button type="button" onClick={() => setShowCatalogos(true)} className="min-h-[44px] px-3 py-2 bg-white/15 border border-white/20 rounded-lg text-[14px] font-bold">Sectores</button>
                                <a href="/empresarios/plantilla.xlsx" className="min-h-[44px] px-3 py-2 bg-white/15 border border-white/20 rounded-lg text-[14px] font-bold flex items-center">Plantilla</a>
                                <input ref={fileRef} id="empresarios-excel" type="file" accept=".xlsx,.xls,.csv" className="sr-only" onChange={upload} />
                                <label htmlFor="empresarios-excel" className={`min-h-[44px] px-3 py-2 bg-white/15 border border-white/20 rounded-lg text-[14px] font-bold flex items-center cursor-pointer ${uploading ? 'opacity-60 pointer-events-none' : ''}`}>
                                    {uploading ? 'Leyendo…' : 'Cargar Excel'}
                                </label>
                                <button type="button" onClick={() => setCreating(true)} className="min-h-[44px] px-4 py-2 bg-white text-[var(--color-primary)] rounded-lg text-[14px] font-extrabold">+ Nueva</button>
                            </>
                        )}
                    </div>
                </div>

                <div className="bg-white border-b border-[var(--color-line)] px-4 lg:px-5 py-2 lg:py-3 space-y-2">
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={() => setFiltersOpen(!filtersOpen)} aria-expanded={filtersOpen} className="lg:hidden min-h-[44px] px-3 bg-[var(--color-primary)] text-white rounded-lg text-[14px] font-bold flex-shrink-0">
                            Filtros{activeCount > 0 ? ` (${activeCount})` : ''}
                        </button>
                        <input type="search" value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') apply(); }} placeholder="Buscar nombre, NIT o representante…" aria-label="Buscar" className="flex-1 min-w-0 min-h-[44px] text-[15px] border border-[var(--color-line)] rounded-lg px-3 focus:outline-none focus:border-[var(--color-primary)]" />
                        <button type="button" onClick={() => apply()} className="min-h-[44px] px-4 bg-[var(--color-primary)] text-white rounded-lg text-[14px] font-bold flex-shrink-0">Buscar</button>
                    </div>
                    <div className={`${filtersOpen ? 'grid' : 'hidden'} lg:flex grid-cols-2 lg:flex-wrap items-end gap-2 lg:gap-3`}>
                        <SearchableDropdown label="Provincia" options={provincias} selected={filters.provincia || undefined} onChange={val => apply({ provincia: val || null, municipio: null })} multi={false} placeholder="Todas" />
                        <SearchableDropdown
                            label="Municipio"
                            options={munOptions.map(m => m.name)}
                            selected={filters.municipio ? municipios.find(m => m.id === filters.municipio)?.name : undefined}
                            onChange={val => apply({ municipio: munOptions.find(m => m.name === val)?.id ?? null })}
                            multi={false}
                            placeholder="Todos"
                        />
                        <SearchableDropdown label="Tipo" options={['Empresas', 'Gremios']} selected={filters.tipo ? (filters.tipo === 'gremio' ? 'Gremios' : 'Empresas') : undefined} onChange={val => apply({ tipo: val === 'Gremios' ? 'gremio' : val === 'Empresas' ? 'empresa' : null })} multi={false} placeholder="Todos" />
                        {dropdown('Sector económico', catalogos.sector_economico ?? [], filters.sector_economico, 'sector_economico', catalogos.sector_economico ?? [])}
                        {dropdown('Sector', catalogos.sector ?? [], filters.sector, 'sector', catalogos.sector ?? [])}
                        {dropdown('Gremio', gremios, filters.gremio, 'gremio', gremios)}
                        <SearchableDropdown label="Estado" options={['Inactivas', 'Activas e inactivas']} selected={filters.estado === 'inactivos' ? 'Inactivas' : filters.estado === 'todos' ? 'Activas e inactivas' : undefined} onChange={val => apply({ estado: val === 'Inactivas' ? 'inactivos' : val ? 'todos' : null })} multi={false} placeholder="Activas" />
                        {campaign.hasMultiple && (
                            <SearchableDropdown label="Grupo político" options={campaign.list.map(c => c.name)} selected={filters.campana ? campaign.nameOf(filters.campana) : undefined} onChange={val => apply({ campana: campaign.list.find(c => c.name === val)?.id ?? null })} multi={false} placeholder="Todos" />
                        )}
                        {activeCount > 0 && (
                            <button type="button" onClick={() => { setSearch(''); router.get('/empresarios', {}, { preserveState: false }); }} className="min-h-[44px] px-3 text-[14px] font-semibold text-red-700 underline">Quitar filtros</button>
                        )}
                    </div>
                </div>
            </div>

            <div className="p-4 lg:px-6 space-y-3">
                {uploadError && <p role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{uploadError}</p>}
                {truncated && <p role="status" className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-[15px] text-amber-900">Se muestran las primeras 2000. Use los filtros o descargue el Excel para verlas todas.</p>}
                <p className="text-[14px] text-[var(--color-ink-soft)]">{data.length} {data.length === 1 ? 'organización' : 'organizaciones'}</p>
                {data.length === 0
                    ? <p className="px-4 py-10 text-center text-[16px] text-[var(--color-ink-faint)] bg-white rounded-xl border border-[var(--color-line)]">{activeCount ? 'No hay organizaciones con ese filtro.' : 'Todavía no hay empresas ni gremios. Use «+ Nueva» o «Cargar Excel».'}</p>
                    : <OrganizacionSecciones rows={data} onSelect={setOpen} openAll={openAll} />}
            </div>

            {(open || creating) && (
                <OrganizacionPanel
                    key={open ?? 'nueva'}
                    orgId={creating ? null : open}
                    canManage={!!can.manage}
                    catalogos={catalogos}
                    municipios={municipios}
                    gremios={gremios}
                    rangos={rangos}
                    roles={roles}
                    onClose={() => { setOpen(null); setCreating(false); }}
                    onOpen={id => { setCreating(false); setOpen(id); }}
                    onSaved={org => { reload(); if (creating) { setCreating(false); setOpen(org.id); } }}
                    onDeleted={() => { setOpen(null); reload(); }}
                />
            )}
            {showCatalogos && <CatalogosModal catalogos={catalogos} sharedAllowed={!!can.shared_catalog} onClose={() => setShowCatalogos(false)} onChanged={setCatalogos} />}
        </AppLayout>
    );
}
