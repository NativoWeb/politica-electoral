import { tarjetaDe } from '@/lib/reportes';
import { IconoFlecha, focoCls } from './ui';

const SIN_DATO = '—';

export function textoCelda(valor) {
    if (valor === null || valor === undefined || valor === '') return SIN_DATO;
    if (Array.isArray(valor)) return valor.length ? valor.join(', ') : SIN_DATO;
    if (typeof valor === 'boolean') return valor ? 'Sí' : 'No';
    if (typeof valor === 'number') return valor.toLocaleString('es-CO');
    return String(valor);
}

// The phone version of a row: the name (or the first chosen data) big, a line under it that depends on the source
// ("Municipio · Tipo", "Evento · fecha", "Estado · fecha límite"; lib/reportes tarjetaDe) and up to three more data.
// Without onAbrir (a row with no accion) it is plain text: no button, no arrow.
// soloVer: the ficha it opens cannot be edited by this user.
export default function TarjetaPersona({ fila, columnas, fuenteClave = 'personas', onAbrir, botonRef, soloVer = false }) {
    const c = fila.celdas ?? {};
    const { titulo, subtitulo, datos } = tarjetaDe(fuenteClave, fila, columnas);

    const contenido = (
        <span className="flex-1 min-w-0">
            <span className="block text-base font-bold text-ink break-words">{textoCelda(c[titulo])}</span>
            {subtitulo.length > 0 && <span className="block mt-0.5 text-sm text-ink-soft break-words">{subtitulo.map(textoCelda).join(' · ')}</span>}
            {datos.length > 0 && (
                <span className="block mt-2 space-y-0.5">
                    {datos.map(d => (
                        <span key={d.clave} className="flex flex-wrap gap-x-1.5 text-sm">
                            <span className="text-ink-soft">{d.etiqueta}:</span>
                            <span className="text-ink font-semibold break-words min-w-0">{textoCelda(d.valor)}</span>
                        </span>
                    ))}
                </span>
            )}
            {soloVer && <span className="mt-2 inline-block text-[13px] text-ink-soft">Solo para ver</span>}
        </span>
    );

    if (!onAbrir) {
        return <div className="w-full flex items-start gap-3 p-3 rounded-lg bg-panel border border-line">{contenido}</div>;
    }
    return (
        <button
            ref={botonRef}
            type="button"
            onClick={() => onAbrir(fila)}
            className={`w-full flex items-start gap-3 text-left p-3 rounded-lg bg-panel border border-line hover:border-primary active:scale-[0.99] transition-[border-color,transform] duration-150 ${focoCls}`}
        >
            {contenido}
            <IconoFlecha className="w-5 h-5 mt-0.5 text-primary flex-shrink-0" />
        </button>
    );
}
