import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => ({ props: { auth: { user: { campaign: { id: 'cd' } } }, visibleCampaigns: [] } }),
}));

import OrganizacionSecciones from './OrganizacionSecciones';
import MiembrosTab from './MiembrosTab';
import PersonaOrganizaciones, { canSeeEmpresarios } from './PersonaOrganizaciones';
import ImportFila from './ImportFila';
import OrganizacionForm from './OrganizacionForm';
import ActaParticipantes from '@/Components/Eventos/ActaParticipantes';
import { groupRows, toForm, toPayload, SIN_SECTOR } from '@/lib/empresarios';

const json = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

const ROWS = [
    { id: 'o1', tipo: 'empresa', nombre: 'Lácteos', sector_economico: 'Industria', municipio: 'Girón', miembros: 3, is_active: true, celular: '3001112233', representante: 'Rosa' },
    { id: 'o2', tipo: 'empresa', nombre: 'Tienda', sector_economico: null, municipio: 'Lebrija', miembros: 1, is_active: true },
    { id: 'o3', tipo: 'empresa', nombre: 'Fábrica', sector_economico: 'Industria', municipio: 'Girón', miembros: 0, is_active: false },
    { id: 'o4', tipo: 'gremio', nombre: 'Fenalco', sector_economico: 'Comercio', municipio: 'Girón', miembros: 0, is_active: true },
];

const ROLES = [{ value: 'socio', label: 'Socio' }, { value: 'afiliado', label: 'Afiliado' }, { value: 'otro', label: 'Otro' }];

describe('lib/empresarios', () => {
    it('groups by tipo and sector económico, biggest sector first and the empty one last', () => {
        const [[empresas, sectors], [gremios, gSectors]] = groupRows(ROWS);
        expect(empresas.value).toBe('empresa');
        expect(sectors.map(([s, r]) => [s, r.length])).toEqual([['Industria', 2], [SIN_SECTOR, 1]]);
        expect(gremios.value).toBe('gremio');
        expect(gSectors.map(([s]) => s)).toEqual(['Comercio']);
    });

    it('builds the payload: empty texts as null, empleados as a number, no gremios for a gremio', () => {
        const payload = toPayload({ ...toForm(null), nombre: ' Fenalco ', tipo: 'gremio', empleados: '12', correo: '  ', gremio_ids: ['g1'] });
        expect(payload).toMatchObject({ nombre: 'Fenalco', empleados: 12, correo: null, gremio_ids: [] });
        expect(toPayload(toForm(null)).empleados).toBeNull();
        expect(toForm({ tipo: 'empresa', nombre: 'X', nit_input: '900.123.456-8', gremios: [{ id: 'g1' }], is_active: false })).toMatchObject({ nit: '900.123.456-8', gremio_ids: ['g1'], is_active: false });
    });

    it('lets in only who has view or manage empresarios', () => {
        expect(canSeeEmpresarios({ permissions: ['view.empresarios'] })).toBe(true);
        expect(canSeeEmpresarios({ permissions: ['manage.empresarios'] })).toBe(true);
        expect(canSeeEmpresarios({ permissions: ['view.eventos'] })).toBe(false);
        expect(canSeeEmpresarios({ isSuperadmin: true, permissions: [] })).toBe(true);
    });
});

