import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Territory({ departamentos }) {
    const auth = usePage().props.auth?.user;
    const isSuperadmin = auth?.role === 'Superadministrador';
    const [activeForm, setActiveForm] = useState(null); // 'dept' | 'prov' | 'mun'
    const [expandedDept, setExpandedDept] = useState(departamentos.length === 1 ? departamentos[0].id : null);
    const [expandedProv, setExpandedProv] = useState(null);

    const deptForm = useForm({ name: '', code: '' });
    const provForm = useForm({ name: '', departamento_id: '' });
    const munForm = useForm({ name: '', provincia_id: '', code: '' });

    function submitDept(e) {
        e.preventDefault();
        deptForm.post('/admin/territorio/departamento', {
            onSuccess: () => { deptForm.reset(); setActiveForm(null); },
        });
    }
    function submitProv(e) {
        e.preventDefault();
        provForm.post('/admin/territorio/provincia', {
            onSuccess: () => { provForm.reset(); setActiveForm(null); },
        });
    }
    function submitMun(e) {
        e.preventDefault();
        munForm.post('/admin/territorio/municipio', {
            onSuccess: () => { munForm.reset(); setActiveForm(null); },
        });
    }

    // Flatten all provincias for the municipio form select
    const allProvincias = departamentos.flatMap(d =>
        d.provincias.map(p => ({ ...p, dept: d.name }))
    );

    const totalProv = departamentos.reduce((s, d) => s + d.totalProvincias, 0);
    const totalMun = departamentos.reduce((s, d) => s + d.totalMunicipios, 0);
    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Territorio" breadcrumb={[{ label: 'COLOMBIA', href: '/' }, { label: 'TERRITORIO' }]}>
            <Head title="Territorio — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">TERRITORIO</h1>
                <p className="text-[11px] text-white/40 mt-0.5">{departamentos.length} departamento{departamentos.length !== 1 ? 's' : ''} · {totalProv} provincias · {totalMun} municipios</p>
            </div>

            <div className="p-4 space-y-3">
                {/* Botones de acción */}
                <div className="flex gap-2">
                    <button
                        onClick={() => setActiveForm(activeForm === 'dept' ? null : 'dept')}
                        className="flex-1 px-3 py-3 bg-violet-600 text-white text-[13px] font-bold rounded-xl active:bg-violet-700 transition-colors"
                    >
                        + Departamento
                    </button>
                    <button
                        onClick={() => setActiveForm(activeForm === 'prov' ? null : 'prov')}
                        className="flex-1 px-3 py-3 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-xl active:bg-[var(--color-primary-dark)] transition-colors"
                    >
                        + Provincia
                    </button>
                    <button
                        onClick={() => setActiveForm(activeForm === 'mun' ? null : 'mun')}
                        className="flex-1 px-3 py-3 bg-emerald-600 text-white text-[13px] font-bold rounded-xl active:bg-emerald-700 transition-colors"
                    >
                        + Municipio
                    </button>
                </div>

                {/* Form crear departamento */}
                {activeForm === 'dept' && (
                    <form onSubmit={submitDept} className="bg-white border-2 border-violet-500 rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-violet-700">Nuevo Departamento</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                            <input className={inputCls} value={deptForm.data.name} onChange={e => deptForm.setData('name', e.target.value)} required placeholder="Ej: Norte de Santander" />
                            {deptForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{deptForm.errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Código DANE (opcional)</label>
                            <input className={inputCls} value={deptForm.data.code} onChange={e => deptForm.setData('code', e.target.value)} placeholder="Ej: 54" />
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={deptForm.processing} className="flex-1 px-4 py-3 bg-violet-600 text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {deptForm.processing ? 'Creando...' : 'Crear Departamento'}
                            </button>
                            <button type="button" onClick={() => setActiveForm(null)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Form crear provincia */}
                {activeForm === 'prov' && (
                    <form onSubmit={submitProv} className="bg-white border-2 border-[var(--color-primary)] rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-[var(--color-primary)]">Nueva Provincia</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Departamento *</label>
                            <select className={inputCls} value={provForm.data.departamento_id} onChange={e => provForm.setData('departamento_id', e.target.value)} required>
                                <option value="">Seleccionar departamento...</option>
                                {departamentos.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                            {provForm.errors.departamento_id && <p className="text-[12px] text-red-500 mt-1">{provForm.errors.departamento_id}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                            <input className={inputCls} value={provForm.data.name} onChange={e => provForm.setData('name', e.target.value)} required placeholder="Ej: Soto Norte" />
                            {provForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{provForm.errors.name}</p>}
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={provForm.processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {provForm.processing ? 'Creando...' : 'Crear Provincia'}
                            </button>
                            <button type="button" onClick={() => setActiveForm(null)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Form crear municipio */}
                {activeForm === 'mun' && (
                    <form onSubmit={submitMun} className="bg-white border-2 border-emerald-500 rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-emerald-700">Nuevo Municipio</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Provincia *</label>
                            <select className={inputCls} value={munForm.data.provincia_id} onChange={e => munForm.setData('provincia_id', e.target.value)} required>
                                <option value="">Seleccionar provincia...</option>
                                {allProvincias.map(p => <option key={p.id} value={p.id}>{p.name} ({p.dept})</option>)}
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
                            <button type="button" onClick={() => setActiveForm(null)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Lista de departamentos → provincias → municipios */}
                {departamentos.map(dept => (
                    <div key={dept.id} className="bg-white border border-[var(--color-line)] rounded-xl overflow-hidden">
                        <button
                            onClick={() => setExpandedDept(expandedDept === dept.id ? null : dept.id)}
                            className="w-full flex items-center gap-4 px-5 py-4 active:bg-gray-50 transition-colors"
                        >
                            <div className="w-10 h-10 rounded-xl bg-violet-100 text-violet-700 flex items-center justify-center flex-shrink-0">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
                            </div>
                            <div className="flex-1 text-left">
                                <h3 className="text-[16px] font-extrabold text-[var(--color-ink)] uppercase">{dept.name}</h3>
                                <p className="text-[12px] text-[var(--color-ink-faint)]">{dept.totalProvincias} provincia{dept.totalProvincias !== 1 ? 's' : ''} · {dept.totalMunicipios} municipio{dept.totalMunicipios !== 1 ? 's' : ''}</p>
                            </div>
                            {dept.code && <span className="text-[11px] text-[var(--color-ink-faint)] font-mono">{dept.code}</span>}
                            <svg className={`w-5 h-5 text-gray-400 transition-transform ${expandedDept === dept.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                        </button>

                        {expandedDept === dept.id && (
                            <div className="border-t border-[var(--color-line)]">
                                {dept.provincias.map(prov => (
                                    <div key={prov.id}>
                                        <button
                                            onClick={() => setExpandedProv(expandedProv === prov.id ? null : prov.id)}
                                            className="w-full flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 active:bg-gray-50 transition-colors"
                                        >
                                            <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                                            </div>
                                            <div className="flex-1 text-left">
                                                <p className="text-[14px] font-bold text-[var(--color-ink)] uppercase">{prov.name}</p>
                                                <p className="text-[11px] text-[var(--color-ink-faint)]">{prov.count} municipio{prov.count !== 1 ? 's' : ''}</p>
                                            </div>
                                            <span className="px-2.5 py-0.5 rounded-full text-[12px] font-bold bg-indigo-100 text-indigo-700">{prov.count}</span>
                                            <svg className={`w-4 h-4 text-gray-300 transition-transform ${expandedProv === prov.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                                        </button>

                                        {expandedProv === prov.id && (
                                            <div className="bg-gray-50">
                                                {prov.municipios.map(mun => (
                                                    <div key={mun.id} className="flex items-center gap-3 pl-14 pr-5 py-3 border-b border-gray-100 last:border-0">
                                                        <div className="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                                                            {mun.name.slice(0, 2).toUpperCase()}
                                                        </div>
                                                        <p className="text-[13px] font-semibold text-[var(--color-ink)] flex-1 truncate">{mun.name}</p>
                                                        {mun.code && <span className="text-[10px] text-[var(--color-ink-faint)] font-mono">{mun.code}</span>}
                                                    </div>
                                                ))}
                                                {prov.municipios.length === 0 && (
                                                    <p className="pl-14 py-4 text-[13px] text-[var(--color-ink-faint)]">Sin municipios</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {dept.provincias.length === 0 && (
                                    <p className="px-5 py-6 text-center text-[14px] text-[var(--color-ink-faint)]">Sin provincias</p>
                                )}
                            </div>
                        )}
                    </div>
                ))}

                {/* Links a otras secciones admin */}
                <h3 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mt-6 mb-2 px-1">Mas opciones</h3>
                <Link
                    href="/admin/partidos"
                    className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-xl active:bg-gray-50 transition-colors"
                >
                    <div className="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm0 0h3" /></svg>
                    </div>
                    <div className="flex-1">
                        <p className="text-[14px] font-bold text-[var(--color-ink)]">Partidos</p>
                        <p className="text-[12px] text-[var(--color-ink-faint)]">Crear, editar y desactivar partidos</p>
                    </div>
                    <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                </Link>
                {isSuperadmin && (
                    <Link
                        href="/admin/usuarios"
                        className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-xl active:bg-gray-50 transition-colors"
                    >
                        <div className="w-10 h-10 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center flex-shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        </div>
                        <div className="flex-1">
                            <p className="text-[14px] font-bold text-[var(--color-ink)]">Usuarios</p>
                            <p className="text-[12px] text-[var(--color-ink-faint)]">Crear cuentas y asignar roles</p>
                        </div>
                        <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </Link>
                )}
            </div>
        </AppLayout>
    );
}
