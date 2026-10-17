import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';

function EditModal({ item, type, provincias, onClose }) {
    const form = useForm({
        name: item.name,
        code: item.code || '',
        parent_id: item.parent_id || '',
    });

    function submit(e) {
        e.preventDefault();
        form.put(`/admin/territorio/${item.id}`, { onSuccess: onClose });
    }

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";
    const label = type === 'municipality' ? 'Municipio' : type === 'province' ? 'Provincia' : 'Departamento';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-black/40" />
            <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4" onClick={e => e.stopPropagation()}>
                <h3 className="text-[18px] font-bold text-[var(--color-ink)]">Editar {label}</h3>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                        <input className={inputCls} value={form.data.name} onChange={e => form.setData('name', e.target.value)} required />
                        {form.errors.name && <p className="text-[12px] text-red-500 mt-1">{form.errors.name}</p>}
                    </div>

                    {(type === 'municipality' || type === 'department') && (
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Código DANE</label>
                            <input className={inputCls} value={form.data.code} onChange={e => form.setData('code', e.target.value)} placeholder="Opcional" />
                        </div>
                    )}

                    {type === 'municipality' && provincias && (
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Provincia</label>
                            <select className={inputCls} value={form.data.parent_id} onChange={e => form.setData('parent_id', e.target.value)}>
                                {provincias.map(p => (
                                    <option key={p.id} value={p.id}>{p.name} ({p.dept})</option>
                                ))}
                            </select>
                            {form.errors.parent_id && <p className="text-[12px] text-red-500 mt-1">{form.errors.parent_id}</p>}
                        </div>
                    )}

                    <div className="flex gap-3 pt-2">
                        <button type="submit" disabled={form.processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                            {form.processing ? 'Guardando...' : 'Guardar'}
                        </button>
                        <button type="button" onClick={onClose} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ToggleButton({ id, isActive, small }) {
    const [loading, setLoading] = useState(false);

    function toggle(e) {
        e.stopPropagation();
        setLoading(true);
        router.post(`/admin/territorio/${id}/toggle`, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    }

    return (
        <button
            onClick={toggle}
            disabled={loading}
            title={isActive ? 'Desactivar' : 'Activar'}
            className={`${small ? 'w-8 h-8' : 'w-9 h-9'} rounded-lg flex items-center justify-center flex-shrink-0 transition-colors disabled:opacity-50 ${
                isActive
                    ? 'bg-emerald-100 text-emerald-600 hover:bg-emerald-200'
                    : 'bg-red-100 text-red-500 hover:bg-red-200'
            }`}
        >
            {isActive ? (
                <svg className={`${small ? 'w-4 h-4' : 'w-5 h-5'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
            ) : (
                <svg className={`${small ? 'w-4 h-4' : 'w-5 h-5'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            )}
        </button>
    );
}

function EditButton({ onClick, small }) {
    return (
        <button
            onClick={e => { e.stopPropagation(); onClick(); }}
            title="Editar"
            className={`${small ? 'w-8 h-8' : 'w-9 h-9'} rounded-lg flex items-center justify-center flex-shrink-0 bg-blue-100 text-blue-600 hover:bg-blue-200 transition-colors`}
        >
            <svg className={`${small ? 'w-3.5 h-3.5' : 'w-4 h-4'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
        </button>
    );
}

export default function Territory({ departamentos }) {
    const auth = usePage().props.auth?.user;
    const isSuperadmin = auth?.role === 'Superadministrador';
    const canAudit = (auth?.permissions || []).some(p => p === '*' || p === 'audit.view');
    const [activeForm, setActiveForm] = useState(null);
    const [expandedDept, setExpandedDept] = useState(departamentos.length === 1 ? departamentos[0].id : null);
    const [expandedProv, setExpandedProv] = useState(null);
    const [editing, setEditing] = useState(null); // { item, type }
    const [search, setSearch] = useState('');

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

    const allProvincias = departamentos.flatMap(d =>
        d.provincias.map(p => ({ ...p, dept: d.name }))
    );

    const totalProv = departamentos.reduce((s, d) => s + d.totalProvincias, 0);
    const totalMun = departamentos.reduce((s, d) => s + d.totalMunicipios, 0);

    const searchLower = search.toLowerCase().trim();
    const filteredDepts = useMemo(() => {
        if (!searchLower) return departamentos;
        return departamentos.map(dept => {
            const provs = dept.provincias.map(prov => {
                const muns = prov.municipios.filter(m =>
                    m.name.toLowerCase().includes(searchLower)
                );
                if (muns.length > 0 || prov.name.toLowerCase().includes(searchLower)) {
                    return { ...prov, municipios: prov.name.toLowerCase().includes(searchLower) ? prov.municipios : muns, count: muns.length || prov.count };
                }
                return null;
            }).filter(Boolean);
            if (provs.length > 0 || dept.name.toLowerCase().includes(searchLower)) {
                return { ...dept, provincias: provs };
            }
            return null;
        }).filter(Boolean);
    }, [departamentos, searchLower]);

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Territorio" breadcrumb={[{ label: 'COLOMBIA', href: '/' }, { label: 'TERRITORIO' }]}>
            <Head title="Territorio — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">TERRITORIO</h1>
                <p className="text-[11px] text-white/40 mt-0.5">{departamentos.length} departamento{departamentos.length !== 1 ? 's' : ''} · {totalProv} provincias · {totalMun} municipios</p>
            </div>

            <div className="p-4 space-y-3">
                {/* Buscador */}
                <div className="relative">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Buscar municipio o provincia..."
                        className="w-full pl-10 pr-4 py-3 border border-[var(--color-line)] rounded-xl text-[15px] focus:outline-none focus:border-[var(--color-primary)]"
                    />
                    {search && (
                        <button onClick={() => setSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    )}
                </div>

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
                {filteredDepts.map(dept => (
                    <div key={dept.id} className={`bg-white border rounded-xl overflow-hidden ${dept.is_active ? 'border-[var(--color-line)]' : 'border-red-200 bg-red-50/30'}`}>
                        <div
                            onClick={() => setExpandedDept(expandedDept === dept.id ? null : dept.id)}
                            className="w-full flex items-center gap-4 px-5 py-4 active:bg-gray-50 transition-colors cursor-pointer select-none"
                        >
                            <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${dept.is_active ? 'bg-violet-100 text-violet-700' : 'bg-red-100 text-red-400'}`}>
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
                            </div>
                            <div className="flex-1 text-left min-w-0">
                                <h3 className={`text-[16px] font-extrabold uppercase truncate ${dept.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{dept.name}</h3>
                                <p className="text-[12px] text-[var(--color-ink-faint)]">
                                    {dept.totalProvincias} provincia{dept.totalProvincias !== 1 ? 's' : ''} · {dept.totalMunicipios} municipio{dept.totalMunicipios !== 1 ? 's' : ''}
                                    {!dept.is_active && <span className="ml-2 text-red-400 font-bold">INACTIVO</span>}
                                </p>
                            </div>
                            {dept.code && <span className="text-[11px] text-[var(--color-ink-faint)] font-mono">{dept.code}</span>}
                            <EditButton onClick={() => setEditing({ item: dept, type: 'department' })} />
                            <ToggleButton id={dept.id} isActive={dept.is_active} />
                            <svg className={`w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ${expandedDept === dept.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                        </div>

                        {expandedDept === dept.id && (
                            <div className="border-t border-[var(--color-line)]">
                                {dept.provincias.map(prov => (
                                    <div key={prov.id}>
                                        <div
                                            onClick={() => setExpandedProv(expandedProv === prov.id ? null : prov.id)}
                                            className="w-full flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 active:bg-gray-50 transition-colors cursor-pointer select-none"
                                        >
                                            <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${prov.is_active ? 'bg-indigo-100 text-indigo-700' : 'bg-red-100 text-red-400'}`}>
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                                            </div>
                                            <div className="flex-1 text-left min-w-0">
                                                <p className={`text-[14px] font-bold uppercase truncate ${prov.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{prov.name}</p>
                                                <p className="text-[11px] text-[var(--color-ink-faint)]">
                                                    {prov.count} municipio{prov.count !== 1 ? 's' : ''}
                                                    {!prov.is_active && <span className="ml-2 text-red-400 font-bold">INACTIVO</span>}
                                                </p>
                                            </div>
                                            <span className={`px-2.5 py-0.5 rounded-full text-[12px] font-bold ${prov.is_active ? 'bg-indigo-100 text-indigo-700' : 'bg-red-100 text-red-400'}`}>{prov.count}</span>
                                            <EditButton onClick={() => setEditing({ item: prov, type: 'province' })} small />
                                            <ToggleButton id={prov.id} isActive={prov.is_active} small />
                                            <svg className={`w-4 h-4 text-gray-300 transition-transform flex-shrink-0 ${expandedProv === prov.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                                        </div>

                                        {expandedProv === prov.id && (
                                            <div className="bg-gray-50">
                                                {prov.municipios.map(mun => (
                                                    <div key={mun.id} className={`flex items-center gap-3 pl-14 pr-5 py-3 border-b border-gray-100 last:border-0 ${!mun.is_active ? 'bg-red-50/50' : ''}`}>
                                                        <div className={`w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-bold flex-shrink-0 ${mun.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-400'}`}>
                                                            {mun.name.slice(0, 2).toUpperCase()}
                                                        </div>
                                                        <p className={`text-[13px] font-semibold flex-1 truncate ${mun.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{mun.name}</p>
                                                        {mun.code && <span className="text-[10px] text-[var(--color-ink-faint)] font-mono">{mun.code}</span>}
                                                        {!mun.is_active && <span className="text-[10px] text-red-400 font-bold">INACTIVO</span>}
                                                        <EditButton onClick={() => setEditing({ item: { ...mun, parent_id: prov.id }, type: 'municipality' })} small />
                                                        <ToggleButton id={mun.id} isActive={mun.is_active} small />
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

                {filteredDepts.length === 0 && search && (
                    <div className="text-center py-8">
                        <p className="text-[15px] text-[var(--color-ink-faint)]">No se encontraron resultados para "{search}"</p>
                    </div>
                )}

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
                {canAudit && (
                    <Link
                        href="/admin/auditoria"
                        className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-xl hover:bg-gray-50 active:bg-gray-50 transition-colors"
                    >
                        <div className="w-10 h-10 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center flex-shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        </div>
                        <div className="flex-1">
                            <p className="text-[14px] font-bold text-[var(--color-ink)]">Auditoría</p>
                            <p className="text-[12px] text-[var(--color-ink-faint)]">Registro de todo lo que se hace en el sistema</p>
                        </div>
                        <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </Link>
                )}
            </div>

            {/* Modal de edición */}
            {editing && (
                <EditModal
                    item={editing.item}
                    type={editing.type}
                    provincias={editing.type === 'municipality' ? allProvincias : null}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}
