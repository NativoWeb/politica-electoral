import { useState } from 'react';

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white";

const EMPTY_FORM = {
    cargo_politico: '', departamento: '', municipio: '',
    partido: '', aliado: '', votos: '', observacion: '', estado: 'activo',
};

export default function VotanteInfoPolitica({ entries = [], liderId, apiFetch, onUpdate, readOnly }) {
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState({ ...EMPTY_FORM });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function resetForm() {
        setForm({ ...EMPTY_FORM });
        setAdding(false);
        setEditingId(null);
        setError('');
    }

    function startEdit(entry) {
        setEditingId(entry.id);
        setForm({
            cargo_politico: entry.cargo_politico || '',
            departamento: entry.departamento || '',
            municipio: entry.municipio || '',
            partido: entry.partido || '',
            aliado: entry.aliado || '',
            votos: entry.votos ?? '',
            observacion: entry.observacion || '',
            estado: entry.estado || 'activo',
        });
        setAdding(false);
    }

    function setField(key, val) {
        setForm(prev => ({ ...prev, [key]: val }));
    }

    async function handleSave() {
        setSaving(true);
        setError('');
        const payload = {
            ...form,
            votos: form.votos !== '' ? parseInt(form.votos, 10) : null,
        };
        try {
            if (editingId) {
                await apiFetch(`/votantes/info-politica/${editingId}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
            } else {
                await apiFetch(`/votantes/${liderId}/info-politica`, {
                    method: 'POST',
                    body: JSON.stringify(payload),
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
            await apiFetch(`/votantes/info-politica/${id}`, { method: 'DELETE' });
            onUpdate();
        } catch (err) {
            setError(err.message || 'Error al eliminar');
        } finally {
            setSaving(false);
        }
    }

    const isFormOpen = adding || editingId;

    return (
        <div className="space-y-2">
            {entries.length === 0 && !adding && (
                <p className="text-[12px] text-[var(--color-ink-faint)] italic">Sin informacion politica registrada</p>
            )}

            {entries.map(entry => (
                <div key={entry.id} className="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                    {editingId === entry.id ? (
                        <FormFields form={form} setField={setField} saving={saving} onSave={handleSave} onCancel={resetForm} isEdit />
                    ) : (
                        <div className="flex items-start gap-2">
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    {entry.cargo_politico && <span className="text-[13px] font-semibold text-[var(--color-ink)]">{entry.cargo_politico}</span>}
                                    {entry.partido && <span className="px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded">{entry.partido}</span>}
                                </div>
                                <div className="text-[12px] text-[var(--color-ink-faint)] mt-0.5 space-x-2">
                                    {entry.municipio && <span>{entry.municipio}</span>}
                                    {entry.departamento && <span>({entry.departamento})</span>}
                                    {entry.aliado && <span>Aliado: {entry.aliado}</span>}
                                    {entry.votos > 0 && <span>{entry.votos} votos</span>}
                                </div>
                                {entry.observacion && <p className="text-[12px] text-[var(--color-ink-soft)] mt-1 line-clamp-2">{entry.observacion}</p>}
                            </div>
                            {!readOnly && (
                                <div className="flex items-center gap-1 flex-shrink-0">
                                    <button onClick={() => startEdit(entry)} className="w-7 h-7 flex items-center justify-center rounded hover:bg-gray-200 transition-colors" title="Editar">
                                        <svg className="w-3.5 h-3.5 text-[var(--color-ink-faint)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                    <button onClick={() => handleDelete(entry.id)} className="w-7 h-7 flex items-center justify-center rounded hover:bg-red-100 transition-colors" title="Eliminar">
                                        <svg className="w-3.5 h-3.5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            ))}

            {adding && (
                <div className="p-3 rounded-lg border border-blue-200 bg-blue-50/50">
                    <FormFields form={form} setField={setField} saving={saving} onSave={handleSave} onCancel={resetForm} />
                </div>
            )}

            {error && <p className="text-[12px] text-red-500">{error}</p>}

            {!readOnly && !isFormOpen && (
                <button
                    onClick={() => { setAdding(true); setForm({ ...EMPTY_FORM }); }}
                    className="flex items-center gap-1 text-[12px] font-semibold text-[var(--color-primary)] hover:text-[var(--color-primary-light)] transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" d="M12 4v16m8-8H4" /></svg>
                    Agregar info politica
                </button>
            )}
        </div>
    );
}

function FormFields({ form, setField, saving, onSave, onCancel, isEdit }) {
    return (
        <div className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
                <input value={form.cargo_politico} onChange={e => setField('cargo_politico', e.target.value)} placeholder="Cargo politico" className={inputCls} />
                <input value={form.partido} onChange={e => setField('partido', e.target.value)} placeholder="Partido" className={inputCls} />
            </div>
            <div className="grid grid-cols-2 gap-2">
                <input value={form.departamento} onChange={e => setField('departamento', e.target.value)} placeholder="Departamento" className={inputCls} />
                <input value={form.municipio} onChange={e => setField('municipio', e.target.value)} placeholder="Municipio" className={inputCls} />
            </div>
            <div className="grid grid-cols-2 gap-2">
                <input value={form.aliado} onChange={e => setField('aliado', e.target.value)} placeholder="Aliado" className={inputCls} />
                <input type="number" value={form.votos} onChange={e => setField('votos', e.target.value)} placeholder="Votos" className={inputCls} min="0" />
            </div>
            <textarea value={form.observacion} onChange={e => setField('observacion', e.target.value)} placeholder="Observacion" className={inputCls + ' min-h-[60px] resize-none'} />
            <div className="flex items-center gap-3">
                <button onClick={onSave} disabled={saving} className="px-3 py-1.5 bg-[var(--color-primary)] text-white rounded text-[12px] font-bold disabled:opacity-50">
                    {saving ? 'Guardando...' : isEdit ? 'Actualizar' : 'Agregar'}
                </button>
                <button onClick={onCancel} className="px-3 py-1.5 text-[12px] text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">
                    Cancelar
                </button>
            </div>
        </div>
    );
}
