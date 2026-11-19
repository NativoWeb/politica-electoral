import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';

const SMALL_WORDS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en'];

// "NORTE DE SANTANDER" → "Norte de Santander", "BOGOTÁ, D.C." → "Bogotá, D.C." (display only, the data keeps its name).
export function displayName(name) {
    if (!name) return '';
    return name.toLocaleLowerCase('es').split(/(\s+)/).map((w, i) => {
        if (/^\s*$/.test(w)) return w;
        if (/^(\p{L}\.)+,?$/u.test(w)) return w.toLocaleUpperCase('es');
        if (i > 0 && SMALL_WORDS.includes(w)) return w;
        return w.replace(/^([^\p{L}]*)(\p{L})/u, (_, pre, ch) => pre + ch.toLocaleUpperCase('es'));
    }).join('');
}

export function normalize(text) {
    return (text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

export function plural(n, one, many) {
    return `${Number(n).toLocaleString('es-CO')} ${n === 1 ? one : many}`;
}

export function EditModal({ item, type, provincias, onClose }) {
    const form = useForm({
        name: item.name,
        code: item.code || '',
        parent_id: item.parent_id || '',
    });

    function submit(e) {
        e.preventDefault();
        form.put(`/admin/territorio/${item.id}`, { onSuccess: onClose });
    }

    const inputCls = "w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[15px] focus:outline-none focus:border-[var(--color-primary)]";
    const label = type === 'municipality' ? 'Municipio' : type === 'province' ? 'Provincia' : 'Departamento';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-black/40" />
            <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4" onClick={e => e.stopPropagation()}>
                <h3 className="text-[18px] font-bold text-[var(--color-ink)]">Editar {label}</h3>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Nombre *</label>
                        <input className={inputCls} value={form.data.name} onChange={e => form.setData('name', e.target.value)} required />
                        {form.errors.name && <p className="text-[12px] text-red-500 mt-1">{form.errors.name}</p>}
                    </div>

                    {(type === 'municipality' || type === 'department') && (
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Código DANE</label>
                            <input className={inputCls} value={form.data.code} onChange={e => form.setData('code', e.target.value)} placeholder="Opcional" />
                        </div>
                    )}

                    {type === 'municipality' && provincias && (
                        <div>
                            <label className="block text-[13px] font-bold text-[var(--color-ink-faint)] mb-1">Provincia</label>
                            <select className={inputCls} value={form.data.parent_id} onChange={e => form.setData('parent_id', e.target.value)}>
                                {provincias.map(p => (
                                    <option key={p.id} value={p.id}>{p.name} ({p.dept})</option>
                                ))}
                            </select>
                            {form.errors.parent_id && <p className="text-[12px] text-red-500 mt-1">{form.errors.parent_id}</p>}
                        </div>
                    )}

                    <div className="flex gap-3 pt-2">
                        <button type="submit" disabled={form.processing} className="flex-1 px-4 py-3 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-xl disabled:opacity-50">
                            {form.processing ? 'Guardando...' : 'Guardar'}
                        </button>
                        <button type="button" onClick={onClose} className="px-4 py-3 border-2 border-gray-200 text-[14px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export function ToggleButton({ id, isActive, small }) {
    const [loading, setLoading] = useState(false);

    function toggle(e) {
        e.stopPropagation();
        setLoading(true);
        router.post(`/admin/territorio/${id}/toggle`, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    }

    return (
        <button
            onClick={toggle}
            disabled={loading}
            title={isActive ? 'Desactivar' : 'Activar'}
            className={`${small ? 'w-8 h-8' : 'w-9 h-9'} rounded-lg flex items-center justify-center flex-shrink-0 transition-colors disabled:opacity-50 ${
                isActive
                    ? 'bg-emerald-100 text-emerald-600 hover:bg-emerald-200'
                    : 'bg-red-100 text-red-500 hover:bg-red-200'
            }`}
        >
            {isActive ? (
                <svg className={`${small ? 'w-4 h-4' : 'w-5 h-5'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
            ) : (
                <svg className={`${small ? 'w-4 h-4' : 'w-5 h-5'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            )}
        </button>
    );
}

export function EditButton({ onClick, small }) {
    return (
        <button
            onClick={e => { e.stopPropagation(); onClick(); }}
            title="Editar"
            className={`${small ? 'w-8 h-8' : 'w-9 h-9'} rounded-lg flex items-center justify-center flex-shrink-0 bg-blue-100 text-blue-600 hover:bg-blue-200 transition-colors`}
        >
            <svg className={`${small ? 'w-3.5 h-3.5' : 'w-4 h-4'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
        </button>
    );
}
