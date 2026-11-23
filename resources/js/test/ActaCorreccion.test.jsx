// Corregir un acta confirmada y datos por completar (docs/eventos-api.md §14, 2026-11-22).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const { router, page } = vi.hoisted(() => ({ router: { visit: vi.fn(), on: vi.fn(() => () => {}) }, page: { props: {} } }));

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/CampaignChip', () => ({ default: () => null }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => page,
    router,
}));

import Acta from '@/Pages/Eventos/Acta';
import ActaCompromisos from '@/Components/Eventos/ActaCompromisos';
import ActaHistorial from '@/Components/Eventos/ActaHistorial';
import ActaParticipantes from '@/Components/Eventos/ActaParticipantes';
import ActaSugerencias from '@/Components/Eventos/ActaSugerencias';
import { emptyCompromiso, historialItems, toForm } from '@/lib/actas';

const ACTA = {
    id: 'a1', estado: 'confirmada', version: 3, titulo: 'Acta Berlín', fecha: '2026-11-08', municipio: 'RIONEGRO', sitio: 'Escuela',
    hora_inicio: '10:45', hora_fin: '12:00', introduccion: null, orden_dia: ['Vías'], desarrollo: [{ titulo: 'Vías', texto: 'Arreglo' }],
    cierre: null, confirmed_at: '2026-11-08T12:00:00-05:00', confirmed_by: 'Ana', archivo: null,
    participantes: [
        { id: 'p1', nombre: 'Maria Ejemplo', perfil: 'Líder', municipio: 'RIONEGRO', telefono: '3001112233', cedula: null, lider_id: 'L1', attendee_id: 'AT1', sugerencia_rechazada: null, por_completar: ['cedula'] },
        { id: 'p2', nombre: 'Pedro Ejemplo', perfil: null, municipio: 'RIONEGRO', telefono: '3002223344', cedula: '123', lider_id: 'L2', attendee_id: 'AT2', sugerencia_rechazada: null, por_completar: [] },
    ],
    compromisos: [
        { id: 'c1', descripcion: 'Arreglar la vía', fecha_limite: null, recordar_el: null, estado: 'en_curso', vencido: false, avances: 2, por_completar: ['fecha_limite'], responsables: [{ id: 'r1', nombre: 'Sandra', telefono: '3177778888', lider_id: null, sugerencia_rechazada: null }] },
        { id: 'c2', descripcion: 'Llamar al alcalde', fecha_limite: '2026-12-01', recordar_el: null, estado: 'pendiente', vencido: false, avances: 0, por_completar: [], responsables: [{ id: 'r2', nombre: 'Luis', telefono: null, lider_id: 'L3', sugerencia_rechazada: null }] },
    ],
};

const HISTORIAL = [
    { id: 'h2', accion: 'corregida', fecha: '2026-11-14T09:30:00-05:00', usuario: 'Marta', resumen: 'Cambió: fecha (12-nov → 14-nov), desarrollo; +2 participantes, −1; 1 compromiso editado' },
    { id: 'h1', accion: 'confirmada', fecha: '2026-11-08T12:00:00-05:00', usuario: 'Ana', resumen: '2 participantes, 2 compromisos' },
];

const CAN = { edit: false, delete: false, delete_confirmed: true, edit_confirmed: true };

const reply = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

// fetch by route: the search box, the suggestions and the correction (PUT) can each be scripted.
function mockServer({ correccion = () => reply(200, { success: true, message: 'Acta corregida.', data: { ...ACTA, version: 4 } }), sugerencias = () => reply(200, { success: true, data: { participantes: {}, responsables: {} } }) } = {}) {
    globalThis.fetch = vi.fn((url, options = {}) => {
        if (String(url).includes('/buscar/')) return reply(200, { data: [] });
        if (String(url).endsWith('/sugerencias')) return sugerencias(JSON.parse(options.body));
        if (String(url).endsWith('/correccion')) return correccion(JSON.parse(options.body));
        return reply(404, {});
    });
}

const calls = suffix => globalThis.fetch.mock.calls.filter(([url]) => String(url).endsWith(suffix));

function renderActa(props = {}) {
    return render(<Acta evento={{ id: 'e1', nombre: 'Gira' }} acta={ACTA} defaults={{}} asistentes={[]} can={CAN} historial={HISTORIAL} {...props} />);
}

