import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConfigHeader, { Notice, errorOf } from '@/Components/Configuracion/ConfigHeader';
import { apiFetch } from '@/lib/api';

const ACCIONES = {
    crear: 'Agregó', editar: 'Cambió', activar: 'Activó', desactivar: 'Desactivó', eliminar: 'Borró',
    reordenar: 'Reordenó', restablecer: 'Volvió al valor por defecto', revertir: 'Revirtió',
};

const CAMPOS = { etiqueta: 'nombre', activo: 'activa', color: 'color', orden: 'orden', valor: 'valor' };

const FILTROS = [{ value: null, label: 'Todos' }, { value: 'catalogo', label: 'Listas' }, { value: 'ajuste', label: 'Ajustes' }];

function show(v) {
    if (v === true) return 'sí';
    if (v === false) return 'no';
    if (v === null || v === undefined || v === '') return '(vacío)';

    return String(v);
}

// "nombre: Soltero/a → Soltero(a)" from the before / after of a change.
export function resumenCambio(c) {
    if (c.antes?.ordenes || c.despues?.ordenes) return 'Orden de las opciones';
    if (c.tipo === 'ajuste') {
        return `${c.antes ? show(c.antes.valor) : 'por defecto'} → ${c.despues ? show(c.despues.valor) : 'por defecto'}`;
    }
    if (!c.antes) return `Nueva opción: ${show(c.despues?.etiqueta)}`;
    if (!c.despues) return `Opción quitada: ${show(c.antes?.etiqueta)}`;

    return Object.keys(CAMPOS)
        .filter(k => k in c.antes && c.antes[k] !== c.despues[k])
        .map(k => `${CAMPOS[k]}: ${show(c.antes[k])} → ${show(c.despues[k])}`)
        .join(' · ') || 'Sin diferencias';
}

function Row({ cambio, onReverted }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const fecha = cambio.fecha ? new Date(cambio.fecha).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '';

    async function revert() {
        setBusy(true);
        setError('');
        try {
            const res = await apiFetch(`/admin/configuracion/historial/${cambio.id}/revertir`, { method: 'POST' });
            if (!res.ok) {
                setError(await errorOf(res));
                return;
            }
            onReverted((await res.json()).message);
        } catch {
            setError('Sin conexión con el servidor. Intente de nuevo.');
        } finally {
            setBusy(false);
            setConfirming(false);
        }
    }

    return (
        <li className="bg-white border border-[var(--color-line)] rounded-xl px-4 py-3" aria-label={`${ACCIONES[cambio.accion] || cambio.accion} ${cambio.donde}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[14px] font-bold text-[var(--color-ink)]">
                        {ACCIONES[cambio.accion] || cambio.accion} · {cambio.donde}
                        {cambio.revertido && <span className="ml-2 text-[11px] font-bold uppercase text-gray-500">Revertido</span>}
                    </p>
                    <p className="text-[13px] text-[var(--color-ink-soft)] break-words">{resumenCambio(cambio)}</p>
                    <p className="text-[12px] text-[var(--color-ink-faint)]">{fecha} · {cambio.usuario || 'Usuario borrado'}</p>
                </div>
                {!cambio.revertido && (
                    confirming ? (
                        <div className="flex items-center gap-2">
                            <span className="text-[13px] font-semibold">¿Revertir?</span>
                            <button type="button" className="min-h-[40px] px-3 py-2 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg disabled:opacity-50" disabled={busy} onClick={revert}>Sí, revertir</button>
                            <button type="button" className="min-h-[40px] px-3 py-2 border border-[var(--color-line)] text-[13px] rounded-lg" disabled={busy} onClick={() => setConfirming(false)}>No</button>
                        </div>
                    ) : (
                        <button type="button" className="min-h-[40px] px-3 py-2 border border-[var(--color-line)] text-[13px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50" onClick={() => setConfirming(true)}>Revertir</button>
                    )
                )}
            </div>
            {error && <p role="alert" className="mt-2 text-[13px] font-semibold text-red-700">{error}</p>}
        </li>
    );
}

export default function ConfiguracionHistorial({ cambios, filtro = null }) {
    const [notice, setNotice] = useState('');
    const items = cambios?.data || [];

    function reverted(message) {
        setNotice(message);
        router.reload({ only: ['cambios'] });
    }

    return (
        <AppLayout title="Historial de configuración" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CONFIGURACIÓN', href: '/admin/configuracion' }, { label: 'HISTORIAL' }]}>
            <Head title="Historial — Configuración" />
            <ConfigHeader title="HISTORIAL DE CAMBIOS" subtitle="Quién cambió qué en las listas y los ajustes. Revertir aplica el valor anterior y queda registrado." />

            <div className="p-4 lg:p-6 space-y-3 max-w-4xl">
                <nav className="flex gap-2" aria-label="Filtrar cambios">
                    {FILTROS.map(f => (
                        <Link key={f.label} href={f.value ? `/admin/configuracion/historial?tipo=${f.value}` : '/admin/configuracion/historial'}
                            className={`min-h-[40px] px-4 py-2 rounded-lg text-[13px] font-bold border ${filtro === f.value ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'bg-white border-[var(--color-line)] text-[var(--color-ink-soft)]'}`}>
                            {f.label}
                        </Link>
                    ))}
                </nav>

                <Notice notice={notice} />

                {items.length === 0 ? (
                    <p className="text-[14px] text-[var(--color-ink-faint)] py-6 text-center">Todavía no hay cambios registrados.</p>
                ) : (
                    <ul className="space-y-2" aria-label="Cambios">
                        {items.map(c => <Row key={c.id} cambio={c} onReverted={reverted} />)}
                    </ul>
                )}

                {cambios?.last_page > 1 && (
                    <nav className="flex flex-wrap gap-1 pt-2" aria-label="Páginas">
                        {cambios.links.map((l, i) => (
                            l.url ? (
                                <Link key={i} href={l.url} preserveScroll className={`px-3 py-2 rounded-lg text-[13px] border ${l.active ? 'bg-[var(--color-primary)] text-white' : 'bg-white border-[var(--color-line)]'}`}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ) : (
                                <span key={i} className="px-3 py-2 text-[13px] text-gray-400" dangerouslySetInnerHTML={{ __html: l.label }} />
                            )
                        ))}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
