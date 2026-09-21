import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useRef, useState } from 'react';

/* ── helpers ─────────────────────────────────────────────────────── */

const fmt = (n) => {
    if (n == null || n === '') return '—';
    return new Intl.NumberFormat('es-CO').format(n);
};

const OUTCOME_LABELS = {
    elected:      { label: 'ELECTO',       bg: 'var(--color-good-light)',    text: 'var(--color-good)' },
    lost:         { label: 'NO ELECTO',    bg: '#FEF3C7',                    text: '#92400E' },
    withdrawn:    { label: 'RETIRADO',     bg: '#F3F4F6',                    text: '#6B7280' },
    disqualified: { label: 'INHABILITADO', bg: 'var(--color-danger-light)',   text: 'var(--color-danger)' },
};

function OutcomeBadge({ outcome }) {
    const meta = OUTCOME_LABELS[outcome] ?? { label: outcome ?? '—', bg: '#F3F4F6', text: '#6B7280' };
    return (
        <span
            className="inline-block px-2 py-0.5 rounded text-[10px] font-bold tracking-wide"
            style={{ background: meta.bg, color: meta.text }}
        >
            {meta.label}
        </span>
    );
}

/* ── HorizontalBar ───────────────────────────────────────────────── */

function HorizontalBar({ valueA, valueB, labelA, labelB, title }) {
    const total = (valueA || 0) + (valueB || 0);
    const pctA = total > 0 ? Math.round(((valueA || 0) / total) * 100) : 50;
    const pctB = 100 - pctA;

    return (
        <div className="py-3 border-b border-[var(--color-line)] last:border-0">
            <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-2">
                {title}
            </p>
            <div className="flex items-center gap-2">
                <span className="text-[12px] font-bold text-[var(--color-primary)] w-20 text-right tabular-nums">
                    {fmt(valueA)}
                </span>
                <div className="flex-1 flex h-3 rounded overflow-hidden bg-gray-100">
                    <div
                        className="bg-[var(--color-primary)] transition-all duration-500"
                        style={{ width: `${pctA}%` }}
                    />
                    <div
                        className="bg-[#C8016E] transition-all duration-500"
                        style={{ width: `${pctB}%` }}
                    />
                </div>
                <span className="text-[12px] font-bold text-[#C8016E] w-20 tabular-nums">
                    {fmt(valueB)}
                </span>
            </div>
            <div className="flex justify-between mt-0.5 px-22">
                <span className="text-[10px] text-[var(--color-ink-faint)] ml-22">{pctA}%</span>
                <span className="text-[10px] text-[var(--color-ink-faint)]">{pctB}%</span>
            </div>
        </div>
    );
}

/* ── SearchInput ─────────────────────────────────────────────────── */

