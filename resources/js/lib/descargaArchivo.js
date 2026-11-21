// The one place that saves a downloaded file, shared by the Planilla, the header Exportar, the old Eventos and
// Empresarios downloads (EnlaceDescarga) and Reportes.

// Installed app (home screen): there a blob download may do nothing (iOS), so the browser opens the URL itself.
export function esAppInstalada() {
    if (typeof window === 'undefined') return false;
    if (window.navigator?.standalone === true) return true;
    try {
        return !!window.matchMedia?.('(display-mode: standalone)')?.matches;
    } catch {
        return false;
    }
}

export function irA(url) {
    window.location.href = url;
}

export function guardarBlob(blob, nombre) {
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    a.remove();
    // iOS (PWA) lee el blob tarde: se libera al minuto, no al segundo.
    setTimeout(() => URL.revokeObjectURL(href), 60000);
}

// Saves the blob; when this browser cannot (it throws), a GET download opens its URL so the browser saves it
// itself. Without a URL (a POST, like Reportes) there is nothing to open: the error goes up.
// Returns 'guardado' or 'navegador'.
export function guardarOAbrir(blob, nombre, url = null, navegar = irA) {
    try {
        guardarBlob(blob, nombre);
        return 'guardado';
    } catch (e) {
        if (!url) throw e;
        navegar(url);
        return 'navegador';
    }
}
