import { useState, useEffect, useCallback, useRef } from 'react';
import VotanteContactos from '@/Components/VotanteContactos';
import VotanteInfoPolitica from '@/Components/VotanteInfoPolitica';

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white";
const readCls = "text-[13px] text-[var(--color-ink)] py-1";

const GENERO_OPTIONS = ['', 'Masculino', 'Femenino', 'Otro'];
const ESTADO_CIVIL_OPTIONS = ['', 'Soltero/a', 'Casado/a', 'Union libre', 'Divorciado/a', 'Viudo/a'];
const ZONA_OPTIONS = ['', 'rural', 'urbana'];
const ESCOLARIDAD_OPTIONS = ['', 'Ninguna', 'Primaria', 'Secundaria', 'Tecnico', 'Tecnologo', 'Profesional', 'Postgrado'];
const NIVEL_CONFIANZA_OPTIONS = ['sin_llamar', 'contactado', 'confirmado', 'comprometido', 'no_responde'];
const TIPO_DOCUMENTO_OPTIONS = ['', 'CC', 'CE', 'TI', 'PA', 'RC', 'NIT'];

const TAG_FIELDS = [
    { key: 'verificado', label: 'Verificado' },
    { key: 'gran_elector', label: 'Gran Elector' },
    { key: 'militante', label: 'Militante' },
    { key: 'autoriza_datos', label: 'Autoriza datos' },
    { key: 'empresario', label: 'Empresario' },
    { key: 'reservista', label: 'Reservista' },
    { key: 'funcionario', label: 'Funcionario' },
    { key: 'exfuncionario', label: 'Exfuncionario' },
    { key: 'fallecido', label: 'Fallecido' },
];

