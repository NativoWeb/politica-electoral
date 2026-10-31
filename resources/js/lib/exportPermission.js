// The header export sends the current screen as `source`; the server asks for that screen's permission
// (see ExportController). Senado, Cámara and Asamblea are public results and need none.
export function exportPermissionFor(path) {
    if (path.startsWith('/gobernador')) return 'view.gobernador';
    if (path.startsWith('/votantes')) return 'view.votantes';
    if (['/senado', '/camara', '/asamblea'].some((p) => path.startsWith(p))) return null;

    return 'view.mapa';
}

export function canExportFrom(path, hasPerm) {
    const needed = exportPermissionFor(path);

    return !needed || hasPerm(needed);
}
