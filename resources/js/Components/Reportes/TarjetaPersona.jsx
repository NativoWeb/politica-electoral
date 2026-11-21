import { focoCls } from './ui';

const SIN_DATO = '—';

export function textoCelda(valor) {
    if (valor === null || valor === undefined || valor === '') return SIN_DATO;
    if (Array.isArray(valor)) return valor.length ? valor.join(', ') : SIN_DATO;
    if (typeof valor === 'boolean') return valor ? 'Sí' : 'No';
    if (typeof valor === 'number') return valor.toLocaleString('es-CO');
    return String(valor);
}

const PRINCIPALES = ['nombre', 'municipio', 'tipo'];

// The phone version of a row: the name big, "Municipio · Tipo" under it and up to three more chosen data.
export default function TarjetaPersona({ fila, columnas, onAbrir, botonRef }) {
    const c = fila.celdas ?? {};
    const lugar = ['municipio', 'tipo'].map(k => c[k]).filter(v => v !== null && v !== undefined && v !== '').join(' · ');
    const extra = columnas.filter(col => !PRINCIPALES.includes(col.clave)).slice(0, 3);

    return (
        <button
            ref={botonRef}
            type="button"
            onClick={() => onAbrir(fila)}
            className={`w-full text-left p-5 rounded-lg bg-panel border-2 border-line hover:border-primary active:scale-[0.99] transition-[border-color,transform] duration-150 ${focoCls}`}
        >
            <span className="block text-xl font-bold text-ink">{textoCelda(c.nombre)}</span>
            {lugar && <span className="block mt-1 text-lg text-ink-soft">{lugar}</span>}
            {extra.length > 0 && (
                <dl className="mt-3 space-y-1">
                    {extra.map(col => (
                        <div key={col.clave} className="flex flex-wrap gap-x-2 text-lg">
                            <dt className="text-ink-soft">{col.etiqueta}:</dt>
                            <dd className="text-ink font-semibold break-words min-w-0">{textoCelda(c[col.clave])}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {fila.editable === false && <span className="mt-3 inline-block text-base text-ink-soft">Solo para ver</span>}
        </button>
    );
}
