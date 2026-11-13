import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';

const apiFetch = vi.fn();
const showGlobalToast = vi.fn();
vi.mock('@/lib/api', () => ({
    apiFetch: (...args) => apiFetch(...args),
    showGlobalToast: (...args) => showGlobalToast(...args),
}));

import BarriosComunasPanel from './BarriosComunasPanel';

const reply = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

const BGA = { id: 'bga', nombre: 'Bucaramanga' };
const LIST = {
    success: true,
    municipio: BGA,
    municipios: [BGA],
    comunas: [{ id: 'c8', nombre: 'Comuna 8 - Suroccidente', tipo: 'comuna' }, { id: 'c10', nombre: 'Comuna 10 - Provenza', tipo: 'comuna' }],
    barrios: [{ id: 'b1', nombre: 'CORDONCILLO I', comuna_id: 'c8' }, { id: 'b2', nombre: 'DIAMANTE II', comuna_id: 'c10' }],
    total_textos: 2,
    pendientes: [
        { texto: 'BALCONES DE PROVENZA', ejemplos: ['BALCONES DE PROVENZA'], lideres: 2, event_attendees: 1, organizaciones: 0, total: 3, estado: 'sin_match', sugerencia: null, candidatos: [] },
        { texto: 'LA PAZ FLORIDABLANCA', ejemplos: ['LA PAZ, FLORIDABLANCA'], lideres: 1, event_attendees: 0, organizaciones: 0, total: 1, estado: 'otro_municipio', sugerencia: null, candidatos: [] },
    ],
};

describe('BarriosComunasPanel', () => {
    beforeEach(() => {
        apiFetch.mockReset();
        showGlobalToast.mockReset();
    });

    it('lists the barrio texts without comuna with their counts and state', async () => {
        apiFetch.mockReturnValue(reply(200, LIST));
        render(<BarriosComunasPanel />);

        const rows = await screen.findAllByTestId('pendiente');
        expect(rows).toHaveLength(2);
        expect(rows[0]).toHaveTextContent('BALCONES DE PROVENZA');
        expect(rows[0]).toHaveTextContent('2 fichas · 1 asistente');
        expect(rows[0]).toHaveTextContent('Sin coincidencia');
        expect(rows[1]).toHaveTextContent('Escrito como: LA PAZ, FLORIDABLANCA');
        expect(rows[1]).toHaveTextContent('Menciona otro municipio');
        expect(screen.getByText('Bucaramanga: 2 textos sin comuna')).toBeInTheDocument();
        expect(apiFetch).toHaveBeenCalledWith('/admin/territorio/barrios-sin-comuna', { method: 'GET' });
    });

    it('assigns a text to a comuna, which reassigns its records, and reloads the queue', async () => {
        apiFetch
            .mockReturnValueOnce(reply(200, LIST))
            .mockReturnValueOnce(reply(200, { success: true, message: 'Asignado a Comuna 10 - Provenza: 3 registros.' }))
            .mockReturnValueOnce(reply(200, { ...LIST, total_textos: 1, pendientes: [LIST.pendientes[1]] }));
        render(<BarriosComunasPanel />);

        const [row] = await screen.findAllByTestId('pendiente');
        fireEvent.change(within(row).getByRole('combobox'), { target: { value: 'c:c10' } });
        fireEvent.click(within(row).getByRole('button', { name: 'Asignar' }));

        await waitFor(() => expect(screen.getAllByTestId('pendiente')).toHaveLength(1));
        expect(apiFetch).toHaveBeenNthCalledWith(2, '/admin/territorio/barrios/alias', {
            method: 'POST',
            body: JSON.stringify({ municipio_id: 'bga', texto: 'BALCONES DE PROVENZA', comuna_id: 'c10' }),
        });
        expect(apiFetch).toHaveBeenNthCalledWith(3, '/admin/territorio/barrios-sin-comuna?municipio_id=bga', { method: 'GET' });
        expect(showGlobalToast).toHaveBeenCalledWith('Asignado a Comuna 10 - Provenza: 3 registros.');
    });

    it('sends a barrio when one is chosen and keeps the row when the server rejects it', async () => {
        apiFetch
            .mockReturnValueOnce(reply(200, LIST))
            .mockReturnValueOnce(reply(422, { success: false, errors: { barrio_id: ['Ese barrio no es de este municipio.'] } }));
        render(<BarriosComunasPanel />);

        const [row] = await screen.findAllByTestId('pendiente');
        fireEvent.change(within(row).getByRole('combobox'), { target: { value: 'b:b1' } });
        fireEvent.click(within(row).getByRole('button', { name: 'Asignar' }));

        await waitFor(() => expect(showGlobalToast).toHaveBeenCalledWith('Ese barrio no es de este municipio.', 'error'));
        expect(JSON.parse(apiFetch.mock.calls[1][1].body)).toEqual({ municipio_id: 'bga', texto: 'BALCONES DE PROVENZA', barrio_id: 'b1' });
        expect(screen.getAllByTestId('pendiente')).toHaveLength(2);
    });

    it('says so when no municipio of the territory has comunas', async () => {
        apiFetch.mockReturnValue(reply(200, { success: true, municipio: null, municipios: [], comunas: [], barrios: [], pendientes: [] }));
        render(<BarriosComunasPanel />);

        expect(await screen.findByText('Ningún municipio de tu territorio tiene comunas cargadas.')).toBeInTheDocument();
    });

    it('shows the server reason on a 403 and lets retry', async () => {
        apiFetch
            .mockReturnValueOnce(reply(403, { message: 'Este registro está fuera de tu territorio.' }))
            .mockReturnValueOnce(reply(200, LIST));
        render(<BarriosComunasPanel />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Este registro está fuera de tu territorio.');
        fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(await screen.findAllByTestId('pendiente')).toHaveLength(2);
    });

    it('disables Asignar until something is chosen', async () => {
        apiFetch.mockReturnValue(reply(200, LIST));
        render(<BarriosComunasPanel />);

        const [row] = await screen.findAllByTestId('pendiente');
        expect(within(row).getByRole('button', { name: 'Asignar' })).toBeDisabled();
    });
});
