import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));

import ActaCompromisos from './ActaCompromisos';
import ActaEliminarConfirmada from './ActaEliminarConfirmada';
import ActaParticipantes from './ActaParticipantes';
import ActaSugerencias from './ActaSugerencias';
import ActasEvento from './ActasEvento';
import CompromisoDetalle from './CompromisoDetalle';
import PersonaCompromisos from './PersonaCompromisos';
import CompromisosCampana, { resetCampanaCache } from './CompromisosCampana';
import { emptyCompromiso } from '@/lib/actas';

const json = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

const DETAIL = {
    id: 'c1', descripcion: 'Gestionar cámaras', estado: 'pendiente', fecha_limite: '2026-11-01', vencido: true, editable: true,
    responsables: [{ nombre: 'Sandra', telefono: '3177778888', lider_id: null }], acta: { id: 'a1', titulo: 'Acta' }, evento: { id: 'e1', nombre: 'Gira' },
    avances: [],
};

describe('Actas components', () => {
    const original = globalThis.fetch;
    beforeEach(() => { globalThis.fetch = vi.fn(); });
    afterEach(() => { globalThis.fetch = original; });

    it('shows the real counts before deleting a confirmed acta and deletes on confirmation', async () => {
        globalThis.fetch = vi.fn()
            .mockReturnValueOnce(json(200, { success: true, data: { asistentes: 2, fichas_borrar: 1, fichas_conservar: 1, compromisos: 1, avances: 0 } }))
            .mockReturnValueOnce(json(200, { success: true, message: 'Acta eliminada.' }));
        const onDeleted = vi.fn();
        render(<ActaEliminarConfirmada url="/eventos/e1/actas/a1" onDeleted={onDeleted} onError={() => {}} />);
        fireEvent.click(screen.getByText('Eliminar acta'));
        expect(await screen.findByText(/Se quitan 2 asistentes que entraron/)).toBeTruthy();
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/e1/actas/a1/eliminar');
        fireEvent.click(screen.getByText('Sí, eliminar'));
        await waitFor(() => expect(onDeleted).toHaveBeenCalledWith('Acta eliminada.'));
        expect(globalThis.fetch.mock.calls[1][1].method).toBe('DELETE');
    });

    it('does not delete when the preview is refused', async () => {
        globalThis.fetch = vi.fn(() => json(403, { message: 'No tiene permisos para acceder a esta sección.' }));
        const onError = vi.fn();
        const onDeleted = vi.fn();
        render(<ActaEliminarConfirmada url="/eventos/e1/actas/a1" onDeleted={onDeleted} onError={onError} />);
        fireEvent.click(screen.getByText('Eliminar acta'));
        await waitFor(() => expect(onError).toHaveBeenLastCalledWith('No tiene permisos para acceder a esta sección.'));
        expect(screen.queryByText('Sí, eliminar')).toBeNull();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(onDeleted).not.toHaveBeenCalled();
    });

    it('adds an attendee of the event as a participant with one click', () => {
        const onChange = vi.fn();
        render(<ActaParticipantes items={[]} onChange={onChange} municipio="GIRÓN" asistentes={[{ id: 'A1', nombre: 'Ana Pérez', telefono: '300', cedula: '123' }, { id: 'A2', nombre: 'Luis Gómez' }]} />);
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'perez' } });
        expect(screen.queryByText('Luis Gómez')).toBeNull();
        fireEvent.click(screen.getByText('Ana Pérez'));
        expect(onChange).toHaveBeenCalledWith([expect.objectContaining({ nombre: 'Ana Pérez', attendee_id: 'A1', municipio: 'GIRÓN' })]);
    });

    it('searches the system, adds a found person linked and offers a new one', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: [{ id: 'L1', nombre: 'Nelson Díaz', telefono: '3005476151', cedula: '91466940', municipio: 'Rionegro', cargo: 'Presidente JAC' }] }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        const onChange = vi.fn();
        render(<ActaParticipantes items={[]} onChange={onChange} municipio="RIONEGRO" asistentes={[]} />);
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'nelson' } });
        fireEvent.click(await screen.findByText('Nelson Díaz', {}, { timeout: 2000 }));
        expect(onChange).toHaveBeenLastCalledWith([expect.objectContaining({ nombre: 'Nelson Díaz', lider_id: 'L1', perfil: 'Presidente JAC', cedula: '91466940' })]);

        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'Pedro Nuevo' } });
        fireEvent.click(screen.getByText('+ Agregar «Pedro Nuevo» como persona nueva'));
        expect(onChange).toHaveBeenLastCalledWith([expect.objectContaining({ nombre: 'Pedro Nuevo', lider_id: null, municipio: 'RIONEGRO' })]);
    });

    it('says when nobody matches and hides editing when read-only', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        const { rerender } = render(<ActaParticipantes items={[]} onChange={() => {}} asistentes={[{ id: 'A1', nombre: 'Ana' }]} />);
        fireEvent.change(screen.getByLabelText('Agregar participante'), { target: { value: 'zzz' } });
        expect(await screen.findByText('No está en el sistema.', {}, { timeout: 2000 })).toBeTruthy();
        rerender(<ActaParticipantes items={[]} onChange={() => {}} asistentes={[{ id: 'A1', nombre: 'Ana' }]} disabled />);
        expect(screen.queryByText('Agregar participante')).toBeNull();
    });

    it('adds responsables to a compromiso and does not let remove one with avances', () => {
        const onChange = vi.fn();
        const c = { ...emptyCompromiso(), id: 'c1', descripcion: 'Con historia', avances: 2 };
        render(<ActaCompromisos items={[c]} onChange={onChange} />);
        expect(screen.queryByLabelText('Quitar el compromiso 1')).toBeNull();
        fireEvent.click(screen.getByText('Agregar otro responsable'));
        expect(onChange.mock.calls[0][0][0].responsables).toHaveLength(2);
    });

    it('asks "¿Es esta persona?" and saves only when everything is answered', () => {
        const onDone = vi.fn();
        const items = [{ kind: 'participante', index: 0, nombre: 'MARIA', lider: { id: 'L1', nombre: 'Maria Gomez', municipio: 'Girón' } }];
        render(<ActaSugerencias items={items} onDone={onDone} onCancel={() => {}} />);
        const save = screen.getByRole('button', { name: 'Guardar acta' });
        expect(save.disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Sí, es esta persona' }));
        fireEvent.click(save);
        expect(onDone).toHaveBeenCalledWith([expect.objectContaining({ yes: true, lider: expect.objectContaining({ id: 'L1' }) })]);
    });

    it('lets a borrador be saved with questions left for later', () => {
        const onDone = vi.fn();
        const items = [{ kind: 'responsable', path: [0, 0], nombre: 'SANDRA', lider: { id: 'L1', nombre: 'Sandra' } }];
        render(<ActaSugerencias items={items} onDone={onDone} onCancel={() => {}} allowSkip doneLabel="Guardar borrador" />);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar borrador' }));
        expect(onDone).toHaveBeenCalledWith([expect.objectContaining({ yes: undefined })]);
    });

    it('shows the count of the bell and its list, asking the server once', async () => {
        resetCampanaCache();
        globalThis.fetch.mockReturnValue(json(200, { success: true, data: {
            total: 2, vencidos: 1, por_vencer: 0, recordatorios: 1,
            items: [
                { id: 'c1', tipo: 'vencido', descripcion: 'Gestionar cámaras', fecha_limite: '2026-11-01', evento: 'Gira', url: '/eventos/compromisos?abrir=c1' },
                { id: 'c2', tipo: 'recordatorio', descripcion: 'Llamar al alcalde', fecha_limite: null, evento: 'Gira', url: '/eventos/compromisos?abrir=c2' },
            ],
        } }));
        const { unmount } = render(<CompromisosCampana />);
        const bell = await screen.findByRole('button', { name: 'Compromisos: 2 para revisar' });
        fireEvent.click(bell);
        expect(screen.getByText('1 vencidos · 0 por vencer · 1 recordatorios')).toBeTruthy();
        expect(screen.getByText('Gestionar cámaras').closest('a').getAttribute('href')).toBe('/eventos/compromisos?abrir=c1');
        expect(screen.getByText('Recordatorio')).toBeTruthy();
        unmount();
        render(<CompromisosCampana />);
        await screen.findByRole('button', { name: 'Compromisos: 2 para revisar' });
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('changes the estado of a compromiso and shows the new history', async () => {
        globalThis.fetch.mockImplementation((url, options = {}) => {
            if (options.method === 'PUT') {
                expect(JSON.parse(options.body)).toEqual({ estado: 'cumplido', nota: 'Listo' });
                return json(200, { success: true, message: 'Estado actualizado: Cumplido.', data: { ...DETAIL, estado: 'cumplido', vencido: false, avances: [{ id: 'v1', estado_anterior: 'pendiente', estado_nuevo: 'cumplido', nota: 'Listo', usuario: 'Ana' }] } });
            }
            return json(200, { success: true, data: DETAIL });
        });
        const onChanged = vi.fn();
        render(<CompromisoDetalle compromisoId="c1" onClose={() => {}} onChanged={onChanged} />);
        await screen.findByText('Gestionar cámaras');
        expect(screen.getByText('Vencido')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Estado'), { target: { value: 'cumplido' } });
        fireEvent.change(screen.getByLabelText('Nota del avance'), { target: { value: 'Listo' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar avance' }));
        await screen.findByText('Estado actualizado: Cumplido.');
        expect(screen.getByText('Cambió el estado: Pendiente → Cumplido')).toBeTruthy();
        expect(onChanged).toHaveBeenCalled();
    });

    it('does not send an empty avance and explains a refused file', async () => {
        globalThis.fetch.mockImplementation((url, options = {}) => (options.method === 'POST'
            ? json(422, { message: 'x', errors: { archivo: ['El archivo debe ser una foto (JPG o PNG) o un PDF.'] } })
            : json(200, { success: true, data: DETAIL })));
        render(<CompromisoDetalle compromisoId="c1" onClose={() => {}} />);
        await screen.findByText('Gestionar cámaras');
        fireEvent.click(screen.getByRole('button', { name: 'Guardar avance' }));
        expect(screen.getByRole('alert').textContent).toMatch(/Escriba una nota/);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        fireEvent.change(screen.getByLabelText('Nota del avance'), { target: { value: 'Foto' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar avance' }));
        await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/foto \(JPG o PNG\)/));
    });

    it('lists the actas of an event and offers a new one only when editable', async () => {
        globalThis.fetch.mockReturnValue(json(200, { success: true, data: [{ id: 'a1', titulo: 'Acta Berlín', fecha: '2026-11-08', compromisos: 3, abiertos: 2, created_by: 'Ana' }] }));
        const { rerender } = render(<ActasEvento eventId="e1" editable={false} />);
        await screen.findByText('Acta Berlín');
        expect(screen.getByText(/08\/11\/2026 · 3 compromisos · 2 sin cumplir/)).toBeTruthy();
        expect(screen.queryByText('Acta de reunión')).toBeNull();
        rerender(<ActasEvento eventId="e1" editable />);
        expect(screen.getByText('Acta de reunión').getAttribute('href')).toBe('/eventos/e1/actas/nueva');
    });

    it('shows the compromisos of a ficha or why they could not load', async () => {
        globalThis.fetch.mockReturnValueOnce(json(200, { success: true, data: [{ ...DETAIL, evento: { nombre: 'Gira' } }] }));
        const { unmount } = render(<PersonaCompromisos liderId="L1" />);
        await screen.findByText('Gestionar cámaras');
        unmount();
        globalThis.fetch.mockReturnValueOnce(json(403, { message: 'No' }));
        render(<PersonaCompromisos liderId="L1" />);
        await screen.findByRole('alert');
    });
});
