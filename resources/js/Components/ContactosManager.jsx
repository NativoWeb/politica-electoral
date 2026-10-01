import { useState } from 'react';
import { apiFetch } from '@/lib/api';

const TIPO_CONTACTO = ['Celular', 'Fijo', 'WhatsApp', 'Email', 'Otro'];
const ESTADO_CONTACTO = ['activo', 'inactivo'];

const emptyForm = { tipo: 'Celular', valor: '', principal: false, estado: 'activo' };

export default function ContactosManager({ personId, contactos: initialContactos = [], onUpdate }) {
    const [contactos, setContactos] = useState(initialContactos);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function resetForm() {
        setForm(emptyForm);
        setShowForm(false);
        setEditingId(null);
        setError('');
    }

    async function handleSave(e) {
        e.preventDefault();
        if (!form.valor.trim()) { setError('El valor es obligatorio'); return; }
        setSaving(true);
        setError('');

        try {
            const isEdit = !!editingId;
            const url = isEdit
                ? `/votantes/contactos/${editingId}`
                : `/votantes/${personId}/contactos`;
            const method = isEdit ? 'PUT' : 'POST';

            const res = await apiFetch(url, { method, body: JSON.stringify(form) });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.message || `Error ${res.status}`);
            }
            const data = await res.json();

            if (isEdit) {
                setContactos(prev => prev.map(c => c.id === editingId ? (data.data || { ...c, ...form }) : c));
            } else {
                setContactos(prev => [...prev, data.data || { id: Date.now(), ...form }]);
            }
            resetForm();
            onUpdate?.();
        } catch (err) {
            setError(err.message || 'Error al guardar');
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(id) {
        if (!confirm('¿Eliminar este contacto?')) return;
        try {
            const res = await apiFetch(`/votantes/contactos/${id}`, { method: 'DELETE' });
            if (!res.ok) throw new Error();
            setContactos(prev => prev.filter(c => c.id !== id));
            onUpdate?.();
        } catch {
            setError('Error al eliminar');
        }
    }

    function startEdit(c) {
        setEditingId(c.id);
        setForm({ tipo: c.tipo || 'Celular', valor: c.valor || '', principal: !!c.principal, estado: c.estado || 'activo' });
        setShowForm(false);
    }

    const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h4 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                    Contactos adicionales ({contactos.length})
                </h4>
                <button
                    type="button"
                    onClick={() => { resetForm(); setShowForm(true); }}
                    className="px-3 py-1.5 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors"
                >
                    + Agregar
                </button>
            </div>

            {error && (
                <div className="mb-3 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-[12px] text-red-700">{error}</div>
            )}

            {/* Add/Edit form */}
            {(showForm || editingId) && (
                <form onSubmit={handleSave} className="bg-blue-50 rounded-xl p-4 mb-3 space-y-3">
                    <p className="text-[13px] font-bold text-[var(--color-primary)]">{editingId ? 'Editar contacto' : 'Nuevo contacto'}</p>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Tipo</label>
                            <select className={inputCls} value={form.tipo} onChange={e => setForm({ ...form, tipo: e.target.value })}>
                                {TIPO_CONTACTO.map(t => <option key={t} value={t}>{t}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Valor *</label>
                            <input className={inputCls} value={form.valor} onChange={e => setForm({ ...form, valor: e.target.value })} placeholder="Ej: 3XX XXXXXXX" required />
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        <label className="flex items-center gap-2 text-[13px] text-[var(--color-ink-soft)] cursor-pointer">
                            <input type="checkbox" checked={form.principal} onChange={e => setForm({ ...form, principal: e.target.checked })} className="w-4 h-4 rounded border-gray-300 text-[var(--color-primary)]" />
                            Principal
                        </label>
                        <div className="flex items-center gap-2">
                            <label className="text-[11px] font-bold text-[var(--color-ink-faint)]">Estado:</label>
                            <select className="border border-[var(--color-line)] rounded-lg px-2 py-1 text-[12px] focus:outline-none focus:border-[var(--color-primary)]" value={form.estado} onChange={e => setForm({ ...form, estado: e.target.value })}>
                                {ESTADO_CONTACTO.map(e => <option key={e} value={e}>{e}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={resetForm} className="px-4 py-2 text-[12px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                        <button type="submit" disabled={saving} className="px-4 py-2 bg-[var(--color-good)] text-white text-[12px] font-bold rounded-lg hover:bg-emerald-600 disabled:opacity-50">{saving ? 'Guardando...' : 'Guardar'}</button>
                    </div>
                </form>
            )}

            {/* List */}
            {contactos.length === 0 && !showForm && (
                <p className="text-[13px] text-[var(--color-ink-faint)] text-center py-4">Sin contactos adicionales.</p>
            )}
            {contactos.map(c => (
                <div key={c.id} className="flex items-center gap-3 px-3 py-2.5 bg-gray-50 rounded-lg mb-2">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                            <span className="px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded">{c.tipo}</span>
                            <span className="text-[14px] font-semibold text-[var(--color-ink)] truncate">{c.valor}</span>
                            {c.principal && <span className="px-1.5 py-0.5 bg-amber-100 text-amber-700 text-[9px] font-bold rounded">Principal</span>}
                            {c.estado === 'inactivo' && <span className="px-1.5 py-0.5 bg-gray-200 text-gray-500 text-[9px] font-bold rounded">Inactivo</span>}
                        </div>
                    </div>
                    <div className="flex gap-1 flex-shrink-0">
                        <button type="button" onClick={() => startEdit(c)} className="px-2 py-1.5 text-[11px] text-blue-600 bg-blue-50 hover:bg-blue-100 font-bold rounded-lg">Editar</button>
                        <button type="button" onClick={() => handleDelete(c.id)} className="px-2 py-1.5 text-[11px] text-red-600 bg-red-50 hover:bg-red-100 font-bold rounded-lg">Eliminar</button>
                    </div>
                </div>
            ))}
        </div>
    );
}
