// The same rule and message as the server (App\Support\NombreFicha): a name never carries digits.
export const NOMBRE_CON_NUMEROS = 'El nombre no puede tener números: si es la cédula o el teléfono, escríbelo en su campo.';

export const tieneNumeros = (value) => /[0-9]/.test(String(value ?? ''));

const clave = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();

// The message for a name being saved, or null. `actual`: the stored name when editing; sending it back unchanged is
// allowed, as the server allows it.
export function errorNombre(nombre, actual = null) {
    if (!tieneNumeros(nombre)) return null;
    if (actual !== null && actual !== undefined && clave(nombre) === clave(actual)) return null;
    return NOMBRE_CON_NUMEROS;
}

// The first server message of a field from a 422 body ({ errors: { nombre: [...] } }).
export const errorDeCampo = (body, campo) => {
    const value = body?.errors?.[campo];
    return Array.isArray(value) ? value[0] : (value || null);
};
