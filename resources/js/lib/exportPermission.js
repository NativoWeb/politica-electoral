// The header export sends the current screen as `source`; the server asks for that screen's permission
// (see ExportController). Senado, Cámara and Asamblea are public results and need none.
export function exportPermissionFor(path) {
    if (path.startsWith('/gobernador')) return 'view.gobernador';
    if (path.startsWith('/votantes')) return 'view.votantes';
    if (['/senado', '/camara', '/asamblea'].some((p) => path.startsWith(p))) return null;

    return 'view.mapa';
}

// Event pages export from their own buttons (attendees, territory); the header would export the Mapa Político.
export function canExportFrom(path, hasPerm) {
    if (path === '/eventos' || path.startsWith('/eventos/')) return false;
    if (path === '/empresarios' || path.startsWith('/empresarios/')) return false;
    const needed = exportPermissionFor(path);

    return !needed || hasPerm(needed);
}
