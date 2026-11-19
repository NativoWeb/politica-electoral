import { usePage } from '@inertiajs/react';

// Lists of the forms, edited by the superadmin in Centro de configuración and shared by the server as the Inertia prop
// "opcionesListas": { genero: [{ valor, etiqueta, activo, color? }] }. These fallbacks are the original options
// (App\Support\Configuracion\CatalogoRegistry), used only when the prop is missing (a page rendered without it, tests).
const simple = (values) => values.map(v => ({ valor: v, etiqueta: v, activo: true }));

const FALLBACK = {
    tipo_documento: simple(['CC', 'CE', 'TI', 'PA', 'RC', 'NIP', 'NUIP', 'NIT']),
    genero: simple(['Masculino', 'Femenino', 'Otro', 'No binario']),
    estado_civil: simple(['Soltero/a', 'Casado/a', 'Unión libre', 'Divorciado/a', 'Viudo/a']),
    escolaridad: simple(['Primaria', 'Secundaria', 'Técnico', 'Tecnólogo', 'Universitario', 'Posgrado', 'Ninguno']),
    nivel_confianza: [
        { valor: 'sin_llamar', etiqueta: 'Sin llamar', activo: true, color: '#4B5563' },
        { valor: 'contactado', etiqueta: 'Contactado', activo: true, color: '#1D4ED8' },
        { valor: 'confirmado', etiqueta: 'Confirmado', activo: true, color: '#047857' },
        { valor: 'comprometido', etiqueta: 'Comprometido', activo: true, color: '#166534' },
        { valor: 'no_responde', etiqueta: 'No responde', activo: true, color: '#DC2626' },
    ],
    parentesco: simple(['Esposa', 'Esposo', 'Hijo/a', 'Hermano/a', 'Padre', 'Madre', 'Sobrino/a', 'Tío/a', 'Primo/a', 'Cuñado/a', 'Suegro/a', 'Otro']),
    tipo_contacto: [
        { valor: 'celular', etiqueta: 'Celular', activo: true }, { valor: 'fijo', etiqueta: 'Fijo', activo: true },
        { valor: 'whatsapp', etiqueta: 'WhatsApp', activo: true }, { valor: 'email', etiqueta: 'Email', activo: true },
        { valor: 'otro', etiqueta: 'Otro', activo: true },
    ],
    zona: [{ valor: 'rural', etiqueta: 'Rural', activo: true }, { valor: 'urbana', etiqueta: 'Urbana', activo: true }],
};

export function catalogoDe(catalogos, nombre) {
    const list = catalogos?.[nombre];

    return Array.isArray(list) ? list : (FALLBACK[nombre] || []);
}

// Active options as { value, label, color }, plus the value(s) the record already has when they are no longer active
// (or never were an option): a switched off option still shows on the fichas that have it.
export function opcionesDe(catalogos, nombre, actual = null) {
    const all = catalogoDe(catalogos, nombre);
    const current = (Array.isArray(actual) ? actual : [actual]).filter(v => v !== null && v !== undefined && v !== '');
    const out = all.filter(o => o.activo !== false).map(o => ({ value: o.valor, label: o.etiqueta, color: o.color || null }));

    current.forEach(v => {
        if (out.some(o => o.value === v)) return;
        const known = all.find(o => o.valor === v);
        out.push({ value: v, label: known ? known.etiqueta : v, color: known?.color || null, inactive: true });
    });

    return out;
}

// The label of a stored value; the value itself when it is not in the list.
export function etiquetaDe(catalogos, nombre, valor) {
    if (valor === null || valor === undefined || valor === '') return valor;

    return catalogoDe(catalogos, nombre).find(o => o.valor === valor)?.etiqueta ?? valor;
}

export function colorDe(catalogos, nombre, valor) {
    return catalogoDe(catalogos, nombre).find(o => o.valor === valor)?.color || null;
}

// Badge style of an option with a color: the text in the color over a light tint of it.
export function badgeStyle(color) {
    return color ? { color, backgroundColor: `${color}1A` } : undefined;
}

export function useCatalogos() {
    return usePage().props?.opcionesListas;
}

export function useOpciones(nombre, actual = null) {
    return opcionesDe(useCatalogos(), nombre, actual);
}
