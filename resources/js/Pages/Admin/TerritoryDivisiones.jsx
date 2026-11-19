import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { BackToAdmin } from '@/Components/Territorio/AdminCard';
import { EditButton, EditModal, ToggleButton, displayName, normalize, plural } from '@/Components/Territorio/TerritorioControls';
import { apiFetch } from '@/lib/api';

const MATCH_HINT_LIMIT = 3;

function Chevron({ open, className = 'w-5 h-5 text-gray-400' }) {
    return (
        <svg className={`${className} transition-transform flex-shrink-0 ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    );
}

function SearchInput({ value, onChange, placeholder, label }) {
    return (
        <div className="relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input
                type="search"
                aria-label={label}
                value={value}
                onChange={e => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full pl-10 pr-10 py-3 border border-[var(--color-line)] rounded-xl text-[15px] bg-white focus:outline-none focus:border-[var(--color-primary)]"
            />
            {value && (
                <button type="button" aria-label="Borrar búsqueda" onClick={() => onChange('')} className="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 flex items-center justify-center text-gray-400 hover:text-gray-600">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            )}
        </div>
    );
}

function InactiveTag() {
    return <span className="text-[11px] text-red-500 font-bold">INACTIVO</span>;
}

function MunicipioRow({ mun, onEdit, indent = 'pl-14' }) {
    return (
        <li className={`flex items-center gap-3 ${indent} pr-5 py-3 border-b border-gray-100 last:border-0 ${!mun.is_active ? 'bg-red-50/50' : ''}`}>
            <p className={`text-[14px] font-semibold flex-1 min-w-0 truncate ${mun.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{displayName(mun.name)}</p>
            {mun.code && <span className="text-[11px] text-[var(--color-ink-faint)] whitespace-nowrap">Cód. DANE {mun.code}</span>}
            {!mun.is_active && <InactiveTag />}
            <EditButton onClick={() => onEdit(mun)} small />
            <ToggleButton id={mun.id} isActive={mun.is_active} small />
        </li>
    );
}

function ProvinciaBlock({ prov, open, onToggle, onEdit, onEditMunicipio }) {
    return (
        <div>
            <div
                role="button"
                tabIndex={0}
                aria-expanded={open}
                onClick={onToggle}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); } }}
                className="w-full flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 hover:bg-gray-50 active:bg-gray-50 transition-colors cursor-pointer select-none"
            >
                <div className="flex-1 text-left min-w-0">
                    <p className={`text-[15px] font-bold truncate ${prov.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{displayName(prov.name)}</p>
                    <p className="text-[12px] text-[var(--color-ink-faint)]">
                        {plural(prov.count, 'municipio', 'municipios')}
                        {!prov.is_active && <span className="ml-2"><InactiveTag /></span>}
                    </p>
                </div>
                <EditButton onClick={onEdit} small />
                <ToggleButton id={prov.id} isActive={prov.is_active} small />
                <Chevron open={open} className="w-4 h-4 text-gray-400" />
            </div>
            {open && (
                <ul className="bg-gray-50">
                    {prov.municipios.map(mun => <MunicipioRow key={mun.id} mun={mun} onEdit={onEditMunicipio} />)}
                    {prov.municipios.length === 0 && <li className="pl-14 py-4 text-[13px] text-[var(--color-ink-faint)]">Sin municipios</li>}
                </ul>
            )}
        </div>
    );
}

function matchesMunicipio(mun, q) {
    return normalize(mun.name).includes(q);
}

// Santander: open by default, with its own search over provincias and municipios.
function PrincipalDepartamento({ dept, onEdit }) {
    const [open, setOpen] = useState(true);
    const [openProv, setOpenProv] = useState(null);
    const [search, setSearch] = useState('');
    const q = normalize(search);

    const provincias = useMemo(() => {
        if (!q) return dept.provincias;
        return dept.provincias.map(prov => {
            if (normalize(prov.name).includes(q)) return prov;
            const muns = prov.municipios.filter(m => matchesMunicipio(m, q));
            return muns.length ? { ...prov, municipios: muns } : null;
        }).filter(Boolean);
    }, [dept.provincias, q]);
    const directos = useMemo(() => (q ? dept.municipios.filter(m => matchesMunicipio(m, q)) : dept.municipios), [dept.municipios, q]);
    const nothing = q && provincias.length === 0 && directos.length === 0;

    return (
        <section aria-label={`Departamento de ${displayName(dept.name)}`} className={`bg-white border-2 rounded-xl overflow-hidden ${dept.is_active ? 'border-violet-200' : 'border-red-200'}`}>
            <div
                role="button"
                tabIndex={0}
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(!open); } }}
                className="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 active:bg-gray-50 transition-colors cursor-pointer select-none"
            >
                <div className={`w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 ${dept.is_active ? 'bg-violet-100 text-violet-700' : 'bg-red-100 text-red-400'}`}>
                    <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>
                </div>
                <div className="flex-1 text-left min-w-0">
                    <h2 className={`text-[18px] font-extrabold truncate ${dept.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{displayName(dept.name)}</h2>
                    <p className="text-[13px] text-[var(--color-ink-soft)]">
                        {plural(dept.totalProvincias, 'provincia', 'provincias')} · {plural(dept.totalMunicipios, 'municipio', 'municipios')}
                        {dept.code && <span className="ml-2 text-[var(--color-ink-faint)]">Cód. DANE {dept.code}</span>}
                        {!dept.is_active && <span className="ml-2"><InactiveTag /></span>}
                    </p>
                </div>
                <EditButton onClick={() => onEdit({ item: dept, type: 'department' })} />
                <ToggleButton id={dept.id} isActive={dept.is_active} />
                <Chevron open={open} />
            </div>

            {open && (
                <div className="border-t border-[var(--color-line)]">
                    <div className="p-4 bg-gray-50/60 border-b border-gray-100">
                        <SearchInput
                            value={search}
                            onChange={setSearch}
                            label={`Buscar en ${displayName(dept.name)}`}
                            placeholder={`Buscar provincia o municipio de ${displayName(dept.name)}...`}
                        />
                    </div>
                    {provincias.map(prov => (
                        <ProvinciaBlock
                            key={prov.id}
                            prov={prov}
                            open={openProv === prov.id || Boolean(q)}
                            onToggle={() => setOpenProv(openProv === prov.id ? null : prov.id)}
                            onEdit={() => onEdit({ item: prov, type: 'province' })}
                            onEditMunicipio={mun => onEdit({ item: mun, type: 'municipality' })}
                        />
                    ))}
                    {directos.length > 0 && (
                        <div>
                            <p className="px-5 py-2 text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] bg-gray-50 border-b border-gray-100">Sin provincia</p>
                            <ul>{directos.map(mun => <MunicipioRow key={mun.id} mun={mun} indent="pl-5" onEdit={m => onEdit({ item: m, type: 'municipality' })} />)}</ul>
                        </div>
                    )}
                    {!q && dept.provincias.length === 0 && dept.municipios.length === 0 && (
                        <p className="px-5 py-6 text-center text-[14px] text-[var(--color-ink-faint)]">Sin provincias</p>
                    )}
                    {nothing && (
                        <p className="px-5 py-6 text-center text-[14px] text-[var(--color-ink-faint)]">No hay provincias ni municipios que coincidan con "{search}"</p>
                    )}
                </div>
            )}
        </section>
    );
}

