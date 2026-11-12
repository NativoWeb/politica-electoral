import { useState } from 'react';
import { inputCls } from '@/Components/Empresarios/PersonaSearch';
import { ACCIONES } from '@/lib/empresarios';

// Field of an error → the column of the file it comes from.
const ERROR_COLUMN = { sector_economico_id: 'sector_economico', sector_id: 'sector', municipio_id: 'municipio', empleados_rango: 'empleados' };

// One row of an Excel load: its values, what will happen with it and, while in review, its correction.
export default function ImportFila({ fila, columnas, editable, onPatch }) {
    const [editing, setEditing] = useState(false);
    const [datos, setDatos] = useState(fila.datos);
    const [busy, setBusy] = useState(false);
    const accion = ACCIONES[fila.accion] ?? ACCIONES.error;
    const errores = Object.entries(fila.errores ?? {});
    const errorOf = key => errores.find(([k]) => (ERROR_COLUMN[k] ?? k) === key)?.[1];

    async function save(e) {
        e.preventDefault();
        setBusy(true);
        const ok = await onPatch({ datos });
        setBusy(false);
        if (ok) setEditing(false);
    }

    return (
        <li className="bg-white border border-[var(--color-line)] rounded-xl p-3 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-[14px] font-bold text-[var(--color-ink-soft)]">Fila {fila.fila}</span>
                <span className={`px-2 py-0.5 rounded-full border text-[13px] font-bold ${accion.cls}`}>{accion.label}</span>
                <span className="text-[15px] font-bold uppercase">{fila.datos.nombre || '(sin nombre)'}</span>
                {fila.existente && <span className="text-[14px] text-[var(--color-ink-soft)]">Ya existe como «{fila.existente.nombre}»</span>}
            </div>
            {errores.length > 0 && (
                <ul className="text-[14px] font-semibold text-red-700 list-disc pl-5" role="alert">
                    {errores.map(([k, m]) => <li key={k}>{m}</li>)}
                </ul>
            )}
            {editable && fila.existente && fila.accion !== 'error' && (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Qué hacer con la organización que ya existe">
                    {['completar', 'saltar'].map(a => (
                        <button key={a} type="button" aria-pressed={fila.accion === a} onClick={() => onPatch({ accion: a })} className={`min-h-[44px] px-3 rounded-lg border-2 text-[14px] font-bold ${fila.accion === a ? 'border-[var(--color-primary)] bg-blue-50 text-[var(--color-primary)]' : 'border-gray-200'}`}>
                            {a === 'completar' ? 'Completar datos vacíos' : 'Saltar'}
                        </button>
                    ))}
                </div>
            )}
            {editable && !editing && (
                <button type="button" onClick={() => { setDatos(fila.datos); setEditing(true); }} className="min-h-[44px] px-3 text-[14px] font-bold text-[var(--color-primary)] underline">Corregir datos</button>
            )}
            {editing && (
                <form onSubmit={save} className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                    {columnas.map(col => (
                        <label key={col.key} className="block text-[13px] font-semibold text-[var(--color-ink)]">
                            {col.label}
                            <input className={inputCls} value={datos[col.key] ?? ''} maxLength={5000} onChange={e => setDatos(d => ({ ...d, [col.key]: e.target.value }))} aria-invalid={!!errorOf(col.key)} />
                        </label>
                    ))}
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
                        <button type="submit" disabled={busy} className="min-h-[44px] px-5 rounded-lg bg-[var(--color-primary)] text-white text-[14px] font-bold">{busy ? 'Revisando…' : 'Guardar corrección'}</button>
                        <button type="button" onClick={() => { setDatos(fila.datos); setEditing(false); }} className="min-h-[44px] px-4 rounded-lg border-2 border-gray-200 text-[14px] font-bold">Cancelar</button>
                    </div>
                </form>
            )}
        </li>
    );
}
