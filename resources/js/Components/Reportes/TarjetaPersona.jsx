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
            <span className="block text-xl font-bold text-ink break-words">{textoCelda(c[titulo])}</span>
            {subtitulo.length > 0 && <span className="block mt-1 text-lg text-ink-soft">{subtitulo.map(textoCelda).join(' · ')}</span>}
            {datos.length > 0 && (
                <span className="block mt-3 space-y-1">
                    {datos.map(d => (
                        <span key={d.clave} className="flex flex-wrap gap-x-2 text-lg">
                            <span className="text-ink-soft">{d.etiqueta}:</span>
                            <span className="text-ink font-semibold break-words min-w-0">{textoCelda(d.valor)}</span>
                        </span>
                    ))}
                </span>
            )}
            {soloVer && <span className="mt-3 inline-block text-base text-ink-soft">Solo para ver</span>}
        </span>
    );

    if (!onAbrir) {
        return <div className="w-full flex items-start gap-3 p-5 rounded-lg bg-panel border-2 border-line">{contenido}</div>;
    }
    return (
        <button
            ref={botonRef}
            type="button"
            onClick={() => onAbrir(fila)}
            className={`w-full flex items-start gap-3 text-left p-5 rounded-lg bg-panel border-2 border-line hover:border-primary active:scale-[0.99] transition-[border-color,transform] duration-150 ${focoCls}`}
        >
            {contenido}
            <IconoFlecha className="w-6 h-6 mt-1 text-primary flex-shrink-0" />
        </button>
    );
}
