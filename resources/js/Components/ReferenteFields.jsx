import { useEffect, useId, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';

const MAX_RESULTS = 8;
const DEBOUNCE_MS = 300;

// Splits a full name into nombre/apellido: with 4 or more words the first two are the nombre
// ("Maria Fernanda | Rueda Gomez"); with 2 or 3 only the first ("Pedro | Rueda Gomez"); one word is all nombre.
export function splitNombre(full) {
    const words = String(full || '').trim().split(/\s+/).filter(Boolean);
    const take = words.length >= 4 ? 2 : 1;
    return { nombre: words.slice(0, take).join(' '), apellido: words.slice(take).join(' ') };
}

// The referente of a ficha: typing its document or name searches the user's own lideres and picking one fills the
// three fields. The referido relation is the cédula (lideres.referente_documento = referente's cedula), so a
// referente written without it is only a note. Offline, or when nobody matches, the fields are plain inputs.
export default function ReferenteFields({ values, onChange, excludeId, inputClassName, labelClassName, className }) {
    const [results, setResults] = useState([]);
    // Unique per instance: Votantes mounts two details at once (desktop and mobile).
    const uid = useId();
    const [open, setOpen] = useState(false);
    const [searched, setSearched] = useState(false);
    const timer = useRef(null);
    const seq = useRef(0);

    useEffect(() => () => clearTimeout(timer.current), []);

    function close() {
        clearTimeout(timer.current);
        seq.current += 1;
        setOpen(false);
        setResults([]);
        setSearched(false);
    }

    function search(q) {
        clearTimeout(timer.current);
        const term = q.trim();
        if (term.length < 2 || (typeof navigator !== 'undefined' && navigator.onLine === false)) {
            close();
            return;
        }
        timer.current = setTimeout(() => {
            const id = ++seq.current;
            const exclude = excludeId ? `&exclude_id=${encodeURIComponent(excludeId)}` : '';
            apiFetch(`/mapa-politico/search-lideres?q=${encodeURIComponent(term)}${exclude}`)
                .then(r => (r.ok ? r.json() : { data: [] }))
                .then(res => {
                    if (id !== seq.current) return;
                    setResults((res.data || []).slice(0, MAX_RESULTS));
                    setSearched(true);
                    setOpen(true);
                })
                .catch(() => {
                    if (id === seq.current) close();
                });
        }, DEBOUNCE_MS);
    }

    function type(field, value) {
        onChange(field, value);
        if (field !== 'referente_apellido') search(value);
    }

    function pick(lider) {
        const { nombre, apellido } = splitNombre(lider.nombre);
        onChange('referente_documento', lider.cedula || '');
        onChange('referente_nombre', nombre);
        onChange('referente_apellido', apellido);
        close();
    }

    const sinCedula = !String(values.referente_documento || '').trim()
        && (String(values.referente_nombre || '').trim() || String(values.referente_apellido || '').trim());

    const input = (field, label) => (
        <div>
            <label className={labelClassName} htmlFor={`${uid}-${field}`}>{label}</label>
            <input
                id={`${uid}-${field}`}
                className={inputClassName}
                value={values[field] || ''}
                autoComplete="off"
                onChange={e => type(field, e.target.value)}
                onKeyDown={e => { if (e.key === 'Escape' && open) { e.stopPropagation(); close(); } }}
            />
        </div>
    );

    return (
        <div className="relative" onBlur={e => { if (!e.currentTarget.contains(e.relatedTarget)) close(); }}>
            <div className={className}>
                {input('referente_documento', 'Documento')}
                {input('referente_nombre', 'Nombre')}
                {input('referente_apellido', 'Apellido')}
            </div>
            {open && (
                <div className="mt-1 border border-[var(--color-line)] rounded-lg bg-white shadow-lg max-h-72 overflow-y-auto">
                    {results.length > 0 ? (
                        <ul role="listbox" aria-label="Personas encontradas">
                            {results.map(r => (
                                <li key={r.id} role="option" aria-selected="false">
                                    <button
                                        type="button"
                                        onMouseDown={e => e.preventDefault()}
                                        onClick={() => pick(r)}
                                        className="w-full text-left px-3 py-2 hover:bg-[var(--color-surface-2,#f3f4f6)] border-b border-[var(--color-line)] last:border-b-0"
                                    >
                                        <span className="block text-[14px] font-semibold text-[var(--color-ink)]">{r.nombre}</span>
                                        <span className="block text-[12px] text-[var(--color-ink-faint)]">
                                            {[r.cedula ? `CC ${r.cedula}` : 'Sin cédula', r.municipio, r.cargo].filter(Boolean).join(' · ')}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : searched && (
                        <p className="px-3 py-2 text-[13px] text-[var(--color-ink-faint)]">No está en el sistema: puede escribir los datos a mano.</p>
                    )}
                </div>
            )}
            {sinCedula && (
                <p className="mt-2 text-[12px] text-amber-700">Sin cédula, esta persona no queda vinculada como referida de su referente.</p>
            )}
        </div>
    );
}
