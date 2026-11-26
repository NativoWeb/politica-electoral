import { useId, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { IconoAlerta, IconoCheck, focoCls } from '@/Components/Reportes/ui';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';

export { Girando, IconoAlerta, IconoBuscar, IconoChevron, IconoDescarga, IconoX, focoCls } from '@/Components/Reportes/ui';

// Pieces of the Campañas electorales screens, at the size of the rest of the platform (Votantes, Mapa Político):
// 14-15 px text and 40 px controls on a desktop; 44 px touch targets on a phone (tactilCls).

export const tactilCls = 'min-h-11 sm:min-h-10';

const VARIANTES = {
    primario: 'bg-primary text-white border border-primary hover:bg-primary-light hover:border-primary-light active:bg-primary-dark',
    secundario: 'bg-panel text-primary border border-primary hover:bg-primary/5 active:bg-primary/10',
    suave: 'bg-transparent text-primary border border-transparent hover:bg-primary/5 underline underline-offset-4',
};

const botonBaseCls = `inline-flex items-center justify-center gap-2 ${tactilCls} px-4 text-sm font-semibold rounded-md transition-[background-color,border-color,transform] duration-150 active:scale-[0.98] ${focoCls}`;

export const botonDescargaCls = `${botonBaseCls} ${VARIANTES.secundario}`;

export function Boton({ variante = 'primario', className = '', children, type = 'button', ...props }) {
    return (
        <button
            type={type}
            className={`${botonBaseCls} disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100 ${VARIANTES[variante]} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

export function LinkBoton({ variante = 'primario', className = '', children, ...props }) {
    return (
        <Link className={`${botonBaseCls} ${VARIANTES[variante]} ${className}`} {...props}>
            {children}
        </Link>
    );
}

export function Chip({ activo, children, className = '', ...props }) {
    return (
        <button
            type="button"
            aria-pressed={activo}
            className={`inline-flex items-center gap-1.5 min-h-11 sm:min-h-9 px-3 text-sm rounded-full border transition-colors duration-150 ${focoCls} ${
                activo ? 'bg-primary border-primary text-white font-semibold' : 'bg-panel border-line-strong text-ink hover:border-primary'
            } ${className}`}
            {...props}
        >
            {activo && <IconoCheck className="w-4 h-4 flex-shrink-0" />}
            {children}
        </button>
    );
}

export function AvisoError({ children, accion }) {
    return (
        <div role="alert" className="flex flex-col sm:flex-row sm:items-center gap-3 p-3 rounded-md border border-danger bg-danger-light text-ink text-sm">
            <span className="flex items-start gap-2 flex-1"><IconoAlerta className="w-5 h-5 text-danger flex-shrink-0" />{children}</span>
            {accion}
        </div>
    );
}

// A question before doing something: a bottom sheet on a phone, centered on a desktop. Escape and the backdrop go back.
export function Dialogo({ titulo, children, acciones, onVolver }) {
    const ref = useRef(null);
    const id = useId();
    useDialogFocus(ref);
    useEscapeKey(onVolver, ref);
    return (
        <>
            <div className="fixed inset-0 z-50 bg-ink/60" onClick={onVolver} aria-hidden="true" />
            <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 pointer-events-none">
                <div
                    ref={ref}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby={`${id}-t`}
                    aria-describedby={`${id}-d`}
                    className="pointer-events-auto w-full sm:max-w-md max-h-[100dvh] overflow-y-auto bg-panel rounded-t-2xl sm:rounded-xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))] sm:pb-5 shadow-2xl"
                >
                    <h2 id={`${id}-t`} className="font-heading text-lg font-bold text-ink">{titulo}</h2>
                    <div id={`${id}-d`} className="mt-2 space-y-2 text-[15px] text-ink text-pretty">{children}</div>
                    <div className="mt-5 flex flex-col sm:flex-row-reverse sm:flex-wrap gap-2">{acciones}</div>
                </div>
            </div>
        </>
    );
}

export function Estado({ archivada }) {
    return (
        <span className={`inline-flex items-center h-6 px-2.5 rounded-full text-xs font-semibold border ${archivada ? 'bg-bg text-ink-soft border-line-strong' : 'bg-good-light text-ink border-good'}`}>
            {archivada ? 'Archivada' : 'Activa'}
        </span>
    );
}

// The swatch of a territory in the tablero (same paint as the map): solid, striped or white with a dotted border.
export function Muestra({ clase, color, className = 'w-4 h-4' }) {
    if (clase === 'insuficiente') {
        return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm border border-line-strong ${className}`} style={{ background: 'repeating-linear-gradient(45deg, #e5e7eb 0 4px, #9ca3af 4px 6px)' }} />;
    }
    if (clase === 'fuera') return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm border-2 border-dashed border-line-strong bg-white ${className}`} />;
    return <span aria-hidden="true" className={`inline-block flex-shrink-0 rounded-sm ${className}`} style={{ background: color }} />;
}

export function FranjaSoloLectura({ children }) {
    return (
        <div role="note" className="flex items-start gap-2 p-3 rounded-md border border-line-strong bg-bg text-sm text-ink">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="w-5 h-5 flex-shrink-0 text-ink-soft">
                <rect x="5" y="11" width="14" height="10" rx="2" /><path strokeLinecap="round" d="M8 11V7a4 4 0 118 0v4" />
            </svg>
            <span><strong className="font-semibold">Solo lectura.</strong> {children}</span>
        </div>
    );
}

export const IconoMas = ({ className = 'w-4 h-4' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5} className={className}><path strokeLinecap="round" d="M12 5v14M5 12h14" /></svg>
);

export const IconoSubir = ({ className = 'w-4 h-4' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M12 20V9m0 0l-4 4m4-4l4 4M5 4h14" /></svg>
);

export const IconoReloj = ({ className = 'w-4 h-4' }) => (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className={className}><circle cx="12" cy="12" r="9" /><path strokeLinecap="round" d="M12 7v5l3 2" /></svg>
);

export const tituloPaginaCls = 'font-heading text-[22px] lg:text-[28px] font-extrabold text-primary leading-tight text-balance break-words';
export const tituloSeccionCls = 'font-heading text-lg font-bold text-ink';
export const tarjetaCls = 'bg-panel border border-line rounded-lg';

export const campoCls = `w-full ${tactilCls} px-3 text-[15px] text-ink rounded-md border border-line-strong bg-white focus:border-primary ${focoCls}`;
export const etiquetaCls = 'block text-sm font-semibold text-ink';
export const ayudaCls = 'mt-1 text-[13px] text-ink-soft';

export function ErrorCampo({ id, children }) {
    if (!children) return null;
    return <p id={id} className="mt-1 text-sm font-semibold text-danger">{children}</p>;
}
