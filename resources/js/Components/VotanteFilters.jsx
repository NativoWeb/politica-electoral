import { useState, useRef, useEffect, useId } from 'react';
import { useCampaign } from '@/lib/campaign';
import { formatEventDate } from '@/lib/eventos';
import { eventOptionLabel } from '@/lib/eventosIntegracion';

const NIVEL_OPTIONS = [
    { value: '', label: 'Todos' },
    { value: 'sin_llamar', label: 'Sin llamar' },
    { value: 'contactado', label: 'Contactado' },
    { value: 'confirmado', label: 'Confirmado' },
    { value: 'comprometido', label: 'Comprometido' },
    { value: 'no_responde', label: 'No responde' },
];

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white";

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

export default function VotanteFilters({ filters, municipios, onApply, onClear, eventos = [], canViewEventos = false }) {
    // The panel is rendered twice (desktop and mobile): ids must be unique per instance.
    const uid = useId();
    const eventoId = `${uid}-evento`;
    const campanaId = `${uid}-campana`;
    const [search, setSearch] = useState(filters.search || '');
    const [municipio, setMunicipio] = useState(filters.municipio || '');
    const [partido, setPartido] = useState(filters.partido || '');
    const [nivelConfianza, setNivelConfianza] = useState(filters.nivel_confianza || '');
    const [estado, setEstado] = useState(filters.estado || 'activos');
    const [verificado, setVerificado] = useState(filters.verificado === '1' || filters.verificado === true);
    const [granElector, setGranElector] = useState(filters.gran_elector === '1' || filters.gran_elector === true);
    const [militante, setMilitante] = useState(filters.militante === '1' || filters.militante === true);
    const [campana, setCampana] = useState(filters.campana || '');
    const [evento, setEvento] = useState(filters.evento || '');
    const [asistentesEventos, setAsistentesEventos] = useState(filters.asistentes_eventos === '1' || filters.asistentes_eventos === true);
    const campaign = useCampaign();
    const [munSearch, setMunSearch] = useState('');
    const [munOpen, setMunOpen] = useState(false);
    const munRef = useRef(null);
    const debounceRef = useRef(null);

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
            nivel_confianza: nivelConfianza,
            estado,
            ...(campaign.hasMultiple && campana ? { campana } : {}),
            ...(verificado ? { verificado: '1' } : {}),
            ...(granElector ? { gran_elector: '1' } : {}),
            ...(militante ? { militante: '1' } : {}),
            ...(canViewEventos && evento ? { evento } : {}),
            ...(canViewEventos && !evento && asistentesEventos ? { asistentes_eventos: '1' } : {}),
            ...overrides,
        };
        onApply(vals);
    }

    function handleClear() {
        setSearch('');
        setMunicipio('');
        setPartido('');
        setNivelConfianza('');
        setEstado('activos');
        setVerificado(false);
        setGranElector(false);
        setMilitante(false);
        setCampana('');
        setEvento('');
        setAsistentesEventos(false);
        onClear();
    }

    const filteredMunicipios = municipios.filter(m =>
        !munSearch || m.name.toLowerCase().includes(munSearch.toLowerCase())
    );

    const selectedMunName = municipios.find(m => m.name === municipio)?.name || '';

    return (
        <div className="bg-white rounded-xl border border-[var(--color-line)] p-4 space-y-4">
            <h3 className="text-[13px] font-bold text-[var(--color-ink)] uppercase tracking-wider">Filtros</h3>

            {/* Search */}
            <div>
                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Buscar</label>
                <div className="relative">
                    <svg className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" strokeLinecap="round" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Nombre o cedula..."
                        className={inputCls + ' pl-8'}
                    />
                </div>
            </div>

            {/* Municipio searchable */}
            <div ref={munRef} className="relative">
                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Municipio</label>
                <button
                    type="button"
                    onClick={() => setMunOpen(!munOpen)}
                    className={inputCls + ' text-left flex items-center justify-between'}
                >
                    <span className={selectedMunName ? 'text-[var(--color-ink)]' : 'text-gray-400'}>
                        {selectedMunName || 'Todos'}
                    </span>
                    <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M19 9l-7 7-7-7" /></svg>
                </button>
                {munOpen && (
                    <div className="absolute left-0 right-0 top-full mt-1 bg-white border border-[var(--color-line)] rounded-lg shadow-xl z-30 max-h-60 overflow-hidden">
                        <div className="p-2 border-b border-[var(--color-line)]">
                            <input
                                type="text"
                                value={munSearch}
                                onChange={e => setMunSearch(e.target.value)}
                                placeholder="Buscar municipio..."
                                className={inputCls}
                                autoFocus
                            />
                        </div>
                        <div className="max-h-48 overflow-y-auto">
                            <button
                                onClick={() => { setMunicipio(''); setMunOpen(false); setMunSearch(''); }}
                                className="w-full text-left px-3 py-2 text-[13px] text-gray-400 hover:bg-gray-50"
                            >
                                Todos
                            </button>
                            {filteredMunicipios.map(m => (
                                <button
                                    key={m.id}
                                    onClick={() => { setMunicipio(m.name); setMunOpen(false); setMunSearch(''); }}
                                    className={`w-full text-left px-3 py-2 text-[13px] hover:bg-blue-50 transition-colors ${municipio === m.name ? 'bg-blue-50 text-[var(--color-primary)] font-semibold' : 'text-[var(--color-ink)]'}`}
                                >
                                    {m.name}
                                    {m.provincia && <span className="text-[11px] text-[var(--color-ink-faint)] ml-1">({m.provincia})</span>}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Partido */}
            <div>
                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Partido</label>
                <input
                    type="text"
                    value={partido}
                    onChange={e => setPartido(e.target.value)}
                    placeholder="Nombre del partido..."
                    className={inputCls}
                />
            </div>

            {/* Nivel de confianza */}
            <div>
                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Nivel de confianza</label>
                <select
                    value={nivelConfianza}
                    onChange={e => setNivelConfianza(e.target.value)}
                    className={inputCls}
                >
                    {NIVEL_OPTIONS.map(o => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                </select>
            </div>

            {/* Estado */}
            <div>
                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Estado</label>
                <select value={estado} onChange={e => setEstado(e.target.value)} className={inputCls}>
                    <option value="activos">Activos</option>
                    <option value="inactivos">Inactivos</option>
                    <option value="todos">Todos</option>
                </select>
            </div>

            {campaign.hasMultiple && (
                <div>
                    <label htmlFor={campanaId} className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Campaña</label>
                    <select id={campanaId} value={campana} onChange={e => setCampana(e.target.value)} className={inputCls}>
                        <option value="">Todas</option>
                        {campaign.list.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
            )}

            {canViewEventos && (
                <div className="space-y-2">
                    <div>
                        <label htmlFor={eventoId} className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-1">Asistió al evento…</label>
                        <select id={eventoId} value={evento} onChange={e => setEvento(e.target.value)} className={inputCls}>
                            <option value="">Cualquiera o ninguno</option>
                            {eventos.map(e => <option key={e.id} value={e.id}>{eventOptionLabel(e, iso => formatEventDate(iso, { withTime: false }))}</option>)}
                        </select>
                    </div>
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" checked={asistentesEventos || !!evento} disabled={!!evento} onChange={e => setAsistentesEventos(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                        <span className="text-[13px] text-[var(--color-ink)]">Asistentes a eventos (cualquiera)</span>
                    </label>
                </div>
            )}

            {/* Checkboxes */}
            <div className="space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" checked={verificado} onChange={e => setVerificado(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                    <span className="text-[13px] text-[var(--color-ink)]">Verificado</span>
                </label>
                <label className="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" checked={granElector} onChange={e => setGranElector(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                    <span className="text-[13px] text-[var(--color-ink)]">Gran Elector</span>
                </label>
                <label className="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" checked={militante} onChange={e => setMilitante(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                    <span className="text-[13px] text-[var(--color-ink)]">Militante</span>
                </label>
            </div>

            {/* Buttons */}
            <div className="flex gap-2 pt-2">
                <button
                    onClick={() => handleApply()}
                    className="flex-1 px-3 py-2 bg-[var(--color-primary)] text-white rounded-lg text-[13px] font-bold hover:bg-[var(--color-primary-light)] transition-colors"
                >
                    Aplicar
                </button>
                <button
                    onClick={handleClear}
                    className="px-3 py-2 border border-[var(--color-line)] rounded-lg text-[13px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors"
                >
                    Limpiar
                </button>
            </div>
        </div>
    );
}
