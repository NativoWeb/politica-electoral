import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { apiFetch } from '@/lib/api';
import { attendeeEditData, eventErrorMessage, fieldErrors } from '@/lib/eventos';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';

const inputCls = 'w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[16px] bg-white focus:outline-none focus:border-[var(--color-primary)] aria-[invalid=true]:border-red-500';

const FIELDS = [
    { name: 'nombres', label: 'Nombres', required: true, maxLength: 100 },
    { name: 'apellidos', label: 'Apellidos', required: true, maxLength: 100 },
    { name: 'cedula', label: 'Cédula', inputMode: 'numeric', maxLength: 20 },
    { name: 'celular', label: 'Celular', inputMode: 'tel', maxLength: 20 },
    { name: 'fijo', label: 'Teléfono fijo', inputMode: 'tel', maxLength: 20 },
    { name: 'fecha_nacimiento', label: 'Fecha de nacimiento', type: 'date' },
    { name: 'direccion', label: 'Dirección', maxLength: 255 },
    { name: 'barrio', label: 'Barrio', maxLength: 120 },
    { name: 'nota', label: 'Nota / actividad', maxLength: 255 },
];

// Corrects an attendee that is not in Votantes yet; the linked ficha (if any) is never changed from here.
export default function AttendeeEditModal({ eventId, attendee, onClose, onSaved }) {
    const dialogRef = useRef(null);
    useDialogFocus(dialogRef);
    useEscapeKey(onClose, dialogRef);
    const [data, setData] = useState(() => attendeeEditData(attendee));
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState('');
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        setFailure('');
        setSaving(true);
        try {
            const body = Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v.trim() === '' ? null : v]));
            const response = await apiFetch(`/eventos/${eventId}/asistentes/${attendee.id}`, { method: 'PUT', body: JSON.stringify(body) });
            const json = await response.json().catch(() => null);
            if (response.ok) {
                onSaved(json?.message || 'Datos del asistente actualizados.');
                return;
            }
            if (response.status === 422) {
                const fe = fieldErrors(json);
                setErrors(fe);
                setFailure(fe.asistente || (Object.keys(fe).length ? 'Revise los datos marcados.' : eventErrorMessage({ status: 422, data: json })));
            } else {
                setFailure(eventErrorMessage({ status: response.status, data: json }));
            }
        } catch {
            setFailure('No hay conexión con el servidor. Intente de nuevo.');
        } finally {
            setSaving(false);
        }
    }

    return createPortal(
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={onClose} />
            <div className="fixed inset-0 z-[60] flex items-center justify-center p-2 sm:p-4 pointer-events-none">
                <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="attendee-edit-title" className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[520px] max-h-[calc(100dvh-1rem)] flex flex-col overflow-hidden">
                    <div className="px-5 pt-5 pb-3 border-b border-[var(--color-line)]">
                        <h2 id="attendee-edit-title" className="text-[18px] font-extrabold text-[var(--color-ink)]">Editar datos del asistente</h2>
                        <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Solo cambia el registro del evento.</p>
                    </div>
                    <form onSubmit={submit} noValidate className="flex-1 min-h-0 flex flex-col">
                        <div className="flex-1 overflow-y-auto px-5 py-4 grid sm:grid-cols-2 gap-3">
                            {FIELDS.map(f => (
                                <div key={f.name} className={['direccion', 'nota'].includes(f.name) ? 'sm:col-span-2' : ''}>
                                    <label htmlFor={`edit-${f.name}`} className="block text-[14px] font-bold text-[var(--color-ink)] mb-1">
                                        {f.label}{f.required && <span className="text-red-600" aria-hidden="true"> *</span>}
                                    </label>
                                    <input
                                        id={`edit-${f.name}`}
                                        type={f.type || 'text'}
                                        inputMode={f.inputMode}
                                        maxLength={f.maxLength}
                                        value={data[f.name]}
                                        onChange={e => setData(prev => ({ ...prev, [f.name]: e.target.value }))}
                                        aria-invalid={errors[f.name] ? 'true' : undefined}
                                        aria-describedby={errors[f.name] ? `edit-${f.name}-error` : undefined}
                                        className={inputCls}
                                    />
                                    {errors[f.name] && <p id={`edit-${f.name}-error`} className="mt-1 text-[14px] font-semibold text-red-700">{errors[f.name]}</p>}
                                </div>
                            ))}
                        </div>
                        {failure && <p role="alert" className="mx-5 mb-2 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{failure}</p>}
                        <div className="px-5 py-4 border-t border-[var(--color-line)] flex gap-3">
                            <button type="button" onClick={onClose} className="flex-1 min-h-[48px] px-4 border-2 border-gray-200 rounded-xl text-[15px] font-bold text-[var(--color-ink-soft)]">Cancelar</button>
                            <button type="submit" disabled={saving} className="flex-1 min-h-[48px] px-4 bg-[var(--color-primary)] text-white rounded-xl text-[15px] font-bold disabled:opacity-50">
                                {saving ? 'Guardando…' : 'Guardar cambios'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>,
        document.body,
    );
}
