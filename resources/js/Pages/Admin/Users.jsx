import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function Badge({ active }) {
    return active ? (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block" />
            ACTIVO
        </span>
    ) : (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-600">
            <span className="w-1.5 h-1.5 rounded-full bg-red-400 inline-block" />
            INACTIVO
        </span>
    );
}

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

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role_id: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        post('/admin/usuarios', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    }

    function handleToggle(id) {
        router.post(`/admin/usuarios/${id}/toggle`);
    }

    return (
        <AppLayout
            title="Usuarios"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'ADMIN', href: '/admin' },
                { label: 'USUARIOS' },
            ]}
        >
            <Head title="Usuarios — Administración" />

            {/* Page header */}
            <div className="bg-white border-b border-[var(--color-line)] px-6 py-5 flex items-center justify-between">
                <div>
                    <h1 className="text-[18px] font-extrabold text-[var(--color-ink)] font-[var(--font-heading)]">Usuarios</h1>
                    <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">{users.length} cuenta{users.length !== 1 ? 's' : ''} registrada{users.length !== 1 ? 's' : ''}</p>
                </div>
                <button
                    onClick={() => setShowForm(!showForm)}
                    className="px-4 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded hover:bg-[var(--color-primary-dark)] transition-colors"
                >
                    {showForm ? 'Cancelar' : '+ Crear usuario'}
                </button>
            </div>

            <div className="p-4 lg:p-6 space-y-6">

                {/* Inline create form */}
                {showForm && (
                    <div className="bg-white border border-[var(--color-primary)] rounded-lg p-6 shadow-sm">
                        <h2 className="text-[13px] font-bold uppercase tracking-widest text-[var(--color-primary)] mb-4">Nuevo usuario</h2>
                        <form onSubmit={handleSubmit} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wider mb-1">Nombre</label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={e => setData('name', e.target.value)}
                                    placeholder="Nombre completo"
                                    className="w-full px-3 py-2 border border-[var(--color-line)] rounded text-[13px] focus:outline-none focus:border-[var(--color-primary)]"
                                />
                                {errors.name && <p className="text-[11px] text-red-500 mt-1">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wider mb-1">Correo</label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="correo@ejemplo.com"
                                    className="w-full px-3 py-2 border border-[var(--color-line)] rounded text-[13px] focus:outline-none focus:border-[var(--color-primary)]"
                                />
                                {errors.email && <p className="text-[11px] text-red-500 mt-1">{errors.email}</p>}
                            </div>

                            <div>
                                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wider mb-1">Contraseña</label>
                                <input
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    placeholder="Mínimo 8 caracteres"
                                    className="w-full px-3 py-2 border border-[var(--color-line)] rounded text-[13px] focus:outline-none focus:border-[var(--color-primary)]"
                                />
                                {errors.password && <p className="text-[11px] text-red-500 mt-1">{errors.password}</p>}
                            </div>

                            <div>
                                <label className="block text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wider mb-1">Rol</label>
                                <select
                                    value={data.role_id}
                                    onChange={e => setData('role_id', e.target.value)}
                                    className="w-full px-3 py-2 border border-[var(--color-line)] rounded text-[13px] focus:outline-none focus:border-[var(--color-primary)] bg-white"
                                >
                                    <option value="">Seleccionar rol...</option>
                                    {roles.map(r => (
                                        <option key={r.id} value={r.id}>{r.name}</option>
                                    ))}
                                </select>
                                {errors.role_id && <p className="text-[11px] text-red-500 mt-1">{errors.role_id}</p>}
                            </div>

                            <div className="sm:col-span-2 lg:col-span-4 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => { setShowForm(false); reset(); }}
                                    className="px-4 py-2 text-[12px] font-semibold text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] transition-colors"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="px-5 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded hover:bg-[var(--color-primary-dark)] transition-colors disabled:opacity-50"
                                >
                                    {processing ? 'Guardando...' : 'Crear usuario'}
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Users table */}
                <div className="bg-white border border-[var(--color-line)] rounded-lg overflow-hidden">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="bg-gray-50 border-b border-[var(--color-line)]">
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Nombre</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Correo</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Rol</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Estado</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Creado</th>
                                <th className="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-5 py-10 text-center text-[12px] text-[var(--color-ink-faint)]">
                                        No hay usuarios registrados.
                                    </td>
                                </tr>
                            )}
                            {users.map((user) => (
                                <tr key={user.id} className="border-b border-[var(--color-line)] last:border-b-0 hover:bg-gray-50 transition-colors">
                                    <td className="px-5 py-3.5 font-semibold text-[var(--color-ink)]">{user.name}</td>
                                    <td className="px-5 py-3.5 text-[var(--color-ink-soft)] font-[var(--font-mono)] text-[12px]">{user.email}</td>
                                    <td className="px-5 py-3.5">
                                        {user.role ? (
                                            <RoleChip code={user.roleCode} name={user.role} />
                                        ) : (
                                            <span className="text-[var(--color-ink-faint)] text-[11px]">Sin rol</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-3.5">
                                        <Badge active={user.isActive} />
                                    </td>
                                    <td className="px-5 py-3.5 text-[var(--color-ink-faint)] font-[var(--font-mono)] text-[11px]">{user.createdAt}</td>
                                    <td className="px-5 py-3.5 text-right">
                                        <button
                                            onClick={() => handleToggle(user.id)}
                                            className={`text-[11px] font-semibold px-3 py-1 rounded border transition-colors ${
                                                user.isActive
                                                    ? 'border-red-200 text-red-500 hover:bg-red-50'
                                                    : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50'
                                            }`}
                                        >
                                            {user.isActive ? 'Desactivar' : 'Activar'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
