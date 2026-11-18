import { useEffect, useId, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';

const MAX_RESULTS = 8;
const DEBOUNCE_MS = 300;

export const NO_REGISTRADA = 'Esta persona no está registrada en el sistema';

// Splits a full name into nombre/apellido: with 4 or more words the first two are the nombre
// ("Maria Fernanda | Rueda Gomez"); with 2 or 3 only the first ("Pedro | Rueda Gomez"); one word is all nombre.
export function splitNombre(full) {
    const words = String(full || '').trim().split(/\s+/).filter(Boolean);
    const take = words.length >= 4 ? 2 : 1;
    return { nombre: words.slice(0, take).join(' '), apellido: words.slice(take).join(' ') };
}

// Same normalization as App\Support\Cedula: "1.098.765" is "1098765"; anything with letters is not a cédula.
function asCedula(q) {
    const digits = String(q || '').trim().replace(/[\s.,'´’‘`-]/g, '');
    return /^\d+$/.test(digits) ? digits : null;
}

// The referente of a ficha is a person already registered in the user's own campaign: it is searched by cédula or
// name and only an existing person (with cédula, the referido relation) can be applied. Its name is read-only and
// still saved in referente_nombre/apellido. A referente written by hand in an older ficha is shown as is and can be
// removed or replaced.
export default function ReferenteFields({ values, onChange, excludeId, inputProps, inputClassName, labelClassName, className }) {
    const uid = useId();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [status, setStatus] = useState('idle'); // idle | loading | done | offline | error
    const [changing, setChanging] = useState(false);
    const timer = useRef(null);
    const seq = useRef(0);

    useEffect(() => () => clearTimeout(timer.current), []);

    const documento = String(values.referente_documento || '').trim();
    const nombreCompleto = [values.referente_nombre, values.referente_apellido].map(v => String(v || '').trim()).filter(Boolean).join(' ');
    const hasReferente = Boolean(documento || nombreCompleto);
    const searching = !hasReferente || changing;

    function reset() {
        clearTimeout(timer.current);
        seq.current += 1;
        setResults([]);
        setStatus('idle');
    }

    function apply(lider) {
        const { nombre, apellido } = splitNombre(lider.nombre);
        onChange('referente_documento', lider.cedula);
        onChange('referente_nombre', nombre);
        onChange('referente_apellido', apellido);
        setQuery('');
        setChanging(false);
        reset();
    }

    function quitar() {
        onChange('referente_documento', '');
        onChange('referente_nombre', '');
        onChange('referente_apellido', '');
        setQuery('');
        setChanging(false);
        reset();
    }

    function search(q) {
        setQuery(q);
        reset();
        const term = q.trim();
        if (term.length < 2) return;
        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            setStatus('offline');
            return;
        }
        setStatus('loading');
        timer.current = setTimeout(() => {
            const id = ++seq.current;
            const exclude = excludeId ? `&exclude_id=${encodeURIComponent(excludeId)}` : '';
            apiFetch(`/mapa-politico/search-lideres?q=${encodeURIComponent(term)}${exclude}`)
                .then(r => (r.ok ? r.json() : Promise.reject(r)))
                .then(res => {
                    if (id !== seq.current) return;
                    const data = res.data || [];
                    const cedula = asCedula(term);
                    const exact = cedula && data.find(r => r.cedula && String(r.cedula) === cedula);
                    // "10987654" is complete for one person and the start of "1098765432" for another: only a
                    // cédula nobody else's starts with is applied by itself; otherwise the exact one is listed first.
                    if (exact && data.filter(r => String(r.cedula ?? '').startsWith(cedula)).length === 1) {
                        apply(exact);
                        return;
                    }
                    setResults((exact ? [exact, ...data.filter(r => r !== exact)] : data).slice(0, MAX_RESULTS));
                    setStatus('done');
                })
                .catch(() => {
                    if (id === seq.current) setStatus('error');
                });
        }, DEBOUNCE_MS);
    }

    const readCls = 'w-full rounded-lg px-4 py-3 text-[16px] bg-gray-50 border border-[var(--color-line)] text-[var(--color-ink)]';

    return (
        <div className="space-y-3">
            {hasReferente && (
                <div>
                    <div className={className || 'grid grid-cols-1 sm:grid-cols-3 gap-3'}>
                        <div>
                            <span className={labelClassName}>Documento</span>
                            <p className={readCls} data-testid="referente-documento">{documento || '-'}</p>
                        </div>
                        <div className="sm:col-span-2">
                            <span className={labelClassName}>Nombre</span>
                            <p className={readCls} data-testid="referente-nombre">{nombreCompleto || '-'}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 mt-2">
                        <button type="button" onClick={quitar} className="px-3 py-2 text-[13px] font-semibold text-red-700 border border-red-200 rounded-lg hover:bg-red-50">
                            Quitar referente
                        </button>
                        {!changing && (
                            <button type="button" onClick={() => setChanging(true)} className="px-3 py-2 text-[13px] font-semibold text-[var(--color-primary)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">
                                Cambiar referente
                            </button>
                        )}
                    </div>
                </div>
            )}

            {searching && (
                <div onKeyDown={e => { if (e.key === 'Escape' && (results.length || status !== 'idle')) { e.stopPropagation(); reset(); } }}>
                    <label className={labelClassName} htmlFor={`${uid}-buscar`}>Buscar referente por cédula o nombre</label>
                    <input
                        id={`${uid}-buscar`}
                        {...inputProps}
                        className={inputClassName}
                        value={query}
                        autoComplete="off"
                        placeholder="Cédula o nombre de una persona registrada"
                        onChange={e => search(e.target.value)}
                    />
                    {results.length > 0 && (
                        <ul role="listbox" aria-label="Personas encontradas" className="mt-1 border border-[var(--color-line)] rounded-lg bg-white shadow-lg max-h-72 overflow-y-auto">
                            {results.map(r => (
                                <li key={r.id} role="option" aria-selected="false" aria-disabled={!r.cedula}>
                                    <button
                                        type="button"
                                        disabled={!r.cedula}
                                        onClick={() => apply(r)}
                                        className="w-full text-left px-3 py-2 hover:bg-[var(--color-surface-2,#f3f4f6)] border-b border-[var(--color-line)] last:border-b-0 disabled:opacity-60 disabled:cursor-not-allowed"
                                    >
                                        <span className="block text-[14px] font-semibold text-[var(--color-ink)]">{r.nombre}</span>
                                        <span className="block text-[12px] text-[var(--color-ink-faint)]">
                                            {[r.cedula ? `CC ${r.cedula}` : 'Sin cédula: no se puede elegir como referente', r.municipio, r.cargo].filter(Boolean).join(' · ')}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {status === 'done' && results.length === 0 && (
                        <p role="alert" className="mt-2 text-[13px] font-semibold text-amber-700">{NO_REGISTRADA}</p>
                    )}
                    {status === 'offline' && (
                        <p role="status" className="mt-2 text-[13px] text-[var(--color-ink-faint)]">Sin conexión: el referente se puede elegir cuando vuelva el internet.</p>
                    )}
                    {status === 'error' && (
                        <p role="status" className="mt-2 text-[13px] text-red-700">No se pudo buscar. Intenta de nuevo.</p>
                    )}
                    {changing && (
                        <button type="button" onClick={() => { setChanging(false); setQuery(''); reset(); }} className="mt-2 text-[13px] text-[var(--color-ink-faint)] underline">
                            Cancelar cambio
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
