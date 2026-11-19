import { Link } from '@inertiajs/react';

// Blue header of the Centro de configuración screens, with the way back.
export default function ConfigHeader({ title, subtitle, back = { href: '/admin/configuracion', label: 'Volver a Configuración' } }) {
    return (
        <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 pt-1 pb-3 lg:pb-4">
            <Link href={back.href} className="inline-flex items-center gap-1.5 min-h-[44px] text-[14px] font-bold text-white/90 hover:text-white">
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                {back.label}
            </Link>
            <h1 className="text-[18px] lg:text-[22px] font-extrabold">{title}</h1>
            {subtitle && <p className="text-[12px] text-white/60 mt-0.5">{subtitle}</p>}
        </div>
    );
}

export function Notice({ notice, error }) {
    return (
        <>
            {notice && <p role="status" className="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-[14px] font-semibold text-emerald-800">{notice}</p>}
            {error && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{error}</p>}
        </>
    );
}

// The message of a JSON answer of the Centro: the first validation error, the message, or a generic one.
export async function errorOf(res) {
    const body = await res.json().catch(() => null);
    if (res.status === 403) return 'Solo un superadministrador puede cambiar la configuración.';
    if (body?.errors) return Object.values(body.errors).flat()[0];

    return body?.message || 'No se pudo guardar. Intente de nuevo.';
}
