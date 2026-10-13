import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import SectionCard from '@/Components/SectionCard';
import { useState } from 'react';

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
const btnPrimary = "px-4 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors";
const btnSecondary = "px-4 py-2 border border-[var(--color-line)] text-[12px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 transition-colors";

function Modal({ open, onClose, title, children }) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={onClose}>
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between px-6 py-4 border-b border-[var(--color-line)]">
                    <h3 className="text-[14px] font-bold text-[var(--color-ink)]">{title}</h3>
                    <button onClick={onClose} className="text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] text-lg">&times;</button>
                </div>
                <div className="p-6">{children}</div>
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <label className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">{label}</label>
            {children}
        </div>
    );
}

export default function Cargos({ cargos = [], cargoCounts = {} }) {
    const [modal, setModal] = useState(null); // { mode: 'create' | 'edit', item? }
    const [formName, setFormName] = useState('');
    const [formHighlighted, setFormHighlighted] = useState(false);
    const [formActive, setFormActive] = useState(true);
    const [saving, setSaving] = useState(false);

    const [fromId, setFromId] = useState('');
    const [toId, setToId] = useState('');
    const [graduating, setGraduating] = useState(false);

    function openCreate() {
        setFormName('');
        setFormHighlighted(false);
        setFormActive(true);
        setModal({ mode: 'create' });
    }

    function openEdit(item) {
        setFormName(item.name);
        setFormHighlighted(!!item.is_highlighted);
        setFormActive(item.is_active !== false);
        setModal({ mode: 'edit', item });
    }

    function submitForm(e) {
        e.preventDefault();
        if (saving) return;
        setSaving(true);

        if (modal.mode === 'create') {
            router.post('/admin/cargos', { name: formName }, {
                preserveScroll: true,
                onSuccess: () => { setSaving(false); setModal(null); },
                onError: () => setSaving(false),
            });
        } else {
            router.put(`/admin/cargos/${modal.item.id}`, {
                name: formName,
                is_highlighted: formHighlighted,
                is_active: formActive,
            }, {
                preserveScroll: true,
                onSuccess: () => { setSaving(false); setModal(null); },
                onError: () => setSaving(false),
            });
        }
    }

    function reorder(index, direction) {
        const ids = cargos.map(c => c.id);
        const targetIndex = index + direction;
        if (targetIndex < 0 || targetIndex >= ids.length) return;
        [ids[index], ids[targetIndex]] = [ids[targetIndex], ids[index]];
        router.post('/admin/cargos/reorder', { ids }, { preserveScroll: true });
    }

    function toggleActive(item) {
        router.put(`/admin/cargos/${item.id}`, {
            is_active: !item.is_active,
        }, { preserveScroll: true });
    }

    function handleGraduar() {
        if (!fromId || !toId) return;
        const from = cargos.find(c => c.id === fromId);
        const to = cargos.find(c => c.id === toId);
        const count = cargoCounts[from?.name] || 0;

        if (!confirm(`¿Mover ${count} líderes de "${from?.name}" a "${to?.name}"? Esta acción no se puede deshacer.`)) return;

        setGraduating(true);
        fetch('/admin/cargos/graduar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ from_id: fromId, to_id: toId }),
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    alert(`Se actualizaron ${res.affected} líderes`);
                    setFromId('');
                    setToId('');
                    router.reload();
                } else {
                    alert('Error al graduar');
                }
            })
            .catch(() => alert('Error de conexión'))
            .finally(() => setGraduating(false));
    }

    const cargosConLideres = cargos.filter(c => (cargoCounts[c.name] || 0) > 0);
    const cargosDestinoActivos = cargos.filter(c => c.is_active && c.id !== fromId);

    return (
        <AppLayout
            title="Cargos"
            breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'ADMIN', href: '/admin' }, { label: 'CARGOS' }]}
        >
            <Head title="Cargos — Administracion" />

            <div className="bg-[var(--color-primary)] text-white px-6 py-5">
                <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">Administracion &gt; Cargos</p>
                <h1 className="text-[24px] font-extrabold">CARGOS</h1>
                <p className="text-[12px] text-white/50">Gestion de categorias de cargo para lideres</p>
            </div>

            <div className="p-4 lg:p-6 space-y-8">

                {/* Lista de cargos */}
                <SectionCard
                    title={`Categorias de cargo (${cargos.length})`}
                    action={<button onClick={openCreate} className={btnPrimary}>+ Nuevo cargo</button>}
                >
                    <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
                        <table className="w-full text-[13px]">
                            <thead className="sticky top-0 bg-white z-10">
                                <tr className="border-b-2 border-[var(--color-line-strong)]">
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">#</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Nombre</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Lideres</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Estado</th>
                                    <th className="text-right py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cargos.map((cargo, i) => (
                                    <tr key={cargo.id} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                        <td className="px-3 py-2.5 text-[var(--color-ink-faint)] font-[var(--font-mono)] text-[11px]">{cargo.display_order}</td>
                                        <td className="px-3 py-2.5">
                                            <span className={cargo.is_highlighted ? 'font-bold text-[var(--color-ink)]' : 'font-semibold text-[var(--color-ink)]'}>
                                                {cargo.name}
                                            </span>
                                            {cargo.is_highlighted && (
                                                <span className="ml-2 px-1.5 py-0.5 bg-amber-100 text-amber-700 text-[9px] font-bold uppercase rounded">Destacado</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 font-[var(--font-mono)] text-[12px] text-[var(--color-ink-soft)]">
                                            {cargoCounts[cargo.name] || 0}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {cargo.is_active !== false ? (
                                                <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-700">ACTIVO</span>
                                            ) : (
                                                <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-500">INACTIVO</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right">
                                            <button onClick={() => openEdit(cargo)} className="text-[10px] text-amber-600 hover:underline font-semibold px-2 py-1">Editar</button>
                                            <button
                                                onClick={() => reorder(i, -1)}
                                                disabled={i === 0}
                                                className="text-[10px] text-[var(--color-primary)] hover:underline font-semibold px-1 py-1 disabled:opacity-30 disabled:no-underline"
                                                title="Subir"
                                            >
                                                &#9650;
                                            </button>
                                            <button
                                                onClick={() => reorder(i, 1)}
                                                disabled={i === cargos.length - 1}
                                                className="text-[10px] text-[var(--color-primary)] hover:underline font-semibold px-1 py-1 disabled:opacity-30 disabled:no-underline"
                                                title="Bajar"
                                            >
                                                &#9660;
                                            </button>
                                            <button
                                                onClick={() => toggleActive(cargo)}
                                                className={`text-[10px] font-semibold px-2 py-1 ${cargo.is_active !== false ? 'text-gray-500 hover:underline' : 'text-emerald-600 hover:underline'}`}
                                            >
                                                {cargo.is_active !== false ? 'Desactivar' : 'Activar'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {cargos.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-3 py-8 text-center text-[var(--color-ink-faint)] text-[14px]">No hay cargos registrados</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                {/* Graduar candidatos */}
                <SectionCard title="Graduar lideres">
                    <p className="text-[12px] text-[var(--color-ink-faint)] mb-4">
                        Mueve todos los lideres de un cargo a otro. El cargo origen se desactiva automaticamente.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                        <Field label="Cargo origen">
                            <select className={inputCls} value={fromId} onChange={e => { setFromId(e.target.value); if (e.target.value === toId) setToId(''); }}>
                                <option value="">Seleccionar...</option>
                                {cargosConLideres.map(c => (
                                    <option key={c.id} value={c.id}>{c.name} ({cargoCounts[c.name] || 0} lideres)</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Cargo destino">
                            <select className={inputCls} value={toId} onChange={e => setToId(e.target.value)} disabled={!fromId}>
                                <option value="">Seleccionar...</option>
                                {cargosDestinoActivos.map(c => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        </Field>
                        <div>
                            <button
                                onClick={handleGraduar}
                                disabled={!fromId || !toId || graduating}
                                className={`${btnPrimary} w-full disabled:opacity-50`}
                            >
                                {graduating ? 'Graduando...' : 'Graduar'}
                            </button>
                        </div>
                    </div>
                </SectionCard>
            </div>

            {/* Modal crear/editar */}
            <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.mode === 'edit' ? 'Editar cargo' : 'Nuevo cargo'}>
                <form onSubmit={submitForm} className="space-y-4">
                    <Field label="Nombre">
                        <input className={inputCls} value={formName} onChange={e => setFormName(e.target.value)} required placeholder="Nombre del cargo" />
                    </Field>
                    {modal?.mode === 'edit' && (
                        <>
                            <label className="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-[var(--color-line)] cursor-pointer hover:bg-amber-50 transition-colors">
                                <input type="checkbox" checked={formHighlighted} onChange={e => setFormHighlighted(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-amber-500" />
                                <div>
                                    <span className="text-[13px] font-bold text-[var(--color-ink)]">Destacado</span>
                                    <p className="text-[11px] text-[var(--color-ink-faint)]">Aparece primero y en negrita en los filtros</p>
                                </div>
                            </label>
                            <label className="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-[var(--color-line)] cursor-pointer hover:bg-gray-50 transition-colors">
                                <input type="checkbox" checked={formActive} onChange={e => setFormActive(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-emerald-500" />
                                <div>
                                    <span className="text-[13px] font-bold text-[var(--color-ink)]">Activo</span>
                                    <p className="text-[11px] text-[var(--color-ink-faint)]">Los cargos inactivos no aparecen en los filtros</p>
                                </div>
                            </label>
                        </>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setModal(null)} className={btnSecondary}>Cancelar</button>
                        <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Guardando...' : (modal?.mode === 'edit' ? 'Actualizar' : 'Crear')}</button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
