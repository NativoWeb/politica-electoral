import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function RoleChip({ code, name }) {
    const isSuperadmin = code === 'R01_SUPERADMIN';
    const label = code === 'R09_CAMPO' ? 'Operador' : name;
    return (
        <span className={`inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold ${isSuperadmin ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'}`}>
            {label}
        </span>
    );
}

export default function Users({ users, roles }) {
    const [showForm, setShowForm] = useState(false);
    const [editId, setEditId] = useState(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '', email: '', password: '', role_id: '',
    });

    const editForm = useForm({ name: '', email: '', role_id: '', password: '' });

    function handleSubmit(e) {
        e.preventDefault();
        post('/admin/usuarios', {
            onSuccess: () => { reset(); setShowForm(false); },
        });
    }

    function startEdit(user) {
        setEditId(user.id);
        editForm.setData({
            name: user.name, email: user.email,
            role_id: roles.find(r => r.name === user.role || (r.code === 'R09_CAMPO' && user.role === 'Usuario de campo'))?.id || '',
            password: '',
        });
    }

    function submitEdit(e) {
        e.preventDefault();
        editForm.put(`/admin/usuarios/${editId}`, {
            onSuccess: () => setEditId(null),
        });
    }

    function handleToggle(id) {
        router.post(`/admin/usuarios/${id}/toggle`);
    }

    function handleDelete(user) {
        if (!confirm(`¿Eliminar al usuario "${user.name}"? Esta accion no se puede deshacer.`)) return;
        router.delete(`/admin/usuarios/${user.id}`);
    }

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Usuarios" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'USUARIOS' }]}>
            <Head title="Usuarios — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between">
                <div>
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">USUARIOS</h1>
                    <p className="text-[11px] text-white/40 mt-0.5">{users.length} cuenta{users.length !== 1 ? 's' : ''}</p>
                </div>
                <button
                    onClick={() => { setShowForm(!showForm); setEditId(null); }}
                    className="px-4 py-2.5 bg-white/15 text-white text-[14px] font-bold rounded-xl active:bg-white/25 border border-white/20"
                >
                    {showForm ? 'Cancelar' : '+ Crear'}
                </button>
            </div>

            <div className="p-4 space-y-3">
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
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Contraseña *</label>
                            <input type="password" className={inputCls} value={data.password} onChange={e => setData('password', e.target.value)} required placeholder="Minimo 8 caracteres" />
                            {errors.password && <p className="text-[12px] text-red-500 mt-1">{errors.password}</p>}
                        </div>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Rol</label>
                            <select className={inputCls} value={data.role_id} onChange={e => setData('role_id', e.target.value)}>
                                <option value="">Seleccionar rol...</option>
                                {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                        </div>
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
                {users.map(user => (
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
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Rol</label>
                                    <select className={inputCls} value={editForm.data.role_id} onChange={e => editForm.setData('role_id', e.target.value)}>
                                        <option value="">Sin rol</option>
                                        {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Nueva contraseña (dejar vacio para no cambiar)</label>
                                    <input type="password" className={inputCls} value={editForm.data.password} onChange={e => editForm.setData('password', e.target.value)} placeholder="Dejar vacio para mantener" />
                                    {editForm.errors.password && <p className="text-[12px] text-red-500 mt-1">{editForm.errors.password}</p>}
                                </div>
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
                                    <div className="flex items-center gap-2 mt-1">
                                        {user.role && <RoleChip code={user.roleCode} name={user.role} />}
                                        <span className={`text-[10px] font-bold ${user.isActive ? 'text-emerald-600' : 'text-red-500'}`}>
                                            {user.isActive ? 'ACTIVO' : 'INACTIVO'}
                                        </span>
                                    </div>
                                </div>
                                <div className="flex gap-1.5 flex-shrink-0">
                                    <button onClick={() => startEdit(user)} className="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center active:bg-blue-100" title="Editar">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                    <button onClick={() => handleToggle(user.id)} className={`w-9 h-9 rounded-lg flex items-center justify-center ${user.isActive ? 'bg-amber-50 text-amber-600 active:bg-amber-100' : 'bg-emerald-50 text-emerald-600 active:bg-emerald-100'}`} title={user.isActive ? 'Desactivar' : 'Activar'}>
                                        {user.isActive ? (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                        ) : (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        )}
                                    </button>
                                    <button onClick={() => handleDelete(user)} className="w-9 h-9 rounded-lg bg-red-50 text-red-500 flex items-center justify-center active:bg-red-100" title="Eliminar">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                ))}

                {/* Admin links */}
                <h3 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mt-6 mb-2 px-1">Administracion</h3>
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
            </div>
        </AppLayout>
    );
}
