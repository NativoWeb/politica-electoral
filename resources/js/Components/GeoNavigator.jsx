import { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';

/**
 * Modal de navegación geográfica estilo Registraduría.
 * Se abre desde el breadcrumb con un botón.
 * Props:
 *   municipios: array [{id, name, provincia}]
 *   currentMunicipio: string (nombre actual) o null
 *   basePath: string como '/municipio' para construir la URL destino
 */
export default function GeoNavigator({ municipios = [], currentMunicipio, basePath = '/municipio' }) {
    const [open, setOpen] = useState(false);
    const [selectedProv, setSelectedProv] = useState('');
    const [search, setSearch] = useState('');

    const provincias = useMemo(() => {
        const map = {};
        municipios.forEach(m => {
            const p = m.provincia || 'Sin provincia';
            if (!map[p]) map[p] = [];
            map[p].push(m);
        });
        return Object.entries(map).sort(([a], [b]) => a.localeCompare(b));
    }, [municipios]);

    const filteredMunicipios = useMemo(() => {
        let list = selectedProv
            ? municipios.filter(m => m.provincia === selectedProv)
            : municipios;
        if (search) list = list.filter(m => m.name.toLowerCase().includes(search.toLowerCase()));
        return list;
    }, [municipios, selectedProv, search]);

    function navigate(mId) {
        setOpen(false);
        router.visit(`${basePath}/${mId}`);
    }

    return (
        <>
            {/* Trigger button — va junto al breadcrumb */}
            <button
                onClick={() => setOpen(true)}
                className="w-6 h-6 rounded-full bg-[var(--color-primary-light)] hover:bg-[var(--color-primary)] text-white flex items-center justify-center transition-colors"
                title="Cambiar municipio"
            >
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {/* Modal */}
            {open && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50" onClick={() => setOpen(false)}>
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[700px] mx-4 max-h-[80vh] flex flex-col" onClick={e => e.stopPropagation()}>

                        {/* Header */}
                        <div className="flex items-center justify-between px-6 py-5 border-b border-[var(--color-line)]">
                            <div>
                                <h2 className="text-[20px] font-extrabold text-[var(--color-primary)]">SANTANDER</h2>
                                <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">Seleccionar municipio</p>
                            </div>
                            <button onClick={() => setOpen(false)} className="w-10 h-10 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center hover:bg-[var(--color-primary-dark)] transition-colors">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Search */}
                        <div className="px-6 py-3 border-b border-[var(--color-line)]">
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Buscar municipio..."
                                className="w-full border border-[var(--color-line)] rounded-lg px-4 py-2.5 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]"
                                autoFocus
                            />
                        </div>

                        {/* Two-column: Provincias | Municipios */}
                        <div className="flex flex-1 min-h-0">
                            {/* Provincias */}
                            <div className="w-[220px] border-r border-[var(--color-line)] overflow-y-auto flex-shrink-0">
                                <p className="px-4 py-2.5 text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] bg-gray-50 border-b border-[var(--color-line)] sticky top-0">
                                    Provincias ({provincias.length})
                                </p>
                                <button
                                    onClick={() => setSelectedProv('')}
                                    className={`w-full text-left px-4 py-2.5 text-[13px] border-b border-[var(--color-line)] transition-colors ${!selectedProv ? 'bg-blue-50 text-[var(--color-primary)] font-bold' : 'text-[var(--color-ink-soft)] hover:bg-gray-50'}`}
                                >
                                    Todas
                                </button>
                                {provincias.map(([prov, mpios]) => (
                                    <button
                                        key={prov}
                                        onClick={() => setSelectedProv(prov)}
                                        className={`w-full text-left px-4 py-2.5 text-[13px] border-b border-[var(--color-line)] transition-colors ${selectedProv === prov ? 'bg-blue-50 text-[var(--color-primary)] font-bold' : 'text-[var(--color-ink-soft)] hover:bg-gray-50'}`}
                                    >
                                        {prov} <span className="text-[var(--color-ink-faint)]">({mpios.length})</span>
                                    </button>
                                ))}
                            </div>

                            {/* Municipios */}
                            <div className="flex-1 overflow-y-auto">
                                <p className="px-4 py-2.5 text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] bg-gray-50 border-b border-[var(--color-line)] sticky top-0">
                                    Municipios ({filteredMunicipios.length})
                                </p>
                                {filteredMunicipios.map(m => (
                                    <button
                                        key={m.id}
                                        onClick={() => navigate(m.id)}
                                        className={`w-full text-left px-4 py-2.5 text-[13px] border-b border-[var(--color-line)] transition-colors hover:bg-blue-50 hover:text-[var(--color-primary)] ${m.name === currentMunicipio ? 'bg-blue-50 text-[var(--color-primary)] font-bold' : 'text-[var(--color-ink)]'}`}
                                    >
                                        {m.name}
                                    </button>
                                ))}
                                {filteredMunicipios.length === 0 && (
                                    <p className="px-4 py-8 text-center text-[13px] text-[var(--color-ink-faint)]">Sin resultados</p>
                                )}
                            </div>
                        </div>

                        {/* Footer */}
                        <div className="px-6 py-4 border-t border-[var(--color-line)] flex justify-end">
                            <button onClick={() => setOpen(false)} className="px-6 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-full hover:bg-[var(--color-primary-dark)] transition-colors">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
