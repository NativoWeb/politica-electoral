import { useOpciones } from '@/lib/catalogos';

// The <option>s of a list of the Centro de configuración, plus the record's own value when it is no longer offered.
export default function CatalogoOptions({ catalogo, actual = null }) {
    const opciones = useOpciones(catalogo, actual);

    return opciones.map(o => (
        <option key={o.value} value={o.value}>{o.label}{o.inactive ? ' (ya no se ofrece)' : ''}</option>
    ));
}
