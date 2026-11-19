import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ConfigHeader, { Notice, errorOf } from '@/Components/Configuracion/ConfigHeader';
import { apiFetch } from '@/lib/api';

const inputCls = 'border border-[var(--color-line)] rounded-lg px-3 py-2 text-[15px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white aria-[invalid=true]:border-red-500';
const btnPrimary = 'min-h-[40px] px-4 py-2 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] disabled:opacity-40 transition-colors';
const btnSecondary = 'min-h-[40px] px-3 py-2 border border-[var(--color-line)] text-[13px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 disabled:opacity-40 transition-colors';

const QUARTERS = Array.from({ length: 96 }, (_, i) => `${String(Math.floor(i / 4)).padStart(2, '0')}:${String((i % 4) * 15).padStart(2, '0')}`);

export function formatValor(ajuste, valor) {
    if (ajuste.tipo === 'bool') return valor ? 'Encendido' : 'Apagado';
    if (ajuste.tipo === 'int') return `${Number(valor).toLocaleString('es-CO')}${ajuste.unidad ? ` ${ajuste.unidad}` : ''}`;

    return String(valor);
}

// Client-side check of the range; the server checks it again.
export function validarAjuste(ajuste, draft) {
    if (ajuste.tipo !== 'int') return null;
    if (draft === '' || !/^-?\d+$/.test(String(draft))) return 'Escriba un número entero.';
    const n = Number(draft);
    if (ajuste.min !== null && n < ajuste.min) return `El valor mínimo es ${ajuste.min.toLocaleString('es-CO')}.`;
    if (ajuste.max !== null && n > ajuste.max) return `El valor máximo es ${ajuste.max.toLocaleString('es-CO')}.`;

    return null;
}

function AjusteRow({ ajuste, onSaved }) {
    const [draft, setDraft] = useState(ajuste.valor);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const inputId = `ajuste-${ajuste.clave}`;
    const localError = validarAjuste(ajuste, draft);
    const changed = String(draft) !== String(ajuste.valor);

    async function send(method, body) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const res = await apiFetch(`/admin/configuracion/ajustes/${ajuste.clave}`, { method, body: body ? JSON.stringify(body) : undefined });
            if (!res.ok) {
                setError(await errorOf(res));
                return;
            }
            const json = await res.json();
            setNotice(json.message);
            setDraft(json.data.valor);
            onSaved(ajuste.clave, json.data);
        } catch {
            setError('Sin conexión con el servidor. Intente de nuevo.');
        } finally {
            setBusy(false);
        }
    }

    function save(e) {
        e.preventDefault();
        if (localError) return;
        send('PUT', { valor: ajuste.tipo === 'int' ? Number(draft) : draft });
    }

    return (
        <li className="bg-white border border-[var(--color-line)] rounded-xl px-4 py-4">
            <form onSubmit={save} className="space-y-2">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <label htmlFor={inputId} className="block text-[15px] font-bold text-[var(--color-ink)]">{ajuste.etiqueta}</label>
                        <p className="text-[12px] text-[var(--color-ink-faint)]">{ajuste.descripcion}</p>
                        <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">
                            Por defecto: {formatValor(ajuste, ajuste.default)}
                            {ajuste.tipo === 'int' && ` · entre ${ajuste.min.toLocaleString('es-CO')} y ${ajuste.max.toLocaleString('es-CO')}`}
                            {ajuste.cambiado && <span className="ml-2 font-bold text-amber-700">Cambiado</span>}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {ajuste.tipo === 'int' && (
                        <input id={inputId} type="number" inputMode="numeric" min={ajuste.min} max={ajuste.max} step="1" className={`${inputCls} w-40`}
                            value={draft} aria-invalid={!!localError} onChange={e => setDraft(e.target.value)} />
                    )}
                    {ajuste.tipo === 'time' && (
                        <select id={inputId} className={`${inputCls} w-32`} value={draft} onChange={e => setDraft(e.target.value)}>
                            {QUARTERS.map(q => <option key={q} value={q}>{q}</option>)}
                        </select>
                    )}
                    {ajuste.tipo === 'bool' && (
                        <select id={inputId} className={`${inputCls} w-40`} value={draft ? '1' : '0'} onChange={e => setDraft(e.target.value === '1')}>
                            <option value="1">Encendido</option>
                            <option value="0">Apagado</option>
                        </select>
                    )}
                    {ajuste.unidad && ajuste.tipo === 'int' && <span className="text-[13px] text-[var(--color-ink-soft)]">{ajuste.unidad}</span>}
                    <button type="submit" className={btnPrimary} disabled={busy || !changed || !!localError}>Guardar</button>
                    {ajuste.cambiado && (
                        <button type="button" className={btnSecondary} disabled={busy} onClick={() => send('DELETE')}>Volver al valor por defecto</button>
                    )}
                </div>
                {localError && changed && <p className="text-[13px] font-semibold text-red-700">{localError}</p>}
                <Notice notice={notice} error={error} />
            </form>
        </li>
    );
}

export default function ConfiguracionAjustes({ grupos = {}, ajustes: initial = [] }) {
    const [ajustes, setAjustes] = useState(initial);
    const onSaved = (clave, data) => setAjustes(list => list.map(a => (a.clave === clave ? { ...a, valor: data.valor, cambiado: data.cambiado } : a)));

    return (
        <AppLayout title="Ajustes del sistema" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CONFIGURACIÓN', href: '/admin/configuracion' }, { label: 'AJUSTES' }]}>
            <Head title="Ajustes del sistema — Configuración" />
            <ConfigHeader title="AJUSTES DEL SISTEMA" subtitle="Límites, horarios y módulos. Aplican a todos los grupos políticos." />

            <div className="p-4 lg:p-6 space-y-5 max-w-4xl">
                <p className="text-[13px] text-[var(--color-ink-soft)]">
                    Las reglas de seguridad (qué ve cada grupo político y territorio, autorización de datos, tiempos de sesión) no se configuran aquí: dependen del código para que nadie las cambie por error.
                </p>
                {Object.entries(grupos).map(([grupo, titulo]) => {
                    const items = ajustes.filter(a => a.grupo === grupo);
                    if (items.length === 0) return null;

                    return (
                        <section key={grupo} aria-label={titulo}>
                            <h2 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-2 px-1">{titulo}</h2>
                            <ul className="space-y-2">
                                {items.map(a => <AjusteRow key={a.clave} ajuste={a} onSaved={onSaved} />)}
                            </ul>
                        </section>
                    );
                })}
            </div>
        </AppLayout>
    );
}
