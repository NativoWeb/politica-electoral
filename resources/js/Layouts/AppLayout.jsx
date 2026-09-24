import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { FullScreenSpinner } from '@/Components/Spinner';
import { OfflineIndicator } from '@/Components/OfflineManager';

const TYPE_LABELS = { persona: 'Persona', municipio: 'Municipio', partido: 'Partido' };
const TYPE_COLORS = {
    persona: 'bg-blue-100 text-blue-700',
    municipio: 'bg-emerald-100 text-emerald-700',
    partido: 'bg-amber-100 text-amber-700',
};

function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [showDropdown, setShowDropdown] = useState(false);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);
    const containerRef = useRef(null);

    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) setShowDropdown(false);
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    function handleChange(e) {
        const val = e.target.value;
        setQuery(val);
        if (debounceRef.current) clearTimeout(debounceRef.current);
        if (val.trim().length < 2) { setResults([]); setShowDropdown(false); return; }
        debounceRef.current = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(`/api/search?q=${encodeURIComponent(val.trim())}`);
                const data = await res.json();
                setResults(data.results ?? []);
                setShowDropdown(true);
            } catch { setResults([]); }
            finally { setLoading(false); }
        }, 300);
    }

    function handleSelect(url) { setShowDropdown(false); setQuery(''); router.visit(url); }

    const grouped = results.reduce((acc, r) => { acc[r.type] = acc[r.type] ?? []; acc[r.type].push(r); return acc; }, {});

    return (
        <div ref={containerRef} className="relative w-full">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
            <input
                type="text"
                value={query}
                onChange={handleChange}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') setShowDropdown(false);
                    if (e.key === 'Enter' && results.length > 0) handleSelect(results[0].url);
                }}
                onFocus={() => results.length > 0 && setShowDropdown(true)}
                placeholder="Buscar persona, municipio, partido..."
                className="w-full pl-9 pr-4 py-2 bg-white border border-gray-200 rounded-lg text-sm text-[var(--color-ink)] placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-light)]"
            />
            {showDropdown && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-80 overflow-y-auto">
                    {loading && <div className="px-4 py-3 text-sm text-gray-400 text-center">Buscando...</div>}
                    {!loading && results.length === 0 && <div className="px-4 py-3 text-sm text-gray-400 text-center">Sin resultados</div>}
                    {!loading && Object.entries(grouped).map(([type, items]) => (
                        <div key={type}>
                            <div className="px-4 py-1.5 text-[10px] font-bold tracking-widest text-gray-400 uppercase bg-gray-50 border-b border-gray-100">
                                {TYPE_LABELS[type] ?? type}
                            </div>
                            {items.map((item) => (
                                <button
                                    key={item.id}
                                    onClick={() => handleSelect(item.url)}
                                    className="w-full text-left px-4 py-2.5 hover:bg-blue-50 flex items-center gap-3 border-b border-gray-50 last:border-0 transition-colors"
                                >
                                    <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded flex-shrink-0 ${TYPE_COLORS[type] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {TYPE_LABELS[type] ?? type}
                                    </span>
                                    <span className="flex-1 min-w-0">
                                        <span className="block text-sm font-medium text-gray-900 truncate">{item.name}</span>
                                        {item.detail && <span className="block text-xs text-gray-400 truncate">{item.detail}</span>}
                                    </span>
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ---- Sidebar icons (SVG inline, lightweight) ---- */
const ICONS = {
    ALCALDE: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M12 12c2.5 0 4.5-2 4.5-4.5S14.5 3 12 3 7.5 5 7.5 7.5 9.5 12 12 12z" />
            <path d="M20 21c0-3.3-3.6-6-8-6s-8 2.7-8 6" strokeLinecap="round" />
        </svg>
    ),
    GOBERNADOR: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M3 21h18M5 21V7l7-4 7 4v14" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M9 21v-4h6v4M9 10h.01M15 10h.01M9 14h.01M15 14h.01" strokeLinecap="round" />
        </svg>
    ),
    CONCEJO: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" strokeLinecap="round" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" strokeLinecap="round" />
        </svg>
    ),
    ASAMBLEA: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    ),
    'CAMARA': (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <rect x="2" y="3" width="20" height="14" rx="2" />
            <path d="M8 21h8M12 17v4" strokeLinecap="round" />
        </svg>
    ),
    SENADO: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M3 21h18M4 21V10l8-7 8 7v11" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M10 21v-6h4v6" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M9 7h6" strokeLinecap="round" />
        </svg>
    ),
    MAPA: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    ),
};

/* ── Export buttons ── */
function ExportButtons({ url }) {
    const [open, setOpen] = useState(false);

    function getExportUrl(format) {
        // Get current full URL with all query params from the browser
        const currentUrl = new URL(window.location.href);
        const params = new URLSearchParams(currentUrl.search);

        // Also parse Inertia URL in case params are there
        const inertiaUrl = url.includes('?') ? url : '';
        if (inertiaUrl) {
            const inertiaParams = new URLSearchParams(inertiaUrl.split('?')[1] || '');
            for (const [k, v] of inertiaParams) {
                if (!params.has(k)) params.set(k, v);
            }
        }

        // Detect source from URL path
        const path = currentUrl.pathname;
        let source = 'mapa-politico';
        let title = 'Mapa Politico';
        if (path.startsWith('/senado')) { source = 'senado'; title = 'Senado'; }
        else if (path.startsWith('/camara')) { source = 'camara'; title = 'Camara'; }
        else if (path.startsWith('/gobernador')) { source = 'gobernador'; title = 'Gobernador'; }
        else if (path.startsWith('/asamblea')) { source = 'asamblea'; title = 'Asamblea'; }
        else if (path.startsWith('/mapa-politico')) { source = 'mapa-politico'; title = 'Mapa Politico'; }

        // Extract municipio ID from path like /senado/uuid
        const pathParts = path.split('/').filter(Boolean);
        if (pathParts.length >= 2 && pathParts[1].length > 10) {
            params.set('municipio', pathParts[1]);
        }

        params.set('source', source);
        params.set('title', title);

        return `/exportar/${format}?${params.toString()}`;
    }

    return (
        <div className="relative">
            <button onClick={() => setOpen(!open)} className="flex items-center gap-1.5 px-3 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-white/70 hover:text-white transition-colors" title="Exportar">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span className="text-[11px] font-semibold hidden sm:inline">Exportar</span>
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
                    <div className="absolute right-0 top-full mt-1 w-48 bg-white rounded-lg shadow-xl border border-gray-200 z-50 overflow-hidden">
                        <a href={getExportUrl('excel')} className="flex items-center gap-3 px-4 py-3 hover:bg-green-50 text-[13px] font-semibold text-[var(--color-ink)] border-b border-gray-100 transition-colors" onClick={() => setOpen(false)}>
                            <svg className="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                            Excel (.xlsx)
                        </a>
                        <a href={getExportUrl('pdf')} className="flex items-center gap-3 px-4 py-3 hover:bg-red-50 text-[13px] font-semibold text-[var(--color-ink)] transition-colors" onClick={() => setOpen(false)}>
                            <svg className="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                            PDF (.pdf)
                        </a>
                    </div>
                </>
            )}
        </div>
    );
}

