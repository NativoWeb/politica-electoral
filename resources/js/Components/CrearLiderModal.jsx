import { useState } from 'react';
import { router } from '@inertiajs/react';
import { offlineCreateLider } from '@/lib/offlineDb';
import { apiFetch, showGlobalToast } from '@/lib/api';
import SearchableDropdown from '@/Components/SearchableDropdown';
import {
    TIPO_OPTIONS, CARGOS_DISPONIBLES, GENERO_OPTIONS,
    TIPO_DOCUMENTO_OPTIONS, ESTADO_CIVIL_OPTIONS, ESCOLARIDAD_OPTIONS,
    NIVEL_CONFIANZA_OPTIONS,
} from '@/lib/mapaPoliticoConstants';

function VoiceButton({ onResult, label }) {
    const [listening, setListening] = useState(false);

    function startListening() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            alert('Tu navegador no soporta dictado por voz. Usa Chrome.');
            return;
        }
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();
        recognition.lang = 'es-CO';
        recognition.continuous = false;
        recognition.interimResults = false;

        recognition.onstart = () => setListening(true);
        recognition.onend = () => setListening(false);
        recognition.onerror = () => setListening(false);
        recognition.onresult = (e) => {
            const text = e.results[0][0].transcript;
            onResult(text);
        };
        recognition.start();
    }

    return (
        <button
            type="button"
            onClick={startListening}
            title={`Dictar ${label}`}
            className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors ${listening ? 'bg-red-500 text-white animate-pulse' : 'bg-gray-100 text-gray-500 hover:bg-[var(--color-primary)] hover:text-white'}`}
        >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
            </svg>
        </button>
    );
}

function CollapsibleSection({ title, open: defaultOpen = false, children }) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden">
            <button type="button" onClick={() => setOpen(!open)} className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors">
                <span className="text-[13px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">{title}</span>
                <svg className={`w-4 h-4 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            {open && <div className="px-4 py-4 space-y-3">{children}</div>}
        </div>
    );
}

const AREA_MET = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Giron', 'Rionegro', 'Lebrija'];
const fieldCls = "w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
const labelCls = "block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1";

const EMPTY_FORM = {
    nombre: '', municipio_id: '', provincia: '', cargo: 'Lider', tipos: ['Lideres'],
    telefono: '', email: '', cedula: '', profesion: '', direccion: '', barrio: '', zona: '', observacion: '',
    tipo_documento: '', fecha_expedicion_doc: '', genero: '', fecha_nacimiento: '', estado_civil: '', escolaridad: '', foto: '',
    departamento_votacion: '', municipio_votacion: '', puesto_votacion: '', direccion_puesto: '', mesa_votacion: '',
    referente_documento: '', referente_nombre: '', referente_apellido: '',
    militante: false, autoriza_datos: false, verificado: false, fallecido: false,
    empresario: false, reservista: false, funcionario: false, exfuncionario: false, gran_elector: false,
    nivel_confianza: '', convenio: '', partido: '',
    facebook: '', twitter: '', instagram: '',
};

