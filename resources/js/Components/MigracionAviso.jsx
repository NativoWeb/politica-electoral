import { useEffect, useState } from 'react';
import { apiFetch } from '@/lib/api';

// Why a resolution failed, in words the user can act on: the server's reason when it gives one.
async function errorDe(response) {
    if (response.status === 401 || response.status === 419) return 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.';
    const body = await response.json().catch(() => null);
    const primero = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return primero || body?.message || 'No se pudo guardar. Intenta de nuevo.';
}

// Notice of a ficha with marks (lideres.migracion_flags) from the Electo import or created by an acta: what the team still has to complete, where Electo said something else and why it
// came in as it did. Discreet and folded: the fields stay editable in the form, and saving a completed field clears
// its mark. Each difference with Electo can be resolved here (puedeResolver): keep ours or take Electo's value.
// abierto/onAbiertoChange: the parent keeps it open while the ficha reloads (the notice unmounts behind its spinner).
export default function MigracionAviso({ migracion, className = '', liderId = null, puedeResolver = false, onResuelta, abierto, onAbiertoChange }) {
    const [openLocal, setOpenLocal] = useState(false);
    const open = abierto ?? openLocal;
    const setOpen = value => (onAbiertoChange ? onAbiertoChange(value) : setOpenLocal(value));
    const [aviso, setAviso] = useState(migracion);
    // campo => 'aqui' | 'electo' while its request runs; a single one at a time.
    const [enCurso, setEnCurso] = useState(null);
    const [confirmando, setConfirmando] = useState(null);
    const [errores, setErrores] = useState({});

    useEffect(() => {
        setAviso(migracion);
        setConfirmando(null);
        setErrores({});
    }, [migracion]);

    if (!aviso) return null;
    const { por_completar: pendientes = [], diferencias = [], marcas = [] } = aviso;
    const total = pendientes.length + diferencias.length + marcas.length;
    if (total === 0) return null;
    const resolver = puedeResolver && !!liderId;

    async function resolverDiferencia(d, accion) {
        if (enCurso) return;
        setEnCurso({ campo: d.campo, accion });
        setConfirmando(null);
        setErrores(prev => ({ ...prev, [d.campo]: null }));
        try {
            const r = await apiFetch(`/votantes/${liderId}/electo/diferencias/${d.campo}`, { method: 'POST', body: JSON.stringify({ accion }) });
            if (!r.ok) {
                const mensaje = await errorDe(r);
                setErrores(prev => ({ ...prev, [d.campo]: mensaje }));
                return;
            }
            const res = await r.json();
            setAviso(res.migracion ?? null);
            onResuelta?.({ campo: d.campo, accion, valor: res.valor ?? null, migracion: res.migracion ?? null, warning: res.warning ?? null });
        } catch {
            setErrores(prev => ({ ...prev, [d.campo]: 'No hay conexión. Intenta de nuevo cuando tengas señal.' }));
        } finally {
            setEnCurso(null);
        }
    }

    // «Datos de Electo» only for what Electo brought (its codes, differences or marks that name it); a ficha created by an
    // acta only lacks data (its «posible duplicado» mark does not come from Electo).
    const deElecto = aviso.origen ? aviso.origen === 'electo' : (diferencias.length > 0 || pendientes.some(p => p.codigo_electo) || marcas.some(m => /Electo/.test(m)));
    const resumen = [
        pendientes.length > 0 && `${pendientes.length} por completar`,
        diferencias.length > 0 && `${diferencias.length} ${diferencias.length === 1 ? 'diferencia' : 'diferencias'} con Electo`,
        marcas.length > 0 && `${marcas.length} ${marcas.length === 1 ? 'aviso' : 'avisos'}`,
    ].filter(Boolean).join(' · ');
    const boton = 'min-h-[48px] px-4 py-2 rounded-lg text-[16px] font-bold transition-colors disabled:opacity-50';

    return (
        <div className={`rounded-lg border border-amber-200 bg-amber-50 text-[13px] text-[var(--color-ink)] ${className}`}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="w-full flex items-center justify-between gap-2 px-3 py-2 text-left">
                <span><span className="font-bold">{deElecto ? 'Datos de Electo' : 'Datos por completar'}:</span> {resumen}</span>
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
                            <ul className={resolver ? 'space-y-3 mt-1' : 'list-disc ml-5'}>
                                {diferencias.map(d => {
                                    const texto = <>{d.label}: aquí <span className="font-semibold">{d.actual || '—'}</span>, en Electo <span className="font-semibold">{d.electo || '—'}</span></>;
                                    if (!resolver) return <li key={d.campo}>{texto}</li>;
                                    const ocupado = !!enCurso;
                                    const esteEnCurso = enCurso?.campo === d.campo;
                                    const usarElecto = d.usar_electo !== false;
                                    return (
                                        <li key={d.campo} className="rounded-lg bg-white/70 border border-amber-200 p-3 text-[16px]">
                                            <p>{texto}</p>
                                            {confirmando === d.campo ? (
                                                <div className="mt-2">
                                                    <p className="font-semibold">Se cambiará {d.label} a «{d.electo}». ¿Continuar?</p>
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        <button type="button" disabled={ocupado} onClick={() => resolverDiferencia(d, 'electo')} className={`${boton} bg-[var(--color-primary)] text-white hover:opacity-90`}>
                                                            Sí, cambiar
                                                        </button>
                                                        <button type="button" disabled={ocupado} onClick={() => setConfirmando(null)} className={`${boton} bg-white border-2 border-[var(--color-line)] text-[var(--color-ink)]`}>
                                                            No
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <button type="button" disabled={ocupado} onClick={() => resolverDiferencia(d, 'aqui')} className={`${boton} bg-white border-2 border-[var(--color-primary)] text-[var(--color-primary)] hover:bg-blue-50`}>
                                                        {esteEnCurso && enCurso.accion === 'aqui' ? 'Guardando…' : 'Dejar el de aquí'}
                                                    </button>
                                                    {usarElecto && (
                                                        <button type="button" disabled={ocupado} onClick={() => setConfirmando(d.campo)} className={`${boton} bg-[var(--color-primary)] text-white hover:opacity-90`}>
                                                            {esteEnCurso && enCurso.accion === 'electo' ? 'Guardando…' : 'Usar el de Electo'}
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                            {!usarElecto && <p className="mt-2 text-[14px] text-[var(--color-ink-faint)]">El dato de Electo no se puede usar tal cual: si hay que cambiarlo, edita la ficha.</p>}
                                            {errores[d.campo] && <p role="alert" className="mt-2 text-[15px] font-semibold text-red-700">{errores[d.campo]}</p>}
                                        </li>
                                    );
                                })}
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
