import { useMemo, useState } from 'react';
import { matchingBarrios, sameBarrio } from '@/lib/eventosIntegracion';

const selectCls = 'w-full min-h-[48px] px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[16px] bg-white focus:outline-none focus:border-[var(--color-primary)]';
const labelCls = 'block text-[15px] font-bold text-[var(--color-ink)] mb-1';

// Municipio (by province) and optional barrio for the territory view. onSearch({ municipio, barrio }).
export default function TerritorioFiltros({ provincias = [], barrios = [], filters = {}, onSearch }) {
    const initialProvincia = useMemo(
        () => provincias.find(p => p.municipios.some(m => m.id === filters.municipio))?.id ?? '',
        [provincias, filters.municipio],
    );
    const [provincia, setProvincia] = useState(initialProvincia);
    const [barrio, setBarrio] = useState(filters.barrio ?? '');
    const municipios = useMemo(
        () => (provincia ? provincias.find(p => p.id === provincia)?.municipios ?? [] : provincias.flatMap(p => p.municipios)),
        [provincias, provincia],
    );
    const suggestions = filters.municipio ? matchingBarrios(barrios, barrio) : [];

    function searchBarrio(value) {
        const clean = String(value ?? '').replace(/\s+/g, ' ').trim();
        setBarrio(clean);
        onSearch({ municipio: filters.municipio, barrio: clean || null });
    }

    return (
        <div className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3">
            <div className="grid sm:grid-cols-2 gap-3">
                <div>
                    <label htmlFor="t-provincia" className={labelCls}>Provincia</label>
                    <select id="t-provincia" className={selectCls} value={provincia} onChange={e => setProvincia(e.target.value)}>
                        <option value="">Todas</option>
                        {provincias.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor="t-municipio" className={labelCls}>Municipio</label>
                    <select
                        id="t-municipio"
                        className={selectCls}
                        value={filters.municipio ?? ''}
                        onChange={e => { setBarrio(''); onSearch({ municipio: e.target.value || null, barrio: null }); }}
                    >
                        <option value="">Elija un municipio</option>
                        {municipios.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </div>
            </div>

            {filters.municipio && (
                <form onSubmit={e => { e.preventDefault(); searchBarrio(barrio); }} className="space-y-2">
                    <label htmlFor="t-barrio" className={labelCls}>Barrio (opcional)</label>
                    <div className="flex gap-2">
                        <input
                            id="t-barrio"
                            value={barrio}
                            onChange={e => setBarrio(e.target.value)}
                            placeholder="Escriba el barrio o elija uno de la lista"
                            autoComplete="off"
                            className="flex-1 min-w-0 min-h-[48px] px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[16px] focus:outline-none focus:border-[var(--color-primary)]"
                        />
                        <button type="submit" className="min-h-[48px] px-4 py-2.5 bg-[var(--color-primary)] text-white rounded-lg text-[16px] font-bold flex-shrink-0">Buscar</button>
                    </div>
                    {suggestions.length > 0 && !(filters.barrio && sameBarrio(barrio, filters.barrio)) && (
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Barrios sugeridos">
                            {suggestions.map(b => (
                                <button key={b.key} type="button" onClick={() => searchBarrio(b.nombre)} className="min-h-[44px] px-3.5 py-2 rounded-full border border-[var(--color-line-strong)] bg-white text-[15px] font-semibold text-[var(--color-ink)]">
                                    {b.nombre}
                                </button>
                            ))}
                        </div>
                    )}
                    {filters.barrio && (
                        <button type="button" onClick={() => searchBarrio('')} className="min-h-[44px] text-[15px] font-bold text-red-700 underline">
                            Ver todo el municipio (quitar barrio)
                        </button>
                    )}
                </form>
            )}
        </div>
    );
}
