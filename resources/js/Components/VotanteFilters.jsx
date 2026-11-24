import { useState, useRef, useEffect, useId } from 'react';
import { useCampaign } from '@/lib/campaign';
import { formatEventDate } from '@/lib/eventos';
import { eventOptionLabel } from '@/lib/eventosIntegracion';
import { catalogoDe, useCatalogos } from '@/lib/catalogos';
import { isOn } from '@/lib/votantesFiltros';
import { SIN_COMUNA, gruposComunas } from '@/lib/comunas';


// lideres.migracion_flags: the Electo import and the fichas created by an acta (cédula, teléfono, municipio).
const POR_COMPLETAR_OPTIONS = [
    { value: '', label: 'Sin filtro' },
    { value: 'cualquiera', label: 'Cualquier dato' },
    { value: 'cedula', label: 'Cédula' },
    { value: 'telefono', label: 'Teléfono' },
    { value: 'barrio', label: 'Barrio' },
    { value: 'nivel_confianza', label: 'Nivel de confianza' },
    { value: 'convenio', label: 'Convenio' },
    { value: 'tipo_hoja_vida', label: 'Tipo de hoja de vida' },
    { value: 'municipio', label: 'Municipio' },
    { value: 'fecha_nacimiento', label: 'Fecha de nacimiento' },
];

const IMPORTADOS_INACTIVOS_OPTIONS = [
    { value: '', label: 'Sin filtro' },
    { value: 'todos', label: 'Todos' },
    { value: 'menor', label: 'Menores de edad' },
    { value: 'fallecido', label: 'Fallecidos' },
    { value: 'sin_municipio', label: 'Sin municipio' },
];

// The same filter bar as Mapa Político and Eventos (search always at hand, the rest folds on a phone), with big
// controls: 16px text and 48px high for older users.
const inputCls = 'w-full min-h-[48px] border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white';
const labelCls = 'block text-[14px] font-bold text-[var(--color-ink-soft)] mb-1';
const checkCls = 'w-5 h-5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] flex-shrink-0';
const checkLabelCls = 'flex items-center gap-2.5 min-h-[48px] px-3 rounded-lg border border-[var(--color-line)] bg-white cursor-pointer';
const checkTextCls = 'text-[16px] text-[var(--color-ink)]';

// The active event filter as a chip, visible even with the (mobile) filters panel closed.
export function EventFilterChips({ filters, eventos = [], onRemove }) {
    const chips = [];
    if (filters?.evento) {
        const evento = eventos.find(e => e.id === filters.evento);
        chips.push({ key: 'evento', label: `Asistió a: ${evento?.nombre ?? 'un evento'}` });
    } else if (filters?.asistentes_eventos) {
        chips.push({ key: 'asistentes_eventos', label: 'Asistentes a eventos' });
    }
    if (!chips.length) return null;

    return (
        <div className="flex flex-wrap gap-2 mb-3" aria-label="Filtros de eventos activos">
            {chips.map(c => (
                <span key={c.key} className="inline-flex items-center gap-1 pl-3 pr-1 min-h-[40px] rounded-full bg-orange-100 text-orange-900 text-[14px] font-semibold">
                    {c.label}
                    <button type="button" onClick={() => onRemove(c.key)} aria-label={`Quitar filtro ${c.label}`} className="w-9 h-9 flex items-center justify-center rounded-full hover:bg-orange-200">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </span>
            ))}
        </div>
    );
}