describe('Empresarios components', () => {
    const original = globalThis.fetch;
    beforeEach(() => { globalThis.fetch = vi.fn(); });
    afterEach(() => { globalThis.fetch = original; });

    it('shows Empresas and Gremios as sections with sector accordions and opens a row', () => {
        const onSelect = vi.fn();
        render(<OrganizacionSecciones rows={ROWS} onSelect={onSelect} />);
        fireEvent.click(screen.getByRole('button', { name: /^Empresas/ }));
        expect(screen.getByText('Industria')).toBeTruthy();
        expect(screen.getByText(SIN_SECTOR)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Industria/ }));
        expect(screen.getByText('Inactiva')).toBeTruthy();
        fireEvent.click(screen.getByText('Lácteos'));
        expect(onSelect).toHaveBeenCalledWith('o1');
        expect(screen.getByText('3 miembros')).toBeTruthy();
        expect(screen.queryByText('Fenalco')).toBeNull();
    });

    it('opens everything with openAll and says when a tipo has no rows', () => {
        render(<OrganizacionSecciones rows={ROWS.filter(r => r.tipo === 'gremio')} onSelect={() => {}} openAll />);
        expect(screen.getByText('Fenalco')).toBeTruthy();
        expect(screen.getByText('No hay empresas con este filtro.')).toBeTruthy();
    });

    it('adds a new person as a member (its ficha is created by the server) and shows the error of a repeated role', async () => {
        let posts = 0;
        globalThis.fetch = vi.fn((url, opts) => {
            if (opts?.method !== 'POST') return json(200, { success: true, data: [] });
            posts++;
            return posts === 1
                ? json(201, { success: true, message: 'Miembro agregado: se creó su ficha en Votantes.', data: { id: 'o1', miembros: [] } })
                : json(422, { message: 'x', errors: { rol: ['Esa persona ya es miembro con ese rol.'] } });
        });
        const onChanged = vi.fn();
        render(<MiembrosTab org={{ id: 'o1', municipio: 'Girón', miembros: [] }} editable roles={ROLES} onChanged={onChanged} />);
        fireEvent.change(screen.getByLabelText('Agregar miembro'), { target: { value: 'Ana Nueva' } });
        fireEvent.click(await screen.findByText('+ Agregar «Ana Nueva» como persona nueva'));
        fireEvent.change(screen.getByLabelText(/Cédula/), { target: { value: '1098' } });
        fireEvent.click(screen.getByText('Agregar miembro'));
        expect(await screen.findByText('Miembro agregado: se creó su ficha en Votantes.')).toBeTruthy();
        const [url, opts] = globalThis.fetch.mock.calls.find(([, o]) => o?.method === 'POST');
        expect(url).toBe('/empresarios/o1/miembros');
        expect(JSON.parse(opts.body)).toMatchObject({ nombre: 'Ana Nueva', cedula: '1098', rol: 'afiliado', municipio: 'Girón' });
        expect(onChanged).toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Agregar miembro'), { target: { value: 'Ana Nueva' } });
        fireEvent.click(await screen.findByText('+ Agregar «Ana Nueva» como persona nueva'));
        fireEvent.click(screen.getByText('Agregar miembro'));
        expect(await screen.findByRole('alert')).toBeTruthy();
    });

    it('lists members read-only without the manage permission', () => {
        render(<MiembrosTab org={{ id: 'o1', miembros: [{ id: 'm1', nombre: 'Pedro', rol_label: 'Socio', rol: 'socio' }] }} editable={false} roles={ROLES} />);
        expect(screen.getByText('Pedro')).toBeTruthy();
        expect(screen.queryByText('Quitar')).toBeNull();
        expect(screen.queryByLabelText('Agregar miembro')).toBeNull();
    });

    it('shows the organizations of a ficha and handles an error', async () => {
        globalThis.fetch = vi.fn().mockReturnValueOnce(json(200, { success: true, data: [{ id: 'o1', nombre: 'Lácteos', tipo_label: 'Empresa', rol: 'socio', rol_label: 'Socio', municipio: 'Girón' }] }));
        const { unmount } = render(<PersonaOrganizaciones liderId="l1" />);
        expect(await screen.findByText('Lácteos')).toBeTruthy();
        expect(screen.getByText('Empresa · Socio · Girón')).toBeTruthy();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/empresarios/persona/l1');
        unmount();

        globalThis.fetch = vi.fn().mockReturnValueOnce(json(403, { message: 'No tiene permisos' }));
        render(<PersonaOrganizaciones liderId="l2" />);
        expect(await screen.findByRole('alert')).toBeTruthy();
    });

    it('lets choose skip for an existing row and correct a row with errors', async () => {
        const onPatch = vi.fn().mockResolvedValue(true);
        const columnas = [{ key: 'nombre', label: 'Nombre' }, { key: 'nit', label: 'NIT' }];
        const { unmount } = render(<ImportFila fila={{ id: 'r1', fila: 2, datos: { nombre: 'Lácteos' }, errores: {}, accion: 'completar', existente: { id: 'o1', nombre: 'Lácteos SAS' } }} columnas={columnas} editable onPatch={onPatch} />);
        expect(screen.getByText('Ya existe: completar vacíos')).toBeTruthy();
        fireEvent.click(screen.getByText('Saltar'));
        expect(onPatch).toHaveBeenCalledWith({ accion: 'saltar' });

        unmount();
        render(<ImportFila fila={{ id: 'r2', fila: 3, datos: { nombre: 'Mala', nit: '12' }, errores: { nit: 'El NIT debe tener de 6 a 10 dígitos' }, accion: 'error', existente: null }} columnas={columnas} editable onPatch={onPatch} />);
        expect(screen.getByText('El NIT debe tener de 6 a 10 dígitos')).toBeTruthy();
        fireEvent.click(screen.getByText('Corregir datos'));
        fireEvent.change(screen.getByLabelText('NIT'), { target: { value: '900123456' } });
        fireEvent.click(screen.getByText('Guardar corrección'));
        await waitFor(() => expect(onPatch).toHaveBeenLastCalledWith({ datos: { nombre: 'Mala', nit: '900123456' } }));
    });

    it('offers the gremios only for an empresa and shows server errors', () => {
        const setForm = vi.fn();
        const form = { ...toForm(null), gremio_ids: [] };
        const { rerender } = render(<OrganizacionForm form={form} setForm={setForm} errors={{ nit: 'El dígito de verificación del NIT no corresponde' }} gremios={[{ id: 'g1', nombre: 'Fenalco' }]} />);
        expect(screen.getByText('Fenalco')).toBeTruthy();
        expect(screen.getByText('El dígito de verificación del NIT no corresponde')).toBeTruthy();
        rerender(<OrganizacionForm form={{ ...form, tipo: 'gremio' }} setForm={setForm} gremios={[{ id: 'g1', nombre: 'Fenalco' }]} />);
        expect(screen.queryByText('Fenalco')).toBeNull();
    });

    it('searches participants of an organization acta in its own endpoint', async () => {
        globalThis.fetch = vi.fn().mockReturnValue(json(200, { data: [] }));
        render(<ActaParticipantes items={[]} onChange={() => {}} searchUrl="/empresarios/buscar/personas" />);
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'Nelson' } });
        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/empresarios/buscar/personas?q=Nelson');
    });
});
