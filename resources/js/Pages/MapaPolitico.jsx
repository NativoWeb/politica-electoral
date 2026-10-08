import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useMemo, useEffect, useCallback } from 'react';
import { downloadMunicipio, downloadProvincia, isDownloaded, getAllOfflineData } from '@/lib/offlineDb';
import { fmt, partyLogo, partyColor } from '@/lib/electoral';
import { apiFetch } from '@/lib/api';
import { TIPO_OPTIONS, CARGOS_DISPONIBLES, AREA_METROPOLITANA } from '@/lib/mapaPoliticoConstants';
import PersonaPanel from '@/Components/PersonaPanel';
import CrearLiderModal from '@/Components/CrearLiderModal';
import SearchableDropdown from '@/Components/SearchableDropdown';

const TIPO_COLORS = {
    'Alcaldía': 'bg-blue-100 text-blue-700',
    'Concejo': 'bg-purple-100 text-purple-700',
    'Líderes': 'bg-amber-100 text-amber-700',
    'Directorio Municipal': 'bg-rose-100 text-rose-700',
    'Senado': 'bg-indigo-100 text-indigo-700',
    'Cámara': 'bg-cyan-100 text-cyan-700',
    'Asamblea': 'bg-teal-100 text-teal-700',
};

const TIPO_ICONS = {
    'Alcaldía': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
    ),
    'Concejo': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
    ),
    'Líderes': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
    ),
    'Directorio Municipal': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
    ),
    'Senado': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
    ),
    'Cámara': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4" /></svg>
    ),
    'Asamblea': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
    ),
};

const SECTION_ORDER = ['Alcaldía', 'Concejo', 'Líderes', 'Directorio Municipal', 'Senado', 'Cámara', 'Asamblea'];