// The municipios of another departamento, asked for when it is opened and again after each edit or (de)activation
// (every one of them ends in an Inertia reload of this page).
function DepartamentoMunicipios({ deptId, q, onEdit }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setError('');
        try {
            const r = await apiFetch(`/admin/territorio/departamento/${encodeURIComponent(deptId)}/municipios`, { method: 'GET' });
            const res = await r.json().catch(() => null);
            if (!r.ok || !res?.success) {
                setError(res?.message || 'No se pudieron cargar los municipios.');
                return;
            }
            setData(res);
        } catch {
            setError('Sin conexión: no se pudieron cargar los municipios.');
        }
    }, [deptId]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => router.on('success', event => {
        if (event.detail?.page?.component === 'Admin/TerritoryDivisiones') load();
    }), [load]);

    if (!data && error) {
        return (
            <div role="alert" className="px-5 py-5 text-center space-y-2">
                <p className="text-[14px] text-red-600">{error}</p>
                <button type="button" onClick={load} className="px-4 py-2 border border-[var(--color-line)] rounded-lg text-[13px] font-bold bg-white">Reintentar</button>
            </div>
        );
    }
    if (!data) {
        return <p className="px-5 py-5 text-[14px] text-[var(--color-ink-faint)]">Cargando municipios…</p>;
    }

    const filter = list => (q ? list.filter(m => matchesMunicipio(m, q)) : list);
    const groups = [
        ...data.provincias.map(p => ({ key: p.id, title: displayName(p.name), municipios: filter(p.municipios) })),
        { key: 'directos', title: data.provincias.length ? 'Sin provincia' : null, municipios: filter(data.municipios) },
    ].filter(g => g.municipios.length > 0);

    return (
        <div className="max-h-[420px] overflow-y-auto bg-gray-50 border-t border-gray-100" data-testid="municipios-departamento">
            {error && <p role="alert" className="px-5 py-2 text-[13px] text-red-600">{error}</p>}
            {groups.map(g => (
                <div key={g.key}>
                    {g.title && <p className="px-5 py-2 text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] border-b border-gray-100">{g.title}</p>}
                    <ul>{g.municipios.map(mun => <MunicipioRow key={mun.id} mun={mun} indent="pl-5" onEdit={onEdit} />)}</ul>
                </div>
            ))}
            {groups.length === 0 && <p className="px-5 py-4 text-[13px] text-[var(--color-ink-faint)]">Sin municipios</p>}
        </div>
    );
}