function Accordion({ title, defaultOpen = false, children }) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <div className="border-b border-[var(--color-line)] last:border-0">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="w-full flex items-center justify-between py-3 text-left"
            >
                <span className="text-[13px] font-bold text-[var(--color-ink)] uppercase tracking-wider">{title}</span>
                <svg className={`w-4 h-4 text-[var(--color-ink-faint)] transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            {open && <div className="pb-4">{children}</div>}
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] mb-0.5">{label}</label>
            {children}
        </div>
    );
}

function buildInitialForm(data) {
    return {
        nombre: data?.nombre || '',
        tipo_documento: data?.tipo_documento || '',
        cedula: data?.cedula || '',
        fecha_expedicion_doc: data?.fecha_expedicion_doc?.split('T')[0] || '',
        genero: data?.genero || '',
        fecha_nacimiento: data?.fecha_nacimiento?.split('T')[0] || '',
        estado_civil: data?.estado_civil || '',
        profesion: data?.profesion || '',
        escolaridad: data?.escolaridad || '',
        direccion: data?.direccion || '',
        barrio: data?.barrio || '',
        zona: data?.zona || '',
        municipio: data?.municipio || '',
        municipio_id: data?.geographic_unit_id || '',
        departamento_votacion: data?.departamento_votacion || '',
        municipio_votacion: data?.municipio_votacion || '',
        puesto_votacion: data?.puesto_votacion || '',
        direccion_puesto: data?.direccion_puesto || '',
        mesa_votacion: data?.mesa_votacion || '',
        referente_documento: data?.referente_documento || '',
        referente_nombre: data?.referente_nombre || '',
        referente_apellido: data?.referente_apellido || '',
        telefono: data?.telefono || '',
        email: data?.email || '',
        facebook: data?.facebook || '',
        twitter: data?.twitter || '',
        instagram: data?.instagram || '',
        partido: data?.partido || '',
        cargo: data?.cargo || '',
        votos: data?.votos ?? '',
        convenio: data?.convenio || '',
        nivel_confianza: data?.nivel_confianza || 'sin_llamar',
        observacion: data?.observacion || '',
        verificado: data?.verificado || false,
        gran_elector: data?.gran_elector || false,
        militante: data?.militante || false,
        autoriza_datos: data?.autoriza_datos || false,
        empresario: data?.empresario || false,
        reservista: data?.reservista || false,
        funcionario: data?.funcionario || false,
        exfuncionario: data?.exfuncionario || false,
        fallecido: data?.fallecido || false,
    };
}

export default function VotanteDetail({
    data, loading, creating, onClose, onSaved, onDeleted,
    apiFetch, municipios, showToast,
}) {
    const [editing, setEditing] = useState(creating);
    const [form, setForm] = useState(() => buildInitialForm(creating ? null : data));
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [localContacts, setLocalContacts] = useState(data?.contactos || []);
    const [localInfoPolitica, setLocalInfoPolitica] = useState(data?.info_politica || []);
    const [localNexos, setLocalNexos] = useState(data?.nexos || []);
    const [localReferidos, setLocalReferidos] = useState(data?.referidos || []);
    const [showRefSearch, setShowRefSearch] = useState(false);
    const [refQuery, setRefQuery] = useState('');
    const [refResults, setRefResults] = useState([]);
    const [refSearching, setRefSearching] = useState(false);
    const prevIdRef = useRef(null);

    useEffect(() => {
        if (creating) {
            setForm(buildInitialForm(null));
            setEditing(true);
            setLocalContacts([]);
            setLocalInfoPolitica([]);
            setLocalNexos([]);
            setLocalReferidos([]);
            prevIdRef.current = null;
        } else if (data) {
            if (data.id !== prevIdRef.current) {
                setForm(buildInitialForm(data));
                setEditing(false);
                prevIdRef.current = data.id;
            }
            setLocalContacts(data.contactos || []);
            setLocalInfoPolitica(data.info_politica || []);
            setLocalNexos(data.nexos || []);
            setLocalReferidos(data.referidos || []);
        }
    }, [data, creating]);

    const setField = useCallback((key, val) => {
        setForm(prev => ({ ...prev, [key]: val }));
    }, []);

    async function handleSave() {
        if (!form.nombre.trim()) {
            showToast('El nombre es requerido', 'error');
            return;
        }
        if (creating && !form.municipio_id) {
            showToast('Selecciona un municipio', 'error');
            return;
        }
        setSaving(true);
        try {
            const payload = { ...form };
            if (typeof payload.votos === 'string') {
                payload.votos = payload.votos !== '' ? parseInt(payload.votos, 10) : null;
            }
            if (creating) {
                const res = await apiFetch('/votantes', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                onSaved(res.id);
            } else {
                await apiFetch(`/votantes/${data.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
                onSaved();
            }
        } catch (err) {
            const msg = err.message || err.data?.message || 'Error al guardar';
            showToast(msg, 'error');
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete() {
        setSaving(true);
        try {
            await apiFetch(`/votantes/${data.id}`, { method: 'DELETE' });
            onDeleted();
        } catch (err) {
            showToast(err.message || 'Error al eliminar', 'error');
        } finally {
            setSaving(false);
            setConfirmDelete(false);
        }
    }

    function searchRef(q) {
        setRefQuery(q);
        if (q.length < 2) { setRefResults([]); return; }
        setRefSearching(true);
        apiFetch(`/mapa-politico/search-lideres?q=${encodeURIComponent(q)}&exclude_id=${data?.id || ''}`)
            .then(res => setRefResults(res.data || []))
            .catch(() => setRefResults([]))
            .finally(() => setRefSearching(false));
    }

    function addRef(liderId) {
        apiFetch(`/mapa-politico/persona/${data.id}/add-referido`, {
            method: 'POST',
            body: JSON.stringify({ lider_id: liderId }),
        })
            .then(() => {
                setShowRefSearch(false); setRefQuery(''); setRefResults([]);
                showToast('Referido agregado');
                handleRefreshDetail();
            })
            .catch(err => showToast(err.message || 'Error al agregar referido', 'error'));
    }

    function removeRef(referidoId) {
        if (!window.confirm('¿Quitar esta persona de los referidos?')) return;
        apiFetch(`/mapa-politico/persona/${data.id}/referido/${referidoId}`, { method: 'DELETE' })
            .then(() => { showToast('Referido eliminado'); handleRefreshDetail(); })
            .catch(() => showToast('Error al eliminar referido', 'error'));
    }

    function handleRefreshDetail() {
        if (data?.id) {
            apiFetch(`/votantes/${data.id}`).then(res => {
                setLocalContacts(res.data.contactos || []);
                setLocalInfoPolitica(res.data.info_politica || []);
                setLocalNexos(res.data.nexos || []);
                setLocalReferidos(res.data.referidos || []);
            }).catch(() => {});
        }
    }

    if (loading) {
        return (
            <div className="bg-white rounded-xl border border-[var(--color-line)] h-[calc(100vh-200px)] flex items-center justify-center">
                <div className="text-center">
                    <div className="w-8 h-8 border-2 border-[var(--color-primary)] border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                    <p className="text-[13px] text-[var(--color-ink-faint)]">Cargando...</p>
                </div>
            </div>
        );
    }

    if (!data && !creating) {
        return (
            <div className="bg-white rounded-xl border border-[var(--color-line)] p-6 text-center">
                <p className="text-[13px] text-[var(--color-ink-faint)]">Selecciona un votante para ver el detalle</p>
            </div>
        );
    }

    const readOnly = !editing;

    return (
        <div className="bg-white lg:rounded-xl lg:border border-[var(--color-line)] flex flex-col min-h-screen lg:min-h-0 lg:max-h-[calc(100vh-200px)]">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-line)] bg-gray-50 rounded-t-xl flex-shrink-0">
                <div className="min-w-0">
                    <h3 className="text-[15px] font-bold text-[var(--color-ink)] truncate">
                        {creating ? 'Nuevo Votante' : (data?.nombre || '')}
                    </h3>
                    {!creating && data?.cedula && (
                        <p className="text-[12px] text-[var(--color-ink-faint)]">CC {data.cedula} &middot; {data.municipio}</p>
                    )}
                </div>
                <div className="flex items-center gap-1 flex-shrink-0">
                    {!creating && !editing && (
                        <button
                            onClick={() => setEditing(true)}
                            className="px-3 py-1.5 text-[12px] font-bold text-[var(--color-primary)] border border-[var(--color-primary)] rounded-lg hover:bg-blue-50 transition-colors"
                        >
                            Editar
                        </button>
                    )}
                    {!creating && editing && (
                        <button
                            onClick={() => { setEditing(false); setForm(buildInitialForm(data)); }}
                            className="px-3 py-1.5 text-[12px] font-semibold text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] transition-colors"
                        >
                            Cancelar
                        </button>
                    )}
                    <button onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 transition-colors" title="Cerrar">
                        <svg className="w-5 h-5 text-[var(--color-ink-faint)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            {/* Scrollable body */}
            <div className="flex-1 overflow-y-auto px-4">
                {/* Informacion Basica */}
                <Accordion title="Informacion Basica" defaultOpen>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="col-span-2">
                            <Field label="Nombre completo">
                                {editing ? <input value={form.nombre} onChange={e => setField('nombre', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.nombre || '-'}</p>}
                            </Field>
                        </div>
                        <Field label="Tipo documento">
                            {editing ? (
                                <select value={form.tipo_documento} onChange={e => setField('tipo_documento', e.target.value)} className={inputCls}>
                                    {TIPO_DOCUMENTO_OPTIONS.map(o => <option key={o} value={o}>{o || 'Seleccionar'}</option>)}
                                </select>
                            ) : <p className={readCls}>{form.tipo_documento || '-'}</p>}
                        </Field>
                        <Field label="Cedula">
                            {editing ? <input value={form.cedula} onChange={e => setField('cedula', e.target.value)} className={inputCls} /> : <p className={readCls + ' font-mono'}>{form.cedula || '-'}</p>}
                        </Field>
                        <Field label="Fecha expedicion">
                            {editing ? <input type="date" value={form.fecha_expedicion_doc} onChange={e => setField('fecha_expedicion_doc', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.fecha_expedicion_doc || '-'}</p>}
                        </Field>
                        <Field label="Genero">
                            {editing ? (
                                <select value={form.genero} onChange={e => setField('genero', e.target.value)} className={inputCls}>
                                    {GENERO_OPTIONS.map(o => <option key={o} value={o}>{o || 'Seleccionar'}</option>)}
                                </select>
                            ) : <p className={readCls}>{form.genero || '-'}</p>}
                        </Field>
                        <Field label="Fecha nacimiento">
                            {editing ? <input type="date" value={form.fecha_nacimiento} onChange={e => setField('fecha_nacimiento', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.fecha_nacimiento || '-'}</p>}
                        </Field>
                        <Field label="Estado civil">
                            {editing ? (
                                <select value={form.estado_civil} onChange={e => setField('estado_civil', e.target.value)} className={inputCls}>
                                    {ESTADO_CIVIL_OPTIONS.map(o => <option key={o} value={o}>{o || 'Seleccionar'}</option>)}
                                </select>
                            ) : <p className={readCls}>{form.estado_civil || '-'}</p>}
                        </Field>
                        <Field label="Profesion">
                            {editing ? <input value={form.profesion} onChange={e => setField('profesion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.profesion || '-'}</p>}
                        </Field>
                        <Field label="Escolaridad">
                            {editing ? (
                                <select value={form.escolaridad} onChange={e => setField('escolaridad', e.target.value)} className={inputCls}>
                                    {ESCOLARIDAD_OPTIONS.map(o => <option key={o} value={o}>{o || 'Seleccionar'}</option>)}
                                </select>
                            ) : <p className={readCls}>{form.escolaridad || '-'}</p>}
                        </Field>
                    </div>
                </Accordion>

                {/* Ubicacion */}
                <Accordion title="Ubicacion">
                    <div className="grid grid-cols-2 gap-3">
                        {creating ? (
                            <div className="col-span-2">
                                <Field label="Municipio *">
                                    <select value={form.municipio_id} onChange={e => setField('municipio_id', e.target.value)} className={inputCls}>
                                        <option value="">Seleccionar municipio</option>
                                        {municipios.map(m => (
                                            <option key={m.id} value={m.id}>{m.name} {m.provincia ? `(${m.provincia})` : ''}</option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                        ) : (
                            <Field label="Municipio">
                                <p className={readCls}>{form.municipio || '-'}</p>
                            </Field>
                        )}
                        <Field label="Direccion">
                            {editing ? <input value={form.direccion} onChange={e => setField('direccion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.direccion || '-'}</p>}
                        </Field>
                        <Field label="Barrio">
                            {editing ? <input value={form.barrio} onChange={e => setField('barrio', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.barrio || '-'}</p>}
                        </Field>
                        <Field label="Zona">
                            {editing ? (
                                <select value={form.zona} onChange={e => setField('zona', e.target.value)} className={inputCls}>
                                    {ZONA_OPTIONS.map(o => <option key={o} value={o}>{o || 'Seleccionar'}</option>)}
                                </select>
                            ) : <p className={readCls}>{form.zona || '-'}</p>}
                        </Field>
                    </div>
                </Accordion>

                {/* Puesto de Votacion */}
                <Accordion title="Puesto de Votacion">
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Departamento">
                            {editing ? <input value={form.departamento_votacion} onChange={e => setField('departamento_votacion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.departamento_votacion || '-'}</p>}
                        </Field>
                        <Field label="Municipio">
                            {editing ? <input value={form.municipio_votacion} onChange={e => setField('municipio_votacion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.municipio_votacion || '-'}</p>}
                        </Field>
                        <div className="col-span-2">
                            <Field label="Puesto">
                                {editing ? <input value={form.puesto_votacion} onChange={e => setField('puesto_votacion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.puesto_votacion || '-'}</p>}
                            </Field>
                        </div>
                        <Field label="Direccion puesto">
                            {editing ? <input value={form.direccion_puesto} onChange={e => setField('direccion_puesto', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.direccion_puesto || '-'}</p>}
                        </Field>
                        <Field label="Mesa">
                            {editing ? <input value={form.mesa_votacion} onChange={e => setField('mesa_votacion', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.mesa_votacion || '-'}</p>}
                        </Field>
                    </div>
                </Accordion>

                {/* Referente */}
                <Accordion title="Referente">
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Documento">
                            {editing ? <input value={form.referente_documento} onChange={e => setField('referente_documento', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.referente_documento || '-'}</p>}
                        </Field>
                        <Field label="Nombre">
                            {editing ? <input value={form.referente_nombre} onChange={e => setField('referente_nombre', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.referente_nombre || '-'}</p>}
                        </Field>
                        <Field label="Apellido">
                            {editing ? <input value={form.referente_apellido} onChange={e => setField('referente_apellido', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.referente_apellido || '-'}</p>}
                        </Field>
                    </div>
                    {!creating && (
                        <div className="border-t border-[var(--color-line)] pt-3 mt-3">
                            <div className="flex items-center justify-between mb-2">
                                <p className="text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider">
                                    Personas referidas ({localReferidos.length})
                                </p>
                                <button
                                    onClick={() => setShowRefSearch(!showRefSearch)}
                                    className="px-2 py-1 bg-purple-500 text-white text-[11px] font-bold rounded hover:bg-purple-600 transition-colors"
                                >
                                    + Agregar
                                </button>
                            </div>

                            {showRefSearch && (
                                <div className="bg-purple-50 rounded-lg p-3 mb-2 border border-purple-200">
                                    <input
                                        type="text"
                                        className="w-full border border-purple-300 rounded-lg px-3 py-2 text-[13px] focus:outline-none focus:border-purple-500 mb-1"
                                        placeholder="Buscar por nombre o cedula..."
                                        value={refQuery}
                                        onChange={e => searchRef(e.target.value)}
                                        autoFocus
                                    />
                                    {refSearching && <p className="text-[11px] text-purple-600">Buscando...</p>}
                                    {refResults.length > 0 && (
                                        <div className="space-y-1 max-h-[150px] overflow-y-auto">
                                            {refResults.map(r => (
                                                <button key={r.id} onClick={() => addRef(r.id)} className="w-full text-left flex items-center justify-between p-1.5 rounded hover:bg-purple-100 transition-colors">
                                                    <div className="min-w-0">
                                                        <span className="text-[12px] font-semibold text-[var(--color-ink)] block truncate">{r.nombre}</span>
                                                        <span className="text-[10px] text-[var(--color-ink-faint)]">{r.cedula && `CC ${r.cedula}`}{r.municipio && ` · ${r.municipio}`}</span>
                                                    </div>
                                                    <svg className="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                    {refQuery.length >= 2 && !refSearching && refResults.length === 0 && (
                                        <p className="text-[11px] text-purple-600 italic">No se encontraron personas</p>
                                    )}
                                    <button onClick={() => { setShowRefSearch(false); setRefQuery(''); setRefResults([]); }} className="mt-1 text-[11px] text-purple-600 hover:text-purple-800 font-semibold">Cancelar</button>
                                </div>
                            )}

                            {localReferidos.length > 0 ? (
                                <div className="space-y-1.5 max-h-[200px] overflow-y-auto">
                                    {localReferidos.map(ref => (
                                        <div key={ref.id} className="flex items-center justify-between p-2 rounded-lg bg-purple-50 border border-purple-100">
                                            <div className="min-w-0">
                                                <span className="text-[13px] font-semibold text-[var(--color-ink)] block truncate">{ref.nombre}</span>
                                                <span className="text-[11px] text-[var(--color-ink-faint)]">{ref.cedula} · {ref.municipio}</span>
                                            </div>
                                            <div className="flex items-center gap-2 flex-shrink-0 ml-2">
                                                {ref.telefono && (
                                                    <a href={`https://wa.me/57${ref.telefono.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" title="WhatsApp">
                                                        <svg className="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" /></svg>
                                                    </a>
                                                )}
                                                <button onClick={() => removeRef(ref.id)} className="text-red-400 hover:text-red-600 transition-colors" title="Quitar referido">
                                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-[13px] text-[var(--color-ink-faint)] italic">Sin referidos</p>
                            )}
                        </div>
                    )}
                </Accordion>

                {/* Contactos */}
                {!creating && (
                    <Accordion title="Contactos">
                        <VotanteContactos
                            contactos={localContacts}
                            liderId={data?.id}
                            apiFetch={apiFetch}
                            onUpdate={handleRefreshDetail}
                            readOnly={readOnly}
                        />
                    </Accordion>
                )}

                {/* Redes Sociales */}
                <Accordion title="Redes Sociales">
                    <div className="space-y-3">
                        <Field label="Telefono">
                            {editing ? <input value={form.telefono} onChange={e => setField('telefono', e.target.value)} className={inputCls} placeholder="3001234567" /> : <p className={readCls}>{form.telefono || '-'}</p>}
                        </Field>
                        <Field label="Email">
                            {editing ? <input type="email" value={form.email} onChange={e => setField('email', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.email || '-'}</p>}
                        </Field>
                        <Field label="Facebook">
                            {editing ? <input value={form.facebook} onChange={e => setField('facebook', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.facebook || '-'}</p>}
                        </Field>
                        <Field label="Twitter / X">
                            {editing ? <input value={form.twitter} onChange={e => setField('twitter', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.twitter || '-'}</p>}
                        </Field>
                        <Field label="Instagram">
                            {editing ? <input value={form.instagram} onChange={e => setField('instagram', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.instagram || '-'}</p>}
                        </Field>
                    </div>
                </Accordion>

                {/* Informacion Politica */}
                <Accordion title="Informacion Politica">
                    <div className="space-y-3 mb-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Partido">
                                {editing ? <input value={form.partido} onChange={e => setField('partido', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.partido || '-'}</p>}
                            </Field>
                            <Field label="Cargo">
                                {editing ? <input value={form.cargo} onChange={e => setField('cargo', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.cargo || '-'}</p>}
                            </Field>
                            <Field label="Votos">
                                {editing ? <input type="number" value={form.votos} onChange={e => setField('votos', e.target.value)} className={inputCls} min="0" /> : <p className={readCls}>{form.votos ?? '-'}</p>}
                            </Field>
                            <Field label="Convenio">
                                {editing ? <input value={form.convenio} onChange={e => setField('convenio', e.target.value)} className={inputCls} /> : <p className={readCls}>{form.convenio || '-'}</p>}
                            </Field>
                            <Field label="Nivel de confianza">
                                {editing ? (
                                    <select value={form.nivel_confianza} onChange={e => setField('nivel_confianza', e.target.value)} className={inputCls}>
                                        {NIVEL_CONFIANZA_OPTIONS.map(o => <option key={o} value={o}>{o.replace('_', ' ')}</option>)}
                                    </select>
                                ) : <p className={readCls}>{(form.nivel_confianza || '').replace('_', ' ') || '-'}</p>}
                            </Field>
                        </div>
                    </div>
                    {!creating && (
                        <>
                            <div className="border-t border-[var(--color-line)] pt-3 mt-3">
                                <p className="text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider mb-2">Historial politico</p>
                                <VotanteInfoPolitica
                                    entries={localInfoPolitica}
                                    liderId={data?.id}
                                    apiFetch={apiFetch}
                                    onUpdate={handleRefreshDetail}
                                    readOnly={readOnly}
                                />
                            </div>
                        </>
                    )}
                </Accordion>

                {/* Opciones / Tags */}
                <Accordion title="Opciones">
                    <div className="space-y-2 mb-3">
                        {TAG_FIELDS.map(tag => (
                            <label key={tag.key} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={form[tag.key] || false}
                                    onChange={e => editing && setField(tag.key, e.target.checked)}
                                    disabled={!editing}
                                    className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] disabled:opacity-60"
                                />
                                <span className={`text-[13px] ${editing ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-soft)]'}`}>{tag.label}</span>
                            </label>
                        ))}
                    </div>
                    <Field label="Observacion">
                        {editing ? (
                            <textarea value={form.observacion} onChange={e => setField('observacion', e.target.value)} className={inputCls + ' min-h-[60px] resize-none'} />
                        ) : (
                            <p className={readCls + ' whitespace-pre-wrap'}>{form.observacion || '-'}</p>
                        )}
                    </Field>
                </Accordion>

                {/* Nexos Familiares (read-only) */}
                {!creating && localNexos.length > 0 && (
                    <Accordion title="Nexos Familiares">
                        <div className="space-y-2">
                            {localNexos.map(n => (
                                <div key={n.id} className="p-2 rounded-lg bg-gray-50 border border-gray-100">
                                    <div className="flex items-center gap-2">
                                        <span className="text-[13px] font-semibold text-[var(--color-ink)]">{n.nombre}</span>
                                        {n.parentesco && <span className="px-1.5 py-0.5 bg-purple-100 text-purple-700 text-[10px] font-bold rounded">{n.parentesco}</span>}
                                    </div>
                                    <div className="text-[12px] text-[var(--color-ink-faint)] mt-0.5 space-x-2">
                                        {n.cargo && <span>{n.cargo}</span>}
                                        {n.edad && <span>{n.edad} anios</span>}
                                        {n.telefono && <span>Tel: {n.telefono}</span>}
                                    </div>
                                    {n.observaciones && <p className="text-[12px] text-[var(--color-ink-soft)] mt-1">{n.observaciones}</p>}
                                </div>
                            ))}
                        </div>
                    </Accordion>
                )}
            </div>

            {/* Footer actions */}
            {editing && (
                <div className="flex items-center gap-2 px-4 py-3 border-t border-[var(--color-line)] bg-gray-50 rounded-b-xl flex-shrink-0">
                    <button
                        onClick={handleSave}
                        disabled={saving}
                        className="flex-1 px-4 py-2.5 bg-[var(--color-primary)] text-white rounded-lg text-[13px] font-bold hover:bg-[var(--color-primary-light)] disabled:opacity-50 transition-colors"
                    >
                        {saving ? 'Guardando...' : creating ? 'Crear Votante' : 'Guardar Cambios'}
                    </button>
                    {!creating && (
                        <button
                            onClick={() => setConfirmDelete(true)}
                            className="px-3 py-2.5 border border-red-300 text-red-500 rounded-lg text-[13px] font-bold hover:bg-red-50 transition-colors"
                            title="Eliminar votante"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                    )}
                </div>
            )}

            {/* Delete confirmation */}
            {confirmDelete && (
                <>
                    <div className="fixed inset-0 bg-black/50 z-[60]" onClick={() => setConfirmDelete(false)} />
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                        <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[360px] p-6 text-center animate-slide-up">
                            <div className="w-14 h-14 rounded-full bg-red-100 mx-auto mb-4 flex items-center justify-center">
                                <svg className="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </div>
                            <h4 className="text-[16px] font-bold text-[var(--color-ink)] mb-2">Eliminar votante</h4>
                            <p className="text-[13px] text-[var(--color-ink-faint)] mb-5">Esta accion no se puede deshacer. Se eliminaran tambien los contactos y nexos familiares.</p>
                            <div className="flex gap-2">
                                <button onClick={() => setConfirmDelete(false)} className="flex-1 px-4 py-2.5 border border-[var(--color-line)] rounded-lg text-[13px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors">
                                    Cancelar
                                </button>
                                <button onClick={handleDelete} disabled={saving} className="flex-1 px-4 py-2.5 bg-red-500 text-white rounded-lg text-[13px] font-bold hover:bg-red-600 disabled:opacity-50 transition-colors">
                                    {saving ? 'Eliminando...' : 'Eliminar'}
                                </button>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