const SIDEBAR_ITEMS = [
    { name: 'GOBERNADOR', href: '/gobernador', label: 'Gobernador' },
    { name: 'MAPA', href: '/mapa-politico', label: 'Mapa Politico' },
    { name: 'USUARIOS', href: '/admin/usuarios', label: 'Usuarios', adminOnly: true },
];

const ICONS_EXTRA = {
    USUARIOS: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="w-5 h-5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" strokeLinecap="round" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" strokeLinecap="round" />
        </svg>
    ),
};

/* ── Mobile Export Sheet ── */
function MobileExportSheet({ open, onClose, url }) {
    if (!open) return null;

    function getExportUrl(format) {
        const currentUrl = new URL(window.location.href);
        const params = new URLSearchParams(currentUrl.search);
        const inertiaUrl = url.includes('?') ? url : '';
        if (inertiaUrl) {
            const inertiaParams = new URLSearchParams(inertiaUrl.split('?')[1] || '');
            for (const [k, v] of inertiaParams) {
                if (!params.has(k)) params.set(k, v);
            }
        }
        const path = currentUrl.pathname;
        let source = 'mapa-politico', title = 'Mapa Politico';
        if (path.startsWith('/senado')) { source = 'senado'; title = 'Senado'; }
        else if (path.startsWith('/camara')) { source = 'camara'; title = 'Camara'; }
        else if (path.startsWith('/gobernador')) { source = 'gobernador'; title = 'Gobernador'; }
        else if (path.startsWith('/asamblea')) { source = 'asamblea'; title = 'Asamblea'; }
        const pathParts = path.split('/').filter(Boolean);
        if (pathParts.length >= 2 && pathParts[1].length > 10) params.set('municipio', pathParts[1]);
        params.set('source', source);
        params.set('title', title);
        return `/exportar/${format}?${params.toString()}`;
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-50" onClick={onClose} />
            <div className="fixed bottom-0 left-0 right-0 z-50 bg-white rounded-t-3xl safe-bottom animate-slide-up">
                <div className="w-12 h-1.5 bg-gray-300 rounded-full mx-auto mt-3" />
                <div className="px-6 pt-5 pb-3">
                    <h3 className="text-[18px] font-extrabold text-[var(--color-ink)]">Descargar datos</h3>
                    <p className="text-[14px] text-[var(--color-ink-faint)] mt-1">Elige el formato para exportar</p>
                </div>
                <div className="px-4 pb-6 space-y-3">
                    <a
                        href={getExportUrl('excel')}
                        onClick={onClose}
                        className="flex items-center gap-4 px-5 py-5 bg-emerald-50 border-2 border-emerald-200 rounded-2xl active:bg-emerald-100 transition-colors"
                    >
                        <div className="w-14 h-14 rounded-xl bg-emerald-500 flex items-center justify-center flex-shrink-0">
                            <svg className="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                        </div>
                        <div>
                            <p className="text-[17px] font-bold text-emerald-800">Excel</p>
                            <p className="text-[13px] text-emerald-600">Archivo .xlsx para abrir en Excel</p>
                        </div>
                    </a>
                    <a
                        href={getExportUrl('pdf')}
                        onClick={onClose}
                        className="flex items-center gap-4 px-5 py-5 bg-red-50 border-2 border-red-200 rounded-2xl active:bg-red-100 transition-colors"
                    >
                        <div className="w-14 h-14 rounded-xl bg-red-500 flex items-center justify-center flex-shrink-0">
                            <svg className="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                        </div>
                        <div>
                            <p className="text-[17px] font-bold text-red-800">PDF</p>
                            <p className="text-[13px] text-red-600">Documento para imprimir o compartir</p>
                        </div>
                    </a>
                    <button onClick={onClose} className="w-full py-4 text-[16px] font-bold text-[var(--color-ink-faint)] rounded-2xl border-2 border-gray-200 active:bg-gray-100 mt-2">
                        Cancelar
                    </button>
                </div>
            </div>
        </>
    );
}