function OtroDepartamentoRow({ dept, open, onToggle, q, matches, onEdit }) {
    const nameMatches = q && normalize(dept.name).includes(q);
    return (
        <li className={`border-b border-[var(--color-line)] last:border-0 ${!dept.is_active ? 'bg-red-50/30' : ''}`} data-testid="otro-departamento">
            <div
                role="button"
                tabIndex={0}
                aria-expanded={open}
                onClick={onToggle}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); } }}
                className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 active:bg-gray-50 transition-colors cursor-pointer select-none"
            >
                <div className="flex-1 min-w-0">
                    <p className={`text-[15px] font-bold truncate ${dept.is_active ? 'text-[var(--color-ink)]' : 'text-red-400 line-through'}`}>{displayName(dept.name)}</p>
                    <p className="text-[12px] text-[var(--color-ink-soft)]">
                        {plural(dept.totalMunicipios, 'municipio', 'municipios')}
                        {dept.totalProvincias > 0 && ` · ${plural(dept.totalProvincias, 'provincia', 'provincias')}`}
                        {dept.code && <span className="ml-2 text-[var(--color-ink-faint)]">Cód. DANE {dept.code}</span>}
                        {!dept.is_active && <span className="ml-2"><InactiveTag /></span>}
                    </p>
                    {q && !nameMatches && matches.length > 0 && (
                        <p className="text-[12px] text-[var(--color-primary)] truncate">
                            Coincide: {matches.slice(0, MATCH_HINT_LIMIT).map(displayName).join(', ')}
                            {matches.length > MATCH_HINT_LIMIT && ` y ${matches.length - MATCH_HINT_LIMIT} más`}
                        </p>
                    )}
                </div>
                <EditButton onClick={() => onEdit({ item: dept, type: 'department' })} small />
                <ToggleButton id={dept.id} isActive={dept.is_active} small />
                <Chevron open={open} className="w-5 h-5 text-gray-400" />
            </div>
            {open && (
                <DepartamentoMunicipios
                    deptId={dept.id}
                    q={nameMatches ? '' : q}
                    onEdit={mun => onEdit({ item: mun, type: 'municipality' })}
                />
            )}
        </li>
    );
}

