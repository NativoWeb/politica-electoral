import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useMemo, useEffect, useCallback } from 'react';
import { downloadMunicipio, isDownloaded, getOfflinePersona, offlineUpdatePersona, offlineCreateNexo, offlineDeleteNexo, offlineCreateLider } from '@/lib/offlineDb';
import { fmt, partyLogo, partyColor } from '@/lib/electoral';
import { FullScreenSpinner } from '@/Components/Spinner';

const TIPO_COLORS = {
    'Alcaldía': 'bg-blue-100 text-blue-700',
    'Concejo': 'bg-purple-100 text-purple-700',
    'Líderes': 'bg-amber-100 text-amber-700',
    'Directorio Municipal': 'bg-rose-100 text-rose-700',
    'Senado': 'bg-indigo-100 text-indigo-700',
    'Cámara': 'bg-cyan-100 text-cyan-700',
    'Asamblea': 'bg-teal-100 text-teal-700',
};

const TIPO_ICONS = {
    'Alcaldía': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
    ),
    'Concejo': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
    ),
    'Líderes': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
    ),
    'Directorio Municipal': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
    ),
    'Senado': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
    ),
    'Cámara': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4" /></svg>
    ),
    'Asamblea': (
        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
    ),
};

const TIPO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'alcaldia', label: 'Alcaldía' },
    { value: 'concejo', label: 'Concejo' },
    { value: 'lideres', label: 'Líderes' },
    { value: 'directorio', label: 'Directorio Municipal' },
    { value: 'senado', label: 'Senado' },
    { value: 'camara', label: 'Cámara' },
    { value: 'asamblea', label: 'Asamblea' },
];

const SECTION_ORDER = ['Alcaldía', 'Concejo', 'Líderes', 'Directorio Municipal', 'Senado', 'Cámara', 'Asamblea'];

const PARENTESCOS = ['Esposa', 'Esposo', 'Hijo/a', 'Hermano/a', 'Padre', 'Madre', 'Sobrino/a', 'Tío/a', 'Primo/a', 'Cuñado/a', 'Suegro/a', 'Otro'];

const CARGOS_DISPONIBLES = [
    'Concejal',
    'Líder',
    'Directorio Municipal',
    'REPRESENTANTE JOVENES',
    'REPRESENTANTE MUJERES',
    'REPRESENTANTE RESERVA',
    'COORDINADOR MUNICIPAL',
    'Candidato Alcaldía',
];

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";

function getCsrfToken() {
    // Try XSRF-TOKEN cookie first (auto-renewed by Laravel), then meta tag fallback
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    if (cookie) return decodeURIComponent(cookie.split('=')[1]);
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function apiFetch(url, options = {}) {
    return fetch(url, {
        ...options,
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            ...options.headers,
        },
        credentials: 'same-origin',
    });
}

