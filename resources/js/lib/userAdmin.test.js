import { describe, it, expect } from 'vitest';
import { saveErrorMessage, unshownErrors, withHiddenPermissions } from './userAdmin';

const laravel403 = reason => `<!DOCTYPE html><html><body><div class="flex"><h1 class="px-4">403</h1><div class="ml-4">${reason}</div></div></body></html>`;

describe('withHiddenPermissions', () => {
    it('keeps the permissions the actor cannot see whatever is picked', () => {
        const visible = ['view.mapa', 'write.data'];
        expect(withHiddenPermissions(['view.mapa', 'manage.partidos'], visible, [])).toEqual(['manage.partidos']);
        expect(withHiddenPermissions(['manage.partidos'], visible, ['view.mapa', 'write.data'])).toEqual(['manage.partidos', 'view.mapa', 'write.data']);
        expect(withHiddenPermissions(null, visible, ['view.mapa'])).toEqual(['view.mapa']);
    });
});

describe('saveErrorMessage', () => {
    it('reads the reason of a 403 from the error page or the JSON body', () => {
        expect(saveErrorMessage({ status: 403, data: laravel403('No puedes otorgar permisos que no tienes.') }))
            .toBe('No puedes otorgar permisos que no tienes.');
        expect(saveErrorMessage({ status: 403, data: { message: 'No puedes asignar ese rol.' } })).toBe('No puedes asignar ese rol.');
        const appPage = '<main><p>Error 403</p><h1>No tienes acceso a esta sección</h1><p data-error-message>No puedes asignar ese rol.</p></main>';
        expect(saveErrorMessage({ status: 403, data: appPage })).toBe('No puedes asignar ese rol.');
    });

    it('falls back to a plain sentence when the server gives no reason', () => {
        expect(saveErrorMessage({ status: 403, data: laravel403('Forbidden') })).toBe('No tienes permiso para hacer este cambio.');
        expect(saveErrorMessage({ status: 419, data: '' })).toMatch(/sesión expiró/);
        expect(saveErrorMessage({ status: 422, data: '' })).toBe('Revisa los datos del formulario.');
        expect(saveErrorMessage({ status: 500, data: '<html></html>' })).toMatch(/No se pudo guardar/);
        expect(saveErrorMessage(undefined)).toMatch(/No se pudo guardar/);
    });
});

describe('unshownErrors', () => {
    it('returns only the errors of fields without their own message, once each', () => {
        const errors = { name: 'Falta el nombre', role_id: 'Rol inválido', 'permissions.0': 'Permiso inválido', 'permissions.1': 'Permiso inválido' };
        expect(unshownErrors(errors, ['name', 'email'])).toEqual(['Rol inválido', 'Permiso inválido']);
        expect(unshownErrors(undefined, ['name'])).toEqual([]);
    });
});