function OtrosDepartamentos({ departamentos, defaultOpen, onEdit }) {
    const [open, setOpen] = useState(defaultOpen);
    const [openDept, setOpenDept] = useState(null);
    const [search, setSearch] = useState('');
    const q = normalize(search);

    const totalMunicipios = departamentos.reduce((s, d) => s + d.totalMunicipios, 0);
    const rows = useMemo(() => departamentos.map(dept => ({
        dept,
        matches: q ? (dept.nombresMunicipios || []).filter(n => normalize(n).includes(q)) : [],
    })).filter(({ dept, matches }) => !q || normalize(dept.name).includes(q) || matches.length > 0), [departamentos, q]);

    if (departamentos.length === 0) return null;

    return (
        <section aria-label="Otros departamentos de Colombia" className="bg-white border border-[var(--color-line)] rounded-xl overflow-hidden">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-4 px-5 py-4 text-left hover:bg-gray-50 active:bg-gray-50 transition-colors"
            >
                <div className="w-11 h-11 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
                    <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div className="flex-1 min-w-0">
                    <h2 className="text-[16px] font-extrabold text-[var(--color-ink)]">Otros departamentos de Colombia ({departamentos.length})</h2>
                    <p className="text-[13px] text-[var(--color-ink-soft)]">{plural(totalMunicipios, 'municipio', 'municipios')} · {open ? 'toque para cerrar' : 'toque para ver, buscar o editar'}</p>
                </div>
                <Chevron open={open} />
            </button>

            {open && (
                <div className="border-t border-[var(--color-line)]">
                    <div className="p-4 bg-gray-50/60 border-b border-gray-100">
                        <SearchInput
                            value={search}
                            onChange={setSearch}
                            label="Buscar departamento o municipio"
                            placeholder="Buscar departamento o municipio..."
                        />
                    </div>
                    {rows.length > 0 ? (
                        <ul aria-label="Departamentos">
                            {rows.map(({ dept, matches }) => (
                                <OtroDepartamentoRow
                                    key={dept.id}
                                    dept={dept}
                                    open={openDept === dept.id}
                                    onToggle={() => setOpenDept(openDept === dept.id ? null : dept.id)}
                                    q={q}
                                    matches={matches}
                                    onEdit={onEdit}
                                />
                            ))}
                        </ul>
                    ) : (
                        <p className="px-5 py-6 text-center text-[14px] text-[var(--color-ink-faint)]">No hay departamentos ni municipios que coincidan con "{search}"</p>
                    )}
                </div>
            )}
        </section>
    );
}

export default function TerritoryDivisiones({ principal, departamentos, provincias: allProvincias, totalMunicipios }) {
    const [activeForm, setActiveForm] = useState(null);
    const [editing, setEditing] = useState(null); // { item, type }

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

    const allDepartamentos = principal ? [principal, ...departamentos] : departamentos;
    const provinciaIds = useMemo(() => new Set(allProvincias.map(p => p.id)), [allProvincias]);
    const provinciaOptions = useMemo(
        () => allProvincias.map(p => ({ ...p, name: displayName(p.name), dept: displayName(p.dept) })),
        [allProvincias],
    );
    // A municipio hanging straight from its departamento has no provincia to pick: the select would move it.
    const editProvincias = editing?.type === 'municipality' && provinciaIds.has(editing.item.parent_id) ? provinciaOptions : null;

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Divisiones territoriales" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'DIVISIONES TERRITORIALES' }]}>
            <Head title="Divisiones territoriales — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 pt-1 pb-3 lg:pb-4">
                <BackToAdmin />
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">DIVISIONES TERRITORIALES</h1>
                <p className="text-[12px] text-white/60 mt-0.5">
                    {plural(allDepartamentos.length, 'departamento', 'departamentos')} · {plural(allProvincias.length, 'provincia', 'provincias')} · {plural(totalMunicipios, 'municipio', 'municipios')}
                </p>
            </div>

            <div className="p-4 space-y-4 max-w-4xl">
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
                                {allDepartamentos.map(d => <option key={d.id} value={d.id}>{displayName(d.name)}</option>)}
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
                                {provinciaOptions.map(p => <option key={p.id} value={p.id}>{p.name} ({p.dept})</option>)}
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

                {principal && <PrincipalDepartamento dept={principal} onEdit={setEditing} />}

                <OtrosDepartamentos departamentos={departamentos} defaultOpen={!principal} onEdit={setEditing} />
            </div>

            {editing && (
                <EditModal
                    item={editing.item}
                    type={editing.type}
                    provincias={editProvincias}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}
