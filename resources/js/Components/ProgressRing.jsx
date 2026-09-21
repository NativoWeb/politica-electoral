/**
 * Anillo de progreso SVG.
 * dark=true para usar sobre fondos oscuros (textos blancos).
 */
export default function ProgressRing({
    value = 0,
    size = 120,
    stroke = 8,
    label,
    sub,
    color = 'var(--color-primary)',
    trackColor = '#E8E8EE',
    dark = false,
}) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const clamped = Math.min(Math.max(value, 0), 100);
    const offset = circumference - (clamped / 100) * circumference;

    return (
        <div className="flex flex-col items-center gap-1">
            <div className="relative" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90">
                    <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={trackColor} strokeWidth={stroke} />
                    <circle
                        cx={size / 2} cy={size / 2} r={radius} fill="none"
                        stroke={color} strokeWidth={stroke} strokeLinecap="round"
                        strokeDasharray={circumference} strokeDashoffset={offset}
                        className="transition-[stroke-dashoffset] duration-700 ease-out"
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span
                        className="text-[22px] font-extrabold leading-none font-[var(--font-mono)]"
                        style={{ color: dark ? '#ffffff' : 'var(--color-ink)' }}
                    >
                        {clamped.toFixed(1).replace('.', ',')}%
                    </span>
                </div>
            </div>
            {label && (
                <span
                    className="text-[11px] font-bold uppercase tracking-widest"
                    style={{ color: dark ? 'rgba(255,255,255,0.6)' : 'var(--color-ink-faint)' }}
                >
                    {label}
                </span>
            )}
            {sub && (
                <span
                    className="text-[10px] text-center leading-tight"
                    style={{ color: dark ? 'rgba(255,255,255,0.4)' : 'var(--color-ink-faint)' }}
                >
                    {sub}
                </span>
            )}
        </div>
    );
}
