import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { formatDate, request } from '@/lib/actas';

const TTL_MS = 5 * 60 * 1000;
const TIPOS = { vencido: 'Vencido', por_vencer: 'Por vencer', recordatorio: 'Recordatorio' };
const TIPO_CLS = { vencido: 'text-red-700', por_vencer: 'text-amber-700', recordatorio: 'text-[var(--color-primary)]' };

// One request per 5 minutes for the whole SPA (the server caches as long), not one per page.
let cache = { at: 0, data: null, pending: null };

export function resetCampanaCache() {
    cache = { at: 0, data: null, pending: null };
}

function load() {
    if (cache.data && Date.now() - cache.at < TTL_MS) return Promise.resolve(cache.data);
    cache.pending ??= request('/eventos/compromisos/alertas').then(res => {
        cache.pending = null;
        if (!res.ok) return null;
        cache = { at: Date.now(), data: res.body.data, pending: null };
        return cache.data;
    });
    return cache.pending;
}

// Bell of the header: compromisos vencidos, por vencer (3 days) and reminders of today, of confirmed actas.
export default function CompromisosCampana() {
    const [data, setData] = useState(cache.data);
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        let alive = true;
        load().then(d => { if (alive && d) setData(d); });
        return () => { alive = false; };
    }, []);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = e => { if (e.key === 'Escape') setOpen(false); };
        const onClick = e => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClick);
        return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('mousedown', onClick); };
    }, [open]);

    const total = data?.total ?? 0;
    const label = total === 0 ? 'Compromisos: nada pendiente' : `Compromisos: ${total} para revisar`;

    return (
        <div ref={ref} className="relative">
            <button type="button" onClick={() => setOpen(o => !o)} aria-expanded={open} aria-label={label} title={label} className="relative w-9 h-9 rounded-full flex items-center justify-center hover:bg-white/10 active:bg-white/20 text-white">
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                {total > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[11px] font-bold leading-[18px] text-center">{total > 99 ? '99+' : total}</span>
                )}
            </button>
            {open && (
                <div className="absolute right-0 top-full mt-1 w-[min(22rem,calc(100vw-1.5rem))] bg-white rounded-xl shadow-2xl border border-gray-200 z-50 text-[var(--color-ink)]">
                    <div className="px-4 py-3 border-b border-gray-100">
                        <p className="text-[15px] font-extrabold">Compromisos</p>
                        <p className="text-[13px] text-[var(--color-ink-soft)]">
                            {data ? `${data.vencidos} vencidos · ${data.por_vencer} por vencer · ${data.recordatorios} recordatorios` : 'Cargando…'}
                        </p>
                    </div>
                    {data && data.items.length === 0 && <p className="px-4 py-4 text-[14px] text-[var(--color-ink-faint)]">No hay compromisos para revisar.</p>}
                    {data?.items?.length > 0 && (
                        <ul className="max-h-[60vh] overflow-y-auto divide-y divide-gray-100" aria-label="Compromisos para revisar">
                            {data.items.map(i => (
                                <li key={i.id}>
                                    <Link href={i.url} onClick={() => setOpen(false)} className="block px-4 py-2.5 hover:bg-blue-50">
                                        <span className={`text-[12px] font-bold ${TIPO_CLS[i.tipo] ?? ''}`}>{TIPOS[i.tipo] ?? i.tipo}</span>
                                        <span className="block text-[14px] leading-snug line-clamp-2">{i.descripcion}</span>
                                        <span className="block text-[12px] text-[var(--color-ink-soft)]">
                                            {[i.evento, i.fecha_limite && `Fecha límite: ${formatDate(i.fecha_limite)}`].filter(Boolean).join(' · ')}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link href="/eventos/compromisos" onClick={() => setOpen(false)} className="block px-4 py-3 border-t border-gray-100 text-[14px] font-bold text-[var(--color-primary)]">
                        Ver todos los compromisos
                    </Link>
                </div>
            )}
        </div>
    );
}
