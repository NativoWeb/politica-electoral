import { useState, useEffect, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { getOfflinePersona, offlineUpdatePersona, offlineCreateNexo, offlineDeleteNexo } from '@/lib/offlineDb';
import { apiFetch, showGlobalToast } from '@/lib/api';
import { FullScreenSpinner } from '@/Components/Spinner';
import TabBar from '@/Components/TabBar';
import ContactosManager from '@/Components/ContactosManager';
import InfoPoliticaManager from '@/Components/InfoPoliticaManager';
import TagsPanel from '@/Components/TagsPanel';
import {
    TIPO_OPTIONS, CARGOS_DISPONIBLES, PARENTESCOS,
    NIVEL_CONFIANZA_OPTIONS, NIVEL_CONFIANZA_COLORS,
    GENERO_OPTIONS, TIPO_DOCUMENTO_OPTIONS, ESTADO_CIVIL_OPTIONS, ESCOLARIDAD_OPTIONS,
} from '@/lib/mapaPoliticoConstants';

function useConfirm() {
    const [state, setState] = useState({ open: false, title: '', message: '', onConfirm: null, danger: false });
    const confirm = useCallback((title, message, onConfirm, danger = true) => {
        setState({ open: true, title, message, onConfirm, danger });
    }, []);
    const close = useCallback(() => setState(s => ({ ...s, open: false })), []);
    const Dialog = () => !state.open ? null : (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={close} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[380px] overflow-hidden animate-slide-up">
                    <div className="px-6 pt-6 pb-4 text-center">
                        <div className={`w-14 h-14 rounded-full mx-auto mb-4 flex items-center justify-center ${state.danger ? 'bg-red-100' : 'bg-blue-100'}`}>
                            {state.danger ? (
                                <svg className="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            ) : (
                                <svg className="w-7 h-7 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            )}
                        </div>
                        <h3 className="text-[18px] font-extrabold text-[var(--color-ink)]">{state.title}</h3>
                        <p className="text-[14px] text-[var(--color-ink-faint)] mt-2 leading-relaxed">{state.message}</p>
                    </div>
                    <div className="px-6 pb-6 flex gap-3">
                        <button onClick={close} className="flex-1 px-4 py-3 border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)] rounded-xl active:bg-gray-100">Cancelar</button>
                        <button onClick={() => { close(); state.onConfirm?.(); }} className={`flex-1 px-4 py-3 text-white text-[15px] font-bold rounded-xl ${state.danger ? 'bg-red-500 active:bg-red-600' : 'bg-[var(--color-primary)] active:bg-[var(--color-primary-dark)]'}`}>{state.danger ? 'Eliminar' : 'Confirmar'}</button>
                    </div>
                </div>
            </div>
        </>
    );
    return { confirm, Dialog };
}

function dateVal(v) {
    if (!v) return '';
    return String(v).split('T')[0];
}

function buildFormFromData(d) {
    const rawCargos = d.cargos || (d.cargo ? [d.cargo] : []);
    const splitCargos = [...new Set((Array.isArray(rawCargos) ? rawCargos : [rawCargos]).flatMap(c => c.split(',').map(s => s.trim())).filter(Boolean))];
    const tipos = d.tipo_registro ? d.tipo_registro.split(',').map(s => s.trim()) : [];
    return {
        telefono: d.telefono || '', email: d.email || '',
        cargo: splitCargos.join(', '), cargos: splitCargos, tipos,
        observacion: d.observacion || '', direccion: d.direccion || '',
        barrio: d.barrio || '', zona: d.zona || '', partido: d.partido || '',
        destacado: !!d.destacado, profesion: d.profesion || '',
        votos: d.votos || '', cedula: d.cedula || '',
        tipo_documento: d.tipo_documento || '', fecha_expedicion_doc: dateVal(d.fecha_expedicion_doc),
        genero: d.genero || '', fecha_nacimiento: dateVal(d.fecha_nacimiento),
        foto: d.foto || '', estado_civil: d.estado_civil || '',
        escolaridad: d.escolaridad || '',
        departamento_votacion: d.departamento_votacion || '', municipio_votacion: d.municipio_votacion || '',
        puesto_votacion: d.puesto_votacion || '', direccion_puesto: d.direccion_puesto || '',
        mesa_votacion: d.mesa_votacion || '',
        referente_documento: d.referente_documento || '', referente_nombre: d.referente_nombre || '',
        referente_apellido: d.referente_apellido || '',
        militante: !!d.militante, autoriza_datos: !!d.autoriza_datos, verificado: !!d.verificado,
        fallecido: !!d.fallecido, empresario: !!d.empresario, reservista: !!d.reservista,
        funcionario: !!d.funcionario, exfuncionario: !!d.exfuncionario, gran_elector: !!d.gran_elector,
        nivel_confianza: d.nivel_confianza || '', convenio: d.convenio || '',
        tipo_hoja_vida: d.tipo_hoja_vida || '', fecha_registro_hv: dateVal(d.fecha_registro_hv),
        facebook: d.facebook || '', twitter: d.twitter || '', instagram: d.instagram || '',
    };
}

const TABS = [
    { key: 'basicos', label: 'Basicos' },
    { key: 'ubicacion', label: 'Ubicacion' },
    { key: 'contactos', label: 'Contactos' },
    { key: 'politica', label: 'Politica' },
    { key: 'nexos', label: 'Nexos' },
    { key: 'opciones', label: 'Opciones' },
];

const fieldCls = "w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
const labelCls = "block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1";

function DataRow({ label, value }) {
    return (
        <div className="flex items-start justify-between py-2 border-b border-[var(--color-line)] last:border-0">
            <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[140px]">{label}</span>
            <span className="text-[16px] text-[var(--color-ink)] text-right flex-1">{value || '—'}</span>
        </div>
    );
}

export default function PersonaPanel({ personId, onClose, cargosPorTipo = {}, fallbackData = null }) {
    const { confirm, Dialog: ConfirmDialog } = useConfirm();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [editMode, setEditMode] = useState(false);
    const [form, setForm] = useState({});
    const [nexoForm, setNexoForm] = useState({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '', cedula: '', telefono: '' });
    const [showNexoForm, setShowNexoForm] = useState(false);
    const [editingNexoId, setEditingNexoId] = useState(null);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [activeTab, setActiveTab] = useState('basicos');

    function loadData() {
        return apiFetch(`/mapa-politico/persona/${personId}`, { method: 'GET' })
            .then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const ct = r.headers.get('content-type') || '';
                if (!ct.includes('json')) throw new Error('Respuesta no es JSON');
                return r.json();
            })
            .then(d => {
                setData(d);
                setLoadError(false);
                setForm(buildFormFromData(d));
            })
            .catch(async () => {
                const offlineData = await getOfflinePersona(personId).catch(() => null);
                const source = offlineData || fallbackData;
                if (source) {
                    setData({ ...source, _offline: true });
                    setLoadError(false);
                    setForm(buildFormFromData(source));
                } else {
                    setLoadError(true);
                }
            });
    }

    useEffect(() => {
        setLoading(true);
        setLoadError(false);
        loadData().finally(() => setLoading(false));
    }, [personId]);

    function savePersona() {
        setSaving(true);
        setMessage('');
        const payload = { ...form, votos: form.votos ? parseInt(form.votos, 10) : 0 };
        apiFetch(`/mapa-politico/persona/${personId}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        })
            .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
            .then(res => {
                setMessage(res.message || 'Guardado');
                setEditMode(false);
                loadData();
                if (form.partido) {
                    setTimeout(() => router.reload({ only: ['data'] }), 500);
                }
                setTimeout(() => setMessage(''), 3000);
            })
            .catch(async () => {
                try {
                    await offlineUpdatePersona(personId, form);
                    setMessage('Guardado localmente');
                    showGlobalToast('Guardado sin internet. Se sincronizara automaticamente.');
                    setEditMode(false);
                    setData(prev => ({ ...prev, ...form }));
                } catch {
                    setMessage('Error al guardar');
                    showGlobalToast('Error al guardar', 'error');
                }
                setTimeout(() => setMessage(''), 5000);
            })
            .finally(() => setSaving(false));
    }

    function addNexo(e) {
        e.preventDefault();
        apiFetch(`/mapa-politico/persona/${personId}/nexos`, {
            method: 'POST',
            body: JSON.stringify(nexoForm),
        })
            .then(r => r.json())
            .then(() => {
                setNexoForm({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '', cedula: '', telefono: '' });
                setShowNexoForm(false);
                setMessage('Nexo agregado');
                loadData();
                setTimeout(() => setMessage(''), 3000);
            })
            .catch(async () => {
                try {
                    const newNexo = await offlineCreateNexo(personId, nexoForm);
                    setNexoForm({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '', cedula: '', telefono: '' });
                    setShowNexoForm(false);
                    setMessage('Nexo guardado localmente');
                    showGlobalToast('Nexo guardado sin internet. Se sincronizara automaticamente.');
                    setData(prev => ({ ...prev, nexos: [...(prev.nexos || []), { ...nexoForm, id: newNexo.id }] }));
                    setTimeout(() => setMessage(''), 5000);
                } catch {
                    setMessage('Error al guardar nexo');
                    showGlobalToast('Error al guardar nexo', 'error');
                }
            });
    }

    function startEditNexo(n) {
        setEditingNexoId(n.id);
        setNexoForm({ nombre: n.nombre || '', parentesco: n.parentesco || '', cargo: n.cargo || '', edad: n.edad || '', gustos: n.gustos || '', observaciones: n.observaciones || '', cedula: n.cedula || '', telefono: n.telefono || '' });
        setShowNexoForm(false);
    }

    function saveEditNexo(e) {
        e.preventDefault();
        apiFetch(`/mapa-politico/nexos/${editingNexoId}`, {
            method: 'PUT',
            body: JSON.stringify(nexoForm),
        })
            .then(r => r.json())
            .then(() => {
                setEditingNexoId(null);
                setNexoForm({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '', cedula: '', telefono: '' });
                setMessage('Nexo actualizado');
                loadData();
                setTimeout(() => setMessage(''), 3000);
            })
            .catch(() => setMessage('Error al actualizar nexo'));
    }

    function deleteNexo(nexoId) {
        confirm('Eliminar nexo', '¿Seguro que quieres eliminar este nexo familiar?', () => {
            apiFetch(`/mapa-politico/nexos/${nexoId}`, { method: 'DELETE' })
                .then(() => loadData())
                .catch(async () => {
                    await offlineDeleteNexo(nexoId).catch(() => {});
                    setData(prev => ({ ...prev, nexos: (prev.nexos || []).filter(n => n.id !== nexoId) }));
                    setMessage('Eliminado localmente. Se sincronizara con internet.');
                    setTimeout(() => setMessage(''), 5000);
                });
        });
    }

    if (loading) return <FullScreenSpinner message="Cargando ficha..." />;

    if (loadError || !data) return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[400px] p-8 text-center">
                    <svg className="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                    <p className="text-[16px] font-bold text-[var(--color-ink)] mb-2">No se pudo cargar la ficha</p>
                    <p className="text-[14px] text-[var(--color-ink-faint)] mb-6">Revisa tu conexion a internet e intenta de nuevo.</p>
                    <div className="flex gap-3 justify-center">
                        <button onClick={() => { setLoading(true); setLoadError(false); loadData().finally(() => setLoading(false)); }} className="px-6 py-3 bg-[var(--color-primary)] text-white text-[15px] font-bold rounded-xl">Reintentar</button>
                        <button onClick={onClose} className="px-6 py-3 border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)] rounded-xl">Cerrar</button>
                    </div>
                </div>
            </div>
        </>
    );

    const tabsWithCounts = TABS.map(t => {
        if (t.key === 'nexos') return { ...t, count: data.nexos?.length ?? 0 };
        if (t.key === 'contactos') return { ...t, count: (data.contactos?.length ?? 0) };
        if (t.key === 'politica') return { ...t, count: (data.info_politica?.length ?? 0) };
        return t;
    });

    const isLider = data.source === 'lider';
    const f = (field) => form[field] ?? '';
    const set = (field, value) => setForm(prev => ({ ...prev, [field]: value }));

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-end lg:items-center justify-center lg:p-4">
                <div className="bg-white lg:rounded-2xl shadow-2xl w-full max-w-[750px] h-[95vh] lg:h-auto lg:max-h-[90vh] overflow-y-auto rounded-t-2xl lg:rounded-2xl">

                    {/* Header */}
                    <div className="bg-[var(--color-primary)] text-white px-4 lg:px-8 py-4 lg:py-6 rounded-t-2xl flex items-start justify-between gap-3 sticky top-0 z-10">
                        <div className="min-w-0 flex-1">
                            <p className="text-[10px] lg:text-[12px] text-white/50 uppercase tracking-widest">
                                Ficha personal
                                {data._offline && <span className="ml-2 px-2 py-0.5 bg-amber-500 text-white text-[10px] font-bold rounded normal-case">Datos guardados</span>}
                            </p>
                            <h2 className="text-[18px] lg:text-[24px] font-extrabold uppercase mt-1 break-words">{data.nombre}</h2>
                            <p className="text-[12px] lg:text-[14px] text-white/60 mt-1 truncate">{data.municipio ?? '—'} · {data.cargo ?? '—'}</p>
                        </div>
                        <button onClick={onClose} className="w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-white/15 hover:bg-white/30 flex items-center justify-center text-white transition-colors flex-shrink-0">
                            <svg className="w-5 h-5 lg:w-6 lg:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    {/* Actions bar */}
                    <div className="flex items-center gap-2 px-4 lg:px-8 py-3 border-b border-[var(--color-line)] flex-wrap">
                        <button onClick={() => setEditMode(!editMode)} className="px-4 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">
                            {editMode ? 'Cancelar' : 'Editar'}
                        </button>
                        {editMode && (
                            <button onClick={savePersona} disabled={saving} className="px-6 py-2.5 bg-[var(--color-good)] text-white text-[13px] font-bold rounded-lg hover:bg-emerald-600 transition-colors disabled:opacity-50">
                                {saving ? 'Guardando...' : 'Guardar cambios'}
                            </button>
                        )}
                        <div className="flex-1" />
                        <button onClick={() => {
                            const w = window.open('', '_blank');
                            w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ficha - ${data.nombre}</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;color:#1a1a2e}.header{background:#003B71;color:white;padding:30px 40px}.header h1{font-size:24px;margin-bottom:4px}.header p{font-size:13px;opacity:.7}.section{padding:20px 40px;border-bottom:1px solid #e8e8ee}.section h2{font-size:12px;text-transform:uppercase;letter-spacing:2px;color:#8e8ea0;margin-bottom:15px}.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0}.row:last-child{border:none}.label{font-size:13px;color:#8e8ea0;text-transform:uppercase;width:140px;flex-shrink:0}.value{font-size:14px;text-align:right;flex:1}.nexo{background:#f5f5f5;border-radius:8px;padding:12px 16px;margin-bottom:10px}.nexo-name{font-size:16px;font-weight:bold}.nexo-detail{font-size:12px;color:#8e8ea0;margin-top:4px}.footer{padding:20px 40px;font-size:10px;color:#8e8ea0;text-align:center}@media print{body{padding:0}.no-print{display:none}}</style></head><body><div class="header"><p>FICHA PERSONAL</p><h1>${data.nombre}</h1><p>${data.municipio || ''} · ${data.cargo || ''}</p></div><div class="section"><h2>Datos Personales</h2>${[['Cedula',data.cedula],['Telefono',data.telefono],['Email',data.email],['Partido',data.partido],['Tipo',data.tipo_registro],['Cargo',data.cargos?.join(', ')||data.cargo],['Profesion',data.profesion],['Votos',data.votos>0?data.votos.toLocaleString('es-CO'):null],['Direccion',data.direccion],['Barrio/Vereda',data.barrio],['Observaciones',data.observacion]].filter(([,v])=>v).map(([l,v])=>'<div class="row"><span class="label">'+l+'</span><span class="value">'+v+'</span></div>').join('')}</div>${data.nexos?.length>0?'<div class="section"><h2>Familia / Nexos ('+data.nexos.length+')</h2>'+data.nexos.map(n=>'<div class="nexo"><div class="nexo-name">'+n.nombre+'</div><div class="nexo-detail">'+ [n.parentesco,n.cargo,n.edad?n.edad+' años':null,n.cedula?'CC: '+n.cedula:null,n.telefono,n.gustos?'Gustos: '+n.gustos:null,n.observaciones].filter(Boolean).join(' · ')+'</div></div>').join('')+'</div>':''}<div class="footer">Inteligencia Electoral Santander · Generado el ${new Date().toLocaleDateString('es-CO')} a las ${new Date().toLocaleTimeString('es-CO')}</div><div class="no-print" style="text-align:center;padding:20px;display:flex;gap:12px;justify-content:center"><button onclick="window.close()" style="padding:12px 30px;background:white;color:#003B71;border:2px solid #003B71;border-radius:8px;font-size:15px;font-weight:bold;cursor:pointer">Volver</button><button onclick="window.print()" style="padding:12px 30px;background:#003B71;color:white;border:none;border-radius:8px;font-size:15px;font-weight:bold;cursor:pointer">Imprimir / Guardar PDF</button></div></body></html>`);
                            w.document.close();
                        }} className="px-3 py-2.5 bg-emerald-50 text-emerald-700 text-[13px] font-bold rounded-lg hover:bg-emerald-100 transition-colors flex items-center gap-1.5" title="Exportar ficha">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            PDF
                        </button>
                        <button onClick={() => {
                            confirm('Eliminar persona', '¿Eliminar esta persona? Se borraran sus nexos familiares. Esta accion no se puede deshacer.', () => {
                                apiFetch(`/mapa-politico/persona/${personId}`, { method: 'DELETE' })
                                    .then(r => r.json())
                                    .then(() => { onClose(); router.get(window.location.href, {}, { preserveState: false }); })
                                    .catch(() => setMessage('Error al eliminar'));
                            });
                        }} className="px-3 py-2.5 bg-red-50 text-red-600 text-[14px] font-bold rounded-lg hover:bg-red-100 transition-colors" title="Eliminar persona">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                    </div>

                    {/* Status message */}
                    {message && (
                        <div className={`mx-4 lg:mx-8 mt-3 px-4 py-3 rounded-xl text-[14px] font-semibold flex items-center gap-3 ${message.toLowerCase().includes('error') ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700'}`}>
                            {message.toLowerCase().includes('error') ? (
                                <svg className="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            ) : (
                                <svg className="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                            )}
                            {message}
                        </div>
                    )}

                    {/* Tab bar */}
                    <TabBar tabs={tabsWithCounts} active={activeTab} onChange={setActiveTab} />

                    {/* Tab content */}
                    <div className="px-4 lg:px-8 py-4 lg:py-6">

                        {/* ── BASICOS ── */}
                        {activeTab === 'basicos' && (
                            editMode ? (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Cedula</label>
                                            <input className={fieldCls} value={f('cedula')} onChange={e => set('cedula', e.target.value)} placeholder="Numero de cedula" />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Tipo documento</label>
                                            <select className={fieldCls} value={f('tipo_documento')} onChange={e => set('tipo_documento', e.target.value)}>
                                                <option value="">Seleccionar...</option>
                                                {TIPO_DOCUMENTO_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Fecha expedicion documento</label>
                                            <input type="date" className={fieldCls} value={f('fecha_expedicion_doc')} onChange={e => set('fecha_expedicion_doc', e.target.value)} />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Genero</label>
                                            <select className={fieldCls} value={f('genero')} onChange={e => set('genero', e.target.value)}>
                                                <option value="">Seleccionar...</option>
                                                {GENERO_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Fecha nacimiento</label>
                                            <input type="date" className={fieldCls} value={f('fecha_nacimiento')} onChange={e => set('fecha_nacimiento', e.target.value)} />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Estado civil</label>
                                            <select className={fieldCls} value={f('estado_civil')} onChange={e => set('estado_civil', e.target.value)}>
                                                <option value="">Seleccionar...</option>
                                                {ESTADO_CIVIL_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Profesion</label>
                                            <input className={fieldCls} value={f('profesion')} onChange={e => set('profesion', e.target.value)} placeholder="Ej: Abogado, Ingeniero..." />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Escolaridad</label>
                                            <select className={fieldCls} value={f('escolaridad')} onChange={e => set('escolaridad', e.target.value)}>
                                                <option value="">Seleccionar...</option>
                                                {ESCOLARIDAD_OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label className={labelCls}>Foto (URL)</label>
                                        <input className={fieldCls} value={f('foto')} onChange={e => set('foto', e.target.value)} placeholder="https://..." />
                                    </div>
                                    <label className="flex items-center gap-3 px-4 py-3 rounded-lg border cursor-pointer transition-colors bg-amber-50 border-amber-200">
                                        <input type="checkbox" checked={form.destacado} onChange={e => set('destacado', e.target.checked)} className="w-6 h-6 rounded border-amber-300 text-amber-500 focus:ring-amber-400" />
                                        <div>
                                            <span className="text-[15px] font-bold text-amber-700">Persona destacada</span>
                                            <p className="text-[12px] text-amber-600">Aparecera con estrella en el listado</p>
                                        </div>
                                    </label>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <DataRow label="Cedula" value={data.cedula} />
                                    <DataRow label="Tipo doc." value={data.tipo_documento} />
                                    <DataRow label="Fec. expedicion" value={dateVal(data.fecha_expedicion_doc)} />
                                    <DataRow label="Genero" value={data.genero} />
                                    <DataRow label="Fec. nacimiento" value={dateVal(data.fecha_nacimiento)} />
                                    <DataRow label="Estado civil" value={data.estado_civil} />
                                    <DataRow label="Profesion" value={data.profesion} />
                                    <DataRow label="Escolaridad" value={data.escolaridad} />
                                    {data.foto && (
                                        <div className="flex items-start justify-between py-2 border-b border-[var(--color-line)]">
                                            <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[140px]">Foto</span>
                                            <img src={data.foto} alt="Foto" className="w-16 h-16 rounded-lg object-cover" onError={e => { e.target.style.display = 'none'; }} />
                                        </div>
                                    )}
                                </div>
                            )
                        )}

                        {/* ── UBICACION ── */}
                        {activeTab === 'ubicacion' && (
                            editMode ? (
                                <div className="space-y-4">
                                    <div>
                                        <label className={labelCls}>Municipio</label>
                                        <input className={`${fieldCls} bg-gray-50`} value={data.municipio || ''} disabled />
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Direccion</label>
                                            <input className={fieldCls} value={f('direccion')} onChange={e => set('direccion', e.target.value)} />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Barrio / Vereda / Corregimiento</label>
                                            <input className={fieldCls} value={f('barrio')} onChange={e => set('barrio', e.target.value)} />
                                        </div>
                                    </div>
                                    <div>
                                        <label className={labelCls}>Zona</label>
                                        <select className={fieldCls} value={f('zona')} onChange={e => set('zona', e.target.value)}>
                                            <option value="">Seleccionar...</option>
                                            <option value="rural">Rural</option>
                                            <option value="urbana">Urbana</option>
                                        </select>
                                    </div>
                                    <div className="pt-2 border-t border-[var(--color-line)]">
                                        <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Puesto de votacion</p>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label className={labelCls}>Departamento</label>
                                                <input className={fieldCls} value={f('departamento_votacion')} onChange={e => set('departamento_votacion', e.target.value)} placeholder="Ej: Santander" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Municipio</label>
                                                <input className={fieldCls} value={f('municipio_votacion')} onChange={e => set('municipio_votacion', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                                            <div>
                                                <label className={labelCls}>Puesto</label>
                                                <input className={fieldCls} value={f('puesto_votacion')} onChange={e => set('puesto_votacion', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Mesa</label>
                                                <input className={fieldCls} value={f('mesa_votacion')} onChange={e => set('mesa_votacion', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="mt-3">
                                            <label className={labelCls}>Direccion del puesto</label>
                                            <input className={fieldCls} value={f('direccion_puesto')} onChange={e => set('direccion_puesto', e.target.value)} />
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <DataRow label="Municipio" value={data.municipio} />
                                    <DataRow label="Direccion" value={data.direccion} />
                                    <DataRow label="Barrio / Vereda" value={data.barrio} />
                                    <DataRow label="Zona" value={data.zona} />
                                    {(data.puesto_votacion || data.departamento_votacion || data.municipio_votacion) && (
                                        <>
                                            <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] pt-3">Puesto de votacion</p>
                                            <DataRow label="Departamento" value={data.departamento_votacion} />
                                            <DataRow label="Municipio" value={data.municipio_votacion} />
                                            <DataRow label="Puesto" value={data.puesto_votacion} />
                                            <DataRow label="Mesa" value={data.mesa_votacion} />
                                            <DataRow label="Dir. puesto" value={data.direccion_puesto} />
                                        </>
                                    )}
                                </div>
                            )
                        )}

                        {/* ── CONTACTOS ── */}
                        {activeTab === 'contactos' && (
                            <div className="space-y-6">
                                {editMode ? (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>Telefono</label>
                                            <input className={fieldCls} value={f('telefono')} onChange={e => set('telefono', e.target.value)} />
                                        </div>
                                        <div>
                                            <label className={labelCls}>Email</label>
                                            <input className={fieldCls} value={f('email')} onChange={e => set('email', e.target.value)} />
                                        </div>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <div className="flex items-start justify-between py-2 border-b border-[var(--color-line)]">
                                            <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[140px]">Telefono</span>
                                            <span className="text-right flex-1">
                                                {data.telefono ? (
                                                    <span className="flex items-center justify-end gap-3">
                                                        <a href={`tel:${data.telefono}`} className="text-[var(--color-primary)] font-bold hover:underline text-[18px]">{data.telefono}</a>
                                                        <a href={`https://wa.me/57${data.telefono.replace(/\D/g,'').replace(/^57/,'')}`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 px-3 py-1.5 bg-[#25D366] text-white text-[13px] font-bold rounded-lg">
                                                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
                                                            WhatsApp
                                                        </a>
                                                    </span>
                                                ) : '—'}
                                            </span>
                                        </div>
                                        <DataRow label="Email" value={data.email} />
                                    </div>
                                )}

                                {/* Contactos CRUD - only for lideres */}
                                {isLider ? (
                                    <ContactosManager personId={personId} contactos={data.contactos || []} onUpdate={loadData} />
                                ) : (
                                    <p className="text-[13px] text-[var(--color-ink-faint)] text-center py-3 bg-gray-50 rounded-lg">Guarda los datos para habilitar contactos adicionales.</p>
                                )}

                                {/* Social media */}
                                <div>
                                    <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Redes sociales</p>
                                    {editMode ? (
                                        <div className="space-y-3">
                                            <div>
                                                <label className={labelCls}>Facebook</label>
                                                <input className={fieldCls} value={f('facebook')} onChange={e => set('facebook', e.target.value)} placeholder="URL o usuario" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Twitter / X</label>
                                                <input className={fieldCls} value={f('twitter')} onChange={e => set('twitter', e.target.value)} placeholder="@usuario" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Instagram</label>
                                                <input className={fieldCls} value={f('instagram')} onChange={e => set('instagram', e.target.value)} placeholder="@usuario" />
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {[['Facebook', data.facebook], ['Twitter', data.twitter], ['Instagram', data.instagram]].map(([label, val]) => (
                                                <div key={label} className="flex items-center justify-between py-1.5">
                                                    <span className="text-[13px] text-[var(--color-ink-faint)]">{label}</span>
                                                    {val ? (
                                                        <a href={val.startsWith('http') ? val : `https://${label.toLowerCase()}.com/${val.replace('@','')}`} target="_blank" rel="noopener" className="text-[13px] text-[var(--color-primary)] font-semibold hover:underline">{val}</a>
                                                    ) : (
                                                        <span className="text-[13px] text-[var(--color-ink-faint)]">—</span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* ── POLITICA ── */}
                        {activeTab === 'politica' && (
                            <div className="space-y-6">
                                {editMode ? (
                                    <div className="space-y-4">
                                        <div>
                                            <label className={labelCls}>Partido</label>
                                            <select className={fieldCls} value={f('partido')} onChange={e => set('partido', e.target.value)}>
                                                <option value="">Sin partido</option>
                                                {(data.partidos || []).map(p => <option key={p} value={p}>{p}</option>)}
                                            </select>
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
                                                    let opts = cargosPorTipo['todos'] || CARGOS_DISPONIBLES;
                                                    if (selectedTipos.length > 0) {
                                                        const merged = [...new Set(selectedTipos.flatMap(t => cargosPorTipo[t] || []))];
                                                        if (merged.length > 0) opts = merged.sort();
                                                    }
                                                    return opts;
                                                })().map(c => {
                                                    const isChecked = (form.cargos || []).includes(c);
                                                    return (
                                                        <label key={c} className={`flex items-center gap-2 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors text-[13px] ${isChecked ? 'bg-emerald-50 border-emerald-400 font-bold text-emerald-700' : 'border-[var(--color-line)] text-[var(--color-ink-soft)]'}`}>
                                                            <input type="checkbox" checked={isChecked} onChange={() => {
                                                                const cargos = form.cargos || [];
                                                                const next = isChecked ? cargos.filter(x => x !== c) : [...cargos, c];
                                                                setForm(prev => ({ ...prev, cargos: next, cargo: next.join(', ') }));
                                                            }} className="w-4 h-4 rounded border-gray-300 text-emerald-500" />
                                                            {c}
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label className={labelCls}>Votos</label>
                                                <input type="number" className={fieldCls} value={f('votos')} onChange={e => set('votos', e.target.value)} min="0" placeholder="0" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Nivel de confianza</label>
                                                <select className={fieldCls} value={f('nivel_confianza')} onChange={e => set('nivel_confianza', e.target.value)}>
                                                    {NIVEL_CONFIANZA_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label className={labelCls}>Convenio</label>
                                            <input className={fieldCls} value={f('convenio')} onChange={e => set('convenio', e.target.value)} />
                                        </div>
                                        <div className="pt-2 border-t border-[var(--color-line)]">
                                            <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Referente</p>
                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <div>
                                                    <label className={labelCls}>Documento</label>
                                                    <input className={fieldCls} value={f('referente_documento')} onChange={e => set('referente_documento', e.target.value)} />
                                                </div>
                                                <div>
                                                    <label className={labelCls}>Nombre</label>
                                                    <input className={fieldCls} value={f('referente_nombre')} onChange={e => set('referente_nombre', e.target.value)} />
                                                </div>
                                                <div>
                                                    <label className={labelCls}>Apellido</label>
                                                    <input className={fieldCls} value={f('referente_apellido')} onChange={e => set('referente_apellido', e.target.value)} />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <DataRow label="Partido" value={data.partido} />
                                        <DataRow label="Tipo" value={data.tipo_registro} />
                                        <DataRow label="Cargo" value={data.cargos?.length > 0 ? data.cargos.join(', ') : data.cargo} />
                                        <DataRow label="Votos" value={data.votos > 0 ? data.votos.toLocaleString('es-CO') : null} />
                                        {data.nivel_confianza && (
                                            <div className="flex items-start justify-between py-2 border-b border-[var(--color-line)]">
                                                <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[140px]">Confianza</span>
                                                <span className={`px-2 py-1 rounded text-[12px] font-bold ${NIVEL_CONFIANZA_COLORS[data.nivel_confianza] || 'bg-gray-100 text-gray-600'}`}>
                                                    {NIVEL_CONFIANZA_OPTIONS.find(o => o.value === data.nivel_confianza)?.label || data.nivel_confianza}
                                                </span>
                                            </div>
                                        )}
                                        <DataRow label="Convenio" value={data.convenio} />
                                        {(data.referente_nombre || data.referente_apellido || data.referente_documento) && (
                                            <>
                                                <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] pt-3">Referente</p>
                                                <DataRow label="Documento" value={data.referente_documento} />
                                                <DataRow label="Nombre" value={[data.referente_nombre, data.referente_apellido].filter(Boolean).join(' ')} />
                                            </>
                                        )}
                                    </div>
                                )}

                                {/* Referidos */}
                                {data.referidos?.length > 0 && (
                                    <div className="pt-4 border-t border-[var(--color-line)]">
                                        <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3 flex items-center gap-2">
                                            <svg className="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                            Referidos ({data.referidos?.length || data.referidos_count || 0})
                                        </p>
                                        <div className="space-y-2">
                                            {(data.referidos || []).map(r => (
                                                <div key={r.id} className="flex items-center justify-between p-2 rounded-lg bg-amber-50/60 border border-amber-100">
                                                    <div className="min-w-0">
                                                        <span className="text-[13px] font-semibold text-[var(--color-ink)] block truncate">{r.nombre}</span>
                                                        <span className="text-[11px] text-[var(--color-ink-faint)]">
                                                            {r.cedula && `CC ${r.cedula}`}{r.municipio && ` · ${r.municipio}`}
                                                        </span>
                                                    </div>
                                                    {r.telefono && (
                                                        <a href={`https://wa.me/57${r.telefono.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="flex-shrink-0 text-green-500 hover:text-green-600" title="WhatsApp">
                                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" /></svg>
                                                        </a>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Info Politica CRUD */}
                                <div className="pt-4 border-t border-[var(--color-line)]">
                                    {isLider ? (
                                        <InfoPoliticaManager personId={personId} infoPolitica={data.info_politica || []} onUpdate={loadData} />
                                    ) : (
                                        <p className="text-[13px] text-[var(--color-ink-faint)] text-center py-3 bg-gray-50 rounded-lg">Guarda los datos para habilitar historial politico.</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* ── NEXOS ── */}
                        {activeTab === 'nexos' && (
                            <div>
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-[14px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                                        Familia / Nexos ({data.nexos?.length ?? 0})
                                    </h3>
                                    <button onClick={() => setShowNexoForm(!showNexoForm)} className="px-5 py-2.5 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">
                                        + Agregar familiar
                                    </button>
                                </div>

                                {showNexoForm && (
                                    <form onSubmit={addNexo} className="bg-blue-50 rounded-xl p-6 mb-4 space-y-4">
                                        <h4 className="text-[14px] font-bold text-[var(--color-primary)]">Nuevo nexo familiar</h4>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <label className={labelCls}>Nombre *</label>
                                                <input className={fieldCls} value={nexoForm.nombre} onChange={e => setNexoForm({ ...nexoForm, nombre: e.target.value })} required />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Parentesco</label>
                                                <select className={fieldCls} value={nexoForm.parentesco} onChange={e => setNexoForm({ ...nexoForm, parentesco: e.target.value })}>
                                                    <option value="">Seleccionar...</option>
                                                    {PARENTESCOS.map(p => <option key={p} value={p}>{p}</option>)}
                                                </select>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <label className={labelCls}>Cedula</label>
                                                <input className={fieldCls} value={nexoForm.cedula} onChange={e => setNexoForm({ ...nexoForm, cedula: e.target.value })} placeholder="Numero de cedula" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Telefono</label>
                                                <input className={fieldCls} value={nexoForm.telefono} onChange={e => setNexoForm({ ...nexoForm, telefono: e.target.value })} placeholder="3XX XXX XXXX" />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:gap-4">
                                            <div>
                                                <label className={labelCls}>Cargo</label>
                                                <input className={fieldCls} value={nexoForm.cargo} onChange={e => setNexoForm({ ...nexoForm, cargo: e.target.value })} />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Edad</label>
                                                <input type="number" className={fieldCls} value={nexoForm.edad} onChange={e => setNexoForm({ ...nexoForm, edad: e.target.value })} min="0" max="120" />
                                            </div>
                                            <div>
                                                <label className={labelCls}>Gustos</label>
                                                <input className={fieldCls} value={nexoForm.gustos} onChange={e => setNexoForm({ ...nexoForm, gustos: e.target.value })} />
                                            </div>
                                        </div>
                                        <div>
                                            <label className={labelCls}>Observaciones</label>
                                            <textarea className={fieldCls} rows={2} value={nexoForm.observaciones} onChange={e => setNexoForm({ ...nexoForm, observaciones: e.target.value })} />
                                        </div>
                                        <div className="flex justify-end gap-3">
                                            <button type="button" onClick={() => setShowNexoForm(false)} className="px-5 py-2.5 text-[14px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                                            <button type="submit" className="px-6 py-2.5 bg-[var(--color-good)] text-white text-[14px] font-bold rounded-lg hover:bg-emerald-600">Guardar</button>
                                        </div>
                                    </form>
                                )}

                                {(!data.nexos || data.nexos.length === 0) && !showNexoForm && (
                                    <p className="text-[16px] text-[var(--color-ink-faint)] text-center py-8">Sin nexos familiares registrados.</p>
                                )}

                                {data.nexos?.map(n => (
                                    <div key={n.id} className="bg-gray-50 rounded-xl p-4 mb-3">
                                        {editingNexoId === n.id ? (
                                            <form onSubmit={saveEditNexo} className="space-y-3">
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                    <div>
                                                        <label className={labelCls}>Nombre *</label>
                                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.nombre} onChange={e => setNexoForm({ ...nexoForm, nombre: e.target.value })} required />
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Parentesco</label>
                                                        <select className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.parentesco} onChange={e => setNexoForm({ ...nexoForm, parentesco: e.target.value })}>
                                                            <option value="">Seleccionar...</option>
                                                            {PARENTESCOS.map(p => <option key={p} value={p}>{p}</option>)}
                                                        </select>
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                    <div>
                                                        <label className={labelCls}>Cedula</label>
                                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.cedula} onChange={e => setNexoForm({ ...nexoForm, cedula: e.target.value })} />
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Telefono</label>
                                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.telefono} onChange={e => setNexoForm({ ...nexoForm, telefono: e.target.value })} />
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                    <div>
                                                        <label className={labelCls}>Cargo</label>
                                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.cargo} onChange={e => setNexoForm({ ...nexoForm, cargo: e.target.value })} />
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Edad</label>
                                                        <input type="number" className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.edad} onChange={e => setNexoForm({ ...nexoForm, edad: e.target.value })} min="0" max="120" />
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Gustos</label>
                                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.gustos} onChange={e => setNexoForm({ ...nexoForm, gustos: e.target.value })} />
                                                    </div>
                                                </div>
                                                <div>
                                                    <label className={labelCls}>Observaciones</label>
                                                    <textarea className="w-full border border-[var(--color-line)] rounded-lg px-3 py-2.5 text-[14px] focus:outline-none focus:border-[var(--color-primary)]" rows={2} value={nexoForm.observaciones} onChange={e => setNexoForm({ ...nexoForm, observaciones: e.target.value })} />
                                                </div>
                                                <div className="flex gap-2 justify-end">
                                                    <button type="button" onClick={() => setEditingNexoId(null)} className="px-4 py-2 text-[13px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg">Cancelar</button>
                                                    <button type="submit" className="px-4 py-2 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg">Guardar</button>
                                                </div>
                                            </form>
                                        ) : (
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <p className="text-[16px] lg:text-[18px] font-bold text-[var(--color-ink)]">{n.nombre}</p>
                                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                                        {n.parentesco && <span className="px-2 py-1 bg-blue-100 text-blue-700 text-[12px] font-bold rounded">{n.parentesco}</span>}
                                                        {n.cargo && <span className="text-[14px] text-[var(--color-ink-faint)]">{n.cargo}</span>}
                                                        {n.edad && <span className="text-[14px] text-[var(--color-ink-faint)]">{n.edad} anos</span>}
                                                    </div>
                                                    {n.cedula && <p className="text-[13px] text-[var(--color-ink-soft)] mt-1">CC: {n.cedula}</p>}
                                                    {n.telefono && <p className="text-[13px] mt-1"><a href={`tel:${n.telefono}`} className="text-[var(--color-primary)] font-semibold">{n.telefono}</a> <a href={`https://wa.me/57${n.telefono.replace(/\D/g,'').replace(/^57/,'')}`} target="_blank" rel="noopener" className="inline-flex items-center gap-0.5 px-2 py-0.5 bg-[#25D366] text-white text-[10px] font-bold rounded ml-1"><svg className="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>WA</a></p>}
                                                    {n.gustos && <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Gustos: {n.gustos}</p>}
                                                    {n.observaciones && <p className="text-[14px] text-[var(--color-ink-faint)] mt-1">{n.observaciones}</p>}
                                                </div>
                                                <div className="flex gap-1.5 flex-shrink-0">
                                                    <button onClick={() => startEditNexo(n)} className="px-3 py-2 text-[13px] text-blue-600 bg-blue-50 hover:bg-blue-100 font-bold rounded-lg transition-colors">Editar</button>
                                                    <button onClick={() => deleteNexo(n.id)} className="px-3 py-2 text-[13px] text-red-600 bg-red-50 hover:bg-red-100 font-bold rounded-lg transition-colors">Eliminar</button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* ── OPCIONES ── */}
                        {activeTab === 'opciones' && (
                            <div className="space-y-6">
                                <div>
                                    <p className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">Etiquetas</p>
                                    <TagsPanel
                                        values={{
                                            militante: editMode ? form.militante : data.militante,
                                            autoriza_datos: editMode ? form.autoriza_datos : data.autoriza_datos,
                                            verificado: editMode ? form.verificado : data.verificado,
                                            fallecido: editMode ? form.fallecido : data.fallecido,
                                            empresario: editMode ? form.empresario : data.empresario,
                                            reservista: editMode ? form.reservista : data.reservista,
                                            funcionario: editMode ? form.funcionario : data.funcionario,
                                            exfuncionario: editMode ? form.exfuncionario : data.exfuncionario,
                                            gran_elector: editMode ? form.gran_elector : data.gran_elector,
                                        }}
                                        onChange={(field, value) => set(field, value)}
                                        disabled={!editMode}
                                    />
                                </div>
                                <div>
                                    <label className={labelCls}>Observaciones</label>
                                    {editMode ? (
                                        <textarea className={fieldCls} rows={3} value={f('observacion')} onChange={e => set('observacion', e.target.value)} />
                                    ) : (
                                        <p className="text-[16px] text-[var(--color-ink)] py-2">{data.observacion || '—'}</p>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <ConfirmDialog />
        </>
    );
}
