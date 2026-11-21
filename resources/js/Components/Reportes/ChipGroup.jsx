import { useId } from 'react';
import { mismoValor } from '@/lib/reportes';
import { Chip } from './ui';

// A row of toggle chips. `multiple`: an array of values ("O" between them); otherwise one value or none.
// `porDefecto` (single only): the option shown on when nothing is chosen; tapping it clears the value.
export default function ChipGroup({ etiqueta, opciones, valor, multiple = true, porDefecto = null, onCambiar, ayuda }) {
    const id = useId();
    const seleccion = multiple ? [].concat(valor ?? []) : (valor ?? porDefecto);

    function tocar(v) {
        if (multiple) onCambiar(seleccion.some(x => mismoValor(x, v)) ? seleccion.filter(x => !mismoValor(x, v)) : [...seleccion, v]);
        else onCambiar((seleccion !== null && mismoValor(seleccion, v)) || (porDefecto !== null && mismoValor(v, porDefecto)) ? null : v);
    }

    return (
        <div role="group" aria-labelledby={`${id}-t`} aria-describedby={ayuda ? `${id}-a` : undefined}>
            <p id={`${id}-t`} className="text-lg font-semibold text-ink mb-3">{etiqueta}</p>
            <div className="flex flex-wrap gap-3">
                {opciones.map(o => (
                    <Chip key={o.valor} activo={multiple ? seleccion.some(x => mismoValor(x, o.valor)) : seleccion !== null && seleccion !== undefined && mismoValor(seleccion, o.valor)} onClick={() => tocar(o.valor)}>
                        {o.etiqueta}
                    </Chip>
                ))}
            </div>
            {ayuda && <p id={`${id}-a`} className="mt-3 text-base text-ink-soft">{ayuda}</p>}
        </div>
    );
}
