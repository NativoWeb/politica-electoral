import { useId } from 'react';
import { MONTHS, barrioSuggestions, birthYears } from '@/lib/eventos';

export const fieldCls = 'w-full px-4 py-3.5 border-2 border-[var(--color-line-strong)] rounded-xl text-[18px] text-[var(--color-ink)] bg-white focus:outline-none focus:border-[var(--color-primary)] aria-[invalid=true]:border-red-500';
const labelCls = 'block text-[18px] font-bold text-[var(--color-ink)] mb-1.5';

function Required() {
    return <span className="text-red-600" aria-hidden="true"> *</span>;
}

function FieldError({ id, message }) {
    if (!message) return null;
    return <p id={id} className="mt-1.5 text-[18px] font-semibold text-red-700">{message}</p>;
}

function TextField({ name, label, required, value, onChange, error, inputRef, hint, ...rest }) {
    const id = `att-${name}`;
    const errorId = `${id}-error`;
    return (
        <div>
            <label htmlFor={id} className={labelCls}>{label}{required && <Required />}</label>
            {hint && <p className="-mt-1 mb-1.5 text-[18px] text-[var(--color-ink-soft)]">{hint}</p>}
            <input
                id={id}
                name={name}
                ref={inputRef}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                aria-required={required || undefined}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={error ? errorId : undefined}
                className={fieldCls}
                {...rest}
            />
            <FieldError id={errorId} message={error} />
        </div>
    );
}

export function BirthDateSelects({ value, onChange, error }) {
    const years = birthYears();
    const errorId = 'att-birth-error';
    const selectCls = `${fieldCls.replace('px-4', 'px-2')} appearance-none`;
    const describedBy = error ? errorId : undefined;
    const update = (part, v) => onChange({ ...value, [part]: v });

    return (
        <fieldset>
            <legend className={labelCls}>Fecha de nacimiento <span className="font-normal text-[var(--color-ink-soft)]">(opcional)</span></legend>
            <div className="grid grid-cols-[3.25rem_1fr_5rem] gap-2">
                <div>
                    <label htmlFor="att-birth-day" className="block text-[18px] text-[var(--color-ink-soft)] mb-1">Día</label>
                    <select id="att-birth-day" value={value.day} onChange={e => update('day', e.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} className={selectCls}>
                        <option value="">--</option>
                        {Array.from({ length: 31 }, (_, i) => i + 1).map(d => <option key={d} value={d}>{d}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor="att-birth-month" className="block text-[18px] text-[var(--color-ink-soft)] mb-1">Mes</label>
                    <select id="att-birth-month" value={value.month} onChange={e => update('month', e.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} className={selectCls}>
                        <option value="">--</option>
                        {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor="att-birth-year" className="block text-[18px] text-[var(--color-ink-soft)] mb-1">Año</label>
                    <select id="att-birth-year" value={value.year} onChange={e => update('year', e.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} className={selectCls}>
                        <option value="">--</option>
                        {years.map(y => <option key={y} value={y}>{y}</option>)}
                    </select>
                </div>
            </div>
            <FieldError id={errorId} message={error} />
        </fieldset>
    );
}

function BarrioField({ value, onChange, error, barrios }) {
    const suggestions = barrioSuggestions(barrios, value);
    const hintId = useId();
    return (
        <div>
            <TextField
                name="barrio"
                label="Barrio o vereda"
                required
                value={value}
                onChange={onChange}
                error={error}
                autoComplete="off"
                maxLength={120}
            />
            {suggestions.length > 0 && (
                <div className="mt-2" role="group" aria-labelledby={hintId}>
                    <p id={hintId} className="text-[18px] text-[var(--color-ink-soft)] mb-1.5">Barrios de este evento (toque uno para elegirlo):</p>
                    <div className="flex flex-wrap gap-2">
                        {suggestions.map(b => (
                            <button
                                key={b}
                                type="button"
                                onClick={() => onChange(b)}
                                className="min-h-[44px] px-4 py-2 rounded-full border-2 border-blue-200 bg-blue-50 text-[18px] font-semibold text-[var(--color-primary)] active:bg-blue-100"
                            >
                                {b}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// The attendee fields in one column, the same for the operator and the public QR form.
export default function AttendeeFields({ form, barrios, firstFieldRef }) {
    const { data, set, setBirth, errors } = form;
    return (
        <div className="space-y-5">
            <TextField name="nombres" label="Nombres" required value={data.nombres} onChange={v => set('nombres', v)} error={errors.nombres} inputRef={firstFieldRef} autoComplete="given-name" maxLength={100} />
            <TextField name="apellidos" label="Apellidos" required value={data.apellidos} onChange={v => set('apellidos', v)} error={errors.apellidos} autoComplete="family-name" maxLength={100} />
            <TextField name="cedula" label="Cédula" required value={data.cedula} onChange={v => set('cedula', v)} error={errors.cedula} inputMode="numeric" autoComplete="off" maxLength={20} hint="Solo números, sin puntos." />
            <TextField name="celular" label="Celular" required value={data.celular} onChange={v => set('celular', v)} error={errors.celular} inputMode="numeric" type="tel" autoComplete="tel-national" maxLength={20} hint="10 números, empieza por 3." />
            <BarrioField value={data.barrio} onChange={v => set('barrio', v)} error={errors.barrio} barrios={barrios} />
            <TextField name="fijo" label="Teléfono fijo (opcional)" value={data.fijo} onChange={v => set('fijo', v)} error={errors.fijo} inputMode="numeric" type="tel" autoComplete="off" maxLength={20} />
            <BirthDateSelects value={data.birth} onChange={setBirth} error={errors.fecha_nacimiento} />
            <TextField name="direccion" label="Dirección (opcional)" value={data.direccion} onChange={v => set('direccion', v)} error={errors.direccion} autoComplete="street-address" maxLength={255} />
        </div>
    );
}