export default function CrearLiderModal({ open, onClose, municipios, provincias = [], cargosPorTipo = {} }) {
    const [form, setForm] = useState(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [successMsg, setSuccessMsg] = useState('');

    if (!open) return null;

    const set = (field, value) => setForm(prev => ({ ...prev, [field]: value }));

    function submit(e) {
        e.preventDefault();
        if (!form.nombre.trim()) { setErrors({ nombre: 'El nombre es obligatorio' }); return; }
        if (!form.municipio_id) { setErrors({ municipio_id: 'Selecciona un municipio' }); return; }
        if (saving) return;

        setSaving(true);
        setErrors({});
        setSuccessMsg('');

        const safetyTimeout = setTimeout(() => setSaving(false), 8000);

        if (!navigator.onLine) {
            offlineCreateLider({ ...form, municipio: municipios.find(m => m.id === form.municipio_id)?.name || '' })
                .then(() => {
                    setSuccessMsg('Lider guardado localmente');
                    showGlobalToast('Lider creado sin internet. Se sincronizara automaticamente.');
                    setForm(EMPTY_FORM);
                    setTimeout(() => { setSuccessMsg(''); onClose(); }, 2500);
                })
                .catch(() => { setErrors({ general: 'Error al guardar localmente' }); showGlobalToast('Error al guardar lider', 'error'); })
                .finally(() => { setSaving(false); clearTimeout(safetyTimeout); });
            return;
        }

        apiFetch('/mapa-politico/crear-lider', {
            method: 'POST',
            body: JSON.stringify(form),
        })
            .then(r => { if (!r.ok) throw r; return r.json(); })
            .then(res => {
                setSuccessMsg(res.message || 'Lider creado exitosamente');
                showGlobalToast('Lider creado exitosamente');
                setForm(EMPTY_FORM);
                setTimeout(() => { setSuccessMsg(''); onClose(); router.reload(); }, 1500);
            })
            .catch(async (err) => {
                if (err instanceof Response) {
                    try { const body = await err.json(); setErrors(body.errors || { general: body.message || 'Error al guardar' }); }
                    catch { setErrors({ general: `Error ${err.status}` }); }
                } else {
                    try {
                        await offlineCreateLider({ ...form, municipio: municipios.find(m => m.id === form.municipio_id)?.name || '' });
                        setSuccessMsg('Lider guardado localmente');
                        showGlobalToast('Sin conexion. Lider guardado localmente.');
                        setForm(EMPTY_FORM);
                        setTimeout(() => { setSuccessMsg(''); onClose(); }, 2500);
                    } catch { setErrors({ general: 'Error al guardar' }); }
                }
            })
            .finally(() => { setSaving(false); clearTimeout(safetyTimeout); });
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div className="bg-white lg:rounded-2xl shadow-2xl w-full max-w-[800px] h-[95vh] lg:h-auto lg:max-h-[90vh] overflow-y-auto rounded-t-2xl lg:rounded-2xl">

                    <div className="bg-[var(--color-primary)] text-white px-4 lg:px-8 py-4 lg:py-6 rounded-t-2xl flex items-center justify-between sticky top-0 z-10">
                        <div>
                            <h2 className="text-[18px] lg:text-[22px] font-extrabold">CREAR NUEVO LIDER</h2>
                            <p className="text-[12px] lg:text-[13px] text-white/50 mt-1">Usa el microfono para dictar los campos</p>
                        </div>
                        <button onClick={onClose} className="w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-white/15 hover:bg-white/30 flex items-center justify-center text-white flex-shrink-0">
                            <svg className="w-5 h-5 lg:w-6 lg:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form onSubmit={submit} className="px-4 lg:px-8 py-4 lg:py-6 space-y-4">
                        {successMsg && (
                            <div className="px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-[14px] font-semibold text-emerald-700">{successMsg}</div>
                        )}
                        {Object.keys(errors).length > 0 && (
                            <div className="px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-[13px] text-red-700">
                                {Object.values(errors).map((err, i) => <p key={i}>{err}</p>)}
                            </div>
                        )}

                        {/* Nombre + Voz */}
                        <div>
                            <label className={labelCls}>Nombre completo *</label>
                            <div className="flex gap-2">
                                <input className={fieldCls} value={form.nombre} onChange={e => set('nombre', e.target.value)} required placeholder="Nombre del lider" />
                                <VoiceButton label="nombre" onResult={t => set('nombre', t)} />
                            </div>
                        </div>

                        {/* Provincia + Municipio */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <SearchableDropdown
                                label="Provincia"
                                options={provincias}
                                selected={form.provincia || undefined}
                                onChange={val => setForm(prev => ({ ...prev, provincia: val || '', municipio_id: '' }))}
                                multi={false}
                                placeholder="Todas"
                            />
                            <SearchableDropdown
                                label="Municipio *"
                                options={(() => {
                                    const filtered = !form.provincia ? municipios : form.provincia === 'Area Metropolitana' ? municipios.filter(m => AREA_MET.includes(m.name)) : municipios.filter(m => m.provincia === form.provincia);
                                    return filtered.map(m => `${m.name} (${m.provincia})`);
                                })()}
                                selected={form.municipio_id ? (() => { const m = municipios.find(m => m.id === form.municipio_id); return m ? `${m.name} (${m.provincia})` : undefined; })() : undefined}
                                onChange={val => {
                                    if (!val) { set('municipio_id', ''); return; }
                                    const name = val.split(' (')[0];
                                    const mun = municipios.find(m => m.name === name);
                                    set('municipio_id', mun?.id || '');
                                }}
                                multi={false}
                                placeholder="Seleccionar..."
                            />
                        </div>

                        {/* Tipo checkboxes */}
                        <div>
                            <label className={`${labelCls} mb-2`}>Tipo</label>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                {TIPO_OPTIONS.filter(o => o.value !== 'todos').map(o => {
                                    const checked = (form.tipos || []).includes(o.label);
                                    return (
                                        <label key={o.value} className={`flex items-center gap-2 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors text-[13px] ${checked ? 'bg-blue-50 border-[var(--color-primary)] font-bold text-[var(--color-primary)]' : 'border-[var(--color-line)] text-[var(--color-ink-soft)]'}`}>
                                            <input type="checkbox" checked={checked} onChange={() => {
                                                const tipos = form.tipos || [];
                                                const next = checked ? tipos.filter(x => x !== o.label) : [...tipos, o.label];
                                                set('tipos', next);
                                            }} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)]" />
                                            {o.label}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Cargo checkboxes */}
                        <div>
                            <label className={`${labelCls} mb-2`}>Cargo</label>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                {(() => {
                                    const selectedTipos = (form.tipos || []).map(label => TIPO_OPTIONS.find(o => o.label === label)?.value).filter(Boolean);
                                    if (selectedTipos.length === 0) return cargosPorTipo['todos'] || CARGOS_DISPONIBLES;
                                    const merged = [...new Set(selectedTipos.flatMap(t => cargosPorTipo[t] || []))];
                                    return merged.length > 0 ? merged.sort() : CARGOS_DISPONIBLES;
                                })().map(c => {
                                    const cargos = form.cargo ? form.cargo.split(', ').map(s => s.trim()) : [];
                                    const checked = cargos.includes(c);
                                    return (
                                        <label key={c} className={`flex items-center gap-2 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors text-[13px] ${checked ? 'bg-emerald-50 border-emerald-400 font-bold text-emerald-700' : 'border-[var(--color-line)] text-[var(--color-ink-soft)]'}`}>
                                            <input type="checkbox" checked={checked} onChange={() => {
                                                const next = checked ? cargos.filter(x => x !== c) : [...cargos, c];
                                                set('cargo', next.join(', '));
                                            }} className="w-4 h-4 rounded border-gray-300 text-emerald-500" />
                                            {c}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>Telefono</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.telefono} onChange={e => set('telefono', e.target.value)} placeholder="3XX XXX XXXX" />
                                    <VoiceButton label="telefono" onResult={t => set('telefono', t.replace(/\s/g, ''))} />
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>Email</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.email} onChange={e => set('email', e.target.value)} placeholder="correo@ejemplo.com" />
                                    <VoiceButton label="email" onResult={t => set('email', t.replace(/\s/g, '').toLowerCase())} />
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className={labelCls}>Cedula</label>
                                <input className={fieldCls} value={form.cedula} onChange={e => set('cedula', e.target.value)} placeholder="Numero de cedula" />
                            </div>
                            <div>
                                <label className={labelCls}>Profesion</label>
                                <input className={fieldCls} value={form.profesion} onChange={e => set('profesion', e.target.value)} placeholder="Ej: Abogado, Ingeniero..." />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label className={labelCls}>Direccion</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.direccion} onChange={e => set('direccion', e.target.value)} placeholder="Calle / Carrera" />
                                    <VoiceButton label="direccion" onResult={t => set('direccion', t)} />
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>Barrio / Vereda / Corregimiento</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.barrio} onChange={e => set('barrio', e.target.value)} placeholder="Barrio, vereda o corregimiento" />
                                    <VoiceButton label="barrio" onResult={t => set('barrio', t)} />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label className={labelCls}>Observaciones</label>
                            <div className="flex gap-2">
                                <textarea className={fieldCls} rows={3} value={form.observacion} onChange={e => set('observacion', e.target.value)} placeholder="Notas adicionales..." />
                                <VoiceButton label="observaciones" onResult={t => set('observacion', (form.observacion ? form.observacion + ' ' : '') + t)} />
                            </div>
                        </div>

                        {/* ── Collapsible: Informacion Basica ── */}
                        <CollapsibleSection title="Informacion Personal">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className={labelCls}>Tipo documento</label>
                                    <select className={fieldCls} value={form.tipo_documento} onChange={e => set('tipo_documento', e.target.value)}>
                                        <option value="">Seleccionar...</option>
                                        {TIPO_DOCUMENTO_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>Genero</label>
                                    <select className={fieldCls} value={form.genero} onChange={e => set('genero', e.target.value)}>
                                        <option value="">Seleccionar...</option>
                                        {GENERO_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className={labelCls}>Fecha nacimiento</label>
                                    <input type="date" className={fieldCls} value={form.fecha_nacimiento} onChange={e => set('fecha_nacimiento', e.target.value)} />
                                </div>
                                <div>
                                    <label className={labelCls}>Estado civil</label>
                                    <select className={fieldCls} value={form.estado_civil} onChange={e => set('estado_civil', e.target.value)}>
                                        <option value="">Seleccionar...</option>
                                        {ESTADO_CIVIL_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>Escolaridad</label>
                                <select className={fieldCls} value={form.escolaridad} onChange={e => set('escolaridad', e.target.value)}>
                                    <option value="">Seleccionar...</option>
                                    {ESCOLARIDAD_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                </select>
                            </div>
                        </CollapsibleSection>

                        {/* ── Collapsible: Ubicacion y Votacion ── */}
                        <CollapsibleSection title="Ubicacion y Votacion">
                            <div>
                                <label className={labelCls}>Zona</label>
                                <select className={fieldCls} value={form.zona} onChange={e => set('zona', e.target.value)}>
                                    <option value="">Seleccionar...</option>
                                    <option value="rural">Rural</option>
                                    <option value="urbana">Urbana</option>
                                </select>
                            </div>
                            <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Puesto de votacion</p>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className={labelCls}>Departamento</label>
                                    <input className={fieldCls} value={form.departamento_votacion} onChange={e => set('departamento_votacion', e.target.value)} placeholder="Ej: Santander" />
                                </div>
                                <div>
                                    <label className={labelCls}>Municipio</label>
                                    <input className={fieldCls} value={form.municipio_votacion} onChange={e => set('municipio_votacion', e.target.value)} />
                                </div>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className={labelCls}>Puesto</label>
                                    <input className={fieldCls} value={form.puesto_votacion} onChange={e => set('puesto_votacion', e.target.value)} />
                                </div>
                                <div>
                                    <label className={labelCls}>Mesa</label>
                                    <input className={fieldCls} value={form.mesa_votacion} onChange={e => set('mesa_votacion', e.target.value)} />
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>Direccion del puesto</label>
                                <input className={fieldCls} value={form.direccion_puesto} onChange={e => set('direccion_puesto', e.target.value)} />
                            </div>
                        </CollapsibleSection>

                        {/* ── Collapsible: Referente ── */}
                        <CollapsibleSection title="Referente">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label className={labelCls}>Documento</label>
                                    <input className={fieldCls} value={form.referente_documento} onChange={e => set('referente_documento', e.target.value)} />
                                </div>
                                <div>
                                    <label className={labelCls}>Nombre</label>
                                    <input className={fieldCls} value={form.referente_nombre} onChange={e => set('referente_nombre', e.target.value)} />
                                </div>
                                <div>
                                    <label className={labelCls}>Apellido</label>
                                    <input className={fieldCls} value={form.referente_apellido} onChange={e => set('referente_apellido', e.target.value)} />
                                </div>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className={labelCls}>Partido</label>
                                    <input className={fieldCls} value={form.partido} onChange={e => set('partido', e.target.value)} placeholder="Nombre del partido" />
                                </div>
                                <div>
                                    <label className={labelCls}>Nivel de confianza</label>
                                    <select className={fieldCls} value={form.nivel_confianza} onChange={e => set('nivel_confianza', e.target.value)}>
                                        {NIVEL_CONFIANZA_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>Convenio</label>
                                <input className={fieldCls} value={form.convenio} onChange={e => set('convenio', e.target.value)} />
                            </div>
                        </CollapsibleSection>

                        {/* ── Collapsible: Redes Sociales ── */}
                        <CollapsibleSection title="Redes sociales">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label className={labelCls}>Facebook</label>
                                    <input className={fieldCls} value={form.facebook} onChange={e => set('facebook', e.target.value)} placeholder="URL o usuario" />
                                </div>
                                <div>
                                    <label className={labelCls}>Twitter / X</label>
                                    <input className={fieldCls} value={form.twitter} onChange={e => set('twitter', e.target.value)} placeholder="@usuario" />
                                </div>
                                <div>
                                    <label className={labelCls}>Instagram</label>
                                    <input className={fieldCls} value={form.instagram} onChange={e => set('instagram', e.target.value)} placeholder="@usuario" />
                                </div>
                            </div>
                        </CollapsibleSection>

                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={onClose} className="px-6 py-3 text-[16px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                            <button type="submit" disabled={saving} className="px-8 py-3 bg-[var(--color-good)] text-white text-[16px] font-bold rounded-lg hover:bg-emerald-600 transition-colors disabled:opacity-50">
                                {saving ? 'Guardando...' : 'Crear Lider'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}
