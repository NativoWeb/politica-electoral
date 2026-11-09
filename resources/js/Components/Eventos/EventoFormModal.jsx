import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';
import { addBarrio, barrioSuggestions, eventErrorMessage, eventFormData, eventFormErrors, eventPayload, figureSummary } from '@/lib/eventos';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import { unshownErrors } from '@/lib/userAdmin';

const inputCls = 'w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[16px] bg-white focus:outline-none focus:border-[var(--color-primary)] disabled:bg-[var(--color-bg)] disabled:text-[var(--color-ink-faint)]';
const labelCls = 'block text-[14px] font-bold text-[var(--color-ink-soft)] mb-1';
const sectionCls = 'border border-[var(--color-line)] rounded-xl p-3 space-y-3';
const smallBtnCls = 'min-h-[44px] px-4 py-2 rounded-lg text-[15px] font-bold';

const SHOWN_FIELDS = ['nombre', 'event_type_id', 'starts_at', 'ends_at', 'sitio_encuentro', 'municipio_id', 'barrios', 'descripcion', 'organizadores', 'figuras'];

function Err({ children }) {
    return children ? <p className="text-[13px] text-red-600 mt-1">{children}</p> : null;
}

function useRemoteSearch(url, minLength) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [status, setStatus] = useState('idle');
    const timer = useRef(null);

    useEffect(() => () => clearTimeout(timer.current), []);

    function search(text) {
        setQuery(text);
        clearTimeout(timer.current);
        if (text.trim().length < minLength) {
            setResults([]);
            setStatus('idle');
            return;
        }
        timer.current = setTimeout(async () => {
            setStatus('loading');
            try {
                const res = await apiFetch(`${url}?${new URLSearchParams({ q: text.trim() })}`);
                if (!res.ok) throw new Error(String(res.status));
                const json = await res.json();
                setResults(json.data || []);
                setStatus('done');
            } catch {
                setResults([]);
                setStatus('error');
            }
        }, 350);
    }

    function clear() {
        setQuery('');
        setResults([]);
        setStatus('idle');
    }

    return { query, results, status, search, clear };
}

