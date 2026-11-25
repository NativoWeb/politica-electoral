import { Link } from '@inertiajs/react';
import { focoCls } from '@/Components/Reportes/ui';

// Pieces of the Campañas electorales screens on top of Components/Reportes/ui.jsx (docs/DESIGN.md sizes).

const LINK_VARIANTES = {
    primario: 'bg-primary text-white border-2 border-primary hover:bg-primary-light hover:border-primary-light active:bg-primary-dark',
    secundario: 'bg-panel text-primary border-2 border-primary hover:bg-primary/5 active:bg-primary/10',
};

export function LinkBoton({ variante = 'primario', className = '', children, ...props }) {
    return (
        <Link
            className={`inline-flex items-center justify-center gap-2 min-h-14 px-6 text-lg font-semibold rounded-md transition-[background-color,border-color,transform] duration-150 active:scale-[0.98] ${focoCls} ${LINK_VARIANTES[variante]} ${className}`}
            {...props}
        >
            {children}
        </Link>
    );
}

export function Estado({ archivada }) {
    return (
        <span className={`inline-flex items-center min-h-8 px-3 rounded-full text-base font-semibold ${archivada ? 'bg-bg text-ink-soft border-2 border-line-strong' : 'bg-good-light text-ink border-2 border-good'}`}>
            {archivada ? 'Archivada' : 'Activa'}
        </span>
    );
}

// The swatch of a territory in the tablero (same paint as the map): solid, striped or white with a dotted border.
export function Muestra({ clase, color, className = 'w-6 h-6' }) {
    if (clase === 'insuficiente') {
        return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm border border-line-strong ${className}`} style={{ background: 'repeating-linear-gradient(45deg, #e5e7eb 0 4px, #9ca3af 4px 6px)' }} />;
    }
    if (clase === 'fuera') return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm border-2 border-dashed border-line-strong bg-white ${className}`} />;
    return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm ${className}`} style={{ background: color }} />;
}

export function FranjaSoloLectura({ children }) {
    return (
        <div role="note" className="flex items-start gap-3 p-4 rounded-md border-2 border-line-strong bg-bg text-lg text-ink">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-6 h-6 flex-shrink-0 mt-0.5 text-ink-soft">
                <rect x="5" y="11" width="14" height="10" rx="2" /><path strokeLinecap="round" d="M8 11V7a4 4 0 118 0v4" />
            </svg>
            <span><strong className="font-semibold">Solo lectura.</strong> {children}</span>
        </div>
    );
}

export const IconoMas = ({ className = 'w-6 h-6' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5} className={className}><path strokeLinecap="round" d="M12 5v14M5 12h14" /></svg>
);

export const IconoSubir = ({ className = 'w-6 h-6' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M12 20V9m0 0l-4 4m4-4l4 4M5 4h14" /></svg>
);

export const IconoReloj = ({ className = 'w-6 h-6' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className={className}><circle cx="12" cy="12" r="9" /><path strokeLinecap="round" d="M12 7v5l3 2" /></svg>
);

export const campoCls = `w-full min-h-14 px-4 text-lg text-ink rounded-md border-2 border-line-strong bg-bg focus:border-primary ${focoCls}`;
export const etiquetaCls = 'block text-lg font-semibold text-ink';
export const ayudaCls = 'mt-1 text-base text-ink-soft';

export function ErrorCampo({ id, children }) {
    if (!children) return null;
    return <p id={id} className="mt-2 text-base font-semibold text-danger">{children}</p>;
}