function SearchInput({ label, side, filterType, onSelect, selected }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const debounceRef = useRef(null);
    const containerRef = useRef(null);

    const borderColor = side === 'a' ? 'var(--color-primary)' : '#C8016E';
    const bgSelected = side === 'a' ? 'bg-blue-50 border-[var(--color-primary)]' : 'bg-pink-50 border-[#C8016E]';
    const textSelected = side === 'a' ? 'text-[var(--color-primary)]' : 'text-[#C8016E]';

    function handleChange(e) {
        const val = e.target.value;
        setQuery(val);

        if (debounceRef.current) clearTimeout(debounceRef.current);

        if (val.trim().length < 2) {
            setResults([]);
            setOpen(false);
            return;
        }

        debounceRef.current = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(
                    `/api/search?q=${encodeURIComponent(val.trim())}&type=${filterType}`
                );
                const data = await res.json();
                const filtered = (data.results ?? []).filter((r) => r.type === filterType);
                setResults(filtered);
                setOpen(true);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 300);
    }

    function handleSelect(item) {
        setOpen(false);
        setQuery('');
        setResults([]);
        onSelect(item);
    }

    function handleClear() {
        setQuery('');
        setResults([]);
        setOpen(false);
        onSelect(null);
    }

    return (
        <div ref={containerRef} className="relative flex-1">
            <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-1.5">
                {label}
            </p>

            {selected ? (
                <div
                    className={`flex items-center justify-between px-3 py-3 rounded border-2 ${bgSelected}`}
                >
                    <div>
                        <p className={`text-[14px] font-bold ${textSelected}`}>{selected.name}</p>
                        {selected.detail && (
                            <p className="text-[11px] text-[var(--color-ink-faint)] mt-0.5">{selected.detail}</p>
                        )}
                    </div>
                    <button
                        onClick={handleClear}
                        className="text-[11px] text-[var(--color-ink-faint)] hover:text-red-500 transition-colors ml-3 flex-shrink-0"
                    >
                        Cambiar
                    </button>
                </div>
            ) : (
                <>
                    <input
                        type="text"
                        value={query}
                        onChange={handleChange}
                        onFocus={() => results.length > 0 && setOpen(true)}
                        onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                        placeholder={`Buscar ${filterType}...`}
                        className="w-full px-3 py-2.5 border-2 border-[var(--color-line)] rounded text-sm focus:outline-none transition-colors"
                        style={{ borderColor: query.length >= 2 ? borderColor : undefined }}
                    />

                    {open && (
                        <div className="absolute top-full left-0 right-0 mt-1 bg-white rounded shadow-xl border border-gray-200 z-50 max-h-64 overflow-y-auto">
                            {loading && (
                                <div className="px-4 py-3 text-sm text-gray-400 text-center">Buscando...</div>
                            )}
                            {!loading && results.length === 0 && (
                                <div className="px-4 py-3 text-sm text-gray-400 text-center">Sin resultados</div>
                            )}
                            {!loading && results.map((item) => (
                                <button
                                    key={item.id}
                                    onMouseDown={() => handleSelect(item)}
                                    className="w-full text-left px-4 py-2.5 hover:bg-blue-50 flex flex-col border-b border-gray-50 last:border-0 transition-colors"
                                >
                                    <span className="text-sm font-semibold text-gray-900">{item.name}</span>
                                    {item.detail && (
                                        <span className="text-xs text-gray-400">{item.detail}</span>
                                    )}
                                </button>
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

/* ── PersonaResult ───────────────────────────────────────────────── */

function PersonaResult({ data, side }) {
    if (!data) return (
        <div className="flex-1 bg-white rounded-lg border border-[var(--color-line)] p-6 flex items-center justify-center">
            <p className="text-sm text-[var(--color-ink-faint)]">Sin datos</p>
        </div>
    );

    const isA = side === 'a';
    const accent = isA ? 'var(--color-primary)' : '#C8016E';
    const lightBg = isA ? '#EFF6FF' : '#FDF2F8';

    return (
        <div className="flex-1 bg-white rounded-lg border-2 overflow-hidden" style={{ borderColor: accent }}>
            {/* Header */}
            <div className="px-5 py-4" style={{ background: accent }}>
                <p className="text-[10px] font-bold text-white/70 uppercase tracking-widest mb-0.5">
                    {isA ? 'CANDIDATO A' : 'CANDIDATO B'}
                </p>
                <h3 className="text-[16px] font-extrabold text-white leading-tight">{data.name}</h3>
            </div>

            {/* KPI strip */}
            <div className="grid grid-cols-3 divide-x divide-[var(--color-line)] border-b border-[var(--color-line)]" style={{ background: lightBg }}>
                <div className="px-4 py-3 text-center">
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{fmt(data.totalVotos)}</p>
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mt-0.5">Total votos</p>
                </div>
                <div className="px-4 py-3 text-center">
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{data.victorias}</p>
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mt-0.5">Victorias</p>
                </div>
                <div className="px-4 py-3 text-center">
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{data.totalCandidaturas}</p>
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mt-0.5">Candidaturas</p>
                </div>
            </div>

            {/* Candidaturas table */}
            <div className="overflow-x-auto">
                <table className="w-full text-[12px]">
                    <thead>
                        <tr className="border-b border-[var(--color-line)] bg-gray-50">
                            <th className="px-3 py-2 text-left font-bold text-[10px] uppercase tracking-widest text-[var(--color-ink-faint)]">Evento</th>
                            <th className="px-3 py-2 text-left font-bold text-[10px] uppercase tracking-widest text-[var(--color-ink-faint)]">Cargo</th>
                            <th className="px-3 py-2 text-left font-bold text-[10px] uppercase tracking-widest text-[var(--color-ink-faint)]">Partido</th>
                            <th className="px-3 py-2 text-right font-bold text-[10px] uppercase tracking-widest text-[var(--color-ink-faint)]">Votos</th>
                            <th className="px-3 py-2 text-center font-bold text-[10px] uppercase tracking-widest text-[var(--color-ink-faint)]">Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.candidaturas.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-3 py-4 text-center text-[var(--color-ink-faint)]">Sin candidaturas registradas</td>
                            </tr>
                        )}
                        {data.candidaturas.map((c, i) => (
                            <tr key={i} className="border-b border-[var(--color-line)] last:border-0 hover:bg-gray-50 transition-colors">
                                <td className="px-3 py-2 text-[var(--color-ink)]">{c.evento ?? '—'}</td>
                                <td className="px-3 py-2 text-[var(--color-ink)]">{c.cargo ?? '—'}</td>
                                <td className="px-3 py-2 text-[var(--color-ink-faint)]">{c.partido ?? '—'}</td>
                                <td className="px-3 py-2 text-right font-bold tabular-nums" style={{ color: accent }}>{fmt(c.votos)}</td>
                                <td className="px-3 py-2 text-center"><OutcomeBadge outcome={c.outcome} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/* ── MunicipioResult ─────────────────────────────────────────────── */

function MunicipioResult({ data, side }) {
    if (!data) return (
        <div className="flex-1 bg-white rounded-lg border border-[var(--color-line)] p-6 flex items-center justify-center">
            <p className="text-sm text-[var(--color-ink-faint)]">Sin datos</p>
        </div>
    );

    const isA = side === 'a';
    const accent = isA ? 'var(--color-primary)' : '#C8016E';
    const lightBg = isA ? '#EFF6FF' : '#FDF2F8';
    const maxVotos = data.partidos.length > 0 ? Math.max(...data.partidos.map((p) => Number(p.votos))) : 1;

    return (
        <div className="flex-1 bg-white rounded-lg border-2 overflow-hidden" style={{ borderColor: accent }}>
            {/* Header */}
            <div className="px-5 py-4" style={{ background: accent }}>
                <p className="text-[10px] font-bold text-white/70 uppercase tracking-widest mb-0.5">
                    {isA ? 'MUNICIPIO A' : 'MUNICIPIO B'}
                </p>
                <h3 className="text-[16px] font-extrabold text-white leading-tight">{data.name}</h3>
                {data.provincia && (
                    <p className="text-[11px] text-white/70 mt-0.5">Provincia {data.provincia}</p>
                )}
            </div>

            {/* KPI strip */}
            <div className="grid grid-cols-2 divide-x divide-[var(--color-line)] border-b border-[var(--color-line)]" style={{ background: lightBg }}>
                <div className="px-4 py-3 text-center">
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{fmt(data.totalVotos)}</p>
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mt-0.5">Total votos</p>
                </div>
                <div className="px-4 py-3 text-center">
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{data.candidatos}</p>
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mt-0.5">Candidatos</p>
                </div>
            </div>

            <div className="grid grid-cols-2 divide-x divide-[var(--color-line)] border-b border-[var(--color-line)]">
                <div className="px-4 py-3">
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-1">Alcalde electo</p>
                    <p className="text-[13px] font-bold text-[var(--color-ink)]">{data.alcalde ?? '—'}</p>
                </div>
                <div className="px-4 py-3">
                    <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-1">Concejales electos</p>
                    <p className="text-[22px] font-extrabold" style={{ color: accent }}>{data.concejales}</p>
                </div>
            </div>

            {/* Partido breakdown */}
            {data.partidos.length > 0 && (
                <div className="px-5 py-4">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-3">
                        Votos por partido (Alcaldía)
                    </p>
                    <div className="space-y-2">
                        {data.partidos.slice(0, 6).map((p, i) => {
                            const barW = maxVotos > 0 ? Math.max((Number(p.votos) / maxVotos) * 100, 1) : 0;
                            return (
                                <div key={i}>
                                    <div className="flex items-center justify-between mb-0.5">
                                        <span className="text-[11px] text-[var(--color-ink)] truncate max-w-[60%]">{p.name ?? '—'}</span>
                                        <span className="text-[11px] font-bold tabular-nums" style={{ color: accent }}>{fmt(p.votos)}</span>
                                    </div>
                                    <div className="h-1.5 bg-gray-100 rounded overflow-hidden">
                                        <div
                                            className="h-full rounded transition-all duration-500"
                                            style={{ width: `${barW}%`, background: accent }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── ComparisonBars (centre panel) ───────────────────────────────── */

function ComparisonBars({ result }) {
    if (!result) return null;
    const { type, a, b } = result;
    if (!a || !b) return null;

    if (type === 'persona') {
        return (
            <div className="bg-white rounded-lg border border-[var(--color-line)] p-5">
                <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-4 text-center">
                    Comparativa directa
                </p>
                <HorizontalBar
                    title="Total de votos"
                    valueA={a.totalVotos}
                    valueB={b.totalVotos}
                    labelA={a.name}
                    labelB={b.name}
                />
                <HorizontalBar
                    title="Victorias"
                    valueA={a.victorias}
                    valueB={b.victorias}
                    labelA={a.name}
                    labelB={b.name}
                />
                <HorizontalBar
                    title="Total candidaturas"
                    valueA={a.totalCandidaturas}
                    valueB={b.totalCandidaturas}
                    labelA={a.name}
                    labelB={b.name}
                />
            </div>
        );
    }

    if (type === 'municipio') {
        return (
            <div className="bg-white rounded-lg border border-[var(--color-line)] p-5">
                <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-4 text-center">
                    Comparativa directa
                </p>
                <HorizontalBar
                    title="Total de votos"
                    valueA={a.totalVotos}
                    valueB={b.totalVotos}
                    labelA={a.name}
                    labelB={b.name}
                />
                <HorizontalBar
                    title="Candidatos"
                    valueA={a.candidatos}
                    valueB={b.candidatos}
                    labelA={a.name}
                    labelB={b.name}
                />
                <HorizontalBar
                    title="Concejales electos"
                    valueA={a.concejales}
                    valueB={b.concejales}
                    labelA={a.name}
                    labelB={b.name}
                />
            </div>
        );
    }

    return null;
}

/* ── Main page ───────────────────────────────────────────────────── */

export default function Comparador() {
    const [activeType, setActiveType] = useState('persona');
    const [selectedA, setSelectedA] = useState(null);
    const [selectedB, setSelectedB] = useState(null);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    async function handleComparar() {
        if (!selectedA || !selectedB) return;
        setLoading(true);
        setError(null);
        setResult(null);
        try {
            const url = `/api/comparar?type=${activeType}&a=${selectedA.id}&b=${selectedB.id}`;
            const res = await fetch(url);
            const data = await res.json();
            if (data.error) {
                setError(data.error);
            } else {
                setResult(data);
            }
        } catch {
            setError('Error al cargar la comparación. Intente de nuevo.');
        } finally {
            setLoading(false);
        }
    }

    function handleTypeChange(type) {
        setActiveType(type);
        setSelectedA(null);
        setSelectedB(null);
        setResult(null);
        setError(null);
    }

    const canCompare = selectedA && selectedB && selectedA.id !== selectedB.id;

    return (
        <AppLayout
            title="Comparador Electoral"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'COMPARADOR' },
            ]}
        >
            <Head title="Comparador Electoral — Inteligencia Electoral Santander" />

            {/* Hero header */}
            <div className="bg-white border-b border-[var(--color-line)] px-6 py-6">
                <div className="max-w-5xl mx-auto">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-1">
                        Herramienta de análisis
                    </p>
                    <h1 className="text-[24px] font-extrabold text-[var(--color-ink)] tracking-tight">
                        COMPARADOR ELECTORAL
                    </h1>
                    <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">
                        Seleccione dos entidades del mismo tipo para comparar su desempeño electoral.
                    </p>
                </div>
            </div>

            <div className="max-w-5xl mx-auto px-4 lg:px-6 py-6 space-y-6">

                {/* Type selector tabs */}
                <div className="bg-white rounded-lg border border-[var(--color-line)] p-1 flex gap-1 w-fit">
                    {[
                        { key: 'persona',   label: 'Personas' },
                        { key: 'municipio', label: 'Municipios' },
                    ].map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => handleTypeChange(tab.key)}
                            className={`px-6 py-2 rounded text-[12px] font-bold uppercase tracking-wider transition-colors ${
                                activeType === tab.key
                                    ? 'bg-[var(--color-primary)] text-white shadow-sm'
                                    : 'text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] hover:bg-gray-50'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Search row */}
                <div className="bg-white rounded-lg border border-[var(--color-line)] p-5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-4">
                        Seleccione las dos entidades a comparar
                    </p>
                    <div className="flex flex-col md:flex-row gap-4 items-start">
                        <SearchInput
                            label="Entidad A"
                            side="a"
                            filterType={activeType}
                            selected={selectedA}
                            onSelect={setSelectedA}
                        />

                        {/* VS divider */}
                        <div className="flex-shrink-0 flex flex-col items-center justify-center md:mt-6">
                            <div className="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
                                <span className="text-[11px] font-extrabold text-[var(--color-ink-faint)]">VS</span>
                            </div>
                        </div>

                        <SearchInput
                            label="Entidad B"
                            side="b"
                            filterType={activeType}
                            selected={selectedB}
                            onSelect={setSelectedB}
                        />
                    </div>

                    <div className="mt-5 flex items-center gap-4">
                        <button
                            onClick={handleComparar}
                            disabled={!canCompare || loading}
                            className={`px-8 py-2.5 rounded text-[12px] font-bold uppercase tracking-wider transition-all ${
                                canCompare && !loading
                                    ? 'bg-[var(--color-primary)] text-white hover:bg-[var(--color-primary-dark)] shadow-sm'
                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                            }`}
                        >
                            {loading ? 'Cargando...' : 'Comparar'}
                        </button>
                        {!canCompare && !loading && (
                            <p className="text-[11px] text-[var(--color-ink-faint)]">
                                {selectedA && selectedB && selectedA.id === selectedB.id
                                    ? 'Seleccione dos entidades diferentes.'
                                    : 'Seleccione las dos entidades para continuar.'}
                            </p>
                        )}
                    </div>
                </div>

                {/* Error */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-lg px-5 py-4">
                        <p className="text-sm text-red-700 font-medium">{error}</p>
                    </div>
                )}

                {/* Results */}
                {result && (
                    <div className="space-y-5">
                        {/* Legend */}
                        <div className="flex items-center gap-6 px-1">
                            <div className="flex items-center gap-2">
                                <div className="w-3 h-3 rounded-full bg-[var(--color-primary)]" />
                                <span className="text-[11px] font-semibold text-[var(--color-ink-faint)]">
                                    {result.a?.name ?? 'Entidad A'}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="w-3 h-3 rounded-full bg-[#C8016E]" />
                                <span className="text-[11px] font-semibold text-[var(--color-ink-faint)]">
                                    {result.b?.name ?? 'Entidad B'}
                                </span>
                            </div>
                        </div>

                        {/* Comparison bars */}
                        <ComparisonBars result={result} />

                        {/* Side-by-side detail cards */}
                        <div className="flex flex-col lg:flex-row gap-4">
                            {result.type === 'persona' && (
                                <>
                                    <PersonaResult data={result.a} side="a" />
                                    <PersonaResult data={result.b} side="b" />
                                </>
                            )}
                            {result.type === 'municipio' && (
                                <>
                                    <MunicipioResult data={result.a} side="a" />
                                    <MunicipioResult data={result.b} side="b" />
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* Empty state */}
                {!result && !loading && !error && (
                    <div className="bg-white rounded-lg border border-dashed border-[var(--color-line)] px-6 py-16 text-center">
                        <p className="text-[32px] mb-3">&#8644;</p>
                        <p className="text-[14px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider">
                            Sin comparación activa
                        </p>
                        <p className="text-[12px] text-[var(--color-ink-faint)] mt-1">
                            Busque y seleccione dos {activeType === 'persona' ? 'personas' : 'municipios'} para comenzar.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
