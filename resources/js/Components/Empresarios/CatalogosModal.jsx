import { useState } from 'react';
import { inputCls } from '@/Components/Empresarios/PersonaSearch';
import { failure, request } from '@/lib/actas';

const TITULOS = { sector_economico: 'Sectores económicos', sector: 'Sectores' };

// Editable catalogs (sector económico, sector): add a value of the campaign, rename or deactivate the editable ones.
export default function CatalogosModal({ catalogos, sharedAllowed, onClose, onChanged }) {
    const [items, setItems] = useState(catalogos);
    const [nuevo, setNuevo] = useState({ sector_economico: '', sector: '' });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function send(url, method, json) {
        setBusy(true);
        setError('');
        const res = await request(url, { method, json });
        setBusy(false);
        if (!res.ok) { setError(res.body?.errors?.nombre?.[0] ?? failure(res, 'No se pudo guardar.')); return false; }
        setItems(res.body.data);
        onChanged?.(res.body.data);
        return true;
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-50" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none">
                <div role="dialog" aria-modal="true" aria-labelledby="catalogos-title" onKeyDown={e => { if (e.key === 'Escape') onClose(); }} className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[640px] max-h-[90vh] overflow-y-auto p-5 space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 id="catalogos-title" className="text-[19px] font-extrabold">Listas de sectores</h2>
                        <button type="button" onClick={onClose} className="min-w-[44px] min-h-[44px] rounded-lg border-2 border-gray-200 text-[18px] font-bold" aria-label="Cerrar">×</button>
                    </div>
                    {error && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{error}</p>}
                    {Object.keys(TITULOS).map(tipo => (
                        <section key={tipo} className="space-y-2" aria-label={TITULOS[tipo]}>
                            <h3 className="text-[16px] font-extrabold">{TITULOS[tipo]}</h3>
                            <ul className="space-y-1">
                                {(items[tipo] ?? []).map(c => (
                                    <li key={c.id} className="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border border-[var(--color-line)]">
                                        <span className={`text-[15px] ${c.is_active ? '' : 'line-through text-[var(--color-ink-faint)]'}`}>{c.nombre}{c.compartido && <span className="ml-2 text-[12px] text-[var(--color-ink-faint)]">(inicial)</span>}</span>
                                        {c.editable && (
                                            <button type="button" disabled={busy} onClick={() => send(`/empresarios/catalogos/${c.id}`, 'PUT', { nombre: c.nombre, is_active: !c.is_active })} className="min-h-[44px] px-3 text-[14px] font-bold text-[var(--color-primary)]">
                                                {c.is_active ? 'Desactivar' : 'Activar'}
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <form className="flex gap-2" onSubmit={async e => { e.preventDefault(); if (await send('/empresarios/catalogos', 'POST', { tipo, nombre: nuevo[tipo], compartido: sharedAllowed })) setNuevo(n => ({ ...n, [tipo]: '' })); }}>
                                <input className={inputCls} value={nuevo[tipo]} maxLength={120} required placeholder="Nuevo valor" aria-label={`Nuevo valor de ${TITULOS[tipo]}`} onChange={e => setNuevo(n => ({ ...n, [tipo]: e.target.value }))} />
                                <button type="submit" disabled={busy} className="min-h-[44px] px-4 rounded-lg bg-[var(--color-primary)] text-white text-[14px] font-bold">Agregar</button>
                            </form>
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}