export default function VotanteFilters({ filters, municipios, comunasPorMunicipio = {}, onApply, onClear, eventos = [], canViewEventos = false, campanasActivas = false }) {
    const uid = useId();
    const id = (name) => `${uid}-${name}`;
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState(filters.search || '');
    const [municipio, setMunicipio] = useState(filters.municipio || '');
    const [comuna, setComuna] = useState(filters.comuna || '');
    const [partido, setPartido] = useState(filters.partido || '');
    const [nivelConfianza, setNivelConfianza] = useState(filters.nivel_confianza || '');
    const [estado, setEstado] = useState(filters.estado || 'activos');
    const [verificado, setVerificado] = useState(isOn(filters.verificado));
    const [granElector, setGranElector] = useState(isOn(filters.gran_elector));
    const [conReferidos, setConReferidos] = useState(isOn(filters.con_referidos));
    // The KPI cards change these two from outside the bar: the checkboxes follow the URL.
    useEffect(() => { setGranElector(isOn(filters.gran_elector)); }, [filters.gran_elector]);
    useEffect(() => { setConReferidos(isOn(filters.con_referidos)); }, [filters.con_referidos]);
    // «Marcados como líder» (Campañas electorales module): also a KPI card.
    const [esLider, setEsLider] = useState(isOn(filters.es_lider));
    useEffect(() => { setEsLider(isOn(filters.es_lider)); }, [filters.es_lider]);
    const [militante, setMilitante] = useState(isOn(filters.militante));
    const [campana, setCampana] = useState(filters.campana || '');
    const [evento, setEvento] = useState(filters.evento || '');
    const [asistentesEventos, setAsistentesEventos] = useState(isOn(filters.asistentes_eventos));
    const [porCompletar, setPorCompletar] = useState(filters.por_completar || '');
    // The select follows the URL (back/forward, a link with ?por_completar=…).
    useEffect(() => { setPorCompletar(filters.por_completar || ''); }, [filters.por_completar]);
    const [diferencias, setDiferencias] = useState(isOn(filters.diferencias));
    const [importadosInactivos, setImportadosInactivos] = useState(filters.importados_inactivos || '');
    const campaign = useCampaign();
    // Every nivel, also the switched off ones: old fichas may still have them.
    const nivelOptions = [{ value: '', label: 'Todos' }, ...catalogoDe(useCatalogos(), 'nivel_confianza').map(o => ({ value: o.valor, label: o.etiqueta }))];
    const [munSearch, setMunSearch] = useState('');
    const [munOpen, setMunOpen] = useState(false);
    const munRef = useRef(null);
    const debounceRef = useRef(null);
    // Only a municipio with comunas (Bucaramanga) offers the filter; another municipio drops the chosen comuna.
    const comunasDelMunicipio = (municipio && comunasPorMunicipio[municipio]) || [];

    function elegirMunicipio(nombre) {
        if (nombre !== municipio) setComuna('');
        setMunicipio(nombre);
        setMunOpen(false);
        setMunSearch('');
    }

    useEffect(() => {
        function handleClick(e) {
            if (munRef.current && !munRef.current.contains(e.target)) setMunOpen(false);
        }
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, []);

    const handleApplyRef = useRef(null);
    handleApplyRef.current = (overrides) => handleApply(overrides);

    useEffect(() => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            if (search !== (filters.search || '')) {
                handleApplyRef.current({ search });
            }
        }, 400);
        return () => clearTimeout(debounceRef.current);
    }, [search]);

    function handleApply(overrides = {}) {
        const vals = {
            search, municipio, partido,
            ...(comuna && comunasDelMunicipio.length ? { comuna } : {}),
            nivel_confianza: nivelConfianza,
            estado,
            ...(campaign.hasMultiple && campana ? { campana } : {}),
            ...(verificado ? { verificado: '1' } : {}),
            ...(granElector ? { gran_elector: '1' } : {}),
            ...(conReferidos ? { con_referidos: '1' } : {}),
            ...(campanasActivas && esLider ? { es_lider: '1' } : {}),
            ...(militante ? { militante: '1' } : {}),
            ...(canViewEventos && evento ? { evento } : {}),
            ...(canViewEventos && !evento && asistentesEventos ? { asistentes_eventos: '1' } : {}),
            ...(porCompletar ? { por_completar: porCompletar } : {}),
            ...(diferencias ? { diferencias: '1' } : {}),
            ...(importadosInactivos ? { importados_inactivos: importadosInactivos } : {}),
            ...overrides,
        };
        onApply(vals);
        setOpen(false);
    }

    function handleClear() {
        setSearch('');
        setMunicipio('');
        setComuna('');
        setPartido('');
        setNivelConfianza('');
        setEstado('activos');
        setVerificado(false);
        setGranElector(false);
        setConReferidos(false);
        setEsLider(false);
        setMilitante(false);
        setCampana('');
        setEvento('');
        setAsistentesEventos(false);
        setPorCompletar('');
        setDiferencias(false);
        setImportadosInactivos('');
        setOpen(false);
        onClear();
    }

    const filteredMunicipios = municipios.filter(m =>
        !munSearch || m.name.toLowerCase().includes(munSearch.toLowerCase())
    );

    const selectedMunName = municipios.find(m => m.name === municipio)?.name || '';

    // What the URL filters by (the search has its own box), for the badge of the folded Filtros button.
    const activeCount = ['municipio', 'comuna', 'partido', 'nivel_confianza', 'campana', 'evento', 'asistentes_eventos', 'por_completar',
        'importados_inactivos', 'diferencias', 'verificado', 'gran_elector', 'con_referidos', 'es_lider', 'militante'].filter(k => filters[k]).length
        + (filters.estado && filters.estado !== 'activos' ? 1 : 0);

    return (
        <div className="bg-white rounded-xl border border-[var(--color-line)] px-3 lg:px-4 py-3 space-y-3">
            <form onSubmit={e => { e.preventDefault(); handleApply(); }} className="flex items-center gap-2" role="search">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    aria-controls={id('panel')}
                    className="lg:hidden flex items-center gap-2 px-3 min-h-[48px] bg-[var(--color-primary)] text-white rounded-lg text-[16px] font-bold flex-shrink-0"
                >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
                    Filtros
                    {activeCount > 0 && <span className="min-w-[22px] h-[22px] px-1.5 bg-amber-400 text-[var(--color-primary)] rounded-full text-[13px] font-extrabold flex items-center justify-center">{activeCount}</span>}
                    <svg className={`w-4 h-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <label htmlFor={id('search')} className="sr-only">Buscar por nombre o cédula</label>
                <div className="relative flex-1 min-w-0 lg:max-w-[440px]">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" strokeLinecap="round" /></svg>
                    <input
                        id={id('search')}
                        type="text"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Nombre o cédula"
                        className={inputCls + ' pl-10'}
                    />
                </div>
            </form>

            <div id={id('panel')} className={`${open ? 'grid' : 'hidden'} lg:grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-3 items-end`}>
                {/* Municipio, with its own search: there are 87 and more outside Santander */}
                <div ref={munRef} className="relative">
                    <span id={id('mun-label')} className={labelCls}>Municipio</span>
                    <button
                        type="button"
                        onClick={() => setMunOpen(!munOpen)}
                        aria-haspopup="listbox"
                        aria-expanded={munOpen}
                        aria-labelledby={id('mun-label')}
                        className={inputCls + ' text-left flex items-center justify-between gap-2'}
                    >
                        <span className={`truncate ${selectedMunName ? 'text-[var(--color-ink)]' : 'text-gray-500'}`}>
                            {selectedMunName || 'Todos'}
                        </span>
                        <svg className="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    {munOpen && (
                        <div className="absolute left-0 right-0 lg:right-auto lg:w-[320px] top-full mt-1 bg-white border border-[var(--color-line)] rounded-xl shadow-xl z-30 overflow-hidden">
                            <div className="p-2 border-b border-[var(--color-line)]">
                                <input
                                    type="text"
                                    value={munSearch}
                                    onChange={e => setMunSearch(e.target.value)}
                                    placeholder="Buscar municipio"
                                    aria-label="Buscar municipio"
                                    className={inputCls}
                                />
                            </div>
                            <div className="max-h-64 overflow-y-auto" role="listbox" aria-labelledby={id('mun-label')}>
                                <button
                                    type="button"
                                    onClick={() => elegirMunicipio('')}
                                    className="w-full text-left px-4 min-h-[44px] text-[16px] text-gray-500 hover:bg-gray-50"
                                >
                                    Todos
                                </button>
                                {filteredMunicipios.map(m => (
                                    <button
                                        type="button"
                                        key={m.id}
                                        onClick={() => elegirMunicipio(m.name)}
                                        className={`w-full text-left px-4 min-h-[44px] text-[16px] hover:bg-blue-50 transition-colors ${municipio === m.name ? 'bg-blue-50 text-[var(--color-primary)] font-semibold' : 'text-[var(--color-ink)]'}`}
                                    >
                                        {m.name}
                                        {m.provincia && <span className="text-[14px] text-[var(--color-ink-faint)] ml-1">({m.provincia})</span>}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {comunasDelMunicipio.length > 0 && (
                    <div>
                        <label htmlFor={id('comuna')} className={labelCls}>Comuna</label>
                        <select id={id('comuna')} value={comuna} onChange={e => setComuna(e.target.value)} className={inputCls}>
                            <option value="">Todas</option>
                            <option value={SIN_COMUNA}>Sin comuna</option>
                            {gruposComunas(comunasDelMunicipio).map(([label, list]) => (
                                <optgroup key={label} label={label}>
                                    {list.map(c => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                                </optgroup>
                            ))}
                        </select>
                    </div>
                )}

                <div>
                    <label htmlFor={id('partido')} className={labelCls}>Partido</label>
                    <input
                        id={id('partido')}
                        type="text"
                        value={partido}
                        onChange={e => setPartido(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter') handleApply(); }}
                        placeholder="Nombre del partido"
                        className={inputCls}
                    />
                </div>

                <div>
                    <label htmlFor={id('nivel')} className={labelCls}>Nivel de confianza</label>
                    <select id={id('nivel')} value={nivelConfianza} onChange={e => setNivelConfianza(e.target.value)} className={inputCls}>
                        {nivelOptions.map(o => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label htmlFor={id('estado')} className={labelCls}>Estado</label>
                    <select id={id('estado')} value={estado} onChange={e => setEstado(e.target.value)} className={inputCls}>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Inactivos</option>
                        <option value="todos">Todos</option>
                    </select>
                </div>

                {campaign.hasMultiple && (
                    <div>
                        <label htmlFor={id('campana')} className={labelCls}>Grupo político</label>
                        <select id={id('campana')} value={campana} onChange={e => setCampana(e.target.value)} className={inputCls}>
                            <option value="">Todas</option>
                            {campaign.list.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                )}

                {canViewEventos && (
                    <div>
                        <label htmlFor={id('evento')} className={labelCls}>Asistió al evento…</label>
                        <select id={id('evento')} value={evento} onChange={e => setEvento(e.target.value)} className={inputCls}>
                            <option value="">Cualquiera o ninguno</option>
                            {eventos.map(e => <option key={e.id} value={e.id}>{eventOptionLabel(e, iso => formatEventDate(iso, { withTime: false }))}</option>)}
                        </select>
                    </div>
                )}

                {/* Electo import */}
                <div>
                    <label htmlFor={id('por-completar')} className={labelCls}>Datos por completar</label>
                    <select id={id('por-completar')} value={porCompletar} onChange={e => setPorCompletar(e.target.value)} className={inputCls}>
                        {POR_COMPLETAR_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor={id('importados-inactivos')} className={labelCls}>Inactivos importados</label>
                    <select id={id('importados-inactivos')} value={importadosInactivos} onChange={e => setImportadosInactivos(e.target.value)} className={inputCls}>
                        {IMPORTADOS_INACTIVOS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                </div>

                <div className="col-span-full flex flex-wrap items-center gap-2">
                    {canViewEventos && (
                        <label className={checkLabelCls}>
                            <input type="checkbox" checked={asistentesEventos || !!evento} disabled={!!evento} onChange={e => setAsistentesEventos(e.target.checked)} className={checkCls} />
                            <span className={checkTextCls}>Asistentes a eventos (cualquiera)</span>
                        </label>
                    )}
                    <label className={checkLabelCls}>
                        <input type="checkbox" checked={diferencias} onChange={e => setDiferencias(e.target.checked)} className={checkCls} />
                        <span className={checkTextCls}>Diferencias con Electo</span>
                    </label>
                    <label className={checkLabelCls}>
                        <input type="checkbox" checked={verificado} onChange={e => setVerificado(e.target.checked)} className={checkCls} />
                        <span className={checkTextCls}>Verificado</span>
                    </label>
                    <label className={checkLabelCls}>
                        <input type="checkbox" checked={granElector} onChange={e => setGranElector(e.target.checked)} className={checkCls} />
                        <span className={checkTextCls}>Gran Elector</span>
                    </label>
                    <label className={checkLabelCls}>
                        <input type="checkbox" checked={conReferidos} onChange={e => setConReferidos(e.target.checked)} className={checkCls} />
                        <span className={checkTextCls}>Con referidos</span>
                    </label>
                    {campanasActivas && (
                        <label className={checkLabelCls}>
                            <input type="checkbox" checked={esLider} onChange={e => setEsLider(e.target.checked)} className={checkCls} />
                            <span className={checkTextCls}>Marcados como líder</span>
                        </label>
                    )}
                    <label className={checkLabelCls}>
                        <input type="checkbox" checked={militante} onChange={e => setMilitante(e.target.checked)} className={checkCls} />
                        <span className={checkTextCls}>Militante</span>
                    </label>

                    <div className="flex gap-2 w-full sm:w-auto sm:ml-auto">
                        <button
                            type="button"
                            onClick={() => handleApply()}
                            className="flex-1 sm:flex-initial min-h-[48px] px-6 bg-[var(--color-primary)] text-white rounded-lg text-[16px] font-bold hover:bg-[var(--color-primary-light)] transition-colors"
                        >
                            Aplicar
                        </button>
                        <button
                            type="button"
                            onClick={handleClear}
                            className="flex-1 sm:flex-initial min-h-[48px] px-5 border border-[var(--color-line)] rounded-lg text-[16px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors"
                        >
                            Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