export default function AppLayout({ children, title, breadcrumb }) {
    const { url, props } = usePage();
    const auth = props.auth?.user;
    const isSuperadmin = auth?.role === 'Superadministrador';
    const visibleItems = SIDEBAR_ITEMS.filter(item => !item.adminOnly || isSuperadmin);
    const [navigating, setNavigating] = useState(false);
    const [exportSheetOpen, setExportSheetOpen] = useState(false);
    const [offlineAlert, setOfflineAlert] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        const removeError = router.on('error', () => {
            setNavigating(false);
            if (!navigator.onLine) {
                setOfflineAlert(true);
                setTimeout(() => setOfflineAlert(false), 4000);
            }
        });
        return () => { removeStart(); removeFinish(); removeError(); };
    }, []);

    return (
        <div className="min-h-screen bg-[var(--color-bg)]">
            {/* Header */}
            <header className="bg-[var(--color-primary)] text-white relative z-40 safe-top">
                <div className="flex items-center justify-between px-3 lg:px-6 h-11 lg:h-14">
                    <div className="flex items-center gap-3">
                        <Link href="/" className="flex items-center gap-2 lg:gap-3">
                            <div className="w-6 h-4 lg:w-7 lg:h-5 rounded-[3px] overflow-hidden flex flex-col flex-shrink-0 shadow-sm">
                                <span className="flex-[2] bg-[#FCD116]" />
                                <span className="flex-1 bg-[#003893]" />
                                <span className="flex-1 bg-[#CE1126]" />
                            </div>
                            <div>
                                <p className="text-[11px] lg:text-[13px] font-bold tracking-wide leading-tight">ELECTORAL</p>
                                <p className="text-[8px] lg:text-[9px] text-white/50 tracking-[0.2em] hidden lg:block">SANTANDER</p>
                            </div>
                        </Link>
                    </div>

                    <div className="hidden md:block flex-1 max-w-lg mx-8">
                        <GlobalSearch />
                    </div>

                    {/* Desktop header actions */}
                    <div className="hidden lg:flex items-center gap-2 flex-shrink-0">
                        <OfflineIndicator />
                        <ExportButtons url={url} />
                        <div className="relative group">
                            <button className="flex items-center gap-2 hover:bg-white/10 rounded-lg px-2 py-1.5 transition-colors">
                                <div className="w-8 h-8 rounded-full bg-white/15 border border-white/20 flex items-center justify-center text-white text-[12px] font-bold flex-shrink-0">
                                    {auth?.name?.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() || 'U'}
                                </div>
                                <div className="text-right">
                                    <p className="text-[11px] font-semibold text-white/90 leading-tight">{auth?.name || 'Usuario'}</p>
                                    <p className="text-[9px] text-white/40">{auth?.role || auth?.email || ''}</p>
                                </div>
                            </button>
                            <div className="absolute right-0 top-full mt-1 w-48 bg-white rounded-lg shadow-xl border border-gray-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                                <div className="px-4 py-3 border-b border-gray-100">
                                    <p className="text-[12px] font-semibold text-[var(--color-ink)] truncate">{auth?.name}</p>
                                    <p className="text-[10px] text-[var(--color-ink-faint)] truncate">{auth?.email}</p>
                                </div>
                                <form method="POST" action="/logout">
                                    <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
                                    <button type="submit" className="w-full text-left px-4 py-2.5 text-[12px] text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors">
                                        Cerrar sesion
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {/* Mobile: offline indicator + logout */}
                    <div className="lg:hidden flex items-center gap-2">
                        <OfflineIndicator />
                        <form method="POST" action="/logout">
                            <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content} />
                            <button type="submit" className="w-9 h-9 rounded-full bg-white/15 flex items-center justify-center active:bg-white/30" title="Cerrar sesion">
                                <svg className="w-4 h-4 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                            </button>
                        </form>
                    </div>
                </div>

                {/* Breadcrumb — desktop only */}
                {breadcrumb && (
                    <div className="hidden lg:flex bg-black/15 px-4 lg:pl-[calc(200px+1.5rem)] py-1.5 text-[11px] items-center gap-1.5 text-white/60">
                        {breadcrumb.map((item, i) => (
                            <span key={i} className="flex items-center gap-1.5">
                                {i > 0 && <span className="text-white/25">/</span>}
                                {item.href ? (
                                    <Link href={item.href} className="hover:text-white transition-colors">{item.label}</Link>
                                ) : (
                                    <span className="text-white/90 font-semibold">{item.label}</span>
                                )}
                            </span>
                        ))}
                    </div>
                )}
            </header>

            <div className="flex">
                {/* Sidebar — desktop only */}
                <aside className="hidden lg:flex fixed lg:sticky top-0 left-0 z-30 lg:z-0 w-[200px] bg-[#002244] h-screen overflow-y-auto overflow-x-hidden flex-col">
                    <div className="h-14 flex-shrink-0" />
                    <nav className="flex flex-col py-3 gap-0.5 px-2">
                        {visibleItems.map((item) => {
                            const isActive = url === item.href || (item.href !== '/' && url.startsWith(item.href));
                            return (
                                <Link
                                    key={item.name}
                                    href={item.href}
                                    prefetch="hover"
                                    className={`
                                        relative flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all text-[13px] font-semibold tracking-wide
                                        ${isActive
                                            ? 'bg-white/15 text-white shadow-sm'
                                            : 'text-white/50 hover:text-white/90 hover:bg-white/8'
                                        }
                                    `}
                                >
                                    {isActive && (
                                        <div className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] h-5 bg-[var(--color-accent)] rounded-r-full" />
                                    )}
                                    <span className="flex-shrink-0">{ICONS[item.name] || ICONS_EXTRA[item.name]}</span>
                                    <span>{item.label}</span>
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                {/* Main content — pb-24 on mobile for tab bar */}
                <main className="flex-1 min-w-0 pb-24 lg:pb-0">
                    {children}
                </main>
            </div>

            {/* ══ Bottom Tab Bar — mobile only, BIG for elderly users ══ */}
            <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#002244] safe-bottom shadow-[0_-4px_20px_rgba(0,0,0,0.3)]">
                <div className="flex items-stretch h-[72px]">
                    {visibleItems.map((item) => {
                        const isActive = url === item.href || (item.href !== '/' && url.startsWith(item.href));
                        return (
                            <Link
                                key={item.name}
                                href={item.href}
                                prefetch="hover"
                                className={`flex flex-col items-center justify-center flex-1 gap-1 transition-colors active:bg-white/10 ${
                                    isActive ? 'text-[var(--color-accent)]' : 'text-white/50'
                                }`}
                            >
                                <span className="w-7 h-7 flex items-center justify-center [&>svg]:w-7 [&>svg]:h-7">
                                    {ICONS[item.name] || ICONS_EXTRA[item.name]}
                                </span>
                                <span className="text-[12px] font-bold leading-tight">{item.label}</span>
                                {isActive && <span className="w-6 h-[3px] bg-[var(--color-accent)] rounded-full" />}
                            </Link>
                        );
                    })}

                    {/* Exportar Excel/PDF */}
                    <button
                        onClick={() => setExportSheetOpen(true)}
                        className="flex flex-col items-center justify-center flex-1 gap-1 text-white/50 active:bg-white/10 transition-colors"
                    >
                        <svg className="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span className="text-[12px] font-bold leading-tight">Exportar</span>
                    </button>
                </div>
            </nav>

            {/* Spinner global de navegación */}
            {navigating && <FullScreenSpinner message="Cargando..." />}

            {/* Offline navigation alert */}
            {offlineAlert && (
                <div className="fixed top-16 left-1/2 -translate-x-1/2 z-50 px-5 py-4 bg-red-500 text-white rounded-2xl shadow-2xl flex items-center gap-3 max-w-[90vw] animate-slide-up">
                    <svg className="w-7 h-7 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728" /><line x1="4" y1="4" x2="20" y2="20" strokeLinecap="round" strokeWidth={2.5} /></svg>
                    <div>
                        <p className="text-[15px] font-bold">Sin conexion</p>
                        <p className="text-[13px] text-white/80">No puedes navegar sin internet</p>
                    </div>
                </div>
            )}

            {/* Mobile export bottom sheet */}
            <MobileExportSheet open={exportSheetOpen} onClose={() => setExportSheetOpen(false)} url={url} />
        </div>
    );
}
