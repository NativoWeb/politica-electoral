import { useState } from 'react';
import { apiFetch } from '@/lib/api';

const ESTADO_OPTIONS = ['activo', 'inactivo'];

const emptyForm = { cargo_politico: '', departamento: '', municipio: '', partido: '', aliado: '', votos: '', observacion: '', estado: 'activo' };

export default function InfoPoliticaManager({ personId, infoPolitica: initialData = [], onUpdate }) {
    const [items, setItems] = useState(initialData);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [expandedId, setExpandedId] = useState(null);

    function resetForm() {
        setForm(emptyForm);
        setShowForm(false);
        setEditingId(null);
        setError('');
    }

    async function handleSave(e) {
        e.preventDefault();
        setSaving(true);
        setError('');

        try {
            const isEdit = !!editingId;
            const url = isEdit
                ? `/votantes/info-politica/${editingId}`
                : `/votantes/${personId}/info-politica`;
            const method = isEdit ? 'PUT' : 'POST';
            const payload = { ...form, votos: form.votos ? parseInt(form.votos, 10) : 0 };

            const res = await apiFetch(url, { method, body: JSON.stringify(payload) });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.message || `Error ${res.status}`);
            }
            const data = await res.json();

            if (isEdit) {
                setItems(prev => prev.map(item => item.id === editingId ? (data.data || { ...item, ...payload }) : item));
            } else {
                setItems(prev => [...prev, data.data || { id: Date.now(), ...payload }]);
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
        if (!confirm('¿Eliminar este registro de info politica?')) return;
        try {
            const res = await apiFetch(`/votantes/info-politica/${id}`, { method: 'DELETE' });
            if (!res.ok) throw new Error();
            setItems(prev => prev.filter(item => item.id !== id));
            onUpdate?.();
        } catch {
            setError('Error al eliminar');
        }
    }

    function startEdit(item) {
        setEditingId(item.id);
        setForm({
            cargo_politico: item.cargo_politico || '',
            departamento: item.departamento || '',
            municipio: item.municipio || '',
            partido: item.partido || '',
            aliado: item.aliado || '',
            votos: item.votos || '',
            observacion: item.observacion || '',
            estado: item.estado || 'activo',
        });
        setShowForm(false);
    }

    const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h4 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                    Historial politico ({items.length})
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
                    <p className="text-[13px] font-bold text-[var(--color-primary)]">{editingId ? 'Editar registro' : 'Nuevo registro'}</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Cargo politico</label>
                            <input className={inputCls} value={form.cargo_politico} onChange={e => setForm({ ...form, cargo_politico: e.target.value })} placeholder="Ej: Concejal" />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Partido</label>
                            <input className={inputCls} value={form.partido} onChange={e => setForm({ ...form, partido: e.target.value })} placeholder="Nombre del partido" />
                        </div>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Departamento</label>
                            <input className={inputCls} value={form.departamento} onChange={e => setForm({ ...form, departamento: e.target.value })} placeholder="Ej: Santander" />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Municipio</label>
                            <input className={inputCls} value={form.municipio} onChange={e => setForm({ ...form, municipio: e.target.value })} placeholder="Ej: Bucaramanga" />
                        </div>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Aliado</label>
                            <input className={inputCls} value={form.aliado} onChange={e => setForm({ ...form, aliado: e.target.value })} />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Votos</label>
                            <input type="number" className={inputCls} value={form.votos} onChange={e => setForm({ ...form, votos: e.target.value })} min="0" />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Estado</label>
                            <select className={inputCls} value={form.estado} onChange={e => setForm({ ...form, estado: e.target.value })}>
                                {ESTADO_OPTIONS.map(e => <option key={e} value={e}>{e}</option>)}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="block text-[11px] font-bold text-[var(--color-ink-faint)] mb-1">Observacion</label>
                        <textarea className={inputCls} rows={2} value={form.observacion} onChange={e => setForm({ ...form, observacion: e.target.value })} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={resetForm} className="px-4 py-2 text-[12px] text-[var(--color-ink-soft)] border border-[var(--color-line)] rounded-lg hover:bg-gray-50">Cancelar</button>
                        <button type="submit" disabled={saving} className="px-4 py-2 bg-[var(--color-good)] text-white text-[12px] font-bold rounded-lg hover:bg-emerald-600 disabled:opacity-50">{saving ? 'Guardando...' : 'Guardar'}</button>
                    </div>
                </form>
            )}

            {/* List */}
            {items.length === 0 && !showForm && (
                <p className="text-[13px] text-[var(--color-ink-faint)] text-center py-4">Sin registros de historial politico.</p>
            )}
            {items.map(item => (
                <div key={item.id} className="bg-gray-50 rounded-lg mb-2 overflow-hidden">
                    <div
                        className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-100 transition-colors"
                        onClick={() => setExpandedId(expandedId === item.id ? null : item.id)}
                    >
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="text-[13px] font-bold text-[var(--color-ink)]">{item.cargo_politico || 'Sin cargo'}</span>
                                {item.partido && <span className="px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded">{item.partido}</span>}
                                {item.estado === 'inactivo' && <span className="px-1.5 py-0.5 bg-gray-200 text-gray-500 text-[9px] font-bold rounded">Inactivo</span>}
                            </div>
                            <p className="text-[11px] text-[var(--color-ink-faint)] mt-0.5">
                                {[item.municipio, item.departamento].filter(Boolean).join(', ') || 'Sin ubicacion'}
                                {item.votos > 0 && ` · ${Number(item.votos).toLocaleString('es-CO')} votos`}
                            </p>
                        </div>
                        <div className="flex gap-1 flex-shrink-0">
                            <button type="button" onClick={e => { e.stopPropagation(); startEdit(item); }} className="px-2 py-1.5 text-[11px] text-blue-600 bg-blue-50 hover:bg-blue-100 font-bold rounded-lg">Editar</button>
                            <button type="button" onClick={e => { e.stopPropagation(); handleDelete(item.id); }} className="px-2 py-1.5 text-[11px] text-red-600 bg-red-50 hover:bg-red-100 font-bold rounded-lg">Eliminar</button>
                        </div>
                        <svg className={`w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ${expandedId === item.id ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
                    </div>
                    {expandedId === item.id && (
                        <div className="px-3 pb-3 pt-1 border-t border-gray-200 text-[12px] text-[var(--color-ink-soft)] space-y-1">
                            {item.aliado && <p><span className="font-bold text-[var(--color-ink-faint)]">Aliado:</span> {item.aliado}</p>}
                            {item.observacion && <p><span className="font-bold text-[var(--color-ink-faint)]">Observacion:</span> {item.observacion}</p>}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
