import { useState } from 'react';
import PersonaSearch, { inputCls } from '@/Components/Empresarios/PersonaSearch';
import { failure, request } from '@/lib/actas';

const EMPTY = { lider_id: null, nombre: '', cedula: '', telefono: '', municipio: '', rol: 'afiliado', nota: '' };

// Members of an organization: fichas of Votantes with a role. Adding one picks an existing ficha or types a new
// person (its ficha is created when saved). Removing a member never touches the ficha.
export default function MiembrosTab({ org, editable, roles = [], onChanged }) {
    const [draft, setDraft] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [editing, setEditing] = useState(null);

    async function send(url, method, json, okMessage) {
        setBusy(true);
        setError('');
        const res = await request(url, { method, json });
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo guardar.')); return false; }
        setNotice(res.body?.message ?? okMessage);
        onChanged?.(res.body?.data);
        return true;
    }

    async function add(e) {
        e.preventDefault();
        const body = draft.lider_id ? { lider_id: draft.lider_id, rol: draft.rol, nota: draft.nota || null }
            : { nombre: draft.nombre, cedula: draft.cedula || null, telefono: draft.telefono || null, municipio: draft.municipio || null, rol: draft.rol, nota: draft.nota || null };
        if (await send(`/empresarios/${org.id}/miembros`, 'POST', body, 'Miembro agregado.')) setDraft(null);
    }

    const rolSelect = (value, onChange, id) => (
        <select id={id} className={inputCls} value={value} onChange={e => onChange(e.target.value)}>
            {roles.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
        </select>
    );

    return (
        <div className="space-y-3">
            {notice && <p role="status" className="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-[14px] font-semibold text-emerald-800">{notice}</p>}
            {error && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{error}</p>}
            {org.miembros.length === 0 && <p className="text-[15px] text-[var(--color-ink-faint)]">Todavía no tiene miembros.</p>}
            <ul className="space-y-2" aria-label="Miembros">
                {org.miembros.map(m => (
                    <li key={m.id} className="p-3 rounded-xl border border-[var(--color-line)] bg-white">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-[15px] font-bold text-[var(--color-ink)] uppercase">{m.nombre}</p>
                                <p className="text-[14px] text-[var(--color-ink-soft)]">
                                    <span className="font-bold text-[var(--color-primary)]">{m.rol_label}</span>
                                    {[m.municipio, m.cedula && `C.C. ${m.cedula}`, m.telefono].filter(Boolean).map(t => ` · ${t}`).join('')}
                                </p>
                                {m.nota && <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">{m.nota}</p>}
                            </div>
                            {editable && editing?.id !== m.id && (
                                <div className="flex gap-1">
                                    <button type="button" className="min-h-[44px] px-3 text-[14px] font-bold text-[var(--color-primary)]" onClick={() => setEditing({ id: m.id, rol: m.rol, nota: m.nota ?? '' })} aria-label={`Editar a ${m.nombre}`}>Editar</button>
                                    <button type="button" disabled={busy} className="min-h-[44px] px-3 text-[14px] font-bold text-red-700" onClick={() => send(`/empresarios/miembros/${m.id}`, 'DELETE', undefined, 'Miembro quitado.')} aria-label={`Quitar a ${m.nombre}`}>Quitar</button>
                                </div>
                            )}
                        </div>
                        {editing?.id === m.id && (
                            <form className="mt-2 grid sm:grid-cols-3 gap-2" onSubmit={async e => { e.preventDefault(); if (await send(`/empresarios/miembros/${m.id}`, 'PUT', { rol: editing.rol, nota: editing.nota || null }, 'Miembro actualizado.')) setEditing(null); }}>
                                {rolSelect(editing.rol, rol => setEditing(x => ({ ...x, rol })), `rol-${m.id}`)}
                                <input className={inputCls} value={editing.nota} maxLength={500} placeholder="Nota" aria-label="Nota" onChange={e => setEditing(x => ({ ...x, nota: e.target.value }))} />
                                <div className="flex gap-2">
                                    <button type="submit" disabled={busy} className="flex-1 min-h-[44px] rounded-lg bg-[var(--color-primary)] text-white text-[14px] font-bold">Guardar</button>
                                    <button type="button" className="min-h-[44px] px-3 rounded-lg border-2 border-gray-200 text-[14px] font-bold" onClick={() => setEditing(null)}>Cancelar</button>
                                </div>
                            </form>
                        )}
                    </li>
                ))}
            </ul>

            {editable && !draft && (
                <PersonaSearch
                    id="org-miembro-buscar"
                    label="Agregar miembro"
                    allowNew
                    onPick={l => setDraft({ ...EMPTY, lider_id: l.id, nombre: l.nombre, telefono: l.telefono ?? '', municipio: l.municipio ?? '' })}
                    onNew={nombre => setDraft({ ...EMPTY, nombre, municipio: org.municipio ?? '' })}
                />
            )}
            {editable && draft && (
                <form onSubmit={add} className="border-2 border-[var(--color-primary)] rounded-xl p-3 space-y-2" aria-label="Nuevo miembro">
                    <p className="text-[15px] font-bold">{draft.lider_id ? `${draft.nombre} (ya está en Votantes)` : 'Persona nueva: se crea su ficha en Votantes al guardar (si la cédula ya está, se vincula).'}</p>
                    {!draft.lider_id && (
                        <div className="grid sm:grid-cols-2 gap-2">
                            <label className="block text-[14px] font-semibold">Nombre<input className={inputCls} value={draft.nombre} maxLength={200} required onChange={e => setDraft(d => ({ ...d, nombre: e.target.value }))} /></label>
                            <label className="block text-[14px] font-semibold">Cédula (si la tiene)<input className={inputCls} value={draft.cedula} maxLength={20} inputMode="numeric" onChange={e => setDraft(d => ({ ...d, cedula: e.target.value }))} /></label>
                            <label className="block text-[14px] font-semibold">Celular<input className={inputCls} value={draft.telefono} maxLength={60} inputMode="tel" onChange={e => setDraft(d => ({ ...d, telefono: e.target.value }))} /></label>
                            <label className="block text-[14px] font-semibold">Municipio<input className={inputCls} value={draft.municipio} maxLength={120} onChange={e => setDraft(d => ({ ...d, municipio: e.target.value }))} /></label>
                        </div>
                    )}
                    <div className="grid sm:grid-cols-2 gap-2">
                        <label className="block text-[14px] font-semibold" htmlFor="nuevo-rol">Rol{rolSelect(draft.rol, rol => setDraft(d => ({ ...d, rol })), 'nuevo-rol')}</label>
                        <label className="block text-[14px] font-semibold">Nota<input className={inputCls} value={draft.nota} maxLength={500} onChange={e => setDraft(d => ({ ...d, nota: e.target.value }))} /></label>
                    </div>
                    <div className="flex gap-2">
                        <button type="submit" disabled={busy} className="min-h-[48px] px-5 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold disabled:opacity-50">{busy ? 'Guardando…' : 'Agregar miembro'}</button>
                        <button type="button" className="min-h-[48px] px-5 rounded-xl border-2 border-gray-200 text-[15px] font-bold" onClick={() => setDraft(null)}>Cancelar</button>
                    </div>
                </form>
            )}
        </div>
    );
}
