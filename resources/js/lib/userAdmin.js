// The form only lists the permissions the actor may grant; whatever else the user already had travels untouched,
// so saving never drops a permission the actor cannot see (the server then decides whether the actor may edit them).
export function withHiddenPermissions(current, visibleKeys, next) {
    const hidden = (current || []).filter(key => !visibleKeys.includes(key));

    return [...new Set([...hidden, ...next])];
}

// The app's error page (resources/views/errors) marks the abort() reason; Laravel's default one puts it next to the code.
function serverReason(data) {
    if (data && typeof data === 'object') {
        return data.message || '';
    }
    if (typeof data !== 'string' || typeof DOMParser === 'undefined') {
        return '';
    }
    const doc = new DOMParser().parseFromString(data, 'text/html');
    const text = (doc.querySelector('[data-error-message]') || doc.querySelector('h1 + div'))?.textContent?.trim() || '';

    return /^forbidden$/i.test(text) ? '' : text;
}

// A failed save (non-Inertia response) as a sentence for the form, instead of Inertia's raw error modal.
export function saveErrorMessage(response) {
    const status = response?.status;
    if (status === 403) {
        return serverReason(response.data) || 'No tienes permiso para hacer este cambio.';
    }
    if (status === 404) {
        return 'Este usuario ya no existe o no lo puedes ver. Recarga la página.';
    }
    if (status === 419) {
        return 'Tu sesión expiró. Recarga la página e intenta de nuevo.';
    }
    if (status === 422) {
        return serverReason(response.data) || 'Revisa los datos del formulario.';
    }

    return 'No se pudo guardar el cambio. Intenta de nuevo en un momento.';
}

// Validation errors of fields that have no message of their own on the form (role, permissions, territory...).
export function unshownErrors(errors, shownFields) {
    return [...new Set(Object.entries(errors || {})
        .filter(([field]) => !shownFields.includes(field))
        .map(([, message]) => message))];
}