/* ── Panel lateral de detalle ── */
function PersonaPanel({ personId, onClose }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [editMode, setEditMode] = useState(false);
    const [form, setForm] = useState({});
    const [nexoForm, setNexoForm] = useState({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '' });
    const [showNexoForm, setShowNexoForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');

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
                setForm({ telefono: d.telefono || '', email: d.email || '', cargo: d.cargo || '', cargos: d.cargos || [], observacion: d.observacion || '', direccion: d.direccion || '', barrio: d.barrio || '', zona: d.zona || '', partido: d.partido || '', destacado: !!d.destacado });
            })
            .catch(async () => {
                // Fallback: try offline data from IndexedDB
                const offlineData = await getOfflinePersona(personId).catch(() => null);
                if (offlineData) {
                    setData(offlineData);
                    setLoadError(false);
                    setForm({ telefono: offlineData.telefono || '', email: offlineData.email || '', cargo: offlineData.cargo || '', cargos: offlineData.cargos || [], observacion: offlineData.observacion || '', direccion: offlineData.direccion || '', barrio: offlineData.barrio || '', zona: offlineData.zona || '', partido: offlineData.partido || '' });
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
        apiFetch(`/mapa-politico/persona/${personId}`, {
            method: 'PUT',
            body: JSON.stringify(form),
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
                // Offline fallback: save locally
                try {
                    await offlineUpdatePersona(personId, form);
                    setMessage('Guardado localmente. Se enviara cuando haya internet.');
                    setEditMode(false);
                    setData(prev => ({ ...prev, ...form }));
                } catch {
                    setMessage('Error al guardar');
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
                setNexoForm({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '' });
                setShowNexoForm(false);
                setMessage('Nexo agregado');
                loadData();
                setTimeout(() => setMessage(''), 3000);
            })
            .catch(async () => {
                // Offline fallback
                try {
                    const newNexo = await offlineCreateNexo(personId, nexoForm);
                    setNexoForm({ nombre: '', parentesco: '', cargo: '', edad: '', gustos: '', observaciones: '' });
                    setShowNexoForm(false);
                    setMessage('Nexo guardado localmente. Se enviara con internet.');
                    setData(prev => ({ ...prev, nexos: [...(prev.nexos || []), { ...nexoForm, id: newNexo.id }] }));
                    setTimeout(() => setMessage(''), 5000);
                } catch {
                    setMessage('Error al guardar nexo');
                }
            });
    }

    function deleteNexo(nexoId) {
        if (!confirm('Eliminar este nexo familiar?')) return;
        apiFetch(`/mapa-politico/nexos/${nexoId}`, { method: 'DELETE' })
            .then(() => loadData())
            .catch(async () => {
                await offlineDeleteNexo(nexoId).catch(() => {});
                setData(prev => ({ ...prev, nexos: (prev.nexos || []).filter(n => n.id !== nexoId) }));
                setMessage('Eliminado localmente. Se sincronizara con internet.');
                setTimeout(() => setMessage(''), 5000);
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

    return (
        <>
            {/* Overlay */}
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />

            {/* Modal centrado */}
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[750px] max-h-[90vh] overflow-y-auto">

                    {/* Header */}
                    <div className="bg-[var(--color-primary)] text-white px-8 py-6 rounded-t-2xl flex items-center justify-between">
                        <div>
                            <p className="text-[12px] text-white/50 uppercase tracking-widest">
                                Ficha personal
                                {data._offline && <span className="ml-2 px-2 py-0.5 bg-amber-500 text-white text-[10px] font-bold rounded normal-case">Datos guardados</span>}
                            </p>
                            <h2 className="text-[24px] font-extrabold uppercase mt-1">{data.nombre}</h2>
                            <p className="text-[14px] text-white/60 mt-1">{data.municipio ?? '—'} · {data.cargo ?? '—'}</p>
                        </div>
                        <button onClick={onClose} className="w-12 h-12 rounded-full bg-white/15 hover:bg-white/30 flex items-center justify-center text-white transition-colors">
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    {/* Mensaje de éxito */}
                    {message && (
                        <div className="mx-8 mt-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-[14px] font-semibold text-emerald-700">
                            {message}
                        </div>
                    )}

                    {/* Datos personales */}
                    <div className="px-8 py-6 border-b border-[var(--color-line)]">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-[14px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Datos personales</h3>
                            <button onClick={() => setEditMode(!editMode)} className="px-5 py-2.5 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">
                                {editMode ? 'Cancelar' : 'Editar datos'}
                            </button>
                        </div>

                        {editMode ? (
                            <div className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Telefono</label>
                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" value={form.telefono} onChange={e => setForm({ ...form, telefono: e.target.value })} />
                                    </div>
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Email</label>
                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} />
                                    </div>
                                </div>
                                {/* Cargos — checkboxes */}
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-2">Cargos (seleccionar los que apliquen)</label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {CARGOS_DISPONIBLES.map(c => {
                                            const isChecked = (form.cargos || []).includes(c);
                                            return (
                                                <label key={c} className={`flex items-center gap-3 px-4 py-3 rounded-lg border cursor-pointer transition-colors ${isChecked ? 'bg-blue-50 border-[var(--color-primary)]' : 'border-[var(--color-line)] hover:bg-gray-50'}`}>
                                                    <input
                                                        type="checkbox"
                                                        checked={isChecked}
                                                        onChange={() => {
                                                            const cargos = form.cargos || [];
                                                            const next = isChecked ? cargos.filter(x => x !== c) : [...cargos, c];
                                                            setForm({ ...form, cargos: next, cargo: next.join(', ') });
                                                        }}
                                                        className="w-5 h-5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                                    />
                                                    <span className={`text-[14px] font-semibold ${isChecked ? 'text-[var(--color-primary)]' : 'text-[var(--color-ink-soft)]'}`}>{c}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                                {/* Partido */}
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-2">Partido</label>
                                    <select className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" value={form.partido ?? ''} onChange={e => setForm({ ...form, partido: e.target.value })}>
                                        <option value="">Sin partido</option>
                                        {(data.partidos || []).map(p => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                </div>
                                {/* Dirección / Barrio */}
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Dirección</label>
                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" value={form.direccion ?? ''} onChange={e => setForm({ ...form, direccion: e.target.value })} />
                                    </div>
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Barrio / Vereda / Corregimiento</label>
                                        <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" value={form.barrio ?? ''} onChange={e => setForm({ ...form, barrio: e.target.value })} />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Observaciones</label>
                                    <textarea className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]" rows={3} value={form.observacion} onChange={e => setForm({ ...form, observacion: e.target.value })} />
                                </div>
                                {/* Destacado */}
                                <label className="flex items-center gap-3 px-4 py-3 rounded-lg border cursor-pointer transition-colors bg-amber-50 border-amber-200">
                                    <input type="checkbox" checked={form.destacado} onChange={e => setForm({ ...form, destacado: e.target.checked })} className="w-6 h-6 rounded border-amber-300 text-amber-500 focus:ring-amber-400" />
                                    <div>
                                        <span className="text-[15px] font-bold text-amber-700">Persona destacada</span>
                                        <p className="text-[12px] text-amber-600">Aparecera con estrella en el listado</p>
                                    </div>
                                </label>
                                <button onClick={savePersona} disabled={saving} className="px-6 py-3 bg-[var(--color-good)] text-white text-[16px] font-bold rounded-lg hover:bg-emerald-600 transition-colors disabled:opacity-50">
                                    {saving ? 'Guardando...' : 'Guardar cambios'}
                                </button>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {[
                                    ['Telefono', data.telefono ? <a href={`tel:${data.telefono}`} className="text-[var(--color-primary)] font-bold hover:underline text-[18px]">{data.telefono}</a> : '—'],
                                    ['Email', data.email || '—'],
                                    ['Partido', data.partido || '—'],
                                    ['Cargo', data.cargo || '—'],
                                    ['Cargos', data.cargos?.length > 0 ? data.cargos.join(', ') : '—'],
                                    ['Dirección', data.direccion || '—'],
                                    ['Barrio / Vereda', data.barrio || '—'],
                                    ['Observaciones', data.observacion || '—'],
                                ].map(([label, value]) => (
                                    <div key={label} className="flex items-start justify-between py-2 border-b border-[var(--color-line)] last:border-0">
                                        <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[140px]">{label}</span>
                                        <span className="text-[16px] text-[var(--color-ink)] text-right flex-1">{value}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Familia / Nexos */}
                    <div className="px-8 py-6">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-[14px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                                Familia / Nexos ({data.nexos?.length ?? 0})
                            </h3>
                            <button onClick={() => setShowNexoForm(!showNexoForm)} className="px-5 py-2.5 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">
                                + Agregar familiar
                            </button>
                        </div>

                    {/* Form agregar nexo */}
                    {showNexoForm && (
                        <form onSubmit={addNexo} className="bg-blue-50 rounded-xl p-6 mb-4 space-y-4">
                            <h4 className="text-[14px] font-bold text-[var(--color-primary)]">Nuevo nexo familiar</h4>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                                    <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.nombre} onChange={e => setNexoForm({ ...nexoForm, nombre: e.target.value })} required />
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Parentesco</label>
                                    <select className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.parentesco} onChange={e => setNexoForm({ ...nexoForm, parentesco: e.target.value })}>
                                        <option value="">Seleccionar...</option>
                                        {PARENTESCOS.map(p => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Cargo</label>
                                    <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.cargo} onChange={e => setNexoForm({ ...nexoForm, cargo: e.target.value })} />
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Edad</label>
                                    <input type="number" className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.edad} onChange={e => setNexoForm({ ...nexoForm, edad: e.target.value })} min="0" max="120" />
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Gustos</label>
                                    <input className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" value={nexoForm.gustos} onChange={e => setNexoForm({ ...nexoForm, gustos: e.target.value })} />
                                </div>
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Observaciones</label>
                                <textarea className="w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] focus:outline-none focus:border-[var(--color-primary)]" rows={2} value={nexoForm.observaciones} onChange={e => setNexoForm({ ...nexoForm, observaciones: e.target.value })} />
                            </div>
                            <div className="flex justify-end gap-3">
                                <button type="button" onClick={() => setShowNexoForm(false)} className="px-5 py-2.5 text-[14px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                                <button type="submit" className="px-6 py-2.5 bg-[var(--color-good)] text-white text-[14px] font-bold rounded-lg hover:bg-emerald-600">Guardar</button>
                            </div>
                        </form>
                    )}

                    {/* Lista de nexos */}
                    {(!data.nexos || data.nexos.length === 0) && !showNexoForm && (
                        <p className="text-[16px] text-[var(--color-ink-faint)] text-center py-8">Sin nexos familiares registrados.</p>
                    )}

                    {data.nexos?.map(n => (
                        <div key={n.id} className="bg-gray-50 rounded-xl p-4 mb-3 group">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-[18px] font-bold text-[var(--color-ink)]">{n.nombre}</p>
                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                        {n.parentesco && <span className="px-2 py-1 bg-blue-100 text-blue-700 text-[12px] font-bold rounded">{n.parentesco}</span>}
                                        {n.cargo && <span className="text-[14px] text-[var(--color-ink-faint)]">{n.cargo}</span>}
                                        {n.edad && <span className="text-[14px] text-[var(--color-ink-faint)]">· {n.edad} años</span>}
                                    </div>
                                    {n.gustos && <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Gustos: {n.gustos}</p>}
                                    {n.observaciones && <p className="text-[14px] text-[var(--color-ink-faint)] mt-1">{n.observaciones}</p>}
                                </div>
                                <button onClick={() => deleteNexo(n.id)} className="px-4 py-2 text-[13px] text-red-600 bg-red-50 hover:bg-red-100 font-bold rounded-lg transition-colors">Eliminar</button>
                            </div>
                        </div>
                    ))}
                    </div>
                </div>
            </div>
        </>
    );
}

/* ── Botón micrófono para dictado por voz ── */
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

/* ── Modal Crear Líder ── */
function CrearLiderModal({ open, onClose, municipios }) {
    const emptyForm = { nombre: '', municipio_id: '', cargo: 'Líder', telefono: '', email: '', direccion: '', barrio: '', zona: '', observacion: '' };
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [successMsg, setSuccessMsg] = useState('');

    if (!open) return null;

    function submit(e) {
        e.preventDefault();
        if (!form.nombre.trim()) { setErrors({ nombre: 'El nombre es obligatorio' }); return; }
        if (!form.municipio_id) { setErrors({ municipio_id: 'Selecciona un municipio' }); return; }

        setSaving(true);
        setErrors({});
        setSuccessMsg('');

        if (!navigator.onLine) {
            // Offline: save locally
            offlineCreateLider({ ...form, municipio: municipios.find(m => m.id === form.municipio_id)?.name || '' })
                .then(() => {
                    setSuccessMsg('Lider guardado localmente. Se enviara cuando haya internet.');
                    setForm(emptyForm);
                    setTimeout(() => { setSuccessMsg(''); onClose(); }, 2500);
                })
                .catch(() => setErrors({ general: 'Error al guardar localmente' }))
                .finally(() => setSaving(false));
            return;
        }

        router.post('/mapa-politico/crear-lider', form, {
            preserveScroll: true,
            onSuccess: () => {
                setSuccessMsg('Líder creado exitosamente');
                setForm(emptyForm);
                setTimeout(() => { setSuccessMsg(''); onClose(); }, 1500);
            },
            onError: (errs) => setErrors(errs),
            onFinish: () => setSaving(false),
        });
    }

    const fieldCls = "w-full border border-[var(--color-line)] rounded-lg px-4 py-3 text-[16px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-[800px] max-h-[90vh] overflow-y-auto">

                    <div className="bg-[var(--color-primary)] text-white px-8 py-6 rounded-t-2xl flex items-center justify-between">
                        <div>
                            <h2 className="text-[22px] font-extrabold">CREAR NUEVO LIDER</h2>
                            <p className="text-[13px] text-white/50 mt-1">Usa el micrófono para dictar los campos</p>
                        </div>
                        <button onClick={onClose} className="w-12 h-12 rounded-full bg-white/15 hover:bg-white/30 flex items-center justify-center text-white">
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form onSubmit={submit} className="px-8 py-6 space-y-5">
                        {/* Mensajes */}
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
                            <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre completo *</label>
                            <div className="flex gap-2">
                                <input className={fieldCls} value={form.nombre} onChange={e => setForm({...form, nombre: e.target.value})} required placeholder="Nombre del líder" />
                                <VoiceButton label="nombre" onResult={t => setForm({...form, nombre: t})} />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            {/* Municipio */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Municipio *</label>
                                <select className={fieldCls} value={form.municipio_id} onChange={e => setForm({...form, municipio_id: e.target.value})} required>
                                    <option value="">Seleccionar...</option>
                                    {municipios.map(m => <option key={m.id} value={m.id}>{m.name} ({m.provincia})</option>)}
                                </select>
                            </div>
                            {/* Cargo */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Cargo *</label>
                                <select className={fieldCls} value={form.cargo} onChange={e => setForm({...form, cargo: e.target.value})} required>
                                    {CARGOS_DISPONIBLES.map(c => <option key={c} value={c}>{c}</option>)}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            {/* Teléfono + Voz */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Teléfono</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.telefono} onChange={e => setForm({...form, telefono: e.target.value})} placeholder="3XX XXX XXXX" />
                                    <VoiceButton label="teléfono" onResult={t => setForm({...form, telefono: t.replace(/\s/g, '')})} />
                                </div>
                            </div>
                            {/* Email */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Email</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.email} onChange={e => setForm({...form, email: e.target.value})} placeholder="correo@ejemplo.com" />
                                    <VoiceButton label="email" onResult={t => setForm({...form, email: t.replace(/\s/g, '').toLowerCase()})} />
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            {/* Dirección + Voz */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Dirección</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.direccion} onChange={e => setForm({...form, direccion: e.target.value})} placeholder="Calle / Carrera" />
                                    <VoiceButton label="dirección" onResult={t => setForm({...form, direccion: t})} />
                                </div>
                            </div>
                            {/* Barrio / Vereda / Corregimiento + Voz */}
                            <div>
                                <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Barrio / Vereda / Corregimiento</label>
                                <div className="flex gap-2">
                                    <input className={fieldCls} value={form.barrio} onChange={e => setForm({...form, barrio: e.target.value})} placeholder="Barrio, vereda o corregimiento" />
                                    <VoiceButton label="barrio" onResult={t => setForm({...form, barrio: t})} />
                                </div>
                            </div>
                        </div>

                        {/* Observaciones + Voz */}
                        <div>
                            <label className="block text-[14px] font-bold text-[var(--color-ink-faint)] mb-1">Observaciones</label>
                            <div className="flex gap-2">
                                <textarea className={fieldCls} rows={3} value={form.observacion} onChange={e => setForm({...form, observacion: e.target.value})} placeholder="Notas adicionales..." />
                                <VoiceButton label="observaciones" onResult={t => setForm({...form, observacion: (form.observacion ? form.observacion + ' ' : '') + t})} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={onClose} className="px-6 py-3 text-[16px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                            <button type="submit" disabled={saving} className="px-8 py-3 bg-[var(--color-good)] text-white text-[16px] font-bold rounded-lg hover:bg-emerald-600 transition-colors disabled:opacity-50">
                                {saving ? 'Guardando...' : 'Crear Líder'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

/* ── Acordeón por Partido (estilo Registraduría) ── */
function PartyAccordion({ partyName, rows, index, onSelectPerson }) {
    const [open, setOpen] = useState(false);
    const logo = partyLogo(partyName);
    const color = partyColor(partyName, index);
    const totalVotos = rows.reduce((sum, r) => sum + (r.votos || 0), 0);
    const electos = rows.filter(r => r.outcome === 'elected' || (r.cargo && r.cargo.includes('Electo')));

    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden mb-2">
            <button
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors"
            >
                {/* Color bar */}
                <div className="w-1 h-10 rounded-full flex-shrink-0" style={{ backgroundColor: color }} />

                {/* Logo */}
                {logo ? (
                    <img src={logo} alt="" className="w-8 h-8 object-contain flex-shrink-0" />
                ) : (
                    <div className="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-white text-[10px] font-bold" style={{ backgroundColor: color }}>
                        {(partyName || '?').slice(0, 2).toUpperCase()}
                    </div>
                )}

                {/* Info */}
                <div className="flex-1 text-left">
                    <p className="text-[13px] font-bold text-[var(--color-ink)] uppercase">{partyName || 'Sin partido'}</p>
                    <p className="text-[11px] text-[var(--color-ink-faint)]">
                        {rows.length} {rows.length === 1 ? 'persona' : 'personas'}
                        {electos.length > 0 && <span className="ml-1 text-[var(--color-good)] font-bold">· {electos.length} electo{electos.length > 1 ? 's' : ''}</span>}
                    </p>
                </div>

                {/* Votos */}
                {totalVotos > 0 && (
                    <span className="text-[13px] font-bold text-[var(--color-ink)] font-[var(--font-mono)]">{fmt(totalVotos)} votos</span>
                )}

                {/* Arrow */}
                <svg className={`w-4 h-4 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>

            {open && (
                <div className="border-t border-[var(--color-line)] bg-gray-50/50">
                    {rows.map((row, i) => {
                        const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
                        return (
                            <div
                                key={`${row.id}-${i}`}
                                onClick={() => onSelectPerson(row.id)}
                                className={`flex items-center gap-3 px-5 py-2.5 border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50 cursor-pointer transition-colors ${isElecto ? 'bg-emerald-50/60' : ''}`}
                            >
                                <div className="w-1 h-6 rounded-full flex-shrink-0" style={{ backgroundColor: color, opacity: 0.4 }} />
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        {row.destacado && <svg className="w-4 h-4 text-amber-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>}
                                        <span className="text-[13px] font-bold text-[var(--color-primary)] uppercase truncate">{row.nombre}</span>
                                        {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[8px] font-bold uppercase rounded flex-shrink-0">Electo</span>}
                                    </div>
                                    <p className="text-[11px] text-[var(--color-ink-faint)]">{row.municipio ?? ''} · {row.cargo ?? ''}</p>
                                </div>
                                {row.telefono && <span className="text-[11px] text-[var(--color-primary)] font-semibold flex-shrink-0">{row.telefono}</span>}
                                {row.votos > 0 && <span className="text-[12px] font-bold text-[var(--color-ink)] font-[var(--font-mono)] flex-shrink-0">{fmt(row.votos)}</span>}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/* ── Sección colapsable (Alcaldía, Concejo, etc.) ── */
/* ── Flat person row — used on mobile for all types ── */
function PersonRow({ row, onSelectPerson, color }) {
    const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
    const initials = (row.nombre || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();

    return (
        <div
            onClick={() => onSelectPerson(row.id)}
            className={`flex items-center gap-3 px-4 py-3.5 border-b border-gray-100 last:border-0 active:bg-blue-50 cursor-pointer transition-colors ${isElecto ? 'bg-emerald-50/40' : ''}`}
        >
            {/* Avatar */}
            <div
                className="w-10 h-10 rounded-full flex items-center justify-center text-[12px] font-bold flex-shrink-0 text-white"
                style={{ backgroundColor: color || 'var(--color-primary)' }}
            >
                {initials}
            </div>

            {/* Info */}
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    {row.destacado && <svg className="w-4 h-4 text-amber-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>}
                    <span className="text-[14px] font-bold text-[var(--color-ink)] uppercase truncate">{row.nombre}</span>
                    {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[9px] font-bold uppercase rounded flex-shrink-0">Electo</span>}
                </div>
                <p className="text-[12px] text-[var(--color-ink-faint)] truncate">
                    {row.municipio ?? ''}
                    {row.partido && <> · <span className="font-semibold">{row.partido}</span></>}
                </p>
            </div>

            {/* Right: votos or phone + chevron */}
            <div className="flex items-center gap-2 flex-shrink-0">
                {row.votos > 0 && <span className="text-[12px] font-bold text-[var(--color-ink-soft)]">{fmt(row.votos)}</span>}
                <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
            </div>
        </div>
    );
}

function CollapsibleSection({ tipo, rows, onSelectPerson }) {
    const [open, setOpen] = useState(false);
    const colorCls = TIPO_COLORS[tipo] ?? 'bg-gray-100 text-gray-600';
    const icon = TIPO_ICONS[tipo];
    const totalVotos = rows.reduce((sum, r) => sum + (r.votos || 0), 0);
    const [isMobile, setIsMobile] = useState(typeof window !== 'undefined' && window.innerWidth < 1024);

    useEffect(() => {
        function handleResize() { setIsMobile(window.innerWidth < 1024); }
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    // Agrupar por partido para acordeones internos (desktop only)
    const byParty = useMemo(() => {
        const groups = {};
        rows.forEach(r => {
            const key = r.partido || 'Sin partido';
            if (!groups[key]) groups[key] = [];
            groups[key].push(r);
        });
        return Object.entries(groups).sort((a, b) => {
            const votosA = a[1].reduce((s, r) => s + (r.votos || 0), 0);
            const votosB = b[1].reduce((s, r) => s + (r.votos || 0), 0);
            return votosB - votosA;
        });
    }, [rows]);

    // Sort rows by votos desc for mobile flat list
    const sortedRows = useMemo(() =>
        [...rows].sort((a, b) => (b.votos || 0) - (a.votos || 0)),
    [rows]);

    return (
        <div className="border border-[var(--color-line)] rounded-xl overflow-hidden bg-white">
            <button
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition-colors"
            >
                <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${colorCls}`}>
                    {icon}
                </div>
                <div className="flex-1 text-left">
                    <h3 className="text-[16px] font-extrabold text-[var(--color-ink)] uppercase">{tipo}</h3>
                    <p className="text-[12px] text-[var(--color-ink-faint)]">
                        {rows.length} {rows.length === 1 ? 'registro' : 'registros'}
                        {byParty.length > 0 && tipo !== 'Líderes' && ` · ${byParty.length} partido${byParty.length > 1 ? 's' : ''}`}
                        {totalVotos > 0 && ` · ${fmt(totalVotos)} votos`}
                    </p>
                </div>
                <span className={`px-3 py-1 rounded-full text-[13px] font-bold ${colorCls}`}>{rows.length}</span>
                <svg className={`w-5 h-5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>

            {open && (
                <div className="border-t border-[var(--color-line)]">
                    {isMobile ? (
                        /* ── Mobile: flat list, no party nesting ── */
                        <div>
                            {sortedRows.map((row, i) => (
                                <PersonRow
                                    key={`${row.id}-${i}`}
                                    row={row}
                                    onSelectPerson={onSelectPerson}
                                    color={partyColor(row.partido, i)}
                                />
                            ))}
                        </div>
                    ) : (
                        /* ── Desktop: party accordions ── */
                        <div className="px-4 py-3 space-y-1">
                            {tipo === 'Líderes' ? (
                                <div className="space-y-1">
                                    {rows.map((row, i) => (
                                        <PersonRow
                                            key={`${row.id}-${i}`}
                                            row={row}
                                            onSelectPerson={onSelectPerson}
                                            color={partyColor(row.partido, i)}
                                        />
                                    ))}
                                </div>
                            ) : (
                                byParty.map(([party, partyRows], idx) => (
                                    <PartyAccordion
                                        key={party}
                                        partyName={party}
                                        rows={partyRows}
                                        index={idx}
                                        onSelectPerson={onSelectPerson}
                                    />
                                ))
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/* ── MAIN ── */
export default function MapaPolitico({ data = [], municipios = [], provincias = [], municipioInfo, cargosDisponibles = [], cargosPorTipo = {}, barrios = [], sectionCounts = {}, filters = {} }) {
    const [localSearch, setLocalSearch] = useState(filters.search ?? '');
    const [selectedProv, setSelectedProv] = useState(filters.provincia ?? '');
    const [selectedPerson, setSelectedPerson] = useState(null);
    const [showCrearLider, setShowCrearLider] = useState(false);
    const [selectedCargos, setSelectedCargos] = useState(filters.cargo ? filters.cargo.split(',') : []);
    const [showCargoDropdown, setShowCargoDropdown] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [offlineStatus, setOfflineStatus] = useState('idle'); // idle | downloading | saved | already
    const [isOnline, setIsOnline] = useState(typeof navigator !== 'undefined' ? navigator.onLine : true);

    // Detect online/offline
    useEffect(() => {
        const goOnline = () => setIsOnline(true);
        const goOffline = () => setIsOnline(false);
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
        return () => { window.removeEventListener('online', goOnline); window.removeEventListener('offline', goOffline); };
    }, []);

    // Check if current municipio is already downloaded
    useEffect(() => {
        if (!municipioInfo?.id) { setOfflineStatus('idle'); return; }
        isDownloaded(municipioInfo.id).then(yes => setOfflineStatus(yes ? 'already' : 'idle'));
    }, [municipioInfo?.id]);

    const handleOfflineDownload = useCallback(async () => {
        if (!municipioInfo?.id) return;
        setOfflineStatus('downloading');
        try {
            await downloadMunicipio(municipioInfo.id);
            setOfflineStatus('saved');
        } catch {
            setOfflineStatus('idle');
            alert('No se pudo guardar. Revisa tu conexion.');
        }
    }, [municipioInfo?.id]);

    // Sync local state when Inertia props change (e.g. after redirect)
    useEffect(() => {
        setLocalSearch(filters.search ?? '');
        setSelectedProv(filters.provincia ?? '');
        setSelectedCargos(filters.cargo ? filters.cargo.split(',') : []);
    }, [filters.search, filters.provincia, filters.cargo]);

    const AREA_METROPOLITANA = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Girón', 'Rionegro', 'Lebrija'];

    const filteredMunicipios = useMemo(() => {
        if (!selectedProv) return municipios;
        if (selectedProv === 'Área Metropolitana') return municipios.filter(m => AREA_METROPOLITANA.includes(m.name));
        return municipios.filter(m => m.provincia === selectedProv);
    }, [municipios, selectedProv]);

    // Determine if we should show collapsible sections or flat table
    const tipoFilter = filters.tipo ?? 'todos';
    const hasSpecificFilters = filters.search || filters.cargo || filters.barrio || filters.destacado;
    const showSections = tipoFilter === 'todos' && !hasSpecificFilters;

    // Group data by tipo_registro for sections
    const groupedData = useMemo(() => {
        if (!showSections) return {};
        const groups = {};
        data.forEach(row => {
            // A row may have multiple tipos (e.g. "Alcaldía, Líderes")
            const tipos = (row.tipo_registro || '').split(',').map(t => t.trim()).filter(Boolean);
            tipos.forEach(t => {
                if (!groups[t]) groups[t] = [];
                groups[t].push(row);
            });
        });
        return groups;
    }, [data, showSections]);

    function applyFilters(overrides = {}) {
        const params = {
            municipio: filters.municipio,
            tipo: filters.tipo ?? 'todos',
            cargo: filters.cargo,
            partido: filters.partido,
            barrio: filters.barrio,
            destacado: filters.destacado,
            search: localSearch || undefined,
            provincia: selectedProv || undefined,
            ...overrides,
        };
        Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });
        router.get('/mapa-politico', params, { preserveState: true, replace: true });
    }

    function handleSearch(e) {
        if (e.key === 'Enter') applyFilters({ search: localSearch || undefined });
    }

    return (
        <AppLayout title="Mapa Político" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'MAPA POLITICO' }]}>
            <Head title="Mapa Politico — Inteligencia Electoral" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">MAPA POLITICO</h1>
                    <p className="text-[10px] lg:text-[11px] text-white/40 mt-0.5 truncate">Directorio unificado · Alcaldías, Concejos, Líderes, Senado, Cámara y Asamblea</p>
                </div>
                <button onClick={() => setShowCrearLider(true)} className="px-3 lg:px-5 py-2.5 lg:py-3 bg-white/15 hover:bg-white/25 text-white text-[13px] lg:text-[15px] font-bold rounded-lg transition-colors border border-white/20 flex items-center gap-2 flex-shrink-0">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                    <span className="hidden sm:inline">Crear Líder</span>
                </button>
            </div>

            {/* Banner sin conexión */}
            {!isOnline && (
                <div className="bg-red-500 text-white px-4 py-3 flex items-center gap-3">
                    <svg className="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728" />
                        <line x1="4" y1="4" x2="20" y2="20" strokeLinecap="round" strokeWidth={2.5} />
                    </svg>
                    <div>
                        <p className="text-[15px] font-bold">Sin conexion a internet</p>
                        <p className="text-[13px] text-white/80">Solo puedes ver los datos que hayas guardado antes. Conectate a internet para buscar o filtrar.</p>
                    </div>
                </div>
            )}

            {/* Filtros */}
            <div className="bg-white border-b border-[var(--color-line)] px-4 lg:px-5 py-3 space-y-3">
                {/* Mobile: toggle + search inline */}
                <div className="flex items-center gap-2 lg:hidden">
                    <button
                        onClick={() => setFiltersOpen(!filtersOpen)}
                        className="flex items-center gap-2 px-3 py-2.5 border border-[var(--color-line)] rounded-lg text-[13px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-50 transition-colors flex-shrink-0"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
                        Filtros
                        {(filters.provincia || filters.municipio || filters.tipo !== 'todos' || filters.cargo || filters.partido || filters.barrio) && (
                            <span className="w-2 h-2 bg-[var(--color-primary)] rounded-full" />
                        )}
                    </button>
                    <input type="text" value={localSearch} onChange={e => setLocalSearch(e.target.value)} onKeyDown={handleSearch} placeholder="Buscar nombre..."
                        className="flex-1 min-w-0 text-[14px] border border-[var(--color-line)] rounded-lg px-3 py-2.5 focus:outline-none focus:border-[var(--color-primary)] text-[var(--color-ink)]" />
                    <button onClick={() => applyFilters({ search: localSearch || undefined })} className="px-3 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg flex-shrink-0">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </button>
                </div>

                {/* Filter grid — always visible on desktop, collapsible on mobile */}
                <div className={`${filtersOpen ? 'block' : 'hidden'} lg:block`}>
                    <div className="grid grid-cols-2 lg:flex lg:flex-wrap items-end gap-2 lg:gap-3">
                        <div className="col-span-1">
                            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Provincia</label>
                            <select value={selectedProv} onChange={e => { setSelectedProv(e.target.value); applyFilters({ provincia: e.target.value || undefined, municipio: undefined }); }} className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[220px] focus:outline-none focus:border-[var(--color-primary)] font-semibold">
                                <option value="">Todas</option>
                                {provincias.map(p => <option key={p} value={p}>{p}</option>)}
                            </select>
                        </div>
                        <div className="col-span-1">
                            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Municipio</label>
                            <select value={filters.municipio ?? ''} onChange={e => applyFilters({ municipio: e.target.value || undefined })} className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[240px] focus:outline-none focus:border-[var(--color-primary)] font-semibold">
                                <option value="">Todos</option>
                                {filteredMunicipios.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </div>
                        <div className="col-span-1">
                            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Tipo</label>
                            <select value={filters.tipo ?? 'todos'} onChange={e => { setSelectedCargos([]); applyFilters({ tipo: e.target.value, cargo: undefined }); }} className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[180px] focus:outline-none focus:border-[var(--color-primary)] font-semibold">
                                {TIPO_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </div>
                        <div className="relative col-span-1">
                            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Cargo</label>
                            <button
                                type="button"
                                onClick={() => setShowCargoDropdown(!showCargoDropdown)}
                                className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[250px] focus:outline-none focus:border-[var(--color-primary)] font-semibold text-left flex items-center justify-between bg-white"
                            >
                                <span className={`truncate ${selectedCargos.length > 0 ? 'text-[var(--color-ink)]' : 'text-gray-400'}`}>
                                    {selectedCargos.length > 0 ? `${selectedCargos.length} sel.` : 'Todos'}
                                </span>
                                <svg className={`w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ${showCargoDropdown ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                            {showCargoDropdown && (
                                <>
                                    <div className="fixed inset-0 z-30" onClick={() => setShowCargoDropdown(false)} />
                                    <div className="absolute top-full left-0 mt-1 w-[280px] lg:w-[300px] bg-white rounded-xl shadow-2xl border border-[var(--color-line)] z-40 max-h-[350px] overflow-y-auto">
                                        <button
                                            onClick={() => { setSelectedCargos([]); applyFilters({ cargo: undefined }); setShowCargoDropdown(false); }}
                                            className="w-full text-left px-4 py-2.5 text-[14px] text-[var(--color-primary)] font-semibold border-b border-[var(--color-line)] hover:bg-blue-50"
                                        >
                                            Limpiar seleccion
                                        </button>
                                        {(cargosPorTipo[filters.tipo ?? 'todos'] ?? cargosDisponibles).map(c => {
                                            const checked = selectedCargos.includes(c);
                                            return (
                                                <label key={c} className={`flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 border-b border-[var(--color-line)] last:border-0 ${checked ? 'bg-blue-50' : ''}`}>
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => {
                                                            const next = checked ? selectedCargos.filter(x => x !== c) : [...selectedCargos, c];
                                                            setSelectedCargos(next);
                                                            applyFilters({ cargo: next.length > 0 ? next.join(',') : undefined });
                                                            setShowCargoDropdown(false);
                                                        }}
                                                        className="w-5 h-5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                                    />
                                                    <span className={`text-[14px] ${checked ? 'font-bold text-[var(--color-primary)]' : 'text-[var(--color-ink-soft)]'}`}>{c}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="col-span-1">
                            <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Partido</label>
                            <select value={filters.partido ?? ''} onChange={e => applyFilters({ partido: e.target.value || undefined })} className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[220px] focus:outline-none focus:border-[var(--color-primary)] font-semibold">
                                <option value="">Todos</option>
                                {(data.length > 0 ? [...new Set(data.map(d => d.partido).filter(Boolean))].sort() : []).map(p => <option key={p} value={p}>{p}</option>)}
                            </select>
                        </div>
                        {barrios.length > 0 && (
                            <div className="col-span-1">
                                <label className="block text-[11px] lg:text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Barrio</label>
                                <select value={filters.barrio ?? ''} onChange={e => applyFilters({ barrio: e.target.value || undefined })} className="text-[13px] lg:text-[15px] border border-[var(--color-line)] rounded-lg px-3 lg:px-4 py-2.5 lg:py-3 w-full lg:w-[200px] focus:outline-none focus:border-[var(--color-primary)] font-semibold">
                                    <option value="">Todos</option>
                                    {barrios.map(b => <option key={b} value={b}>{b}</option>)}
                                </select>
                            </div>
                        )}
                        {/* Search visible only on desktop (mobile has it above) */}
                        <div className="hidden lg:block">
                            <label className="block text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Buscar</label>
                            <input type="text" value={localSearch} onChange={e => setLocalSearch(e.target.value)} onKeyDown={handleSearch} placeholder="Nombre..."
                                className="text-[15px] border border-[var(--color-line)] rounded-lg px-4 py-3 w-[240px] focus:outline-none focus:border-[var(--color-primary)] text-[var(--color-ink)]" />
                        </div>
                        <div className="col-span-2 lg:col-span-1 flex gap-2 pt-1 lg:pt-4">
                            <button onClick={() => { applyFilters({ search: localSearch || undefined }); setFiltersOpen(false); }} className="flex-1 lg:flex-initial px-4 lg:px-6 py-2.5 lg:py-3 bg-[var(--color-primary)] text-white text-[13px] lg:text-[15px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors">Filtrar</button>
                            <button onClick={() => { setLocalSearch(''); setSelectedProv(''); setSelectedCargos([]); setFiltersOpen(false); router.get('/mapa-politico', {}, { preserveState: false }); }} className="flex-1 lg:flex-initial px-3 lg:px-5 py-2.5 lg:py-3 border border-[var(--color-line)] text-[13px] lg:text-[15px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 transition-colors">Limpiar</button>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3 text-[11px] text-[var(--color-ink-faint)] flex-wrap">
                    <span className="font-bold text-[var(--color-ink)]">{data.length} registros</span>
                    {municipioInfo && <span>· {municipioInfo.name} ({municipioInfo.provincia})</span>}

                    {/* Destacados toggle */}
                    <button
                        onClick={() => applyFilters({ destacado: filters.destacado ? undefined : '1' })}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold transition-colors ${
                            filters.destacado
                                ? 'bg-amber-400 text-white'
                                : 'bg-amber-50 text-amber-600 border border-amber-200'
                        }`}
                    >
                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>
                        {filters.destacado ? 'Destacados' : 'Destacados'}
                    </button>

                    {/* Offline download button — only when a municipio is selected */}
                    {municipioInfo && offlineStatus === 'idle' && (
                        <button
                            onClick={handleOfflineDownload}
                            className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg active:bg-[var(--color-primary-dark)] transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            Guardar sin internet
                        </button>
                    )}
                    {municipioInfo && offlineStatus === 'downloading' && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-blue-50 text-[var(--color-primary)] text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            Guardando...
                        </span>
                    )}
                    {municipioInfo && (offlineStatus === 'saved' || offlineStatus === 'already') && (
                        <span className="ml-auto flex items-center gap-1.5 px-3 py-2 bg-emerald-50 text-emerald-700 text-[12px] font-bold rounded-lg">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                            Guardado
                        </span>
                    )}
                </div>
            </div>

            {/* Contenido principal */}
            {showSections && data.length > 0 ? (
                /* ── Vista de secciones colapsables ── */
                <div className="p-4 space-y-3">
                    {SECTION_ORDER.map(tipo => {
                        const rows = groupedData[tipo];
                        if (!rows || rows.length === 0) return null;
                        return (
                            <CollapsibleSection
                                key={tipo}
                                tipo={tipo}
                                rows={rows}
                                onSelectPerson={setSelectedPerson}
                            />
                        );
                    })}
                </div>
            ) : data.length === 0 ? (
                /* ── Sin datos ── */
                <div className="px-4 py-16 text-center">
                    <div className="flex flex-col items-center gap-3">
                        <svg className="w-16 h-16 text-[var(--color-ink-faint)]/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <p className="text-[18px] font-bold text-[var(--color-ink-faint)]">No se encontraron resultados</p>
                        <p className="text-[14px] text-[var(--color-ink-faint)]/60">Ajusta los filtros o limpia la búsqueda</p>
                    </div>
                </div>
            ) : (
                /* ── Tabla plana (cuando hay filtros específicos) ── */
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="sticky top-0 bg-[var(--color-primary)] text-white z-10">
                            <tr>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Nombre</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Municipio</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Barrio/Vereda</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Tipo</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Cargo</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Partido</th>
                                <th className="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Telefono</th>
                                <th className="text-right px-4 py-3 text-[10px] font-bold uppercase tracking-wider">Votos</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((row, i) => {
                                const isElecto = row.outcome === 'elected' || (row.cargo && row.cargo.includes('Electo'));
                                return (
                                    <tr
                                        key={`${row.id}-${i}`}
                                        className={`border-b border-[var(--color-line)] hover:bg-blue-50/50 transition-colors cursor-pointer ${isElecto ? 'bg-emerald-50/60' : ''}`}
                                        onClick={() => setSelectedPerson(row.id)}
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                {row.destacado && <svg className="w-4 h-4 text-amber-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>}
                                                <span className="font-bold text-[var(--color-primary)] uppercase hover:underline">{row.nombre}</span>
                                                {isElecto && <span className="px-1.5 py-0.5 bg-[var(--color-good-light)] text-[var(--color-good)] text-[8px] font-bold uppercase rounded">Electo</span>}
                                            </div>
                                            {row.email && <p className="text-[10px] text-[var(--color-ink-faint)] mt-0.5">{row.email}</p>}
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)]">
                                            <p>{row.municipio ?? '—'}</p>
                                            <p className="text-[10px] text-[var(--color-ink-faint)]">{row.provincia ?? ''}</p>
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)] text-[12px]">{row.barrio ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`px-2 py-0.5 rounded text-[9px] font-bold ${TIPO_COLORS[row.tipo_registro] ?? 'bg-gray-100 text-gray-500'}`}>{row.tipo_registro}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)] text-[12px]">
                                            {row.cargo ?? '—'}
                                            {row.tipo_aval && <span className="ml-1 px-1 py-0.5 bg-amber-50 text-amber-700 text-[8px] font-bold uppercase rounded border border-amber-200">{row.tipo_aval}</span>}
                                        </td>
                                        <td className="px-4 py-3 text-[var(--color-ink-soft)]">{row.partido ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {row.telefono ? <span className="text-[var(--color-primary)] font-semibold text-[12px]">{row.telefono}</span> : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-bold font-[var(--font-mono)] text-[var(--color-ink)]">
                                            {row.votos > 0 ? fmt(row.votos) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Panel lateral de detalle */}
            {selectedPerson && (
                <PersonaPanel personId={selectedPerson} onClose={() => setSelectedPerson(null)} />
            )}

            {/* Modal crear líder */}
            <CrearLiderModal open={showCrearLider} onClose={() => setShowCrearLider(false)} municipios={municipios} />

        </AppLayout>
    );
}
