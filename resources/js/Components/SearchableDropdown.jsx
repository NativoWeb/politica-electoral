import { useState } from 'react';

export default function SearchableDropdown({ label, options, selected, onChange, placeholder = 'Todos', multi = true }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const selectedArr = multi ? (selected ? selected.split(',') : []) : [];
    const filtered = options.filter(o => !search || o.toLowerCase().includes(search.toLowerCase()));

    function toggle(val) {
        if (multi) {
            const next = selectedArr.includes(val) ? selectedArr.filter(x => x !== val) : [...selectedArr, val];
            onChange(next.length > 0 ? next.join(',') : undefined);
        } else {
            onChange(selected === val ? undefined : val);
            setOpen(false);
        }
    }

    function clear() { onChange(undefined); setSearch(''); setOpen(false); }

    const displayText = multi
        ? (selectedArr.length > 0 ? `${selectedArr.length} sel.` : placeholder)
        : (selected || placeholder);

    return (
        <div className="relative">
            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">{label}</label>
            <button
                type="button"
                onClick={() => { setOpen(!open); setSearch(''); }}
                className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[220px] focus:outline-none focus:border-[var(--color-primary)] font-semibold text-left flex items-center justify-between bg-white"
            >
                <span className={`truncate ${(multi ? selectedArr.length > 0 : !!selected) ? 'text-[var(--color-ink)]' : 'text-gray-400'}`}>
                    {displayText}
                </span>
                <svg className={`w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
                    <div className="absolute top-full left-0 right-0 lg:right-auto mt-1 w-auto lg:w-[300px] bg-white rounded-xl shadow-2xl border border-[var(--color-line)] z-40 max-h-[350px] flex flex-col" role="listbox" aria-label={label}>
                        <div className="px-3 pt-3 pb-2 border-b border-[var(--color-line)]">
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Buscar..."
                                className="w-full px-3 py-2 border border-[var(--color-line)] rounded-lg text-[13px] focus:outline-none focus:border-[var(--color-primary)]"
                                autoFocus
                            />
                        </div>
                        <button onClick={clear} className="w-full text-left px-4 py-2 text-[13px] text-[var(--color-primary)] font-semibold border-b border-[var(--color-line)] hover:bg-blue-50">
                            Limpiar
                        </button>
                        <div className="overflow-y-auto flex-1">
                            {filtered.length === 0 && <p className="px-4 py-3 text-[13px] text-[var(--color-ink-faint)]">Sin resultados</p>}
                            {filtered.map(opt => {
                                const checked = multi ? selectedArr.includes(opt) : selected === opt;
                                return (
                                    <label key={opt} role="option" aria-selected={checked} className={`flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-gray-50 border-b border-gray-100 last:border-0 ${checked ? 'bg-blue-50' : ''}`}>
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            onChange={() => toggle(opt)}
                                            className="w-5 h-5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                        />
                                        <span className={`text-[13px] ${checked ? 'font-bold text-[var(--color-primary)]' : 'text-[var(--color-ink-soft)]'}`}>{opt}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