describe('Corregir un acta confirmada', () => {
    const original = globalThis.fetch;
    beforeEach(() => {
        router.visit.mockReset();
        router.visit.mockImplementation((url, options = {}) => {
            options.onSuccess?.({ props: { acta: { ...ACTA, version: 4 } } });
            options.onFinish?.();
        });
        mockServer();
    });
    afterEach(() => { globalThis.fetch = original; cleanup(); });

    it('offers «Corregir acta» only with can.edit_confirmed on a confirmed acta', () => {
        const { unmount } = renderActa({ can: { ...CAN, edit_confirmed: false } });
        expect(screen.queryByRole('button', { name: 'Corregir acta' })).toBeNull();
        expect(screen.getByText(/ya no se puede editar ni borrar/)).toBeTruthy();
        unmount();

        const borrador = { ...ACTA, estado: 'borrador', confirmed_at: null, confirmed_by: null };
        const second = renderActa({ acta: borrador, can: { edit: true, delete: true, edit_confirmed: true } });
        expect(screen.queryByRole('button', { name: 'Corregir acta' })).toBeNull();
        // The borrador keeps its own buttons, and no historial is shown for it.
        expect(screen.getByRole('button', { name: 'Guardar borrador' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Confirmar acta' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Borrar acta' })).toBeTruthy();
        expect(screen.queryByText('Historial de cambios')).toBeNull();
        second.unmount();

        renderActa();
        expect(screen.getByRole('button', { name: 'Corregir acta' })).toBeTruthy();
        expect(screen.getByDisplayValue('Acta Berlín').disabled).toBe(true);
    });

    it('opens the same editor with a warning, keeps each participant linked to its ficha and cancels back', () => {
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        expect(screen.getByText('Está corrigiendo un acta ya confirmada.')).toBeTruthy();
        expect(screen.getAllByText('Vinculado a su ficha')).toHaveLength(2);
        expect(screen.queryByRole('button', { name: 'Corregir acta' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Eliminar acta' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Guardar borrador' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Confirmar acta' })).toBeNull();

        const titulo = screen.getByDisplayValue('Acta Berlín');
        expect(titulo.disabled).toBe(false);
        fireEvent.change(titulo, { target: { value: 'Otro título' } });
        // Changing the name of someone already linked does not drop the ficha.
        fireEvent.change(screen.getByDisplayValue('Maria Ejemplo'), { target: { value: 'María Ejemplo Ruiz' } });
        expect(screen.getAllByText('Vinculado a su ficha')).toHaveLength(2);

        fireEvent.click(screen.getByRole('button', { name: 'Cancelar corrección' }));
        // With changes it asks first; «No, seguir corrigiendo» keeps them.
        const ask = screen.getByRole('alertdialog', { name: '¿Descartar los cambios?' });
        fireEvent.click(within(ask).getByRole('button', { name: 'No, seguir corrigiendo' }));
        expect(screen.getByDisplayValue('Otro título')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, descartar' }));
        expect(screen.getByDisplayValue('Acta Berlín').disabled).toBe(true);
        expect(screen.getByDisplayValue('Maria Ejemplo')).toBeTruthy();
        expect(screen.queryByText('Está corrigiendo un acta ya confirmada.')).toBeNull();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('shows a summary before saving and sends the correction with the version, by id', async () => {
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByDisplayValue('Maria Ejemplo'), { target: { value: 'María Ejemplo Ruiz' } });
        fireEvent.click(screen.getByRole('button', { name: 'Quitar a Pedro Ejemplo' }));
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'Nueva Persona' } });
        fireEvent.click(screen.getByText('+ Agregar «Nueva Persona» como persona nueva'));
        fireEvent.change(screen.getByDisplayValue('Llamar al alcalde'), { target: { value: 'Llamar al alcalde y al personero' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));

        const dialog = screen.getByRole('alertdialog', { name: '¿Guardar la corrección del acta?' });
        expect(within(dialog).getByText('Se agregará 1 persona al acta y al evento.')).toBeTruthy();
        expect(within(dialog).getByText('1 pasa a Votantes (si su cédula ya está, se une a esa ficha).')).toBeTruthy();
        expect(within(dialog).queryByText(/ya está en Votantes/)).toBeNull();
        expect(within(dialog).getByText('Se quitará 1 persona del acta: deja de figurar como asistente, pero su ficha en Votantes no se borra.')).toBeTruthy();
        expect(within(dialog).getByText(/Se corrigen los datos de 1 participante/)).toBeTruthy();
        expect(within(dialog).getByText('Compromisos: 1 editado.')).toBeTruthy();
        expect(within(dialog).getByText('Los avances de los compromisos se conservan.')).toBeTruthy();
        expect(calls('/correccion')).toHaveLength(0);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Sí, guardar corrección' }));
        await waitFor(() => expect(calls('/correccion')).toHaveLength(1));

        // Only the new person is asked about: Sandra (unchanged text responsable) and Luis (linked) are not.
        expect(JSON.parse(calls('/sugerencias')[0][1].body)).toEqual({ participantes: [{ nombre: 'Nueva Persona', telefono: null, cedula: null, municipio: 'RIONEGRO' }], responsables: [] });
        const [url, options] = calls('/correccion')[0];
        expect(url).toBe('/eventos/e1/actas/a1/correccion');
        expect(options.method).toBe('PUT');
        const body = JSON.parse(options.body);
        expect(body.version).toBe(3);
        expect(body.participantes).toEqual([
            expect.objectContaining({ id: 'p1', nombre: 'María Ejemplo Ruiz', lider_id: 'L1', attendee_id: 'AT1' }),
            expect.objectContaining({ id: null, nombre: 'Nueva Persona', lider_id: null }),
        ]);
        expect(body.compromisos.map(c => [c.id, c.descripcion])).toEqual([['c1', 'Arreglar la vía'], ['c2', 'Llamar al alcalde y al personero']]);

        await screen.findByText('Acta corregida.');
        expect(router.visit).toHaveBeenCalledWith('/eventos/e1/actas/a1', expect.objectContaining({ preserveState: true }));
        expect(screen.queryByText('Está corrigiendo un acta ya confirmada.')).toBeNull();
        expect(screen.getByRole('button', { name: 'Corregir acta' })).toBeTruthy();
    });

    it('sends a single request on a double click', async () => {
        let release;
        mockServer({ correccion: () => new Promise(resolve => { release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, message: 'Acta corregida.', data: ACTA }) }); }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByDisplayValue('Acta Berlín'), { target: { value: 'Acta Berlín corregida' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        const confirm = screen.getByRole('button', { name: 'Sí, guardar corrección' });
        // Both clicks land before React re-renders (as a fast double click can): the button is still enabled.
        act(() => { confirm.click(); confirm.click(); });
        fireEvent.click(confirm);
        await waitFor(() => expect(calls('/correccion')).toHaveLength(1));
        expect(screen.getAllByRole('button', { name: 'Guardando…' }).every(b => b.disabled)).toBe(true);
        release();
        await screen.findByText('Acta corregida.');
        expect(calls('/correccion')).toHaveLength(1);
    });

    it('explains a 409 and offers «Recargar», keeping what was typed', async () => {
        mockServer({ correccion: () => reply(409, { success: false, code: 'version', message: 'Otra persona cambió esta acta mientras usted la corregía. Recargue la página para ver los cambios; lo suyo no se guardó.' }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByDisplayValue('Acta Berlín'), { target: { value: 'Mi cambio' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toMatch(/Otra persona cambió esta acta mientras usted la corregía/);
        expect(screen.getByDisplayValue('Mi cambio')).toBeTruthy();
        expect(router.visit).not.toHaveBeenCalled();
        fireEvent.click(within(alert).getByRole('button', { name: 'Recargar' }));
        expect(router.visit).toHaveBeenCalledWith('/eventos/e1/actas/a1', { preserveState: false });
    });

    it('puts back a compromiso that got avances meanwhile, with the 422 beside it and without «Recargar»', async () => {
        // c2 had no avances when the page opened; someone added one before the correction arrived.
        mockServer({ correccion: () => reply(422, { message: 'x', errors: { 'compromisos_quitados.c2': ['Este compromiso tiene 1 avances: no se puede quitar. Márquelo como No cumplido en Seguimiento.'] } }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.click(screen.getByLabelText('Quitar el compromiso 2'));
        expect(screen.queryByDisplayValue('Llamar al alcalde')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        expect(screen.getByText('Compromisos: 1 quitado.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));
        await waitFor(() => expect(screen.getAllByRole('alert')).toHaveLength(2));
        const [top, beside] = screen.getAllByRole('alert');
        expect(top.textContent).toMatch(/No se guardó nada: hay compromisos con avances/);
        expect(within(top).queryByRole('button', { name: 'Recargar' })).toBeNull();
        expect(beside.textContent).toBe('Este compromiso tiene 1 avances: no se puede quitar. Márquelo como No cumplido en Seguimiento.');
        const textarea = screen.getByDisplayValue('Llamar al alcalde');
        expect(textarea.closest('div.border').contains(beside)).toBe(true);
        expect(screen.getByLabelText('Quitar el compromiso 2').disabled).toBe(true);
        // Still correcting: nothing was lost and it can be sent again.
        expect(screen.getByRole('button', { name: 'Guardar corrección' }).disabled).toBe(false);
    });

    it('shows the 403 of a user outside the territory without «Recargar»', async () => {
        mockServer({ correccion: () => reply(403, { message: 'Este registro está fuera de tu territorio.' }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByDisplayValue('Acta Berlín'), { target: { value: 'Mi cambio' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));
        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toBe('Este registro está fuera de tu territorio.');
        expect(within(alert).queryByRole('button', { name: 'Recargar' })).toBeNull();
        expect(screen.getByDisplayValue('Mi cambio')).toBeTruthy();
    });

    it('says which participant the 422 is about, marks and focuses its field', async () => {
        mockServer({ correccion: () => reply(422, { message: 'x', errors: { 'participantes.1.cedula': ['Esa cédula ya pertenece a otra persona en Votantes: corrija la cédula o deje la que tenía.'] } }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        const cedula = screen.getByDisplayValue('123');
        fireEvent.change(cedula, { target: { value: '999' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));
        await waitFor(() => expect(screen.getByDisplayValue('999').getAttribute('aria-invalid')).toBe('true'), { timeout: 3000 });
        const field = screen.getByDisplayValue('999');
        expect(screen.getAllByRole('alert')[0].textContent).toMatch(/^Participante 2: Esa cédula ya pertenece a otra persona/);
        expect(document.getElementById(field.getAttribute('aria-describedby')).textContent).toMatch(/Esa cédula ya pertenece/);
        await waitFor(() => expect(document.activeElement).toBe(field), { timeout: 3000 });
        // The other participant's fields are not marked.
        expect(screen.getByDisplayValue('Maria Ejemplo').getAttribute('aria-invalid')).toBeNull();
    });

    it('marks the responsable of a compromiso the server refused', async () => {
        mockServer({ correccion: () => reply(422, { message: 'x', errors: { 'compromisos.1.responsables.0.lider_id': ['La ficha elegida ya no es válida.'] } }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByDisplayValue('Acta Berlín'), { target: { value: 'Mi cambio' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));
        await waitFor(() => expect(screen.getByDisplayValue('Luis').getAttribute('aria-invalid')).toBe('true'), { timeout: 3000 });
        expect(screen.getAllByRole('alert')[0].textContent).toBe('Compromiso 2: La ficha elegida ya no es válida.');
        await waitFor(() => expect(document.activeElement).toBe(screen.getByDisplayValue('Luis')), { timeout: 3000 });
    });

    it('guards unsaved changes when leaving the page, and only then', () => {
        const add = vi.spyOn(window, 'addEventListener');
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        expect(add.mock.calls.filter(([type]) => type === 'beforeunload')).toHaveLength(0);
        fireEvent.change(screen.getByDisplayValue('Acta Berlín'), { target: { value: 'Mi cambio' } });
        const handler = add.mock.calls.find(([type]) => type === 'beforeunload')[1];
        const event = new Event('beforeunload', { cancelable: true });
        handler(event);
        expect(event.defaultPrevented).toBe(true);

        // An Inertia visit asks; saying no cancels it.
        const before = router.on.mock.calls.filter(([name]) => name === 'before').at(-1)[1];
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        // Hovering a menu link prefetches it: that is not leaving, so nothing is asked.
        const prefetch = { detail: { visit: { prefetch: true } }, preventDefault: vi.fn() };
        before(prefetch);
        expect(confirm).not.toHaveBeenCalled();
        expect(prefetch.preventDefault).not.toHaveBeenCalled();
        const visit = { preventDefault: vi.fn() };
        before(visit);
        expect(confirm).toHaveBeenCalled();
        expect(visit.preventDefault).toHaveBeenCalled();
        add.mockRestore();
        confirm.mockRestore();
    });

    it('does not send when nothing changed or an existing row was left blank', () => {
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        expect(screen.getByText('No hay cambios para guardar.')).toBeTruthy();
        expect(screen.queryByRole('alertdialog')).toBeNull();

        fireEvent.change(screen.getByDisplayValue('Pedro Ejemplo'), { target: { value: '  ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        expect(screen.getByRole('alert').textContent).toBe('Escriba el nombre del participante 2 o use «Quitar».');
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('asks every suggestion before correcting, with «Sugerido por nombre»', async () => {
        mockServer({ sugerencias: () => reply(200, { success: true, data: { participantes: { 0: { id: 'L9', nombre: 'Nueva Persona Gómez', municipio: 'Rionegro', cargo: 'Líder', por: 'nombre', motivo: 'nombre' } }, responsables: {} } }) });
        renderActa();
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'Nueva Persona' } });
        fireEvent.click(screen.getByText('+ Agregar «Nueva Persona» como persona nueva'));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));

        const dialog = await screen.findByRole('dialog', { name: '¿Son estas personas?' });
        expect(within(dialog).getByText('Sugerido por nombre')).toBeTruthy();
        const done = within(dialog).getByRole('button', { name: 'Guardar corrección' });
        expect(done.disabled).toBe(true);
        fireEvent.click(within(dialog).getByRole('button', { name: 'No es' }));
        fireEvent.click(done);
        await waitFor(() => expect(calls('/correccion')).toHaveLength(1));
        expect(JSON.parse(calls('/correccion')[0][1].body).participantes[2]).toMatchObject({ nombre: 'Nueva Persona', lider_id: null, sugerencia_rechazada: 'L9' });
    });

    it('corrects an acta of an organization through its own route and wording', async () => {
        renderActa({ evento: { id: 'o1', nombre: 'Gremio' }, origen: { tipo: 'organizacion', base: '/empresarios/o1/actas', volver: '/empresarios/o1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Corregir acta' }));
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'Nueva Persona' } });
        fireEvent.click(screen.getByText('+ Agregar «Nueva Persona» como persona nueva'));
        fireEvent.click(screen.getByRole('button', { name: 'Quitar a Pedro Ejemplo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar corrección' }));
        expect(screen.getByText('Se agregará 1 persona al acta y a la organización.')).toBeTruthy();
        expect(screen.getByText(/deja de figurar como miembro,/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, guardar corrección' }));
        await waitFor(() => expect(calls('/correccion')).toHaveLength(1));
        expect(calls('/correccion')[0][0]).toBe('/empresarios/o1/actas/a1/correccion');
        expect(calls('/sugerencias')[0][0]).toBe('/empresarios/o1/actas/sugerencias');
    });

    it('shows the historial of a confirmed acta', () => {
        renderActa();
        const section = screen.getByText('Historial de cambios').closest('section');
        expect(within(section).getByText('Corrigió el acta')).toBeTruthy();
        expect(within(section).getByText('Confirmó el acta')).toBeTruthy();
        expect(within(section).getByText(/1 compromiso editado/)).toBeTruthy();
        expect(within(section).getByText(/por Marta/)).toBeTruthy();
    });

    it('shows the indicators of an acta with data to complete', () => {
        renderActa();
        expect(screen.getByText('1 participante con datos por completar')).toBeTruthy();
        expect(screen.getByText('Falta: cédula')).toBeTruthy();
        expect(screen.getByText('1 compromiso sin fecha límite o sin responsable')).toBeTruthy();
        expect(screen.getByText('Sin fecha límite')).toBeTruthy();
    });
});

describe('Datos por completar y piezas de la corrección', () => {
    afterEach(cleanup);

    it('counts participants by the ficha and updates as the row is filled', () => {
        const form = toForm(ACTA);
        const onChange = vi.fn();
        const { rerender } = render(<ActaParticipantes items={form.participantes} onChange={onChange} confirmed />);
        expect(screen.getByText('1 participante con datos por completar')).toBeTruthy();
        // Pedro has no por_completar left (his ficha has everything) although his row has no perfil.
        expect(screen.getAllByText(/^Falta:/)).toHaveLength(1);

        const filled = form.participantes.map(p => (p.id === 'p1' ? { ...p, cedula: '1098' } : p));
        rerender(<ActaParticipantes items={filled} onChange={onChange} confirmed />);
        expect(screen.queryByText(/con datos por completar/)).toBeNull();

        const nuevo = { ...form.participantes[0], key: 'n1', id: null, lider_id: null, attendee_id: null, por_completar: undefined, nombre: 'Sin datos', telefono: '', municipio: '', cedula: '' };
        rerender(<ActaParticipantes items={[nuevo]} onChange={onChange} />);
        expect(screen.getByText('Falta: cédula, teléfono, municipio')).toBeTruthy();
    });

    it('drops a chosen ficha when a borrador row changes, but not a confirmed one', () => {
        const row = { ...toForm(ACTA).participantes[0] };
        const onChange = vi.fn();
        const { rerender } = render(<ActaParticipantes items={[{ ...row, id: null }]} onChange={onChange} />);
        fireEvent.change(screen.getByDisplayValue('Maria Ejemplo'), { target: { value: 'Otra' } });
        expect(onChange.mock.calls[0][0][0]).toMatchObject({ lider_id: null, por_completar: undefined });

        rerender(<ActaParticipantes items={[row]} onChange={onChange} confirmed />);
        fireEvent.change(screen.getByDisplayValue('Maria Ejemplo'), { target: { value: 'Otra' } });
        expect(onChange.mock.calls[1][0][0]).toMatchObject({ lider_id: 'L1', attendee_id: 'AT1' });
    });

    it('labels each compromiso without fecha límite or responsable', () => {
        const items = [
            { ...emptyCompromiso(), descripcion: 'Sin nada', responsables: [{ key: 'r', nombre: ' ', telefono: '' }] },
            { ...emptyCompromiso(), descripcion: 'Completo', fecha_limite: '2026-12-01', responsables: [{ key: 's', nombre: 'Sandra', telefono: '' }] },
            { ...emptyCompromiso(), descripcion: '' },
        ];
        render(<ActaCompromisos items={items} onChange={() => {}} />);
        expect(screen.getByText('1 compromiso sin fecha límite o sin responsable')).toBeTruthy();
        expect(screen.getAllByText('Sin fecha límite')).toHaveLength(1);
        expect(screen.getAllByText('Sin responsable')).toHaveLength(1);
        // A compromiso without avances can be removed.
        expect(screen.getByLabelText('Quitar el compromiso 2').disabled).toBe(false);
    });

    it('labels only the suggestions found by name', () => {
        const items = [
            { kind: 'participante', index: 0, nombre: 'ANA', lider: { id: 'L1', nombre: 'Ana Ruiz', por: 'telefono', motivo: 'telefono' } },
            { kind: 'participante', index: 1, nombre: 'LUIS PEREZ', lider: { id: 'L2', nombre: 'Luis Pérez', por: 'nombre', motivo: 'nombre' } },
        ];
        render(<ActaSugerencias items={items} onDone={() => {}} onCancel={() => {}} />);
        const rows = screen.getAllByRole('listitem');
        expect(within(rows[0]).queryByText('Sugerido por nombre')).toBeNull();
        expect(within(rows[1]).getByText('Sugerido por nombre')).toBeTruthy();
    });

    it('says when the historial is empty', () => {
        render(<ActaHistorial items={historialItems([])} />);
        expect(screen.getByText('Todavía no hay cambios registrados.')).toBeTruthy();
    });
});

describe('Confirmar un borrador según permisos', () => {
    const original = globalThis.fetch;
    const borrador = { ...ACTA, estado: 'borrador', confirmed_at: null, confirmed_by: null };
    afterEach(() => { globalThis.fetch = original; page.props = {}; cleanup(); });

    it.each([
        [['manage.eventos'], 'Después de confirmar no se podrá editar ni borrar.'],
        [['manage.eventos', 'edit.actas'], 'Después de confirmar, solo se podrá cambiar con «Corregir acta».'],
        [['manage.eventos', 'delete.actas'], 'Después de confirmar no se podrá editar; solo eliminar con «Eliminar acta».'],
        [['*'], 'Después de confirmar, solo se podrá cambiar con «Corregir acta» o «Eliminar acta».'],
    ])('with %j says: %s', (permissions, text) => {
        page.props = { auth: { user: { permissions } } };
        render(<Acta evento={{ id: 'e1', nombre: 'Gira' }} acta={borrador} defaults={{}} asistentes={[]} can={{ edit: true, delete: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Confirmar acta' }));
        expect(screen.getByRole('alertdialog').textContent).toContain(text);
    });

    it('starts «¿Son estas personas?» on its title, not on «Sí»', () => {
        const items = [{ kind: 'participante', index: 0, nombre: 'ANA', lider: { id: 'L1', nombre: 'Ana Ruiz' } }];
        render(<ActaSugerencias items={items} onDone={() => {}} onCancel={() => {}} />);
        expect(document.activeElement).toBe(screen.getByRole('heading', { name: '¿Son estas personas?' }));
    });
});
