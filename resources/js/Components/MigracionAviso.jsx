import { useState } from 'react';

// Electo import notice of a ficha: what the team still has to complete, where Electo said something else and why it
// came in as it did. Discreet and folded: the fields stay editable in the form, and saving a completed field clears
// its mark.
export default function MigracionAviso({ migracion, className = '' }) {
    const [open, setOpen] = useState(false);
    if (!migracion) return null;
    const { por_completar: pendientes = [], diferencias = [], marcas = [] } = migracion;
    const total = pendientes.length + diferencias.length + marcas.length;
    if (total === 0) return null;

    const resumen = [
        pendientes.length > 0 && `${pendientes.length} por completar`,
        diferencias.length > 0 && `${diferencias.length} ${diferencias.length === 1 ? 'diferencia' : 'diferencias'} con Electo`,
        marcas.length > 0 && `${marcas.length} ${marcas.length === 1 ? 'aviso' : 'avisos'}`,
    ].filter(Boolean).join(' · ');

    return (
        <div className={`rounded-lg border border-amber-200 bg-amber-50 text-[13px] text-[var(--color-ink)] ${className}`}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="w-full flex items-center justify-between gap-2 px-3 py-2 text-left">
                <span><span className="font-bold">Datos de Electo:</span> {resumen}</span>
                <span className="text-[12px] font-semibold text-amber-800 underline flex-shrink-0">{open ? 'Ocultar' : 'Ver'}</span>
            </button>
            {open && (
                <div className="px-3 pb-3 space-y-2">
                    {pendientes.length > 0 && (
                        <div>
                            <p className="font-semibold">Falta completar</p>
                            <ul className="list-disc ml-5">
                                {pendientes.map(p => (
                                    <li key={p.campo}>{p.label}{p.codigo_electo ? <span className="text-[var(--color-ink-faint)]"> (código en Electo: {p.codigo_electo})</span> : null}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {diferencias.length > 0 && (
                        <div>
                            <p className="font-semibold">Diferente en Electo</p>
                            <ul className="list-disc ml-5">
                                {diferencias.map(d => (
                                    <li key={d.campo}>{d.label}: aquí <span className="font-semibold">{d.actual || '—'}</span>, en Electo <span className="font-semibold">{d.electo || '—'}</span></li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {marcas.length > 0 && (
                        <ul className="list-disc ml-5">
                            {marcas.map(m => <li key={m}>{m}</li>)}
                        </ul>
                    )}
                    <p className="text-[12px] text-[var(--color-ink-faint)]">Corrige en la ficha y guarda: lo que completes deja de aparecer aquí.</p>
                </div>
            )}
        </div>
    );
}
