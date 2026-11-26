// Build step (PostCSS) behind «Tamaño del texto»: most screens use fixed sizes (text-[15px]), so the root font-size
// alone would not grow them. Every px/rem font-size and line-height of the app's CSS is multiplied by
// --a11y-texto (1 by default). Layout boxes, Leaflet and Recharts keep their size: only the text grows.

export const VARIABLE_ESCALA = '--a11y-texto';

const PROPIEDADES = new Set(['font-size', 'line-height', '--tw-leading']);
// Tailwind theme sizes (--text-sm: 0.875rem), not their line-heights (--text-sm--line-height).
const TEMA_TEXTO = /^--text-[a-z0-9]+$/;
const LONGITUD = /\d(px|rem)\b/;

export function escalarValor(prop, valor) {
    if (!PROPIEDADES.has(prop) && !TEMA_TEXTO.test(prop)) return null;
    if (valor.includes(VARIABLE_ESCALA)) return null;
    if (!LONGITUD.test(valor) && !valor.includes('var(--spacing)')) return null;
    return `calc((${valor}) * var(${VARIABLE_ESCALA}, 1))`;
}

export default function escalaTexto() {
    return {
        postcssPlugin: 'escala-texto',
        Declaration(decl) {
            if ((decl.source?.input?.file ?? '').includes('node_modules')) return;
            const nuevo = escalarValor(decl.prop, decl.value);
            if (nuevo !== null) decl.value = nuevo;
        },
    };
}
escalaTexto.postcss = true;
