// Value of the «Sin comuna» option of the Votantes filter (VotanteController::SIN_COMUNA).
export const SIN_COMUNA = 'sin_comuna';

// How a ficha got its comuna (comuna_source), the same words as the reports (ComunaCatalog::ORIGENES).
export const COMUNA_ORIGEN = {
    auto: { etiqueta: 'Por barrio', detalle: 'Calculada por el barrio.' },
    texto: { etiqueta: 'Escrita', detalle: 'La comuna está escrita en el barrio o la dirección.' },
    direccion: { etiqueta: 'Por dirección', detalle: 'Calculada por la dirección.' },
    puesto: { etiqueta: 'Por puesto de votación', detalle: 'Calculada por el puesto de votación.' },
    manual: { etiqueta: 'Manual', detalle: 'Elegida a mano: la asignación automática no la cambia.' },
};

export const comunaOrigen = (source) => COMUNA_ORIGEN[source] ?? null;

// Attribution of the polling stations dataset (docs/geografia.md): wherever a comuna comes from the puesto de votación.
export const FUENTE_PUESTOS = 'Fuente: Registraduría – Divipole 2023 (datos.gov.co), CC BY-SA 4.0.';

// Some territory of the goals (or one of its comunas) counts fichas whose comuna came from the puesto de votación.
export const usaPuestos = (filas = []) => filas.some(f => f.porPuesto > 0 || usaPuestos(f.hijos ?? []));

// [label, comunas] groups for a <select>: comunas first, then corregimientos.
export function gruposComunas(comunas = []) {
    return [
        ['Comunas', comunas.filter(c => c.tipo === 'comuna')],
        ['Corregimientos', comunas.filter(c => c.tipo === 'corregimiento')],
    ].filter(([, list]) => list.length);
}
