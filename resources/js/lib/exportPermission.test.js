import { describe, expect, it } from 'vitest';
import { canExportFrom, exportPermissionFor } from './exportPermission';

const only = (...perms) => (p) => perms.includes(p);

describe('exportPermission', () => {
    it('pide el permiso de la pantalla que se exporta', () => {
        expect(exportPermissionFor('/mapa-politico')).toBe('view.mapa');
        expect(exportPermissionFor('/votantes')).toBe('view.votantes');
        expect(exportPermissionFor('/gobernador/abc')).toBe('view.gobernador');
        expect(exportPermissionFor('/senado')).toBeNull();
        // Any other page exports the Mapa Politico rows.
        expect(exportPermissionFor('/perfil')).toBe('view.mapa');
    });

    it('oculta el exporte a quien no puede ver esa pantalla', () => {
        expect(canExportFrom('/votantes', only('view.votantes'))).toBe(true);
        expect(canExportFrom('/perfil', only('view.votantes'))).toBe(false);
        expect(canExportFrom('/mapa-politico', only('view.mapa'))).toBe(true);
        expect(canExportFrom('/votantes', only('view.mapa'))).toBe(false);
        expect(canExportFrom('/camara', only())).toBe(true);
    });
});
