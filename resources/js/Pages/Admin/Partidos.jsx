import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Partidos({ partidos }) {
    const [showForm, setShowForm] = useState(false);
    const [editId, setEditId] = useState(null);
    const [search, setSearch] = useState('');

    const createForm = useForm({ name: '', acronym: '', color: '#003B71' });
    const editForm = useForm({ name: '', acronym: '', color: '' });

    function submitCreate(e) {
        e.preventDefault();
        createForm.post('/admin/partidos', {
            onSuccess: () => { createForm.reset(); setShowForm(false); },
        });
    }

    function startEdit(p) {
        setEditId(p.id);
        editForm.setData({ name: p.name, acronym: p.acronym || '', color: p.color || '#003B71' });
    }

    function submitEdit(e) {
        e.preventDefault();
        editForm.put(`/admin/partidos/${editId}`, {
            onSuccess: () => setEditId(null),
        });
    }

    function toggleStatus(p) {
        if (p.active) {
            if (!confirm(`¿Desactivar "${p.name}"?`)) return;
            router.delete(`/admin/partidos/${p.id}`);
        } else {
            router.post(`/admin/partidos/${p.id}/restore`);
        }
    }

    const filtered = partidos.filter(p =>
        !search || p.name.toLowerCase().includes(search.toLowerCase()) || (p.acronym || '').toLowerCase().includes(search.toLowerCase())
    );

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <AppLayout title="Partidos" breadcrumb={[{ label: 'ADMIN', href: '/admin/usuarios' }, { label: 'PARTIDOS' }]}>
            <Head title="Partidos — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between">
                <div>
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">PARTIDOS</h1>
                    <p className="text-[11px] text-white/40 mt-0.5">{partidos.length} partidos registrados</p>
                </div>
                <button
                    onClick={() => { setShowForm(!showForm); setEditId(null); }}
                    className="px-4 py-2.5 bg-white/15 text-white text-[14px] font-bold rounded-xl active:bg-white/25 border border-white/20"
                >
                    {showForm ? 'Cancelar' : '+ Crear'}
                </button>
            </div>

            <div className="p-4 space-y-3">
                {/* Form crear */}
                {showForm && (
                    <form onSubmit={submitCreate} className="bg-white border-2 border-[var(--color-primary)] rounded-xl p-5 space-y-4">
                        <h3 className="text-[16px] font-bold text-[var(--color-primary)]">Nuevo Partido</h3>
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                            <input className={inputCls} value={createForm.data.name} onChange={e => createForm.setData('name', e.target.value)} required placeholder="Ej: Centro Democrático" />
                            {createForm.errors.name && <p className="text-[12px] text-red-500 mt-1">{createForm.errors.name}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Sigla</label>
                                <input className={inputCls} value={createForm.data.acronym} onChange={e => createForm.setData('acronym', e.target.value)} placeholder="Ej: CD" />
                            </div>
                            <div>
                                <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Color</label>
                                <div className="flex items-center gap-2">
                                    <input type="color" value={createForm.data.color} onChange={e => createForm.setData('color', e.target.value)} className="w-12 h-12 rounded-lg border border-[var(--color-line)] cursor-pointer" />
                                    <input className={inputCls} value={createForm.data.color} onChange={e => createForm.setData('color', e.target.value)} placeholder="#003B71" />
                                </div>
                            </div>
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={createForm.processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                                {createForm.processing ? 'Creando...' : 'Crear Partido'}
                            </button>
                            <button type="button" onClick={() => setShowForm(false)} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                )}

                {/* Buscador */}
                <div className="relative">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Buscar partido..."
                        className="w-full pl-11 pr-4 py-3 border border-[var(--color-line)] rounded-xl text-[15px] focus:outline-none focus:border-[var(--color-primary)]"
                    />
                </div>

                {/* Lista */}
                {filtered.map(p => (
                    <div key={p.id} className={`bg-white border border-[var(--color-line)] rounded-xl overflow-hidden ${!p.active ? 'opacity-50' : ''}`}>
                        {editId === p.id ? (
                            /* Edit inline */
                            <form onSubmit={submitEdit} className="p-4 space-y-3">
                                <div>
                                    <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre</label>
                                    <input className={inputCls} value={editForm.data.name} onChange={e => editForm.setData('name', e.target.value)} required />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Sigla</label>
                                        <input className={inputCls} value={editForm.data.acronym} onChange={e => editForm.setData('acronym', e.target.value)} />
                                    </div>
                                    <div>
                                        <label className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Color</label>
                                        <div className="flex items-center gap-2">
                                            <input type="color" value={editForm.data.color || '#003B71'} onChange={e => editForm.setData('color', e.target.value)} className="w-10 h-10 rounded-lg border border-[var(--color-line)] cursor-pointer" />
                                            <input className={inputCls} value={editForm.data.color} onChange={e => editForm.setData('color', e.target.value)} />
                                        </div>
                                    </div>
                                </div>
                                <div className="flex gap-2">
                                    <button type="submit" disabled={editForm.processing} className="flex-1 px-4 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg disabled:opacity-50">Guardar</button>
                                    <button type="button" onClick={() => setEditId(null)} className="px-4 py-2.5 border border-[var(--color-line)] text-[13px] font-bold text-[var(--color-ink-soft)] rounded-lg">Cancelar</button>
                                </div>
                            </form>
                        ) : (
                            /* View row */
                            <div className="flex items-center gap-3 px-4 py-3.5">
                                <div
                                    className="w-10 h-10 rounded-xl flex items-center justify-center text-white text-[11px] font-bold flex-shrink-0"
                                    style={{ backgroundColor: p.color || '#6B7280' }}
                                >
                                    {(p.acronym || p.name.slice(0, 2)).toUpperCase().slice(0, 3)}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-[14px] font-bold text-[var(--color-ink)] truncate">{p.name}</p>
                                    <p className="text-[11px] text-[var(--color-ink-faint)]">
                                        {p.acronym && <span>{p.acronym} · </span>}
                                        {p.active ? 'Activo' : 'Inactivo'}
                                    </p>
                                </div>
                                <button
                                    onClick={() => startEdit(p)}
                                    className="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center active:bg-blue-100 flex-shrink-0"
                                    title="Editar"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                </button>
                                <button
                                    onClick={() => toggleStatus(p)}
                                    className={`w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 ${p.active ? 'bg-red-50 text-red-500 active:bg-red-100' : 'bg-emerald-50 text-emerald-600 active:bg-emerald-100'}`}
                                    title={p.active ? 'Desactivar' : 'Reactivar'}
                                >
                                    {p.active ? (
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                    ) : (
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    )}
                                </button>
                            </div>
                        )}
                    </div>
                ))}

                {filtered.length === 0 && (
                    <p className="text-center py-8 text-[14px] text-[var(--color-ink-faint)]">No se encontraron partidos</p>
                )}
            </div>
        </AppLayout>
    );
}