/* ── Acordeón por Partido (estilo Registraduría) ── */
function PartyAccordion({ partyName, rows, index, onSelectPerson }) {
    const [open, setOpen] = useState(false);
    const logo = partyLogo(partyName);
    const color = partyColor(partyName, index);
    const totalVotos = rows.reduce((sum, r) => sum + (r.votos || 0), 0);
    const electos = rows.filter(r => r.outcome === 'elected' || (r.cargo && r.cargo.includes('Electo')));

    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden mb-2">
            <button
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors"
            >
                {/* Color bar */}
                <div className="w-1 h-10 rounded-full flex-shrink-0" style={{ backgroundColor: color }} />

                {/* Logo */}
                {logo ? (
                    <img src={logo} alt="" className="w-8 h-8 object-contain flex-shrink-0" />
                ) : (
                    <div className="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-white text-[10px] font-bold" style={{ backgroundColor: color }}>
                        {(partyName || '?').slice(0, 2).toUpperCase()}
                    </div>
                )}

                {/* Info */}
                <div className="flex-1 text-left">
                    <p className="text-[13px] font-bold text-[var(--color-ink)] uppercase">{partyName || 'Sin partido'}</p>
                    <p className="text-[11px] text-[var(--color-ink-faint)]">
                        {rows.length} {rows.length === 1 ? 'persona' : 'personas'}
                        {electos.length > 0 && <span className="ml-1 text-[var(--color-good)] font-bold">· {electos.length} electo{electos.length > 1 ? 's' : ''}</span>}
                    </p>
                </div>

                {/* Votos */}
                {totalVotos > 0 && (
                    <span className="text-[13px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(totalVotos)} votos</span>
                )}

                {/* Arrow */}
                <svg className={`w-4 h-4 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>

            {open && (
                <div className="border-t border-[var(--color-line)] bg-gray-50/50">
                    {rows.map((row, i) => {
                        const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
                        return (
                            <div
                                key={`${row.id}-${i}`}
                                onClick={() => onSelectPerson(row.id)}
                                className={`flex items-center gap-3 px-5 py-2.5 border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50 cursor-pointer transition-colors ${isElecto ? 'bg-emerald-50/60' : ''}`}
                            >
                                <StarToggle id={row.id} initial={!!row.destacado} />
                                <div className="w-1 h-6 rounded-full flex-shrink-0" style={{ backgroundColor: color, opacity: 0.4 }} />
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-[13px] font-bold text-[var(--color-primary)] uppercase truncate">{row.nombre}</span>
                                        {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[8px] font-bold uppercase rounded flex-shrink-0">Electo</span>}
                                    </div>
                                    <p className="text-[11px] text-[var(--color-ink-faint)]">{row.municipio ?? ''} · {row.cargo ?? ''}</p>
                                </div>
                                {row.telefono && <span className="text-[11px] text-[var(--color-primary)] font-semibold flex-shrink-0">{row.telefono}</span>}
                                {row.votos > 0 && <span className="text-[12px] font-bold text-[var(--color-ink)] font-[var(--font-mono)] flex-shrink-0">{fmt(row.votos)}</span>}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/* ── Sección colapsable (Alcaldía, Concejo, etc.) ── */
/* ── Toggle destacado helper ── */
function toggleDestacado(personId, currentValue, onDone) {
    apiFetch(`/mapa-politico/persona/${personId}`, {
        method: 'PUT',
        body: JSON.stringify({ destacado: !currentValue }),
    }).then(() => onDone && onDone()).catch(() => {});
}

/* ── Star toggle button (reusable) ── */
function StarToggle({ id, initial }) {
    const [starred, setStarred] = useState(initial);
    return (
        <button
            onClick={(e) => { e.stopPropagation(); const next = !starred; setStarred(next); toggleDestacado(id, starred); }}
            className="flex-shrink-0 active:scale-125 transition-transform"
            title={starred ? 'Quitar destacado' : 'Destacar'}
        >
            <svg className={`w-5 h-5 ${starred ? 'text-amber-400' : 'text-gray-300'}`} fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>
        </button>
    );
}

/* ── Flat person row — used on mobile for all types ── */
function PersonRow({ row, onSelectPerson, color }) {
    const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
    const initials = (row.nombre || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();

    return (
        <div
            onClick={() => onSelectPerson(row.id)}
            className={`flex items-center gap-3 px-4 py-3.5 border-b border-gray-100 last:border-0 active:bg-blue-50 cursor-pointer transition-colors ${isElecto ? 'bg-emerald-50/40' : ''}`}
        >
            <StarToggle id={row.id} initial={!!row.destacado} />

            {/* Avatar */}
            <div
                className="w-10 h-10 rounded-full flex items-center justify-center text-[12px] font-bold flex-shrink-0 text-white"
                style={{ backgroundColor: color || 'var(--color-primary)' }}
            >
                {initials}
            </div>

            {/* Info */}
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <span className="text-[14px] font-bold text-[var(--color-ink)] uppercase truncate">{row.nombre}</span>
                    {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[9px] font-bold uppercase rounded flex-shrink-0">Electo</span>}
                </div>
                <p className="text-[12px] text-[var(--color-ink-faint)] truncate">
                    {row.municipio ?? ''}
                    {row.partido && <> · <span className="font-semibold">{row.partido}</span></>}
                </p>
            </div>

            {/* Right: votos or phone + chevron */}
            <div className="flex items-center gap-2 flex-shrink-0">
                {row.votos > 0 && <span className="text-[12px] font-bold text-[var(--color-ink-soft)]">{fmt(row.votos)}</span>}
                <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
            </div>
        </div>
    );
}

function CollapsibleSection({ tipo, rows, onSelectPerson, partyTotals }) {
    const [open, setOpen] = useState(false);
    const colorCls = TIPO_COLORS[tipo] ?? 'bg-gray-100 text-gray-600';
    const icon = TIPO_ICONS[tipo];
    const totalVotos = rows.reduce((sum, r) => sum + (r.votos || 0), 0);
    const byParty = useMemo(() => {
        const groups = {};
        rows.forEach(r => {
            const key = r.partido || 'Sin partido';
            if (!groups[key]) groups[key] = [];
            groups[key].push(r);
        });
        return Object.entries(groups).sort((a, b) => {
            const votosA = a[1].reduce((s, r) => s + (r.votos || 0), 0);
            const votosB = b[1].reduce((s, r) => s + (r.votos || 0), 0);
            return votosB - votosA;
        });
    }, [rows]);

    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden bg-white">
            <button
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition-colors"
            >
                <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${colorCls}`}>
                    {icon}
                </div>
                <div className="flex-1 text-left">
                    <h3 className="text-[16px] font-extrabold text-[var(--color-ink)] uppercase">{tipo}</h3>
                    <p className="text-[12px] text-[var(--color-ink-faint)]">
                        {rows.length} {rows.length === 1 ? 'registro' : 'registros'}
                        {byParty.length > 0 && tipo !== 'Líderes' && ` · ${byParty.length} partido${byParty.length > 1 ? 's' : ''}`}
                        {totalVotos > 0 && ` · ${fmt(totalVotos)} votos`}
                    </p>
                </div>
                <span className={`px-3 py-1 rounded-full text-[13px] font-bold ${colorCls}`}>{rows.length}</span>
                <svg className={`w-5 h-5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>

            {open && (
                <div className="border-t border-[var(--color-line)]">
                    {/* Party totals (Cámara/Senado) */}
                    {partyTotals && partyTotals.length > 0 && (
                        <div className="bg-[var(--color-primary)]/5 border-b border-[var(--color-line)] px-4 py-3">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-2">Votacion total por partido</p>
                            <div className="grid grid-cols-2 lg:grid-cols-3 gap-2">
                                {partyTotals.map((pt, i) => {
                                    const logo = partyLogo(pt.partido);
                                    const color = partyColor(pt.partido, i);
                                    return (
                                        <div key={i} className="flex items-center gap-2 bg-white rounded-lg px-3 py-2.5 border border-[var(--color-line)]">
                                            {logo ? (
                                                <img src={logo} alt="" className="w-6 h-6 object-contain flex-shrink-0" />
                                            ) : (
                                                <div className="w-6 h-6 rounded flex-shrink-0 flex items-center justify-center text-white text-[8px] font-bold" style={{ backgroundColor: color }}>
                                                    {(pt.partido || '?').slice(0, 2).toUpperCase()}
                                                </div>
                                            )}
                                            <span className="text-[11px] font-semibold text-[var(--color-ink)] truncate flex-1">{pt.partido}</span>
                                            <span className="text-[12px] font-bold text-[var(--color-primary)] flex-shrink-0">{pt.votos.toLocaleString('es-CO')}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    <div className="px-4 py-3 space-y-1">
                        {tipo === 'Líderes' ? (
                            <div className="space-y-1">
                                {rows.map((row, i) => (
                                    <PersonRow
                                        key={`${row.id}-${i}`}
                                        row={row}
                                        onSelectPerson={onSelectPerson}
                                        color={partyColor(row.partido, i)}
                                    />
                                ))}
                            </div>
                        ) : (
                            byParty.map(([party, partyRows], idx) => (
                                <PartyAccordion
                                    key={party}
                                    partyName={party}
                                    rows={partyRows}
                                    index={idx}
                                    onSelectPerson={onSelectPerson}
                                />
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── MAIN ── */
export default function MapaPolitico({ data: serverData = [], municipios = [], provincias = [], municipioInfo, cargosDisponibles = [], cargosPorTipo = {}, barrios = [], sectionCounts = {}, partyTotals = {}, filters: serverFilters = {} }) {
    const [allData, setAllData] = useState(() => serverData);
    const [localFilters, setLocalFilters] = useState({});
    const [localSearch, setLocalSearch] = useState(serverFilters.search ?? '');
    const [selectedProv, setSelectedProv] = useState(serverFilters.provincia ?? '');
    const [selectedPerson, setSelectedPerson] = useState(null);
    const [showCrearLider, setShowCrearLider] = useState(false);
    const [selectedCargos, setSelectedCargos] = useState(serverFilters.cargo ? serverFilters.cargo.split(',') : []);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [offlineStatus, setOfflineStatus] = useState('idle');
    const [isOnline, setIsOnline] = useState(typeof navigator !== 'undefined' ? navigator.onLine : true);
    const [offlineDataLoaded, setOfflineDataLoaded] = useState(false);
    const [offlineMunicipios, setOfflineMunicipios] = useState(null);

    // Load IndexedDB data when offline (replaces stale SW cache data)
    useEffect(() => {
        if (isOnline) {
            setOfflineDataLoaded(false);
            return;
        }
        getAllOfflineData().then(result => {
            if (result && result.data.length > 0) {
                setAllData(result.data);
                setOfflineDataLoaded(true);
                setOfflineMunicipios(result.municipioNames);
            }
        }).catch(() => {});
    }, [isOnline]);

    // Keep allData in sync with server props when online
    useEffect(() => {
        if (isOnline) setAllData(serverData);
    }, [serverData, isOnline]);

    const isOfflineFiltering = !isOnline && Object.keys(localFilters).length > 0;
    const filters = isOfflineFiltering ? { ...serverFilters, ...localFilters } : serverFilters;

    const data = useMemo(() => {
        const source = (!isOnline && offlineDataLoaded) ? allData : serverData;
        if (!isOfflineFiltering && isOnline) return serverData;
        if (!isOfflineFiltering && !isOnline) return source;
        let result = allData;
        const f = localFilters;
        if (f.search) {
            const q = f.search.toLowerCase();
            result = result.filter(r => (r.nombre || '').toLowerCase().includes(q) || (r.cedula || '').includes(q));
        }
        if (f.municipio) {
            result = result.filter(r => r.municipioId === f.municipio || r.municipio_id === f.municipio);
        }
        if (f.provincia) {
            const prov = f.provincia.toLowerCase();
            if (prov === 'área metropolitana') {
                const am = AREA_METROPOLITANA.map(n => n.toLowerCase());
                result = result.filter(r => am.includes((r.municipio || '').toLowerCase()));
            } else {
                result = result.filter(r => (r.provincia || '').toLowerCase() === prov);
            }
        }
        if (f.tipo && f.tipo !== 'todos') {
            const tipos = f.tipo.split(',');
            const tipoLabels = tipos.map(v => TIPO_OPTIONS.find(o => o.value === v)?.label).filter(Boolean);
            result = result.filter(r => {
                const rowTipos = (r.tipo_registro || '').split(',').map(t => t.trim());
                return tipoLabels.some(tl => rowTipos.includes(tl));
            });
        }
        if (f.cargo) {
            const cargos = f.cargo.split(',');
            result = result.filter(r => {
                const rowCargos = (r.cargo || '').toLowerCase();
                return cargos.some(c => rowCargos.includes(c.toLowerCase()));
            });
        }
        if (f.partido) {
            result = result.filter(r => r.partido === f.partido);
        }
        if (f.profesion) {
            result = result.filter(r => r.profesion === f.profesion);
        }
        if (f.barrio) {
            const barrs = f.barrio.split(',').map(b => b.toLowerCase());
            result = result.filter(r => barrs.some(b => (r.barrio || '').toLowerCase().includes(b)));
        }
        if (f.destacado === '1') {
            result = result.filter(r => r.destacado);
        }
        return result;
    }, [isOnline, isOfflineFiltering, offlineDataLoaded, serverData, allData, localFilters]);

    useEffect(() => {
        const goOnline = () => { setIsOnline(true); setLocalFilters({}); };
        const goOffline = () => setIsOnline(false);
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
        return () => { window.removeEventListener('online', goOnline); window.removeEventListener('offline', goOffline); };
    }, []);

    // Check if current municipio is already downloaded
    useEffect(() => {
        if (!municipioInfo?.id) { setOfflineStatus('idle'); return; }
        isDownloaded(municipioInfo.id).then(yes => setOfflineStatus(yes ? 'already' : 'idle'));
    }, [municipioInfo?.id]);

    const handleOfflineDownload = useCallback(async () => {
        if (!municipioInfo?.id) return;
        setOfflineStatus('downloading');
        try {
            await downloadMunicipio(municipioInfo.id);
            setOfflineStatus('saved');
        } catch {
            setOfflineStatus('idle');
            alert('No se pudo guardar. Revisa tu conexion.');
        }
    }, [municipioInfo?.id]);

    // Sync local state when Inertia props change (e.g. after redirect)
    useEffect(() => {
        setLocalSearch(filters.search ?? '');
        setSelectedProv(filters.provincia ?? '');
        setSelectedCargos(filters.cargo ? filters.cargo.split(',') : []);
    }, [filters.search, filters.provincia, filters.cargo]);

    const filteredMunicipios = useMemo(() => {
        if (!selectedProv) return municipios;
        if (selectedProv === 'Área Metropolitana') return municipios.filter(m => AREA_METROPOLITANA.includes(m.name));
        return municipios.filter(m => m.provincia === selectedProv);
    }, [municipios, selectedProv]);

    const [provOfflineStatus, setProvOfflineStatus] = useState('idle');
    const [provProgress, setProvProgress] = useState(null);
    const [provDownloadedName, setProvDownloadedName] = useState('');

    useEffect(() => {
        setProvProgress(null);
        if (!selectedProv || !filteredMunicipios.length) { setProvOfflineStatus('idle'); return; }
        let cancelled = false;
        Promise.all(filteredMunicipios.map(m => isDownloaded(m.id))).then(results => {
            if (cancelled) return;
            setProvOfflineStatus(results.every(Boolean) ? 'already' : 'idle');
        }).catch(() => { if (!cancelled) setProvOfflineStatus('idle'); });
        return () => { cancelled = true; };
    }, [selectedProv, filteredMunicipios]);

    const handleProvOfflineDownload = useCallback(async () => {
        if (!selectedProv || !filteredMunicipios.length) return;
        setProvDownloadedName(selectedProv);
        setProvOfflineStatus('downloading');
        setProvProgress({ completed: 0, failed: 0, total: filteredMunicipios.length, current: '' });
        try {
            const result = await downloadProvincia(filteredMunicipios, (progress) => {
                setProvProgress(progress);
            });
            setProvOfflineStatus('saved');
            if (result.failed > 0) {
                alert(`${result.completed} municipios guardados, ${result.failed} con error.`);
            }
        } catch {
            setProvOfflineStatus('idle');
            alert('No se pudo guardar. Revisa tu conexion.');
        }
        setProvProgress(null);
    }, [selectedProv, filteredMunicipios]);

    // Determine if we should show collapsible sections or flat table
    const tipoFilter = filters.tipo ?? 'todos';
    const tipoIsAll = tipoFilter === 'todos';
    const hasSpecificFilters = filters.search || filters.cargo || filters.barrio || filters.destacado || filters.profesion;
    const showSections = tipoIsAll && !hasSpecificFilters;

    // Group data by tipo_registro for sections
    const groupedData = useMemo(() => {
        if (!showSections) return {};
        const groups = {};
        data.forEach(row => {
            // A row may have multiple tipos (e.g. "Alcaldía, Líderes")
            const tipos = (row.tipo_registro || '').split(',').map(t => t.trim()).filter(Boolean);
            tipos.forEach(t => {
                if (!groups[t]) groups[t] = [];
                groups[t].push(row);
            });
        });
        return groups;
    }, [data, showSections]);

    function applyFilters(overrides = {}) {
        const params = {
            municipio: filters.municipio,
            tipo: filters.tipo ?? 'todos',
            cargo: filters.cargo,
            partido: filters.partido,
            barrio: filters.barrio,
            destacado: filters.destacado,
            profesion: filters.profesion,
            search: localSearch || undefined,
            provincia: selectedProv || undefined,
            ...overrides,
        };
        Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });

        if (!isOnline) {
            setLocalFilters(params);
            return;
        }

        router.get('/mapa-politico', params, { preserveState: true, replace: true });
    }

    function handleSearch(e) {
        if (e.key === 'Enter') applyFilters({ search: localSearch || undefined });
    }

    return (
        <AppLayout title="Mapa Político" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'MAPA POLITICO' }]}>
            <Head title="Mapa Politico — Inteligencia Electoral" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">MAPA POLITICO</h1>
                    <p className="text-[10px] lg:text-[11px] text-white/40 mt-0.5 truncate">Directorio unificado · Alcaldías, Concejos, Líderes, Senado, Cámara y Asamblea</p>
                </div>
                <button onClick={() => setShowCrearLider(true)} className="px-3 lg:px-5 py-2.5 lg:py-3 bg-white/15 hover:bg-white/25 text-white text-[13px] lg:text-[15px] font-bold rounded-lg transition-colors border border-white/20 flex items-center gap-2 flex-shrink-0">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                    <span className="hidden sm:inline">Crear Líder</span>
                </button>
            </div>

            {/* Banner sin conexión */}
            {!isOnline && (
                <div className="bg-red-500 text-white px-4 py-3 flex items-center gap-3">
                    <svg className="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728" />
                        <line x1="4" y1="4" x2="20" y2="20" strokeLinecap="round" strokeWidth={2.5} />
                    </svg>
                    <div>
                        <p className="text-[15px] font-bold">Sin conexión a internet</p>
                        <p className="text-[13px] text-white/80">
                            {offlineDataLoaded && offlineMunicipios
                                ? `Mostrando datos guardados: ${offlineMunicipios.join(', ')}. Puedes filtrar estos datos.`
                                : 'Puedes filtrar los datos cargados. Para nuevos datos, conecta a internet.'}
                        </p>
                    </div>
                </div>
            )}

            {/* Filtros */}
            <div className="bg-white border-b border-[var(--color-line)] px-4 lg:px-5 py-3 space-y-3">
                {/* Mobile: toggle + search inline */}
                <div className="flex items-center gap-2 lg:hidden">
                    <button
                        onClick={() => setFiltersOpen(!filtersOpen)}
                        className="flex items-center gap-2 px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[13px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors flex-shrink-0"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
                        Filtros
                        {(filters.provincia || filters.municipio || filters.tipo !== 'todos' || filters.cargo || filters.partido || filters.barrio) && (
                            <span className="w-2 h-2 bg-[var(--color-primary)] rounded-full" />
                        )}
                    </button>
                    <input type="text" value={localSearch} onChange={e => setLocalSearch(e.target.value)} onKeyDown={handleSearch} placeholder="Buscar nombre..."
                        className="flex-1 min-w-0 text-[14px] border border-[var(--color-line)] rounded-lg px-3 py-2.5 focus:outline-none focus:border-[var(--color-primary)] text-[var(--color-ink)]" />
                    <button onClick={() => applyFilters({ search: localSearch || undefined })} className="px-3 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg flex-shrink-0">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </button>
                </div>

                {/* Filter grid — always visible on desktop, collapsible on mobile */}
                <div className={`${filtersOpen ? 'block' : 'hidden'} lg:block`}>
                    <div className="grid grid-cols-2 lg:flex lg:flex-wrap items-end gap-2 lg:gap-3">
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Provincia"
                                options={provincias}
                                selected={selectedProv}
                                onChange={val => { setSelectedProv(val || ''); applyFilters({ provincia: val || undefined, municipio: undefined }); }}
                                multi={false}
                                placeholder="Todas"
                            />
                        </div>
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Municipio"
                                options={filteredMunicipios.map(m => m.name)}
                                selected={filters.municipio ? filteredMunicipios.find(m => m.id === filters.municipio)?.name : undefined}
                                onChange={val => {
                                    const mun = filteredMunicipios.find(m => m.name === val);
                                    applyFilters({ municipio: mun?.id || undefined });
                                }}
                                multi={false}
                                placeholder="Todos"
                            />
                        </div>
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Tipo"
                                options={TIPO_OPTIONS.filter(o => o.value !== 'todos').map(o => o.label)}
                                selected={filters.tipo && filters.tipo !== 'todos' ? filters.tipo.split(',').map(v => TIPO_OPTIONS.find(o => o.value === v)?.label).filter(Boolean).join(',') : undefined}
                                onChange={val => {
                                    if (!val) { applyFilters({ tipo: 'todos' }); return; }
                                    const values = val.split(',').map(label => TIPO_OPTIONS.find(o => o.label === label)?.value).filter(Boolean).join(',');
                                    applyFilters({ tipo: values || 'todos' });
                                }}
                                multi={true}
                                placeholder="Todos"
                            />
                        </div>
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Cargo"
                                options={(() => {
                                    const selectedTipos = (filters.tipo && filters.tipo !== 'todos') ? filters.tipo.split(',') : ['todos'];
                                    if (selectedTipos.includes('todos')) return cargosPorTipo['todos'] || CARGOS_DISPONIBLES;
                                    const merged = [...new Set(selectedTipos.flatMap(t => cargosPorTipo[t] || []))];
                                    return merged.length > 0 ? merged.sort() : CARGOS_DISPONIBLES;
                                })()}
                                selected={filters.cargo}
                                onChange={val => { setSelectedCargos(val ? val.split(',') : []); applyFilters({ cargo: val }); }}
                                multi={true}
                            />
                        </div>
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Partido"
                                options={data.length > 0 ? [...new Set(data.map(d => d.partido).filter(Boolean))].sort() : []}
                                selected={filters.partido}
                                onChange={val => applyFilters({ partido: val })}
                                multi={false}
                            />
                        </div>
                        <div className="col-span-1">
                            <SearchableDropdown
                                label="Profesion"
                                options={data.length > 0 ? [...new Set(data.map(d => d.profesion).filter(Boolean))].sort() : []}
                                selected={filters.profesion}
                                onChange={val => applyFilters({ profesion: val })}
                                multi={false}
                            />
                        </div>
                        {barrios.length > 0 && (
                            <div className="col-span-1">
                                <SearchableDropdown
                                    label="Barrio"
                                    options={barrios}
                                    selected={filters.barrio}
                                    onChange={val => applyFilters({ barrio: val })}
                                    multi={true}
                                />
                            </div>
                        )}
                        {/* Search visible only on desktop (mobile has it above) */}
                        <div className="hidden lg:block">
                            <label className="block text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Buscar</label>
                            <input type="text" value={localSearch} onChange={e => setLocalSearch(e.target.value)} onKeyDown={handleSearch} placeholder="Nombre..."
                                className="text-[15px] border border-[var(--color-line)] rounded-lg px-4 py-3 w-[240px] focus:outline-none focus:border-[var(--color-primary)] text-[var(--color-ink)]" />
                        </div>
                        <div className="col-span-2 lg:col-span-1 flex gap-2 pt-1 lg:pt-4">
                            <button onClick={() => { applyFilters({ search: localSearch || undefined }); setFiltersOpen(false); }} className="flex-1 lg:flex-initial px-4 lg:px-6 py-2.5 lg:py-3 bg-[var(--color-primary)] text-white text-[13px] lg:text-[15px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">Filtrar</button>
                            <button onClick={() => { setLocalSearch(''); setSelectedProv(''); setSelectedCargos([]); setLocalFilters({}); setFiltersOpen(false); if (isOnline) router.get('/mapa-politico', {}, { preserveState: false }); }} className="flex-1 lg:flex-initial px-3 lg:px-5 py-2.5 lg:py-3 border border-[var(--color-line)] text-[13px] lg:text-[15px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 transition-colors">Limpiar</button>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3 text-[11px] text-[var(--color-ink-faint)] flex-wrap">
                    <span className="font-bold text-[var(--color-ink)]">{data.length} {data.length === 1 ? 'registro' : 'registros'}</span>
                    {municipioInfo && <span>· {municipioInfo.name} ({municipioInfo.provincia})</span>}

                    {/* Destacados toggle */}
                    <button
                        onClick={() => applyFilters({ destacado: filters.destacado ? undefined : '1' })}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold transition-colors ${
                            filters.destacado
                                ? 'bg-amber-400 text-white'
                                : 'bg-amber-50 text-amber-600 border border-amber-200'
                        }`}
                    >
                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>
                        Destacados
                    </button>

                    {/* Offline download button — only when a municipio is selected */}
                    {municipioInfo && offlineStatus === 'idle' && (
                        <button
                            onClick={handleOfflineDownload}
                            className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg active:bg-[var(--color-primary-dark)] transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            Guardar sin internet
                        </button>
                    )}
                    {municipioInfo && offlineStatus === 'downloading' && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-blue-50 text-[var(--color-primary)] text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            Guardando...
                        </span>
                    )}
                    {municipioInfo && (offlineStatus === 'saved' || offlineStatus === 'already') && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-emerald-50 text-emerald-700 text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                            Guardado
                        </span>
                    )}

                    {/* Offline download — provincia (when no specific municipio) */}
                    {selectedProv && !municipioInfo && provOfflineStatus === 'idle' && (
                        <button
                            onClick={handleProvOfflineDownload}
                            className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg active:bg-[var(--color-primary-dark)] transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            Guardar {selectedProv} sin internet
                        </button>
                    )}
                    {selectedProv && !municipioInfo && provOfflineStatus === 'downloading' && provProgress && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-blue-50 text-[var(--color-primary)] text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {provProgress.current} ({provProgress.completed + provProgress.failed}/{provProgress.total})
                        </span>
                    )}
                    {selectedProv && !municipioInfo && (provOfflineStatus === 'saved' || provOfflineStatus === 'already') && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-emerald-50 text-emerald-700 text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                            {selectedProv} guardada
                        </span>
                    )}
                </div>
            </div>

            {/* Contenido principal */}
            {showSections && data.length > 0 ? (
                /* ── Vista de secciones colapsables ── */
                <div className="p-4 space-y-3">
                    {SECTION_ORDER.map(tipo => {
                        const rows = groupedData[tipo];
                        if (!rows || rows.length === 0) return null;
                        return (
                            <CollapsibleSection
                                key={tipo}
                                tipo={tipo}
                                rows={rows}
                                onSelectPerson={setSelectedPerson}
                                partyTotals={
                                    tipo === 'Cámara' ? partyTotals['Cámara de Representantes'] :
                                    tipo === 'Senado' ? partyTotals['Senado'] :
                                    null
                                }
                            />
                        );
                    })}
                </div>
            ) : data.length === 0 ? (
                /* ── Sin datos ── */
                <div className="px-4 py-16 text-center">
                    <div className="flex flex-col items-center gap-3">
                        <svg className="w-16 h-16 text-[var(--color-ink-faint)]/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <p className="text-[18px] font-bold text-[var(--color-ink-faint)]">No se encontraron resultados</p>
                        <p className="text-[14px] text-[var(--color-ink-faint)]/60">Ajusta los filtros o limpia la búsqueda</p>
                    </div>
                </div>
            ) : (
                /* ── Tabla plana (cuando hay filtros específicos) ── */
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="sticky top-0 bg-[var(--color-primary)] text-white z-10">
                            <tr>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Nombre</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Municipio</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Barrio/Vereda</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Tipo</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Cargo</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Partido</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Telefono</th>
                                <th className="text-right px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Votos</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((row, i) => {
                                const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
                                return (
                                    <tr
                                        key={`${row.id}-${i}`}
                                        className={`border-b border-[var(--color-line)] hover:bg-blue-50/50 transition-colors cursor-pointer ${isElecto ? 'bg-emerald-50/60' : ''}`}
                                        onClick={() => setSelectedPerson(row.id)}
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <StarToggle id={row.id} initial={!!row.destacado} />
                                                <span className="font-bold text-[var(--color-primary)] uppercase hover:underline">{row.nombre}</span>
                                                {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[8px] font-bold uppercase rounded">Electo</span>}
                                            </div>
                                            {row.email && <p className="text-[10px] text-[var(--color-ink-faint)] mt-0.5">{row.email}</p>}
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)]">
                                            <p>{row.municipio ?? '—'}</p>
                                            <p className="text-[10px] text-[var(--color-ink-faint)]">{row.provincia ?? ''}</p>
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)] text-[12px]">{row.barrio ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`px-2 py-0.5 rounded text-[9px] font-bold ${TIPO_COLORS[row.tipo_registro] ?? 'bg-gray-100 text-gray-500'}`}>{row.tipo_registro}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)] text-[12px]">
                                            {row.cargo ?? '—'}
                                            {row.tipo_aval && <span className="ml-1 px-1 py-0.5 bg-amber-50 text-amber-700 text-[8px] font-bold uppercase rounded border border-amber-200">{row.tipo_aval}</span>}
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)]">{row.partido ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {row.telefono ? <span className="flex items-center gap-1"><span className="text-[var(--color-primary)] font-semibold text-[12px]">{row.telefono}</span><a href={`https://wa.me/57${row.telefono.replace(/\D/g,'').replace(/^57/,'')}`} target="_blank" rel="noopener" onClick={e => e.stopPropagation()} className="w-6 h-6 bg-[#25D366] rounded-full flex items-center justify-center flex-shrink-0"><svg className="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></a></span> : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-bold font-[var(--font-mono)] text-[var(--color-ink)]">
                                            {row.votos > 0 ? fmt(row.votos) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Panel lateral de detalle */}
            {selectedPerson && (
                <PersonaPanel personId={selectedPerson} onClose={() => setSelectedPerson(null)} cargosPorTipo={cargosPorTipo} fallbackData={data.find(d => d.id === selectedPerson)} />
            )}

            {/* Modal crear líder */}
            <CrearLiderModal open={showCrearLider} onClose={() => setShowCrearLider(false)} municipios={municipios} provincias={provincias} cargosPorTipo={cargosPorTipo} />

        </AppLayout>
    );
}
