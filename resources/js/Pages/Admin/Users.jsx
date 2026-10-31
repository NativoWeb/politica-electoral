import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, useMemo, useRef } from 'react';
import { preselectedCampaign } from '@/lib/campaignAdmin';
import { saveErrorMessage, unshownErrors, withHiddenPermissions } from '@/lib/userAdmin';

function RoleChip({ code, name }) {
    const isSuperadmin = code === 'R01_SUPERADMIN';
    const label = code === 'R09_CAMPO' ? 'Operador' : name;
    return (
        <span className={`inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold ${isSuperadmin ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'}`}>
            {label}
        </span>
    );
}

function TerritoryBadges({ scope, municipios }) {
    if (!scope || scope.length === 0) return <span className="text-[10px] text-emerald-600 font-bold">TODOS</span>;
    const names = scope.map(id => municipios.find(m => m.id === id)?.name || '?').sort();
    return (
        <div className="flex flex-wrap gap-1 mt-1">
            {names.map(n => (
                <span key={n} className="inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[9px] font-bold">{n}</span>
            ))}
        </div>
    );
}

function MunicipioSelector({ selected, provincias, onChange }) {
    const [search, setSearch] = useState('');
    const [openProvs, setOpenProvs] = useState({});

    const toggleProv = (provId) => setOpenProvs(prev => ({ ...prev, [provId]: !prev[provId] }));

    const filteredProvincias = useMemo(() => {
        if (!search) return provincias;
        const q = search.toLowerCase();
        return provincias.map(p => ({
            ...p,
            municipios: p.municipios.filter(m => m.name.toLowerCase().includes(q)),
        })).filter(p => p.municipios.length > 0);
    }, [provincias, search]);

    function toggleMun(id) {
        if (selected.includes(id)) {
            onChange(selected.filter(s => s !== id));
        } else {
            onChange([...selected, id]);
        }
    }

    function toggleAllProv(prov) {
        const munIds = prov.municipios.map(m => m.id);
        const allSelected = munIds.every(id => selected.includes(id));
        if (allSelected) {
            onChange(selected.filter(s => !munIds.includes(s)));
        } else {
            onChange([...new Set([...selected, ...munIds])]);
        }
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-1">
                <label className="block text-[12px] font-bold text-[var(--color-ink-faint)]">
                    Municipios permitidos
                </label>
                {selected.length > 0 && (
                    <button type="button" onClick={() => onChange([])} className="text-[10px] text-red-500 font-bold hover:text-red-700">
                        Quitar todos
                    </button>
                )}
            </div>
            <p className="text-[11px] text-[var(--color-ink-faint)] mb-2">
                {selected.length === 0
                    ? 'Sin restricción — ve todos los municipios'
                    : `${selected.length} municipio${selected.length > 1 ? 's' : ''} seleccionado${selected.length > 1 ? 's' : ''}`}
            </p>
            <input
                type="text"
                className="w-full px-3 py-2 border border-[var(--color-line)] rounded-lg text-[13px] focus:outline-none focus:border-[var(--color-primary)] mb-2"
                placeholder="Buscar municipio..."
                value={search}
                onChange={e => setSearch(e.target.value)}
            />
            <div className="max-h-[280px] overflow-y-auto border border-[var(--color-line)] rounded-lg">
                {filteredProvincias.map(prov => {
                    const munIds = prov.municipios.map(m => m.id);
                    const selectedCount = munIds.filter(id => selected.includes(id)).length;
                    const allSelected = munIds.length > 0 && selectedCount === munIds.length;
                    const someSelected = selectedCount > 0 && !allSelected;
                    const isOpen = openProvs[prov.id] || !!search;

                    return (
                        <div key={prov.id} className="border-b border-[var(--color-line)] last:border-0">
                            <div
                                className="flex items-center gap-2 px-3 py-2.5 bg-gray-50 cursor-pointer hover:bg-gray-100 select-none"
                                onClick={() => toggleProv(prov.id)}
                            >
                                <svg className={`w-3.5 h-3.5 text-gray-400 transition-transform ${isOpen ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={el => { if (el) el.indeterminate = someSelected; }}
                                    onChange={(e) => { e.stopPropagation(); toggleAllProv(prov); }}
                                    onClick={e => e.stopPropagation()}
                                    className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span className="text-[13px] font-bold text-[var(--color-ink)]">{prov.name}</span>
                                {selectedCount > 0 && (
                                    <span className="ml-auto text-[10px] font-bold text-[var(--color-primary)] bg-blue-50 px-1.5 py-0.5 rounded">
                                        {selectedCount}/{munIds.length}
                                    </span>
                                )}
                            </div>
                            {isOpen && (
                                <div className="pl-6">
                                    {prov.municipios.map(m => (
                                        <label key={m.id} className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(m.id)}
                                                onChange={() => toggleMun(m.id)}
                                                className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                            />
                                            <span className="text-[13px] text-[var(--color-ink)]">{m.name}</span>
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
                {filteredProvincias.length === 0 && (
                    <p className="px-3 py-4 text-[12px] text-[var(--color-ink-faint)] text-center italic">No se encontraron municipios</p>
                )}
            </div>
        </div>
    );
}

// Superadmin picks the campaign of each user; everyone else creates users in their own campaign.
function CampaignField({ isSuperadmin, campaigns, value, onChange, ownCampaign, error, labelCls, inputCls, id }) {
    if (isSuperadmin && campaigns.length > 0) {
        const names = Object.fromEntries(campaigns.map(c => [c.id, c.name]));
        return (
            <div>
                <label htmlFor={id} className={labelCls}>Campaña</label>
                <select id={id} className={inputCls} value={value} onChange={e => onChange(e.target.value)}>
                    {campaigns.map(c => (
                        <option key={c.id} value={c.id}>
                            {c.parent_id && names[c.parent_id] ? `${c.name} (subcampaña de ${names[c.parent_id]})` : c.name}
                        </option>
                    ))}
                </select>
                {error && <p className="text-[12px] text-red-500 mt-1">{error}</p>}
            </div>
        );
    }
    if (!ownCampaign?.name) return null;
    return (
        <div>
            <span className={labelCls}>Campaña</span>
            <p className="px-3 py-3 bg-[var(--color-bg)] border border-[var(--color-line)] rounded-lg text-[15px] text-[var(--color-ink)]">{ownCampaign.name}</p>
        </div>
    );
}

function FormAlert({ messages }) {
    const list = messages.filter(Boolean);
    if (list.length === 0) return null;
    return (
        <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700 space-y-1">
            {list.map(m => <p key={m}>{m}</p>)}
        </div>
    );
}

function ConfirmDialog({ title, message, confirmLabel, danger, onConfirm, onCancel }) {
    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onCancel} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
                <div role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message" onKeyDown={e => { if (e.key === 'Escape') onCancel(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[380px] overflow-hidden">
                    <div className="px-6 pt-6 pb-4 text-center">
                        <h3 id="confirm-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">{title}</h3>
                        <p id="confirm-message" className="text-[15px] text-[var(--color-ink-soft)] mt-2 leading-relaxed">{message}</p>
                    </div>
                    <div className="px-6 pb-6 flex gap-3">
                        <button type="button" autoFocus onClick={onCancel} className="flex-1 px-4 py-3 border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)] rounded-xl active:bg-gray-100">Cancelar</button>
                        <button type="button" onClick={onConfirm} className={`flex-1 px-4 py-3 text-white text-[15px] font-bold rounded-xl ${danger ? 'bg-amber-600 active:bg-amber-700' : 'bg-emerald-600 active:bg-emerald-700'}`}>{confirmLabel}</button>
                    </div>
                </div>
            </div>
        </>
    );
}

function PermissionSelector({ selected, availablePermissions, onChange }) {
    const visibleKeys = useMemo(() => availablePermissions.map(p => p.key), [availablePermissions]);
    const shown = selected.filter(k => visibleKeys.includes(k));
    const set = next => onChange(withHiddenPermissions(selected, visibleKeys, next));
    const grouped = useMemo(() => {
        const map = {};
        availablePermissions.forEach(p => {
            if (!map[p.group]) map[p.group] = [];
            map[p.group].push(p);
        });
        return map;
    }, [availablePermissions]);

    function toggle(key) {
        set(shown.includes(key) ? shown.filter(k => k !== key) : [...shown, key]);
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-1">
                <label className="block text-[12px] font-bold text-[var(--color-ink-faint)]">Permisos</label>
                <div className="flex gap-2">
                    <button type="button" onClick={() => set(visibleKeys)} className="text-[10px] text-blue-500 font-bold hover:text-blue-700">Todos</button>
                    <button type="button" onClick={() => set([])} className="text-[10px] text-red-500 font-bold hover:text-red-700">Ninguno</button>
                </div>
            </div>
            <div className="border border-[var(--color-line)] rounded-lg overflow-hidden">
                {Object.entries(grouped).map(([group, perms]) => (
                    <div key={group}>
                        <div className="px-3 py-1.5 bg-gray-50 text-[10px] font-bold tracking-wider text-gray-400 uppercase border-b border-[var(--color-line)]">
                            {group}
                        </div>
                        {perms.map(p => (
                            <label key={p.key} className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-[var(--color-line)] last:border-0">
                                <input
                                    type="checkbox"
                                    checked={selected.includes(p.key)}
                                    onChange={() => toggle(p.key)}
                                    className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span className="text-[13px] text-[var(--color-ink)]">{p.label}</span>
                            </label>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

const CREATE_FIELDS = ['name', 'email', 'campaign_id'];
const EDIT_FIELDS = ['name', 'email', 'password', 'campaign_id'];

export default function Users({ users, roles, municipios, provincias, availablePermissions, permissionLabels = {}, filters, campaigns = [] }) {
    const page = usePage();
    const authUser = page.props.auth?.user;
    const authPerms = authUser?.permissions || [];
    const canAudit = authPerms.includes('*') || authPerms.includes('audit.view');
    const isSuperadmin = !!authUser?.isSuperadmin;
    const ownCampaign = authUser?.campaign;
    const canEditElectoral = authUser?.canEditElectoral !== false;
    // The server refuses to touch a user holding a permission the actor cannot grant (a superadmin can grant them all).
    const grantableKeys = useMemo(() => availablePermissions.map(p => p.key), [availablePermissions]);
    const isLocked = user => !isSuperadmin && (user.permissions || []).some(k => !grantableKeys.includes(k));
    const defaultCampaignId = ownCampaign?.id || campaigns[0]?.id || '';
    const preselected = isSuperadmin ? preselectedCampaign(page.url, campaigns) : null;
    const campaignField = isSuperadmin ? { campaign_id: preselected || defaultCampaignId } : {};
    const [showForm, setShowForm] = useState(!!preselected);
    const [editId, setEditId] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');
    const [createError, setCreateError] = useState('');
    const [editError, setEditError] = useState('');
    const [listError, setListError] = useState('');
    const [toggleTarget, setToggleTarget] = useState(null);
    const searchTimeout = useRef(null);

    function handleSearch(val) {
        setSearch(val);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get('/admin/usuarios', val ? { search: val } : {}, { preserveState: true, replace: true });
        }, 400);
    }

    const userList = users?.data || users;
    const pagination = users?.links ? users : null;

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '', email: '', role_id: '', permissions: [], territory_scope: [], ...campaignField,
    });

    const editForm = useForm({ name: '', email: '', role_id: '', password: '', permissions: [], territory_scope: [], ...campaignField });

    const selectedRoleCode = roles.find(r => r.id === data.role_id)?.code;
    const editRoleCode = roles.find(r => r.id === editForm.data.role_id)?.code;

    const failedSave = setMessage => response => {
        setMessage(saveErrorMessage(response));
        return false;
    };

    function handleSubmit(e) {
        e.preventDefault();
        setCreateError('');
        post('/admin/usuarios', {
            onSuccess: () => { reset(); setShowForm(false); },
            onHttpException: failedSave(setCreateError),
        });
    }

    function startEdit(user) {
        setEditId(user.id);
        setEditError('');
        editForm.setData({
            name: user.name, email: user.email,
            role_id: roles.find(r => r.name === user.role || (r.code === 'R09_CAMPO' && user.role === 'Usuario de campo'))?.id || '',
            password: '',
            permissions: user.permissions || [],
            territory_scope: user.territoryScope || [],
            ...(isSuperadmin ? { campaign_id: user.campaignId || defaultCampaignId } : {}),
        });
    }

    function submitEdit(e) {
        e.preventDefault();
        setEditError('');
        editForm.put(`/admin/usuarios/${editId}`, {
            onSuccess: () => setEditId(null),
            onHttpException: failedSave(setEditError),
        });
    }

    const listActionOptions = {
        preserveScroll: true,
        onError: errors => setListError(Object.values(errors)[0] || 'No se pudo hacer el cambio.'),
        onHttpException: failedSave(setListError),
    };

    function confirmToggle() {
        const user = toggleTarget;
        setToggleTarget(null);
        setListError('');
        router.post(`/admin/usuarios/${user.id}/toggle`, {}, listActionOptions);
    }

    function handleDelete(user) {
        if (!confirm(`¿Eliminar al usuario "${user.name}"? Esta accion no se puede deshacer.`)) return;
        setListError('');
        router.delete(`/admin/usuarios/${user.id}`, listActionOptions);
    }

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Usuarios" breadcrumb={[{ label: 'ADMIN', href: canEditElectoral ? '/admin/territorio' : '/admin/usuarios' }, { label: 'USUARIOS' }]}>
            <Head title="Usuarios — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between">
                <div>
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">USUARIOS</h1>
                    <p className="text-[11px] text-white/40 mt-0.5">{pagination ? `${pagination.total} cuenta${pagination.total !== 1 ? 's' : ''}` : ''}</p>
                </div>
                <button
                    onClick={() => { setShowForm(!showForm); setEditId(null); }}
                    className="px-4 py-2.5 bg-white/15 text-white text-[14px] font-bold rounded-xl active:bg-white/25 border border-white/20"
                >
                    {showForm ? 'Cancelar' : '+ Crear'}
                </button>
            </div>

            <div className="p-4 space-y-3">
                {/* Search */}
                <div className="relative">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><circle cx="11" cy="11" r="8" /><path strokeLinecap="round" d="m21 21-4.35-4.35" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={e => handleSearch(e.target.value)}
                        placeholder="Buscar por nombre o correo..."
                        className="w-full pl-10 pr-4 py-3 border border-[var(--color-line)] rounded-xl text-[14px] focus:outline-none focus:border-[var(--color-primary)]"
                    />
                </div>

                <FormAlert messages={[listError]} />

                {/* Create form */}
                {showForm && (
                    <form onSubmit={handleSubmit} className="bg-white border-2 border-[var(--color-primary)] rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-[var(--color-primary)]">Nuevo Usuario</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                            <input className={inputCls} value={data.name} onChange={e => setData('name', e.target.value)} required placeholder="Nombre completo" />
                            {errors.name && <p className="text-[12px] text-red-500 mt-1">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Correo *</label>
                            <input type="email" className={inputCls} value={data.email} onChange={e => setData('email', e.target.value)} required placeholder="correo@ejemplo.com" />
                            {errors.email && <p className="text-[12px] text-red-500 mt-1">{errors.email}</p>}
                        </div>
                        <p className="text-[11px] text-amber-600 bg-amber-50 px-3 py-2 rounded-lg">
                            Se generará una contraseña aleatoria y se enviará al correo del usuario.
                        </p>
                        <CampaignField
                            id="new-user-campaign"
                            isSuperadmin={isSuperadmin}
                            campaigns={campaigns}
                            value={data.campaign_id}
                            onChange={val => setData('campaign_id', val)}
                            ownCampaign={ownCampaign}
                            error={errors.campaign_id}
                            labelCls="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1"
                            inputCls={inputCls}
                        />
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Rol</label>
                            <select className={inputCls} value={data.role_id} onChange={e => setData('role_id', e.target.value)}>
                                <option value="">Seleccionar rol...</option>
                                {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                        </div>
                        {selectedRoleCode !== 'R01_SUPERADMIN' && (
                            <PermissionSelector
                                selected={data.permissions}
                                availablePermissions={availablePermissions}
                                onChange={val => setData('permissions', val)}
                            />
                        )}
                        {selectedRoleCode === 'R09_CAMPO' && (
                            <MunicipioSelector
                                selected={data.territory_scope}
                                provincias={provincias}
                                onChange={val => setData('territory_scope', val)}
                            />
                        )}
                        <FormAlert messages={[createError, ...unshownErrors(errors, CREATE_FIELDS)]} />
                        <div className="flex gap-3">
                            <button type="submit" disabled={processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {processing ? 'Creando...' : 'Crear Usuario'}
                            </button>
                            <button type="button" onClick={() => { setShowForm(false); reset(); }} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Users list */}
                {userList.map(user => (
                    <div key={user.id} className={`bg-white border border-[var(--color-line)] rounded-xl overflow-hidden ${!user.isActive ? 'opacity-50' : ''}`}>
                        {editId === user.id ? (
                            <form onSubmit={submitEdit} className="p-4 space-y-3">
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre</label>
                                    <input className={inputCls} value={editForm.data.name} onChange={e => editForm.setData('name', e.target.value)} required />
                                    {editForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{editForm.errors.name}</p>}
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Correo</label>
                                    <input type="email" className={inputCls} value={editForm.data.email} onChange={e => editForm.setData('email', e.target.value)} required />
                                    {editForm.errors.email && <p className="text-[12px] text-red-500 mt-1">{editForm.errors.email}</p>}
                                </div>
                                <CampaignField
                                    id={`edit-user-campaign-${user.id}`}
                                    isSuperadmin={isSuperadmin}
                                    campaigns={campaigns}
                                    value={editForm.data.campaign_id}
                                    onChange={val => editForm.setData('campaign_id', val)}
                                    ownCampaign={ownCampaign}
                                    error={editForm.errors.campaign_id}
                                    labelCls="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1"
                                    inputCls={inputCls}
                                />
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Rol</label>
                                    <select className={inputCls} value={editForm.data.role_id} onChange={e => editForm.setData('role_id', e.target.value)}>
                                        <option value="">Sin rol</option>
                                        {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                                    </select>
                                </div>
                                {editRoleCode !== 'R01_SUPERADMIN' && (
                                    <PermissionSelector
                                        selected={editForm.data.permissions}
                                        availablePermissions={availablePermissions}
                                        onChange={val => editForm.setData('permissions', val)}
                                    />
                                )}
                                {editRoleCode === 'R09_CAMPO' && (
                                    <MunicipioSelector
                                        selected={editForm.data.territory_scope}
                                        provincias={provincias}
                                        onChange={val => editForm.setData('territory_scope', val)}
                                    />
                                )}
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Nueva contraseña (dejar vacio para no cambiar)</label>
                                    <input type="password" className={inputCls} value={editForm.data.password} onChange={e => editForm.setData('password', e.target.value)} placeholder="Dejar vacio para mantener" />
                                    {editForm.errors.password && <p className="text-[12px] text-red-500 mt-1">{editForm.errors.password}</p>}
                                </div>
                                <FormAlert messages={[editError, ...unshownErrors(editForm.errors, EDIT_FIELDS)]} />
                                <div className="flex gap-2">
                                    <button type="submit" disabled={editForm.processing} className="flex-1 px-4 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg disabled:opacity-50">Guardar</button>
                                    <button type="button" onClick={() => setEditId(null)} className="px-4 py-2.5 border border-[var(--color-line)] text-[13px] font-bold text-[var(--color-ink-soft)] rounded-lg">Cancelar</button>
                                </div>
                            </form>
                        ) : (
                            <div className="flex items-center gap-3 px-4 py-3.5">
                                <div className="w-10 h-10 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white text-[12px] font-bold flex-shrink-0">
                                    {user.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-[14px] font-bold text-[var(--color-ink)] truncate">{user.name}</p>
                                    <p className="text-[11px] text-[var(--color-ink-faint)] truncate">{user.email}</p>
                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                        {user.role && <RoleChip code={user.roleCode} name={user.role} />}
                                        {isSuperadmin && user.campaignName && (
                                            <span className="inline-block px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-[var(--color-bg)] border border-[var(--color-line)] text-[var(--color-ink-soft)]">
                                                Campaña: {user.campaignName}
                                            </span>
                                        )}
                                        <span className={`text-[10px] font-bold ${user.isActive ? 'text-emerald-600' : 'text-red-500'}`}>
                                            {user.isActive ? 'ACTIVO' : 'INACTIVO'}
                                        </span>
                                    </div>
                                    {user.permissions && user.permissions.length > 0 && user.roleCode !== 'R01_SUPERADMIN' && (
                                        <div className="flex flex-wrap gap-1 mt-1">
                                            {user.permissions.map(p => (
                                                <span key={p} className="inline-block px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 text-[9px] font-bold">
                                                    {permissionLabels[p] || p}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                    {user.roleCode === 'R09_CAMPO' && (
                                        <TerritoryBadges scope={user.territoryScope} municipios={municipios} />
                                    )}
                                    {isLocked(user) && (
                                        <p className="text-[13px] text-[var(--color-ink-soft)] mt-2 leading-snug">
                                            No puedes modificar a este usuario porque tiene permisos que tú no tienes.
                                        </p>
                                    )}
                                </div>
                                {!isLocked(user) && (
                                <div className="flex gap-1.5 flex-shrink-0">
                                    <button onClick={() => startEdit(user)} className="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center active:bg-blue-100" title="Editar">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                    {user.id !== authUser?.id && (
                                    <button onClick={() => setToggleTarget(user)} className={`w-9 h-9 rounded-lg flex items-center justify-center ${user.isActive ? 'bg-amber-50 text-amber-600 active:bg-amber-100' : 'bg-emerald-50 text-emerald-600 active:bg-emerald-100'}`} title={user.isActive ? 'Desactivar' : 'Activar'} aria-label={`${user.isActive ? 'Desactivar' : 'Activar'} a ${user.name}`}>
                                        {user.isActive ? (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                        ) : (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        )}
                                    </button>
                                    )}
                                    <button onClick={() => handleDelete(user)} className="w-9 h-9 rounded-lg bg-red-50 text-red-500 flex items-center justify-center active:bg-red-100" title="Eliminar">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                                )}
                            </div>
                        )}
                    </div>
                ))}

                {/* Pagination */}
                {pagination && pagination.last_page > 1 && (
                    <div className="flex items-center justify-between pt-2">
                        <p className="text-[12px] text-[var(--color-ink-faint)]">
                            {pagination.from}–{pagination.to} de {pagination.total}
                        </p>
                        <div className="flex gap-1">
                            {pagination.links.map((link, i) => {
                                if (!link.url) return (
                                    <span key={i} className="px-3 py-1.5 text-[12px] text-gray-300 font-bold" dangerouslySetInnerHTML={{ __html: link.label }} />
                                );
                                return (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        preserveState
                                        className={`px-3 py-1.5 text-[12px] font-bold rounded-lg transition-colors ${
                                            link.active
                                                ? 'bg-[var(--color-primary)] text-white'
                                                : 'bg-white border border-[var(--color-line)] text-[var(--color-ink-soft)] hover:bg-gray-50'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Admin links */}
                {(canEditElectoral || canAudit || isSuperadmin) && (
                    <h3 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mt-6 mb-2 px-1">Administracion</h3>
                )}
                {isSuperadmin && (
                    <Link
                        href="/admin/campanas"
                        className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-xl hover:bg-gray-50 active:bg-gray-50 transition-colors"
                    >
                        <div className="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 21V4m0 0h11l-1.5 4L15 12H4" /></svg>
                        </div>
                        <div className="flex-1">
                            <p className="text-[14px] font-bold text-[var(--color-ink)]">Campañas</p>
                            <p className="text-[12px] text-[var(--color-ink-faint)]">Crear, editar y desactivar campañas</p>
                        </div>
                        <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </Link>
                )}
                {canEditElectoral && (<>
                <Link
                    href="/admin/territorio"
                    className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-lg hover:bg-gray-50 transition-colors"
                >
                    <div className="w-10 h-10 rounded-xl bg-violet-100 text-violet-700 flex items-center justify-center flex-shrink-0">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                    </div>
                    <div className="flex-1">
                        <p className="text-[14px] font-bold text-[var(--color-ink)]">Territorio</p>
                        <p className="text-[12px] text-[var(--color-ink-faint)]">Departamentos, provincias y municipios</p>
                    </div>
                    <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                </Link>
                <Link
                    href="/admin/partidos"
                    className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-lg hover:bg-gray-50 transition-colors"
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
                </>)}
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

            {toggleTarget && (
                <ConfirmDialog
                    title={toggleTarget.isActive ? `¿Desactivar a ${toggleTarget.name}?` : `¿Activar a ${toggleTarget.name}?`}
                    message={toggleTarget.isActive
                        ? 'No podrá entrar hasta que lo actives de nuevo. No se borra ningún dato.'
                        : 'Podrá volver a entrar con su correo y su contraseña.'}
                    confirmLabel={toggleTarget.isActive ? 'Sí, desactivar' : 'Sí, activar'}
                    danger={toggleTarget.isActive}
                    onConfirm={confirmToggle}
                    onCancel={() => setToggleTarget(null)}
                />
            )}
        </AppLayout>
    );
}
