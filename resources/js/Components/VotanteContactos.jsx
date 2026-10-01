import { useState } from 'react';

const TIPO_OPTIONS = ['celular', 'fijo', 'whatsapp', 'email', 'otro'];
const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white";

export default function VotanteContactos({ contactos = [], liderId, apiFetch, onUpdate, readOnly }) {
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState({ tipo: 'celular', valor: '', principal: false, estado: 'activo' });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function resetForm() {
        setForm({ tipo: 'celular', valor: '', principal: false, estado: 'activo' });
        setAdding(false);
        setEditingId(null);
        setError('');
    }

    function startEdit(c) {
        setEditingId(c.id);
        setForm({ tipo: c.tipo, valor: c.valor, principal: c.principal, estado: c.estado || 'activo' });
        setAdding(false);
    }

    async function handleSave() {
        if (!form.valor.trim()) {
            setError('El valor es requerido');
            return;
        }
        setSaving(true);
        setError('');
        try {
            if (editingId) {
                await apiFetch(`/votantes/contactos/${editingId}`, {
                    method: 'PUT',
                    body: JSON.stringify(form),
                });
            } else {
                await apiFetch(`/votantes/${liderId}/contactos`, {
                    method: 'POST',
                    body: JSON.stringify(form),
                });
            }
            resetForm();
            onUpdate();
        } catch (err) {
            setError(err.message || 'Error al guardar');
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(id) {
        setSaving(true);
        try {
            await apiFetch(`/votantes/contactos/${id}`, { method: 'DELETE' });
            onUpdate();
        } catch (err) {
            setError(err.message || 'Error al eliminar');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-2">
            {contactos.length === 0 && !adding && (
                <p className="text-[12px] text-[var(--color-ink-faint)] italic">Sin contactos registrados</p>
            )}

            {contactos.map(c => (
                <div key={c.id} className="flex items-center gap-2 p-2 rounded-lg bg-gray-50 border border-gray-100">
                    {editingId === c.id ? (
                        <div className="flex-1 space-y-2">
                            <div className="flex gap-2">
                                <select value={form.tipo} onChange={e => setForm({ ...form, tipo: e.target.value })} className={inputCls + ' w-28 flex-shrink-0'}>
                                    {TIPO_OPTIONS.map(t => <option key={t} value={t}>{t}</option>)}
                                </select>
                                <input value={form.valor} onChange={e => setForm({ ...form, valor: e.target.value })} placeholder="Valor" className={inputCls} />
                            </div>
                            <div className="flex items-center gap-3">
                                <label className="flex items-center gap-1 text-[12px]">
                                    <input type="checkbox" checked={form.principal} onChange={e => setForm({ ...form, principal: e.target.checked })} className="w-3.5 h-3.5 rounded" />
                                    Principal
                                </label>
                                <button onClick={handleSave} disabled={saving} className="px-2 py-1 bg-[var(--color-primary)] text-white rounded text-[11px] font-bold disabled:opacity-50">
                                    {saving ? '...' : 'Guardar'}
                                </button>
                                <button onClick={resetForm} className="px-2 py-1 text-[11px] text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="flex-1 min-w-0">
                                <span className="text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase">{c.tipo}</span>
                                <p className="text-[13px] text-[var(--color-ink)] truncate">{c.valor}</p>
                            </div>
                            {c.principal && (
                                <span className="px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded flex-shrink-0">PRINCIPAL</span>
                            )}
                            {!readOnly && (
                                <div className="flex items-center gap-1 flex-shrink-0">
                                    <button onClick={() => startEdit(c)} className="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 transition-colors" title="Editar">
                                        <svg className="w-3.5 h-3.5 text-[var(--color-ink-faint)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                    <button onClick={() => handleDelete(c.id)} className="w-7 h-7 flex items-center justify-center rounded hover:bg-red-100 transition-colors" title="Eliminar">
                                        <svg className="w-3.5 h-3.5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>
            ))}

            {/* Add form */}
            {adding && (
                <div className="p-3 rounded-lg border border-blue-200 bg-blue-50/50 space-y-2">
                    <div className="flex gap-2">
                        <select value={form.tipo} onChange={e => setForm({ ...form, tipo: e.target.value })} className={inputCls + ' w-28 flex-shrink-0'}>
                            {TIPO_OPTIONS.map(t => <option key={t} value={t}>{t}</option>)}
                        </select>
                        <input value={form.valor} onChange={e => setForm({ ...form, valor: e.target.value })} placeholder="Valor (telefono, email...)" className={inputCls} />
                    </div>
                    <div className="flex items-center gap-3">
                        <label className="flex items-center gap-1 text-[12px]">
                            <input type="checkbox" checked={form.principal} onChange={e => setForm({ ...form, principal: e.target.checked })} className="w-3.5 h-3.5 rounded" />
                            Principal
                        </label>
                        <button onClick={handleSave} disabled={saving} className="px-2 py-1 bg-[var(--color-primary)] text-white rounded text-[11px] font-bold disabled:opacity-50">
                            {saving ? 'Guardando...' : 'Agregar'}
                        </button>
                        <button onClick={resetForm} className="px-2 py-1 text-[11px] text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            {error && <p className="text-[12px] text-red-500">{error}</p>}

            {!readOnly && !adding && !editingId && (
                <button
                    onClick={() => { setAdding(true); setForm({ tipo: 'celular', valor: '', principal: false, estado: 'activo' }); }}
                    className="flex items-center gap-1 text-[12px] font-semibold text-[var(--color-primary)] hover:text-[var(--color-primary-light)] transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" d="M12 4v16m8-8H4" /></svg>
                    Agregar contacto
                </button>
            )}
        </div>
    );
}
