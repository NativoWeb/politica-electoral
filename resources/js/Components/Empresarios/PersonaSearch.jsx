import { useEffect, useState } from 'react';
import { request } from '@/lib/actas';

export const inputCls = 'w-full min-w-0 px-3 py-2.5 border border-[var(--color-line-strong)] rounded-lg text-[15px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:bg-gray-50';

// Search box over the campaign's fichas in Votantes (in the user's territory). onPick(lider) with a found ficha;
// onNew(name) when "agregar como persona nueva" is chosen (only if allowNew).
export default function PersonaSearch({ id, label, onPick, onNew, allowNew = false, url = '/empresarios/buscar/personas', placeholder = 'Nombre o cédula' }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState([]);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) { setFound([]); setSearching(false); return undefined; }
        setSearching(true);
        const timer = setTimeout(() => {
            request(`${url}?q=${encodeURIComponent(term)}`).then(res => {
                setFound(res.ok ? (res.body?.data ?? []) : []);
                setSearching(false);
            });
        }, 300);
        return () => clearTimeout(timer);
    }, [q, url]);

    return (
        <div className="space-y-2">
            <label htmlFor={id} className="block text-[14px] font-semibold text-[var(--color-ink)]">{label}</label>
            <input id={id} className={inputCls} value={q} onChange={e => setQ(e.target.value)} placeholder={placeholder} autoComplete="off" />
            {q.trim().length >= 2 && (
                <div className="space-y-1" aria-live="polite">
                    {found.length > 0 && (
                        <ul className="space-y-1" aria-label="Personas encontradas en Votantes">
                            {found.map(l => (
                                <li key={l.id}>
                                    <button type="button" onClick={() => { onPick(l); setQ(''); }} className="w-full min-h-[44px] px-3 py-2 rounded-lg border border-[var(--color-line)] text-left text-[15px] hover:bg-blue-50">
                                        <span className="font-bold">{l.nombre}</span>
                                        <span className="text-[13px] text-[var(--color-ink-soft)]">{[l.cargo, l.municipio, l.cedula && `C.C. ${l.cedula}`, l.telefono].filter(Boolean).map(t => ` · ${t}`).join('')}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {searching && <p className="text-[14px] text-[var(--color-ink-faint)]">Buscando en Votantes…</p>}
                    {!searching && found.length === 0 && <p className="text-[14px] text-[var(--color-ink-faint)]">No está en Votantes.</p>}
                    {allowNew && (
                        <button type="button" onClick={() => { onNew(q.trim()); setQ(''); }} className="w-full min-h-[44px] px-3 py-2 rounded-lg border-2 border-dashed border-[var(--color-primary)] text-left text-[15px] font-bold text-[var(--color-primary)]">
                            + Agregar «{q.trim()}» como persona nueva
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
