import { Link } from '@inertiajs/react';

// Laravel paginator links with Spanish labels for the first ("previous") and last ("next") entries.
export default function Pagination({ paginator, label, only }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const links = paginator.links ?? [];

    return (
        <nav aria-label={label} className="flex flex-wrap items-center gap-1.5">
            {links.map((l, i) => {
                const text = i === 0 ? 'Anterior' : i === links.length - 1 ? 'Siguiente' : l.label;
                const cls = `min-w-[44px] min-h-[44px] px-3 flex items-center justify-center rounded-lg border text-[15px] font-semibold ${l.active ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'bg-white border-[var(--color-line)] text-[var(--color-ink-soft)]'}`;
                return l.url
                    ? <Link key={i} href={l.url} preserveScroll preserveState only={only} aria-current={l.active ? 'page' : undefined} className={cls}>{text}</Link>
                    : <span key={i} className={`${cls} opacity-40`} aria-disabled="true">{text}</span>;
            })}
        </nav>
    );
}
