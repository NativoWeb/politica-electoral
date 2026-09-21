/**
 * Spinner visual grande con animación y mensaje.
 * Uso: <Spinner message="Cargando datos..." />
 */
export default function Spinner({ message = 'Cargando...', size = 'lg' }) {
    const sizes = {
        sm: { ring: 'w-8 h-8', text: 'text-[12px]' },
        md: { ring: 'w-12 h-12', text: 'text-[14px]' },
        lg: { ring: 'w-16 h-16', text: 'text-[16px]' },
    };
    const s = sizes[size] ?? sizes.lg;

    return (
        <div className="flex flex-col items-center justify-center gap-4 py-12">
            {/* Anillo giratorio */}
            <div className="relative">
                <div className={`${s.ring} rounded-full border-[4px] border-[var(--color-line)]`} />
                <div className={`${s.ring} rounded-full border-[4px] border-transparent border-t-[var(--color-primary)] border-r-[var(--color-primary)] absolute inset-0 animate-spin`} />
                {/* Punto central */}
                <div className="absolute inset-0 flex items-center justify-center">
                    <div className="w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] animate-pulse" />
                </div>
            </div>
            {/* Texto */}
            <p className={`${s.text} font-semibold text-[var(--color-ink-faint)] animate-pulse`}>{message}</p>
        </div>
    );
}

/**
 * Spinner de pantalla completa (overlay).
 * Uso: <FullScreenSpinner message="Procesando..." />
 */
export function FullScreenSpinner({ message = 'Cargando...' }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="bg-white rounded-2xl shadow-2xl px-12 py-10 flex flex-col items-center gap-5">
                <div className="relative">
                    <div className="w-20 h-20 rounded-full border-[5px] border-[var(--color-line)]" />
                    <div className="w-20 h-20 rounded-full border-[5px] border-transparent border-t-[var(--color-primary)] border-r-[var(--color-accent)] absolute inset-0 animate-spin" />
                    <div className="absolute inset-0 flex items-center justify-center">
                        <div className="w-8 h-5 rounded-[3px] overflow-hidden flex flex-col flex-shrink-0">
                            <span className="flex-[2] bg-[#FCD116]" />
                            <span className="flex-1 bg-[#003893]" />
                            <span className="flex-1 bg-[#CE1126]" />
                        </div>
                    </div>
                </div>
                <p className="text-[18px] font-bold text-[var(--color-ink)]">{message}</p>
                <div className="flex gap-1.5">
                    <span className="w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] animate-bounce" style={{ animationDelay: '0ms' }} />
                    <span className="w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] animate-bounce" style={{ animationDelay: '150ms' }} />
                    <span className="w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] animate-bounce" style={{ animationDelay: '300ms' }} />
                </div>
            </div>
        </div>
    );
}
