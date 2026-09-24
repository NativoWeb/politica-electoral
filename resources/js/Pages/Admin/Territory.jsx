import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Territory({ provincias }) {
    const [showProvForm, setShowProvForm] = useState(false);
    const [showMunForm, setShowMunForm] = useState(false);
    const [expandedProv, setExpandedProv] = useState(null);

    const provForm = useForm({ name: '' });
    const munForm = useForm({ name: '', provincia_id: '', code: '' });

    function submitProvincia(e) {
        e.preventDefault();
        provForm.post('/admin/territorio/provincia', {
            onSuccess: () => { provForm.reset(); setShowProvForm(false); },
        });
    }

    function submitMunicipio(e) {
        e.preventDefault();
        munForm.post('/admin/territorio/municipio', {
            onSuccess: () => { munForm.reset(); setShowMunForm(false); },
        });
    }

    const totalMunicipios = provincias.reduce((sum, p) => sum + p.count, 0);
    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Territorio" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'TERRITORIO' }]}>
            <Head title="Territorio — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">TERRITORIO</h1>
                <p className="text-[11px] text-white/40 mt-0.5">{provincias.length} provincias · {totalMunicipios} municipios</p>
            </div>

            <div className="p-4 space-y-3">
                {/* Botones de acción */}
                <div className="flex gap-2">
                    <button
                        onClick={() => { setShowProvForm(!showProvForm); setShowMunForm(false); }}
                        className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl active:bg-[var(--color-primary-dark)] transition-colors"
                    >
                        + Provincia
                    </button>
                    <button
                        onClick={() => { setShowMunForm(!showMunForm); setShowProvForm(false); }}
                        className="flex-1 px-4 py-3 bg-emerald-600 text-white text-[14px] font-bold rounded-xl active:bg-emerald-700 transition-colors"
                    >
                        + Municipio
                    </button>
                </div>

                {/* Form crear provincia */}
                {showProvForm && (
                    <form onSubmit={submitProvincia} className="bg-white border-2 border-[var(--color-primary)] rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-[var(--color-primary)]">Nueva Provincia</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                            <input className={inputCls} value={provForm.data.name} onChange={e => provForm.setData('name', e.target.value)} required placeholder="Ej: Soto Norte" />
                            {provForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{provForm.errors.name}</p>}
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={provForm.processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {provForm.processing ? 'Creando...' : 'Crear Provincia'}
                            </button>
                            <button type="button" onClick={() => setShowProvForm(false)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Form crear municipio */}
                {showMunForm && (
                    <form onSubmit={submitMunicipio} className="bg-white border-2 border-emerald-500 rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-emerald-700">Nuevo Municipio</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Provincia *</label>
                            <select className={inputCls} value={munForm.data.provincia_id} onChange={e => munForm.setData('provincia_id', e.target.value)} required>
                                <option value="">Seleccionar provincia...</option>
                                {provincias.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                            {munForm.errors.provincia_id && <p className="text-[12px] text-red-500 mt-1">{munForm.errors.provincia_id}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre del municipio *</label>
                            <input className={inputCls} value={munForm.data.name} onChange={e => munForm.setData('name', e.target.value)} required placeholder="Ej: Piedecuesta" />
                            {munForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{munForm.errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Código DANE (opcional)</label>
                            <input className={inputCls} value={munForm.data.code} onChange={e => munForm.setData('code', e.target.value)} placeholder="Ej: 68547" />
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={munForm.processing} className="flex-1 px-4 py-3 bg-emerald-600 text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {munForm.processing ? 'Creando...' : 'Crear Municipio'}
                            </button>
                            <button type="button" onClick={() => setShowMunForm(false)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Lista de provincias con municipios */}
                {provincias.map(prov => (
                    <div key={prov.id} className="bg-white border border-[var(--color-line)] rounded-xl overflow-hidden">
                        <button
                            onClick={() => setExpandedProv(expandedProv === prov.id ? null : prov.id)}
                            className="w-full flex items-center gap-4 px-5 py-4 active:bg-gray-50 transition-colors"
                        >
                            <div className="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                            </div>
                            <div className="flex-1 text-left">
                                <h3 className="text-[16px] font-extrabold text-[var(--color-ink)] uppercase">{prov.name}</h3>
                                <p className="text-[12px] text-[var(--color-ink-faint)]">{prov.count} municipio{prov.count !== 1 ? 's' : ''}</p>
                            </div>
                            <span className="px-3 py-1 rounded-full text-[13px] font-bold bg-indigo-100 text-indigo-700">{prov.count}</span>
                            <svg className={`w-5 h-5 text-gray-400 transition-transform ${expandedProv === prov.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                        </button>

                        {expandedProv === prov.id && (
                            <div className="border-t border-[var(--color-line)]">
                                {prov.municipios.map(mun => (
                                    <div key={mun.id} className="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 last:border-0">
                                        <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-[11px] font-bold flex-shrink-0">
                                            {mun.name.slice(0, 2).toUpperCase()}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[14px] font-bold text-[var(--color-ink)] truncate">{mun.name}</p>
                                        </div>
                                        {mun.code && <span className="text-[11px] text-[var(--color-ink-faint)] font-mono">{mun.code}</span>}
                                    </div>
                                ))}
                                {prov.municipios.length === 0 && (
                                    <p className="px-5 py-6 text-center text-[14px] text-[var(--color-ink-faint)]">Sin municipios</p>
                                )}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
