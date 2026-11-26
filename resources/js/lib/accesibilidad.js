// Accessibility preferences of this device: text size, high contrast, reduced motion and underlined links.
// They live as attributes on <html> (styles in app.css). resources/views/app.blade.php applies the saved ones
// before the first paint with an inline copy of aplicarPreferencias: keep both in sync (accesibilidad.test.js
// runs that script against the same cases).

export const CLAVE = 'a11y-preferencias';
export const PASOS_TEXTO = [90, 100, 110, 125, 140, 150];
export const TEXTO_NORMAL = 100;

export const PREFERENCIAS_INICIALES = Object.freeze({
    texto: TEXTO_NORMAL,
    contraste: false,
    // null = follow the system setting (prefers-reduced-motion).
    reducirAnimaciones: null,
    enlaces: false,
});

function normalizar(guardado) {
    const p = guardado && typeof guardado === 'object' ? guardado : {};
    return {
        texto: PASOS_TEXTO.includes(p.texto) ? p.texto : TEXTO_NORMAL,
        contraste: p.contraste === true,
        reducirAnimaciones: typeof p.reducirAnimaciones === 'boolean' ? p.reducirAnimaciones : null,
        enlaces: p.enlaces === true,
    };
}

function almacenamiento() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

export function leerPreferencias() {
    try {
        const crudo = almacenamiento()?.getItem(CLAVE);
        return normalizar(crudo ? JSON.parse(crudo) : null);
    } catch {
        return normalizar(null);
    }
}

// Returns false when the device does not let us save (private mode, blocked storage): the change still applies.
export function guardarPreferencias(prefs) {
    try {
        const store = almacenamiento();
        if (!store) return false;
        store.setItem(CLAVE, JSON.stringify(normalizar(prefs)));
        return true;
    } catch {
        return false;
    }
}

export function sistemaReduceAnimaciones() {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
        return false;
    }
}

export function animacionesReducidas(prefs) {
    return prefs.reducirAnimaciones ?? sistemaReduceAnimaciones();
}

export function aplicarPreferencias(prefs, raiz = document.documentElement) {
    const p = normalizar(prefs);
    const poner = (nombre, valor) => (valor === null ? raiz.removeAttribute(nombre) : raiz.setAttribute(nombre, valor));
    poner('data-a11y-texto', p.texto === TEXTO_NORMAL ? null : String(p.texto));
    poner('data-a11y-contraste', p.contraste ? '' : null);
    // Without a choice of the user the CSS follows prefers-reduced-motion by itself.
    poner('data-a11y-animaciones', p.reducirAnimaciones === null ? null : (p.reducirAnimaciones ? 'reducidas' : 'normales'));
    poner('data-a11y-enlaces', p.enlaces ? '' : null);
}

export function cambiarTexto(actual, direccion) {
    const i = PASOS_TEXTO.indexOf(actual);
    const desde = i === -1 ? PASOS_TEXTO.indexOf(TEXTO_NORMAL) : i;
    const siguiente = Math.min(PASOS_TEXTO.length - 1, Math.max(0, desde + direccion));
    return PASOS_TEXTO[siguiente];
}

export const esTextoMinimo = (texto) => texto === PASOS_TEXTO[0];
export const esTextoMaximo = (texto) => texto === PASOS_TEXTO[PASOS_TEXTO.length - 1];
