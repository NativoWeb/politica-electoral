import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
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

function StatusBadge({ status }) {
    const map = {
        active: { label: 'ACTIVO', cls: 'bg-emerald-100 text-emerald-700' },
        inactive: { label: 'INACTIVO', cls: 'bg-gray-100 text-gray-500' },
        dissolved: { label: 'DISUELTO', cls: 'bg-red-100 text-red-600' },
        suspended: { label: 'SUSPENDIDO', cls: 'bg-amber-100 text-amber-700' },
    };
    const { label, cls } = map[status] ?? { label: status ?? '—', cls: 'bg-gray-100 text-gray-500' };
    return <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${cls}`}>{label}</span>;
}

/* ── Partido Form ── */
function PartidoForm({ item, onClose, baseUrl }) {
    const isEdit = !!item;
    const form = useForm({
        canonical_name: item?.canonical_name ?? '',
        acronym: item?.acronym ?? '',
        type: item?.type ?? 'party',
        color_hex: item?.color_hex ?? '',
        status: item?.status ?? 'active',
    });
    function submit(e) {
        e.preventDefault();
        if (isEdit) form.put(`${baseUrl}/${item.id}`, { onSuccess: onClose, preserveScroll: true });
        else form.post(baseUrl, { onSuccess: () => { form.reset(); onClose(); }, preserveScroll: true });
    }
    return (
        <form onSubmit={submit} className="space-y-4">
            <Field label="Nombre"><input className={inputCls} value={form.data.canonical_name} onChange={e => form.setData('canonical_name', e.target.value)} required /></Field>
            <div className="grid grid-cols-2 gap-4">
                <Field label="Sigla"><input className={inputCls} value={form.data.acronym} onChange={e => form.setData('acronym', e.target.value)} /></Field>
                <Field label="Color HEX"><input className={inputCls} value={form.data.color_hex} onChange={e => form.setData('color_hex', e.target.value)} placeholder="#003B71" /></Field>
            </div>
            <div className="grid grid-cols-2 gap-4">
                <Field label="Tipo">
                    <select className={inputCls} value={form.data.type} onChange={e => form.setData('type', e.target.value)}>
                        <option value="party">Partido</option>
                        <option value="movement">Movimiento</option>
                        <option value="coalition">Coalicion</option>
                        <option value="other">Otro</option>
                    </select>
                </Field>
                <Field label="Estado">
                    <select className={inputCls} value={form.data.status} onChange={e => form.setData('status', e.target.value)}>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                        <option value="dissolved">Disuelto</option>
                        <option value="suspended">Suspendido</option>
                    </select>
                </Field>
            </div>
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className={btnSecondary}>Cancelar</button>
                <button type="submit" disabled={form.processing} className={btnPrimary}>{isEdit ? 'Actualizar' : 'Crear'}</button>
            </div>
        </form>
    );
}

/* ── Simple Form (oficio / corporacion) ── */
function SimpleForm({ item, onClose, baseUrl, fields }) {
    const isEdit = !!item;
    const initial = {};
    fields.forEach(f => { initial[f.key] = item?.[f.key] ?? f.default ?? ''; });
    const form = useForm(initial);
    function submit(e) {
        e.preventDefault();
        if (isEdit) form.put(`${baseUrl}/${item.id}`, { onSuccess: onClose, preserveScroll: true });
        else form.post(baseUrl, { onSuccess: () => { form.reset(); onClose(); }, preserveScroll: true });
    }
    return (
        <form onSubmit={submit} className="space-y-4">
            {fields.map(f => (
                <Field key={f.key} label={f.label}>
                    {f.options ? (
                        <select className={inputCls} value={form.data[f.key]} onChange={e => form.setData(f.key, e.target.value)}>
                            {f.options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    ) : (
                        <input className={inputCls} value={form.data[f.key]} onChange={e => form.setData(f.key, e.target.value)} required={f.required} />
                    )}
                </Field>
            ))}
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className={btnSecondary}>Cancelar</button>
                <button type="submit" disabled={form.processing} className={btnPrimary}>{isEdit ? 'Actualizar' : 'Crear'}</button>
            </div>
        </form>
    );
}

/* ── MAIN ── */
export default function Catalogs({ partidos, oficios, corporaciones }) {
    const [modal, setModal] = useState(null); // { type, item? }

    return (
        <AppLayout
            title="Catalogos"
            breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'ADMIN', href: '/admin' }, { label: 'CATALOGOS' }]}
        >
            <Head title="Catalogos — Administracion" />

            <div className="bg-[var(--color-primary)] text-white px-6 py-5">
                <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">Administracion</p>
                <h1 className="text-[24px] font-extrabold">CATALOGOS</h1>
                <p className="text-[12px] text-white/50">Partidos politicos, cargos y corporaciones</p>
            </div>

            <div className="p-4 lg:p-6 space-y-8">

                {/* Partidos */}
                <SectionCard
                    title={`Organizaciones politicas (${partidos.length})`}
                    action={<button onClick={() => setModal({ type: 'partido' })} className={btnPrimary}>+ Nuevo partido</button>}
                >
                    <div className="overflow-x-auto max-h-[400px] overflow-y-auto">
                        <table className="w-full text-[13px]">
                            <thead className="sticky top-0 bg-white z-10">
                                <tr className="border-b-2 border-[var(--color-line-strong)]">
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Nombre</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Sigla</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Tipo</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Estado</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Color</th>
                                    <th className="text-right py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {partidos.map(p => (
                                    <tr key={p.id} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                        <td className="px-3 py-2.5 font-semibold text-[var(--color-ink)]">{p.canonical_name}</td>
                                        <td className="px-3 py-2.5 font-[var(--font-mono)] text-[11px] text-[var(--color-ink-soft)]">{p.acronym ?? '—'}</td>
                                        <td className="px-3 py-2.5 text-[var(--color-ink-soft)] capitalize">{p.type ?? '—'}</td>
                                        <td className="px-3 py-2.5"><StatusBadge status={p.status} /></td>
                                        <td className="px-3 py-2.5">
                                            {p.color_hex ? (
                                                <div className="flex items-center gap-2">
                                                    <span className="w-4 h-4 rounded border border-black/10" style={{ background: p.color_hex }} />
                                                    <code className="text-[10px] text-[var(--color-ink-faint)]">{p.color_hex}</code>
                                                </div>
                                            ) : '—'}
                                        </td>
                                        <td className="px-3 py-2.5 text-right">
                                            <button onClick={() => setModal({ type: 'partido', item: p })} className="text-[10px] text-amber-600 hover:underline font-semibold px-2 py-1">Editar</button>
                                            <button onClick={() => { if (confirm('Eliminar?')) router.delete(`/admin/catalogos/partidos/${p.id}`, { preserveScroll: true }); }} className="text-[10px] text-red-500 hover:underline font-semibold px-2 py-1">Eliminar</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                {/* Oficios */}
                <SectionCard
                    title={`Cargos / Oficios (${oficios.length})`}
                    action={<button onClick={() => setModal({ type: 'oficio' })} className={btnPrimary}>+ Nuevo cargo</button>}
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead>
                                <tr className="border-b-2 border-[var(--color-line-strong)]">
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Nombre</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Tipo</th>
                                    <th className="text-right py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {oficios.map(o => (
                                    <tr key={o.id} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                        <td className="px-3 py-2.5 font-semibold text-[var(--color-ink)]">{o.name}</td>
                                        <td className="px-3 py-2.5 text-[var(--color-ink-soft)] capitalize">{o.type ?? '—'}</td>
                                        <td className="px-3 py-2.5 text-right">
                                            <button onClick={() => setModal({ type: 'oficio', item: o })} className="text-[10px] text-amber-600 hover:underline font-semibold px-2 py-1">Editar</button>
                                            <button onClick={() => { if (confirm('Eliminar?')) router.delete(`/admin/catalogos/oficios/${o.id}`, { preserveScroll: true }); }} className="text-[10px] text-red-500 hover:underline font-semibold px-2 py-1">Eliminar</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                {/* Corporaciones */}
                <SectionCard
                    title={`Corporaciones (${corporaciones.length})`}
                    action={<button onClick={() => setModal({ type: 'corporacion' })} className={btnPrimary}>+ Nueva corporacion</button>}
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead>
                                <tr className="border-b-2 border-[var(--color-line-strong)]">
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Nombre</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Alcance</th>
                                    <th className="text-right py-2 px-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {corporaciones.map(c => (
                                    <tr key={c.id} className="border-b border-[var(--color-line)] last:border-0 hover:bg-blue-50/50 transition-colors">
                                        <td className="px-3 py-2.5 font-semibold text-[var(--color-ink)]">{c.name}</td>
                                        <td className="px-3 py-2.5 text-[var(--color-ink-soft)] capitalize">{c.scope ?? '—'}</td>
                                        <td className="px-3 py-2.5 text-right">
                                            <button onClick={() => setModal({ type: 'corporacion', item: c })} className="text-[10px] text-amber-600 hover:underline font-semibold px-2 py-1">Editar</button>
                                            <button onClick={() => { if (confirm('Eliminar?')) router.delete(`/admin/catalogos/corporaciones/${c.id}`, { preserveScroll: true }); }} className="text-[10px] text-red-500 hover:underline font-semibold px-2 py-1">Eliminar</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>
            </div>

            {/* Modals */}
            <Modal open={modal?.type === 'partido'} onClose={() => setModal(null)} title={modal?.item ? 'Editar partido' : 'Nuevo partido'}>
                <PartidoForm item={modal?.item} onClose={() => setModal(null)} baseUrl="/admin/catalogos/partidos" />
            </Modal>

            <Modal open={modal?.type === 'oficio'} onClose={() => setModal(null)} title={modal?.item ? 'Editar cargo' : 'Nuevo cargo'}>
                <SimpleForm item={modal?.item} onClose={() => setModal(null)} baseUrl="/admin/catalogos/oficios" fields={[
                    { key: 'name', label: 'Nombre', required: true },
                    { key: 'type', label: 'Tipo', options: [{ value: 'executive', label: 'Ejecutivo' }, { value: 'legislative', label: 'Legislativo' }, { value: 'other', label: 'Otro' }], default: 'other' },
                ]} />
            </Modal>

            <Modal open={modal?.type === 'corporacion'} onClose={() => setModal(null)} title={modal?.item ? 'Editar corporacion' : 'Nueva corporacion'}>
                <SimpleForm item={modal?.item} onClose={() => setModal(null)} baseUrl="/admin/catalogos/corporaciones" fields={[
                    { key: 'name', label: 'Nombre', required: true },
                    { key: 'scope', label: 'Alcance', options: [{ value: 'national', label: 'Nacional' }, { value: 'departmental', label: 'Departamental' }, { value: 'municipal', label: 'Municipal' }], default: 'municipal' },
                ]} />
            </Modal>
        </AppLayout>
    );
}
