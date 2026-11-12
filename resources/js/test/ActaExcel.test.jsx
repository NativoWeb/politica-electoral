// Acta from the Excel template (docs/eventos-api.md §14): template link in both origins and prefill from an .xlsx.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/CampaignChip', () => ({ default: () => null }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => ({ props: {} }),
    router: { visit: vi.fn(), on: () => () => {} },
}));

import Acta from '@/Pages/Eventos/Acta';

const json = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

describe('Acta from Excel', () => {
    const original = globalThis.fetch;
    beforeEach(() => { globalThis.fetch = vi.fn(); });
    afterEach(() => { globalThis.fetch = original; cleanup(); });

    it('offers the Excel template of the event and accepts Word or Excel', () => {
        render(<Acta evento={{ id: 'e1', nombre: 'Evento' }} acta={null} defaults={{}} asistentes={[]} can={{ edit: true }} />);
        expect(screen.getByRole('link', { name: /Descargar plantilla Excel/ }).getAttribute('href')).toBe('/eventos/e1/actas/plantilla.xlsx');
        expect(screen.getByText('Cargar Word o Excel')).toBeTruthy();
        expect(document.getElementById('acta-archivo').getAttribute('accept')).toContain('.xlsx,.xls');
    });

    it('offers the template of an organization in its own endpoint', () => {
        render(<Acta evento={{ id: 'o1', nombre: 'Gremio' }} acta={null} defaults={{}} asistentes={[]} can={{ edit: true }}
            origen={{ tipo: 'organizacion', base: '/empresarios/o1/actas', volver: '/empresarios/o1' }} />);
        expect(screen.getByRole('link', { name: /Descargar plantilla Excel/ }).getAttribute('href')).toBe('/empresarios/o1/actas/plantilla.xlsx');
    });

    it('prefills the form from an Excel read and shows its avisos', async () => {
        globalThis.fetch = vi.fn(() => json(200, { success: true, data: { tipo: 'excel', message: 'Revise lo que se leyó del Excel antes de guardar.', acta: {
            titulo: 'REUNIÓN DESDE EXCEL', fecha: '2026-11-08', orden_dia: ['Vías'], desarrollo: [], participantes: [{ nombre: 'Persona Excel', telefono: '3001112233' }],
            compromisos: [{ descripcion: 'Arreglar la vía', fecha_limite: '2026-11-30', recordar_el: null, responsables: [{ nombre: 'Responsable', telefono: null }] }],
            avisos: ['Compromisos, fila 3: la fecha límite «31/02/2026» no es válida (use dd/mm/aaaa); quedó vacía.'],
        } } }));
        render(<Acta evento={{ id: 'e1', nombre: 'Evento' }} acta={null} defaults={{}} asistentes={[]} can={{ edit: true }} />);
        const file = new File(['x'], 'acta.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        fireEvent.change(document.getElementById('acta-archivo'), { target: { files: [file] } });

        await waitFor(() => expect(screen.getByDisplayValue('REUNIÓN DESDE EXCEL')).toBeTruthy());
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/e1/actas/leer');
        expect(screen.getByDisplayValue('Persona Excel')).toBeTruthy();
        expect(screen.getByDisplayValue('Arreglar la vía')).toBeTruthy();
        expect(screen.getByText(/fila 3: la fecha límite/)).toBeTruthy();
        expect(screen.getByText('Revise lo que se leyó del Excel antes de guardar.')).toBeTruthy();
    });

    it('shows the error of a file that cannot be read', async () => {
        globalThis.fetch = vi.fn(() => json(422, { message: 'x', errors: { archivo: ['El Excel no tiene las hojas «Acta», «Participantes» ni «Compromisos».'] } }));
        render(<Acta evento={{ id: 'e1', nombre: 'Evento' }} acta={null} defaults={{}} asistentes={[]} can={{ edit: true }} />);
        fireEvent.change(document.getElementById('acta-archivo'), { target: { files: [new File(['x'], 'otro.xlsx')] } });
        await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/no tiene las hojas/));
    });
});
