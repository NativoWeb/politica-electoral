import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ConfigHeader, { Notice, errorOf } from '@/Components/Configuracion/ConfigHeader';
import { apiFetch } from '@/lib/api';
import { badgeStyle } from '@/lib/catalogos';

const inputCls = 'w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[14px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)] bg-white';
const btnPrimary = 'min-h-[40px] px-4 py-2 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] disabled:opacity-50 transition-colors';
const btnSecondary = 'min-h-[40px] px-3 py-2 border border-[var(--color-line)] text-[13px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 disabled:opacity-40 transition-colors';

function OptionRow({ opcion, index, total, fijo, busy, onSave, onToggle, onDelete, onMove }) {
    const [editing, setEditing] = useState(false);
    const [etiqueta, setEtiqueta] = useState(opcion.etiqueta);
    const [color, setColor] = useState(opcion.color || '');

    async function save(e) {
        e.preventDefault();
        if (await onSave(opcion, { etiqueta, color: color || null })) setEditing(false);
    }

    const canDelete = !fijo && opcion.usos === 0;

    return (
        <li className={`bg-white border border-[var(--color-line)] rounded-xl px-4 py-3 ${opcion.activo ? '' : 'opacity-70'}`} aria-label={opcion.etiqueta}>
            {editing ? (
                <form onSubmit={save} className="flex flex-wrap items-end gap-3">
                    <label className="flex-1 min-w-[180px]">
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Nombre que se ve</span>
                        <input className={inputCls} value={etiqueta} maxLength={100} onChange={e => setEtiqueta(e.target.value)} autoFocus />
                    </label>
                    <label>
                        <span className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Color</span>
                        <span className="flex items-center gap-2">
                            <input type="color" aria-label="Color" className="h-10 w-12 rounded border border-[var(--color-line)]" value={color || '#4B5563'} onChange={e => setColor(e.target.value.toUpperCase())} />
                            {color && <button type="button" className="text-[12px] underline text-[var(--color-ink-faint)]" onClick={() => setColor('')}>Sin color</button>}
                        </span>
                    </label>
                    <div className="flex gap-2">
                        <button type="button" className={btnSecondary} onClick={() => { setEditing(false); setEtiqueta(opcion.etiqueta); setColor(opcion.color || ''); }}>Cancelar</button>
                        <button type="submit" className={btnPrimary} disabled={busy || etiqueta.trim() === ''}>Guardar</button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex flex-col">
                        <button type="button" aria-label={`Subir ${opcion.etiqueta}`} className="w-8 h-6 text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] disabled:opacity-20" disabled={busy || index === 0} onClick={() => onMove(index, -1)}>▲</button>
                        <button type="button" aria-label={`Bajar ${opcion.etiqueta}`} className="w-8 h-6 text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] disabled:opacity-20" disabled={busy || index === total - 1} onClick={() => onMove(index, 1)}>▼</button>
                    </div>
                    <div className="flex-1 min-w-[160px]">
                        <p className="text-[15px] font-bold text-[var(--color-ink)]">
                            {opcion.color ? <span className="px-2 py-0.5 rounded" style={badgeStyle(opcion.color)}>{opcion.etiqueta}</span> : opcion.etiqueta}
                            {!opcion.activo && <span className="ml-2 text-[11px] font-bold uppercase text-red-600">Desactivada</span>}
                        </p>
                        <p className="text-[12px] text-[var(--color-ink-faint)]">
                            {opcion.valor !== opcion.etiqueta && <>Valor guardado: <span className="font-mono">{opcion.valor}</span> · </>}
                            {opcion.usos === 0 ? 'Sin uso en fichas' : `En ${opcion.usos.toLocaleString('es-CO')} registro${opcion.usos === 1 ? '' : 's'}`}
                            {opcion.protegido && ' · Valor por defecto del sistema'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className={btnSecondary} disabled={busy} onClick={() => setEditing(true)}>Editar</button>
                        <button type="button" className={btnSecondary} disabled={busy || (opcion.activo && opcion.protegido)} onClick={() => onToggle(opcion)}>
                            {opcion.activo ? 'Desactivar' : 'Activar'}
                        </button>
                        {canDelete && <button type="button" className={`${btnSecondary} text-red-700`} disabled={busy} onClick={() => onDelete(opcion)}>Borrar</button>}
                    </div>
                </div>
            )}
        </li>
    );
}

export default function ConfiguracionLista({ lista, opciones: initial = [] }) {
    const [opciones, setOpciones] = useState(initial);
    const [nueva, setNueva] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');

    async function call(url, method, body, okMessage) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const res = await apiFetch(url, { method, body: body ? JSON.stringify(body) : undefined });
            if (!res.ok) {
                setError(await errorOf(res));
                return null;
            }
            const json = await res.json();
            setNotice(json.message || okMessage);
            return json;
        } catch {
            setError('Sin conexión con el servidor. Intente de nuevo.');
            return null;
        } finally {
            setBusy(false);
        }
    }

    async function add(e) {
        e.preventDefault();
        const json = await call(`/admin/configuracion/listas/${lista.clave}/opciones`, 'POST', { etiqueta: nueva }, 'Opción agregada.');
        if (json?.data) {
            setOpciones(list => [...list, { ...json.data, usos: 0 }]);
            setNueva('');
        }
    }

    async function save(opcion, changes) {
        const json = await call(`/admin/configuracion/opciones/${opcion.id}`, 'PUT', changes, 'Opción guardada.');
        if (json?.data) setOpciones(list => list.map(o => (o.id === opcion.id ? { ...o, ...json.data } : o)));

        return !!json;
    }

    async function remove(opcion) {
        const json = await call(`/admin/configuracion/opciones/${opcion.id}`, 'DELETE', null, 'Opción borrada.');
        if (json) setOpciones(list => list.filter(o => o.id !== opcion.id));
    }

    async function move(index, delta) {
        const next = [...opciones];
        [next[index], next[index + delta]] = [next[index + delta], next[index]];
        const json = await call(`/admin/configuracion/listas/${lista.clave}/orden`, 'POST', { ids: next.map(o => o.id) }, 'Orden guardado.');
        if (json) setOpciones(next.map((o, i) => ({ ...o, orden: i + 1 })));
    }

    const activas = opciones.filter(o => o.activo).length;

    return (
        <AppLayout title={lista.nombre} breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CONFIGURACIÓN', href: '/admin/configuracion' }, { label: lista.nombre.toUpperCase() }]}>
            <Head title={`${lista.nombre} — Configuración`} />
            <ConfigHeader title={lista.nombre.toUpperCase()} subtitle={`${lista.descripcion} ${activas} de ${opciones.length} opciones activas.`} />

            <div className="p-4 lg:p-6 space-y-4 max-w-4xl">
                <p className="text-[13px] text-[var(--color-ink-soft)]">
                    {lista.fijo
                        ? 'El sistema usa estos valores: puede cambiar el nombre que se ve, el orden, el color y desactivarlas, pero no agregar ni borrar opciones.'
                        : 'Cambiar el nombre solo cambia lo que se ve: las fichas guardan el valor. Una opción desactivada deja de ofrecerse, pero las fichas que ya la tienen la conservan. Solo se borran las opciones que ninguna ficha usa.'}
                </p>

                <Notice notice={notice} error={error} />

                {!lista.fijo && (
                    <form onSubmit={add} className="flex flex-wrap items-end gap-3 bg-blue-50 rounded-xl p-4">
                        <label className="flex-1 min-w-[200px]">
                            <span className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">Nueva opción</span>
                            <input className={inputCls} value={nueva} maxLength={100} onChange={e => setNueva(e.target.value)} placeholder="Nombre de la opción" />
                        </label>
                        <button type="submit" className={btnPrimary} disabled={busy || nueva.trim() === ''}>Agregar</button>
                    </form>
                )}

                <ul className="space-y-2" aria-label="Opciones">
                    {opciones.map((o, i) => (
                        <OptionRow
                            key={o.id}
                            opcion={o}
                            index={i}
                            total={opciones.length}
                            fijo={lista.fijo}
                            busy={busy}
                            onSave={save}
                            onToggle={op => save(op, { activo: !op.activo })}
                            onDelete={remove}
                            onMove={move}
                        />
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