function SearchResults({ search, emptyText, renderItem, onPick }) {
    return (
        <>
            {search.status === 'loading' && <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">Buscando...</p>}
            {search.status === 'error' && <p className="text-[13px] text-red-600 mt-1">No se pudo buscar. Intente de nuevo.</p>}
            {search.status === 'done' && search.results.length === 0 && <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">{emptyText}</p>}
            {search.results.length > 0 && (
                <ul className="mt-1 border border-[var(--color-line)] rounded-lg overflow-hidden divide-y divide-[var(--color-line)] max-h-56 overflow-y-auto">
                    {search.results.map(item => (
                        <li key={item.id}>
                            <button type="button" onClick={() => { onPick(item); search.clear(); }} className="w-full text-left px-3 py-3 bg-white hover:bg-gray-50 active:bg-gray-100">
                                {renderItem(item)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function RemoveButton({ label, onClick }) {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label} className="w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0 bg-red-50 text-red-600 active:bg-red-100">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    );
}

function BarriosField({ value, onChange, known, error }) {
    const [text, setText] = useState('');
    const suggestions = barrioSuggestions(known.filter(b => !value.includes(b)), text, 8);

    function add(v) {
        onChange(addBarrio(value, v));
        setText('');
    }

    return (
        <div>
            <label htmlFor="evento-barrio" className={labelCls}>Barrios</label>
            {value.length > 0 && (
                <ul className="flex flex-wrap gap-2 mb-2" aria-label="Barrios del evento">
                    {value.map(b => (
                        <li key={b} className="inline-flex items-center gap-1 pl-3 pr-1 py-1 bg-blue-50 border border-blue-200 text-[var(--color-primary)] rounded-full text-[14px] font-semibold">
                            {b}
                            <button type="button" onClick={() => onChange(value.filter(x => x !== b))} aria-label={`Quitar barrio ${b}`} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-blue-100">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex gap-2">
                <input
                    id="evento-barrio"
                    className={inputCls}
                    value={text}
                    onChange={e => setText(e.target.value)}
                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); add(text); } }}
                    placeholder="Escriba un barrio y pulse Agregar"
                    autoComplete="off"
                    maxLength={120}
                />
                <button type="button" onClick={() => add(text)} disabled={!text.trim()} className={`${smallBtnCls} bg-[var(--color-primary)] text-white disabled:opacity-40 flex-shrink-0`}>Agregar</button>
            </div>
            {suggestions.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-2" aria-label="Sugerencias de barrios">
                    {suggestions.map(b => (
                        <button key={b} type="button" onClick={() => add(b)} className="min-h-[36px] px-3 py-1 rounded-full border border-dashed border-[var(--color-line-strong)] text-[13px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50">
                            + {b}
                        </button>
                    ))}
                </div>
            )}
            <Err>{error}</Err>
        </div>
    );
}

const EMPTY_MANUAL_ORG = { nombre: '', cedula: '', telefono: '', rol: '' };

function OrganizersField({ value, onChange, errors }) {
    const search = useRemoteSearch('/eventos/buscar/organizadores', 2);
    const [manual, setManual] = useState(null);

    function update(i, field, v) {
        onChange(value.map((o, idx) => (idx === i ? { ...o, [field]: v } : o)));
    }

    function pick(lider) {
        if (value.some(o => o.lider_id === lider.id)) return;
        onChange([...value, { lider_id: lider.id, nombre: lider.nombre, cedula: lider.cedula ?? '', telefono: lider.telefono ?? '', rol: '' }]);
    }

    function addManual() {
        if (!manual.nombre.trim()) return;
        onChange([...value, { lider_id: null, ...manual }]);
        setManual(null);
    }

    return (
        <fieldset className={sectionCls}>
            <legend className="px-1 text-[15px] font-extrabold text-[var(--color-ink)]">Organizadores</legend>
            {value.length > 0 && (
                <ul className="space-y-2" aria-label="Organizadores del evento">
                    {value.map((o, i) => (
                        <li key={`${o.lider_id ?? 'm'}-${i}`} className="flex items-start gap-2 bg-[var(--color-bg)] rounded-lg p-2.5">
                            <div className="flex-1 min-w-0 space-y-1.5">
                                <p className="text-[15px] font-bold text-[var(--color-ink)]">
                                    {o.nombre}
                                    {!o.lider_id && <span className="ml-2 text-[12px] font-semibold text-[var(--color-ink-faint)]">(agregado a mano)</span>}
                                </p>
                                <p className="text-[13px] text-[var(--color-ink-soft)]">{[o.cedula && `C.C. ${o.cedula}`, o.telefono && `Tel. ${o.telefono}`].filter(Boolean).join(' · ') || 'Sin cédula ni teléfono'}</p>
                                <input aria-label={`Rol de ${o.nombre}`} className={`${inputCls} py-2`} value={o.rol} onChange={e => update(i, 'rol', e.target.value)} placeholder="Rol (opcional), ej.: Coordinador" maxLength={100} />
                                <Err>{errors[`organizadores.${i}.nombre`] || errors[`organizadores.${i}.lider_id`] || errors[`organizadores.${i}.cedula`] || errors[`organizadores.${i}.telefono`]}</Err>
                            </div>
                            <RemoveButton label={`Quitar organizador ${o.nombre}`} onClick={() => onChange(value.filter((_, idx) => idx !== i))} />
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <label htmlFor="evento-org-search" className={labelCls}>Buscar líder por nombre o cédula</label>
                <input id="evento-org-search" className={inputCls} value={search.query} onChange={e => search.search(e.target.value)} placeholder="Escriba al menos 2 letras o números" autoComplete="off" />
                <SearchResults
                    search={search}
                    emptyText="No hay líderes de su campaña con ese nombre o cédula."
                    onPick={pick}
                    renderItem={l => (
                        <>
                            <span className="block text-[15px] font-semibold text-[var(--color-ink)]">{l.nombre}</span>
                            <span className="block text-[12px] text-[var(--color-ink-faint)]">{[l.cedula && `C.C. ${l.cedula}`, l.municipio].filter(Boolean).join(' · ')}</span>
                        </>
                    )}
                />
            </div>

            {manual ? (
                <div className="border border-dashed border-[var(--color-line-strong)] rounded-lg p-3 space-y-2">
                    <p className="text-[14px] font-bold text-[var(--color-ink)]">Agregar organizador a mano</p>
                    <div>
                        <label htmlFor="org-manual-nombre" className={labelCls}>Nombre completo *</label>
                        <input id="org-manual-nombre" className={inputCls} value={manual.nombre} onChange={e => setManual({ ...manual, nombre: e.target.value })} maxLength={200} />
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div>
                            <label htmlFor="org-manual-cedula" className={labelCls}>Cédula</label>
                            <input id="org-manual-cedula" inputMode="numeric" className={inputCls} value={manual.cedula} onChange={e => setManual({ ...manual, cedula: e.target.value })} maxLength={20} />
                        </div>
                        <div>
                            <label htmlFor="org-manual-telefono" className={labelCls}>Teléfono</label>
                            <input id="org-manual-telefono" inputMode="numeric" className={inputCls} value={manual.telefono} onChange={e => setManual({ ...manual, telefono: e.target.value })} maxLength={20} />
                        </div>
                        <div>
                            <label htmlFor="org-manual-rol" className={labelCls}>Rol</label>
                            <input id="org-manual-rol" className={inputCls} value={manual.rol} onChange={e => setManual({ ...manual, rol: e.target.value })} maxLength={100} />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" onClick={addManual} disabled={!manual.nombre.trim()} className={`${smallBtnCls} bg-[var(--color-primary)] text-white disabled:opacity-40`}>Agregar organizador</button>
                        <button type="button" onClick={() => setManual(null)} className={`${smallBtnCls} border-2 border-gray-200 text-[var(--color-ink-soft)]`}>Cancelar</button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => setManual(EMPTY_MANUAL_ORG)} className={`${smallBtnCls} border-2 border-[var(--color-line-strong)] text-[var(--color-primary)] bg-white`}>
                    + Agregar a mano (no está en la plataforma)
                </button>
            )}
            <Err>{errors.organizadores}</Err>
        </fieldset>
    );
}

function FiguresField({ value, onChange, errors }) {
    const search = useRemoteSearch('/eventos/buscar/figuras', 3);
    const [manual, setManual] = useState(null);

    function update(i, field, v) {
        onChange(value.map((f, idx) => (idx === i ? { ...f, [field]: v } : f)));
    }

    function pick(person) {
        if (value.some(f => f.person_id === person.id)) return;
        onChange([...value, { person_id: person.id, nombre: person.nombre, cargo: person.ultimo_cargo ?? '' }]);
    }

    function addManual() {
        if (!manual.nombre.trim()) return;
        onChange([...value, { person_id: null, ...manual }]);
        setManual(null);
    }

    return (
        <fieldset className={sectionCls}>
            <legend className="px-1 text-[15px] font-extrabold text-[var(--color-ink)]">Figuras políticas invitadas</legend>
            {value.length > 0 && (
                <ul className="space-y-2" aria-label="Figuras políticas del evento">
                    {value.map((f, i) => (
                        <li key={`${f.person_id ?? 'm'}-${i}`} className="flex items-start gap-2 bg-[var(--color-bg)] rounded-lg p-2.5">
                            <div className="flex-1 min-w-0 space-y-1.5">
                                <p className="text-[15px] font-bold text-[var(--color-ink)]">{f.nombre}</p>
                                <input aria-label={`Cargo de ${f.nombre}`} className={`${inputCls} py-2`} value={f.cargo} onChange={e => update(i, 'cargo', e.target.value)} placeholder="Cargo (opcional), ej.: Senador" maxLength={150} />
                                <Err>{errors[`figuras.${i}.nombre`] || errors[`figuras.${i}.person_id`] || errors[`figuras.${i}.cargo`]}</Err>
                            </div>
                            <RemoveButton label={`Quitar figura ${f.nombre}`} onClick={() => onChange(value.filter((_, idx) => idx !== i))} />
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <label htmlFor="evento-fig-search" className={labelCls}>Buscar en la base electoral</label>
                <input id="evento-fig-search" className={inputCls} value={search.query} onChange={e => search.search(e.target.value)} placeholder="Escriba al menos 3 letras del nombre" autoComplete="off" />
                <SearchResults
                    search={search}
                    emptyText="No hay personas con ese nombre."
                    onPick={pick}
                    renderItem={p => (
                        <>
                            <span className="block text-[15px] font-semibold text-[var(--color-ink)]">{p.nombre}</span>
                            <span className="block text-[12px] text-[var(--color-ink-faint)]">{figureSummary(p) || 'Sin candidaturas registradas'}</span>
                        </>
                    )}
                />
            </div>

            {manual ? (
                <div className="border border-dashed border-[var(--color-line-strong)] rounded-lg p-3 space-y-2">
                    <p className="text-[14px] font-bold text-[var(--color-ink)]">Agregar figura a mano</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <label htmlFor="fig-manual-nombre" className={labelCls}>Nombre *</label>
                            <input id="fig-manual-nombre" className={inputCls} value={manual.nombre} onChange={e => setManual({ ...manual, nombre: e.target.value })} maxLength={200} />
                        </div>
                        <div>
                            <label htmlFor="fig-manual-cargo" className={labelCls}>Cargo</label>
                            <input id="fig-manual-cargo" className={inputCls} value={manual.cargo} onChange={e => setManual({ ...manual, cargo: e.target.value })} maxLength={150} />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" onClick={addManual} disabled={!manual.nombre.trim()} className={`${smallBtnCls} bg-[var(--color-primary)] text-white disabled:opacity-40`}>Agregar figura</button>
                        <button type="button" onClick={() => setManual(null)} className={`${smallBtnCls} border-2 border-gray-200 text-[var(--color-ink-soft)]`}>Cancelar</button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => setManual({ nombre: '', cargo: '' })} className={`${smallBtnCls} border-2 border-[var(--color-line-strong)] text-[var(--color-primary)] bg-white`}>
                    + Escribir nombre y cargo
                </button>
            )}
            <Err>{errors.figuras}</Err>
        </fieldset>
    );
}

const NEW_TYPE = '__nuevo__';

function typeFailure(status, body) {
    if (status === 403) return 'No tiene permiso para crear tipos de evento.';
    if (status === 419) return 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.';
    if (status === 422) return body?.errors?.nombre?.[0] || body?.message || 'Revise el nombre del tipo.';

    return 'No se pudo crear el tipo. Intente de nuevo en un momento.';
}

// Only global types and the own campaign's can be used; a new one is created in the own campaign and selected.
function TipoField({ tipos, value, onChange, error }) {
    const [added, setAdded] = useState([]);
    const [nombre, setNombre] = useState(null);
    const [saving, setSaving] = useState(false);
    const [failure, setFailure] = useState('');
    const options = useMemo(() => {
        const usable = tipos.filter(t => t.usable !== false);
        const extra = added.filter(a => !usable.some(t => t.id === a.id));

        return [...usable, ...extra].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
    }, [tipos, added]);

    function cancel() {
        setNombre(null);
        setFailure('');
    }

    async function save() {
        if (saving) return;
        const text = (nombre ?? '').trim();
        if (!text) { setFailure('Escriba el nombre del tipo de evento.'); return; }
        setSaving(true);
        setFailure('');
        try {
            const res = await apiFetch('/eventos/tipos', { method: 'POST', body: JSON.stringify({ nombre: text }) });
            const body = await res.json().catch(() => null);
            if (!res.ok || !body?.success) { setFailure(typeFailure(res.status, body)); return; }
            setAdded(list => [...list.filter(t => t.id !== body.data.id), body.data]);
            onChange(body.data.id);
            setNombre(null);
        } catch {
            setFailure('No hay conexión con el servidor. Intente de nuevo.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <label htmlFor="evento-tipo" className={labelCls}>Tipo de evento *</label>
            <select id="evento-tipo" className={inputCls} value={value} onChange={e => (e.target.value === NEW_TYPE ? setNombre('') : onChange(e.target.value))}>
                <option value="">Elija un tipo</option>
                {options.map(t => <option key={t.id} value={t.id}>{t.nombre}</option>)}
                <option value={NEW_TYPE}>+ Crear nuevo tipo…</option>
            </select>
            {nombre !== null && (
                <div className="mt-2 border border-dashed border-[var(--color-line-strong)] rounded-lg p-3 space-y-2">
                    <label htmlFor="evento-tipo-nuevo" className={labelCls}>Nombre del nuevo tipo *</label>
                    <input
                        id="evento-tipo-nuevo"
                        autoFocus
                        className={inputCls}
                        value={nombre}
                        maxLength={80}
                        onChange={e => setNombre(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); save(); } }}
                    />
                    <Err>{failure}</Err>
                    <div className="flex gap-2">
                        <button type="button" aria-label="Guardar nuevo tipo" onClick={save} disabled={saving || !nombre.trim()} className={`${smallBtnCls} bg-[var(--color-primary)] text-white disabled:opacity-40`}>{saving ? 'Guardando...' : 'Guardar'}</button>
                        <button type="button" aria-label="Cancelar nuevo tipo" onClick={cancel} disabled={saving} className={`${smallBtnCls} border-2 border-gray-200 text-[var(--color-ink-soft)]`}>Cancelar</button>
                    </div>
                </div>
            )}
            <Err>{error}</Err>
        </div>
    );
}

export default function EventoFormModal({ evento, tipos, provincias, knownBarrios = [], onClose }) {
    const isEdit = !!evento?.id;
    const form = useForm(eventFormData(evento));
    const [saveError, setSaveError] = useState('');
    const [clientErrors, setClientErrors] = useState({});
    const errors = { ...form.errors, ...clientErrors };
    const dialogRef = useRef(null);
    useEscapeKey(onClose, dialogRef);
    useDialogFocus(dialogRef);

    const municipios = useMemo(
        () => provincias.find(p => p.id === form.data.provincia_id)?.municipios ?? [],
        [provincias, form.data.provincia_id],
    );
    const inlineNested = Object.keys(errors).filter(k => /^(organizadores|figuras)\.\d+\./.test(k));
    const extraErrors = unshownErrors(errors, [...SHOWN_FIELDS, ...inlineNested]);

    function submit(e) {
        e.preventDefault();
        setSaveError('');
        const missing = eventFormErrors(form.data);
        setClientErrors(missing);
        if (Object.keys(missing).length) return;
        form.transform(eventPayload);
        const options = {
            preserveScroll: true,
            onSuccess: () => { if (isEdit) onClose(); },
            onHttpException: response => { setSaveError(eventErrorMessage(response)); return false; },
            onNetworkError: () => { setSaveError('No hay conexión con el servidor. Intente de nuevo.'); return false; },
        };
        if (isEdit) form.put(`/eventos/${evento.id}`, options);
        else form.post('/eventos', options);
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-50" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 pointer-events-none">
                <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="evento-modal-title" className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[640px] max-h-[calc(100dvh-1rem)] flex flex-col overflow-hidden">
                    <div className="bg-[var(--color-primary)] text-white px-5 py-4 flex items-center justify-between gap-3 flex-shrink-0">
                        <h2 id="evento-modal-title" className="text-[18px] font-extrabold">{isEdit ? 'EDITAR EVENTO' : 'NUEVO EVENTO'}</h2>
                        <button type="button" onClick={onClose} aria-label="Cerrar" title="Cerrar" className="w-11 h-11 rounded-full bg-white/15 flex items-center justify-center flex-shrink-0 active:bg-white/30">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form onSubmit={submit} noValidate className="flex flex-col min-h-0 flex-1">
                        <div className="overflow-y-auto px-4 sm:px-5 py-5 space-y-4">
                            <p className="text-[13px] text-[var(--color-ink-faint)]">Los campos con * son obligatorios.</p>
                            <div>
                                <label htmlFor="evento-nombre" className={labelCls}>Nombre del evento *</label>
                                <input id="evento-nombre" autoFocus className={inputCls} value={form.data.nombre} onChange={e => form.setData('nombre', e.target.value)} maxLength={200} placeholder="Ej.: Del Senado al barrio — Girón" />
                                <Err>{errors.nombre}</Err>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <TipoField tipos={tipos} value={form.data.event_type_id} onChange={v => form.setData('event_type_id', v)} error={errors.event_type_id} />
                                <div>
                                    <label htmlFor="evento-inicio" className={labelCls}>Fecha y hora *</label>
                                    <input id="evento-inicio" type="datetime-local" className={inputCls} value={form.data.starts_at} onChange={e => form.setData('starts_at', e.target.value)} />
                                    <Err>{errors.starts_at}</Err>
                                </div>
                            </div>

                            <div>
                                <label htmlFor="evento-fin" className={labelCls}>Hora de finalización (opcional)</label>
                                <input id="evento-fin" type="datetime-local" className={inputCls} value={form.data.ends_at} min={form.data.starts_at || undefined} onChange={e => form.setData('ends_at', e.target.value)} />
                                <Err>{errors.ends_at || errors.registration_closes_at}</Err>
                            </div>

                            <div>
                                <label htmlFor="evento-sitio" className={labelCls}>Sitio de encuentro *</label>
                                <input id="evento-sitio" className={inputCls} value={form.data.sitio_encuentro} onChange={e => form.setData('sitio_encuentro', e.target.value)} maxLength={255} placeholder="Ej.: Parque principal" />
                                <Err>{errors.sitio_encuentro}</Err>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label htmlFor="evento-provincia" className={labelCls}>Provincia *</label>
                                    <select id="evento-provincia" className={inputCls} value={form.data.provincia_id} onChange={e => form.setData(d => ({ ...d, provincia_id: e.target.value, municipio_id: '' }))}>
                                        <option value="">Elija una provincia</option>
                                        {provincias.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="evento-municipio" className={labelCls}>Municipio *</label>
                                    <select id="evento-municipio" className={inputCls} value={form.data.municipio_id} onChange={e => form.setData('municipio_id', e.target.value)} disabled={!form.data.provincia_id}>
                                        <option value="">{form.data.provincia_id ? 'Elija un municipio' : 'Primero elija la provincia'}</option>
                                        {municipios.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                                    </select>
                                    <Err>{errors.municipio_id}</Err>
                                </div>
                            </div>

                            <BarriosField value={form.data.barrios} onChange={v => form.setData('barrios', v)} known={knownBarrios} error={errors.barrios} />

                            <div>
                                <label htmlFor="evento-descripcion" className={labelCls}>Descripción y actividades (opcional)</label>
                                <textarea id="evento-descripcion" rows={3} className={inputCls} value={form.data.descripcion} onChange={e => form.setData('descripcion', e.target.value)} maxLength={5000} />
                                <Err>{errors.descripcion}</Err>
                            </div>

                            <OrganizersField value={form.data.organizadores} onChange={v => form.setData('organizadores', v)} errors={errors} />
                            <FiguresField value={form.data.figuras} onChange={v => form.setData('figuras', v)} errors={errors} />

                            {(saveError || extraErrors.length > 0) && (
                                <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700 space-y-1">
                                    {saveError && <p>{saveError}</p>}
                                    {extraErrors.map(m => <p key={m}>{m}</p>)}
                                </div>
                            )}
                        </div>

                        <div className="flex-shrink-0 border-t border-[var(--color-line)] px-5 py-4 flex gap-3">
                            <button type="submit" disabled={form.processing} className="flex-1 px-4 py-3.5 bg-[var(--color-primary)] text-white text-[16px] font-bold rounded-xl disabled:opacity-50">
                                {form.processing ? 'Guardando...' : (isEdit ? 'Guardar cambios' : 'Crear evento')}
                            </button>
                            <button type="button" onClick={onClose} className="px-4 py-3.5 border-2 border-gray-200 text-[16px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}
