// Shared pieces of the Reportes screens. Sizes follow docs/DESIGN.md: nothing under 16px, 56px buttons,
// 48px chips, yellow focus ring.

const VARIANTES = {
    primario: 'bg-primary text-white border-2 border-primary hover:bg-primary-light hover:border-primary-light active:bg-primary-dark',
    secundario: 'bg-panel text-primary border-2 border-primary hover:bg-primary/5 active:bg-primary/10',
    suave: 'bg-transparent text-primary border-2 border-transparent hover:bg-primary/5 underline underline-offset-4',
};

export const focoCls = 'focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent';

export function Boton({ variante = 'primario', className = '', children, type = 'button', ...props }) {
    return (
        <button
            type={type}
            className={`inline-flex items-center justify-center gap-2 min-h-14 px-6 text-lg font-semibold rounded-md transition-[background-color,border-color,transform] duration-150 active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100 ${focoCls} ${VARIANTES[variante]} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

export function Chip({ activo, children, className = '', ...props }) {
    return (
        <button
            type="button"
            aria-pressed={activo}
            className={`inline-flex items-center gap-2 min-h-12 px-5 text-lg rounded-full border-2 transition-colors duration-150 ${focoCls} ${
                activo ? 'bg-primary border-primary text-white font-semibold' : 'bg-panel border-line-strong text-ink hover:border-primary'
            } ${className}`}
            {...props}
        >
            {activo && <IconoCheck className="w-5 h-5 flex-shrink-0" />}
            {children}
        </button>
    );
}

const svg = { fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor', 'aria-hidden': true, focusable: 'false' };

export const IconoCheck = ({ className = 'w-5 h-5' }) => (
    <svg {...svg} strokeWidth={3} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
);
export const IconoX = ({ className = 'w-5 h-5' }) => (
    <svg {...svg} strokeWidth={2.5} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
);
export const IconoCandado = ({ className = 'w-5 h-5' }) => (
    <svg {...svg} strokeWidth={2} className={className}><rect x="5" y="11" width="14" height="10" rx="2" /><path strokeLinecap="round" d="M8 11V7a4 4 0 118 0v4" /></svg>
);
export const IconoFlecha = ({ className = 'w-6 h-6', direccion = 'derecha' }) => (
    <svg {...svg} strokeWidth={2.5} className={`${className} ${direccion === 'izquierda' ? 'rotate-180' : ''}`}><path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6l6 6-6 6" /></svg>
);
export const IconoChevron = ({ className = 'w-6 h-6', abierto = false }) => (
    <svg {...svg} strokeWidth={2.5} className={`${className} transition-transform duration-200 ${abierto ? 'rotate-180' : ''}`}><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>
);
export const IconoDescarga = ({ className = 'w-6 h-6' }) => (
    <svg {...svg} strokeWidth={2} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" /></svg>
);
export const IconoBuscar = ({ className = 'w-6 h-6' }) => (
    <svg {...svg} strokeWidth={2} className={className}><circle cx="11" cy="11" r="7" /><path strokeLinecap="round" d="M20 20l-3.5-3.5" /></svg>
);
export const IconoAlerta = ({ className = 'w-6 h-6' }) => (
    <svg {...svg} strokeWidth={2} className={className}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5A2 2 0 004.1 20.5h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" /></svg>
);

export function Girando({ className = 'w-6 h-6' }) {
    return (
        <span aria-hidden="true" className={`inline-block rounded-full border-[3px] border-current border-r-transparent animate-spin motion-reduce:animate-none ${className}`} />
    );
}

export function AvisoError({ children, accion }) {
    return (
        <div role="alert" className="flex flex-col sm:flex-row sm:items-center gap-4 p-5 rounded-md border-2 border-danger bg-danger-light text-ink text-lg">
            <span className="flex items-start gap-3 flex-1"><IconoAlerta className="w-7 h-7 text-danger flex-shrink-0" />{children}</span>
            {accion}
        </div>
    );
}
