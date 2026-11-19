import { Link } from '@inertiajs/react';

// The white row of the admin home: icon, title, grey subtitle and ">".
export default function AdminCard({ href, title, subtitle, iconClass, icon }) {
    return (
        <Link
            href={href}
            className="flex items-center gap-4 px-5 py-4 bg-white border border-[var(--color-line)] rounded-xl hover:bg-gray-50 active:bg-gray-50 transition-colors"
        >
            <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${iconClass}`}>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">{icon}</svg>
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-[14px] font-bold text-[var(--color-ink)]">{title}</p>
                <p className="text-[12px] text-[var(--color-ink-faint)]">{subtitle}</p>
            </div>
            <svg className="w-5 h-5 text-gray-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
        </Link>
    );
}

export function BackToAdmin() {
    return (
        <Link href="/admin/territorio" className="inline-flex items-center gap-1.5 min-h-[44px] text-[14px] font-bold text-white/90 hover:text-white">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
            Volver a Admin
        </Link>
    );
}
