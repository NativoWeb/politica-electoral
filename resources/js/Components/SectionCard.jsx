/**
 * Card contenedora con título de sección. Base reutilizable para todas las páginas.
 */
export default function SectionCard({ title, action, className = '', children }) {
    return (
        <div className={`bg-white rounded-lg border border-[var(--color-line)] ${className}`}>
            {(title || action) && (
                <div className="flex items-center justify-between px-5 py-3 border-b border-[var(--color-line)]">
                    {title && (
                        <h2 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">
                            {title}
                        </h2>
                    )}
                    {action && <div>{action}</div>}
                </div>
            )}
            <div className="p-5">
                {children}
            </div>
        </div>
    );
}
