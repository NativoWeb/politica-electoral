import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import UnificarModal from './UnificarModal';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const RESULTS = [
    { id: 'l2', tipo: 'lider', nombre: 'Ana Diaz Ruiz', cedula: '63555111', telefono: '3001234567', municipio: 'Girón', cargo: 'Líder', candidatura: null },
    { id: 'p1', tipo: 'candidato', nombre: 'ANA DIAZ', cedula: null, telefono: null, municipio: 'Santander', cargo: 'Asamblea Santander 2023',
        candidatura: { cargo: 'Asamblea Santander 2023', territorio: 'Santander', anio: 2023, partido: 'Centro Democrático' } },
];

const FINAL = { nombre: 'Ana Diaz Ruiz', cedula: '63555111', telefonos: ['3001234567'], cargos: ['Líder'], candidaturas: [] };

function bodyOf(call) {
    return JSON.parse(call[1].body);
}

describe('UnificarModal', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function renderModal(onDone = vi.fn()) {
        render(<UnificarModal recordId="l1" recordName="Ana Diaz" onClose={vi.fn()} onDone={onDone} />);
        return onDone;
    }

    it('searches from two characters and lists lideres and candidates with their candidacy', async () => {
        fetchMock.mockImplementation(() => json({ success: true, data: RESULTS }));
        renderModal();
        const input = screen.getByLabelText('Buscar por nombre, cédula o teléfono');

        fireEvent.change(input, { target: { value: 'a' } });
        expect(screen.getByText('Escribe al menos 2 caracteres para buscar.')).toBeInTheDocument();
        await new Promise(r => setTimeout(r, 350));
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.change(input, { target: { value: 'ana diaz' } });
        expect(await screen.findByText('Ana Diaz Ruiz')).toBeInTheDocument();
        expect(fetchMock.mock.calls[0][0]).toBe('/mapa-politico/persona/l1/unificar-con/buscar?q=ana%20diaz');
        expect(screen.getByText('Líder · Girón · CC 63555111 · 3001234567')).toBeInTheDocument();
        expect(screen.getByText('Candidato')).toBeInTheDocument();
        expect(screen.getByText('Asamblea Santander 2023 · Santander · 2023 · Centro Democrático')).toBeInTheDocument();
    });

    it('shows how the ficha will look and unifies on confirm', async () => {
        fetchMock
            .mockImplementationOnce(() => json({ success: true, data: RESULTS }))
            .mockImplementationOnce(() => json({ success: true, caso: 'lideres', id: 'l2', cedulas: null, final: FINAL }))
            .mockImplementationOnce(() => json({ success: true, message: 'Registros unificados.', caso: 'lideres', id: 'l2', final: FINAL }));
        const onDone = renderModal();

        fireEvent.change(screen.getByLabelText('Buscar por nombre, cédula o teléfono'), { target: { value: 'ana' } });
        fireEvent.click(await screen.findByText('Ana Diaz Ruiz'));

        expect(await screen.findByText('Así quedará')).toBeInTheDocument();
        expect(screen.getByText('63555111')).toBeInTheDocument();
        expect(fetchMock.mock.calls[1][0]).toBe('/mapa-politico/persona/l1/unificar-con/vista-previa');
        expect(bodyOf(fetchMock.mock.calls[1])).toEqual({ con: 'l2', cedula: null });

        fireEvent.click(screen.getByRole('button', { name: 'Sí, unificar' }));
        await waitFor(() => expect(onDone).toHaveBeenCalledWith('l2'));
        expect(fetchMock.mock.calls[2][0]).toBe('/mapa-politico/persona/l1/unificar-con');
        expect(fetchMock.mock.calls[2][1].method).toBe('POST');
        expect(bodyOf(fetchMock.mock.calls[2])).toEqual({ con: 'l2', cedula: null });
    });

    it('asks which cédula is right before letting the merge go ahead', async () => {
        fetchMock
            .mockImplementationOnce(() => json({ success: true, data: RESULTS }))
            .mockImplementationOnce(() => json({ success: true, caso: 'lideres', id: 'l1', cedulas: ['63555111', '63555112'], final: FINAL }))
            .mockImplementationOnce(() => json({ success: true, caso: 'lideres', id: 'l1', cedulas: ['63555111', '63555112'], final: { ...FINAL, cedula: '63555112' } }))
            .mockImplementationOnce(() => json({ success: true, id: 'l1' }));
        const onDone = renderModal();

        fireEvent.change(screen.getByLabelText('Buscar por nombre, cédula o teléfono'), { target: { value: 'ana' } });
        fireEvent.click(await screen.findByText('Ana Diaz Ruiz'));

        expect(await screen.findByText('Los dos registros tienen cédulas distintas. ¿Cuál es la correcta?')).toBeInTheDocument();
        expect(screen.getByText('Elige la cédula correcta')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sí, unificar' })).toBeDisabled();

        fireEvent.click(screen.getByLabelText('63555112'));
        await waitFor(() => expect(bodyOf(fetchMock.mock.calls[2])).toEqual({ con: 'l2', cedula: '63555112' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Sí, unificar' })).toBeEnabled());

        fireEvent.click(screen.getByRole('button', { name: 'Sí, unificar' }));
        await waitFor(() => expect(onDone).toHaveBeenCalledWith('l1'));
        expect(bodyOf(fetchMock.mock.calls[3])).toEqual({ con: 'l2', cedula: '63555112' });
    });

    it('explains why a record cannot be unified and does not offer to confirm', async () => {
        const reason = 'El candidato se postuló en Tunja y el líder está en Girón, fuera de ese territorio: no pueden ser la misma ficha.';
        fetchMock
            .mockImplementationOnce(() => json({ success: true, data: RESULTS }))
            .mockImplementationOnce(() => json({ success: false, message: reason }, 422));
        const onDone = renderModal();

        fireEvent.change(screen.getByLabelText('Buscar por nombre, cédula o teléfono'), { target: { value: 'ana' } });
        fireEvent.click(await screen.findByText('ANA DIAZ'));

        expect(await screen.findByRole('alert')).toHaveTextContent(reason);
        expect(screen.getByRole('button', { name: 'Sí, unificar' })).toBeDisabled();
        expect(screen.queryByText('Así quedará')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Elegir otra persona' }));
        expect(screen.getByLabelText('Buscar por nombre, cédula o teléfono')).toBeInTheDocument();
        expect(onDone).not.toHaveBeenCalled();
    });

    it('shows the server reason when the user may not unite candidates', async () => {
        fetchMock
            .mockImplementationOnce(() => json({ success: true, data: RESULTS }))
            .mockImplementationOnce(() => json({ success: false, message: 'Solo la campaña principal puede unificar candidatos: sus datos son compartidos por todas las campañas.' }, 403));
        renderModal();

        fireEvent.change(screen.getByLabelText('Buscar por nombre, cédula o teléfono'), { target: { value: 'ana' } });
        fireEvent.click(await screen.findByText('ANA DIAZ'));

        expect(await screen.findByRole('alert')).toHaveTextContent('Solo la campaña principal puede unificar candidatos');
    });
});
