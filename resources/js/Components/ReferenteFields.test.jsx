import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useState } from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ReferenteFields, { splitNombre } from './ReferenteFields';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const EMPTY = { referente_documento: '', referente_nombre: '', referente_apellido: '' };

let lastForm;
function Harness({ excludeId, initial = EMPTY }) {
    const [form, setForm] = useState(initial);
    lastForm = form;
    const set = (field, value) => setForm(prev => ({ ...prev, [field]: value }));
    return <ReferenteFields values={form} onChange={set} excludeId={excludeId} />;
}

const buscar = () => screen.getByLabelText(/Buscar referente/);

describe('splitNombre', () => {
    it('takes two words as nombre from four up, one from two or three', () => {
        expect(splitNombre('Maria Fernanda Rueda Gomez')).toEqual({ nombre: 'Maria Fernanda', apellido: 'Rueda Gomez' });
        expect(splitNombre('Pedro Rueda Gomez')).toEqual({ nombre: 'Pedro', apellido: 'Rueda Gomez' });
        expect(splitNombre('  Ana   Diaz ')).toEqual({ nombre: 'Ana', apellido: 'Diaz' });
        expect(splitNombre('Ana')).toEqual({ nombre: 'Ana', apellido: '' });
        expect(splitNombre(null)).toEqual({ nombre: '', apellido: '' });
    });
});

describe('ReferenteFields', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('an exact cédula applies the registered person and shows the name read-only', async () => {
        fetchMock.mockImplementation(() => json({ data: [
            { id: 'l2', nombre: 'Maria Fernanda Rueda Gomez', cedula: '1098765432', municipio: 'Girón', cargo: 'Concejal' },
        ] }));
        render(<Harness excludeId="l1" />);

        fireEvent.change(buscar(), { target: { value: '1.098.765.432' } });

        expect(await screen.findByTestId('referente-nombre')).toHaveTextContent('Maria Fernanda Rueda Gomez');
        expect(screen.getByTestId('referente-documento')).toHaveTextContent('1098765432');
        expect(fetchMock.mock.calls[0][0]).toBe('/mapa-politico/search-lideres?q=1.098.765.432&exclude_id=l1');
        expect(lastForm).toEqual({ referente_documento: '1098765432', referente_nombre: 'Maria Fernanda', referente_apellido: 'Rueda Gomez' });
        // Nombre and Apellido are no longer editable fields.
        expect(screen.queryByLabelText('Nombre')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Apellido')).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Buscar referente/)).not.toBeInTheDocument();
    });

    it('an exact cédula that starts another one is listed first and not applied by itself', async () => {
        fetchMock.mockImplementation(() => json({ data: [
            { id: 'l10', nombre: 'Diez Digitos', cedula: '1098765432', municipio: 'Girón', cargo: 'Líder' },
            { id: 'l8', nombre: 'Ocho Digitos', cedula: '10987654', municipio: 'Girón', cargo: 'Líder' },
        ] }));
        render(<Harness />);

        fireEvent.change(buscar(), { target: { value: '10987654' } });

        await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(2));
        expect(screen.getAllByRole('option')[0]).toHaveTextContent('Ocho Digitos');
        expect(lastForm).toEqual(EMPTY);

        fireEvent.click(screen.getByRole('button', { name: /Ocho Digitos/ }));
        expect(lastForm).toEqual({ referente_documento: '10987654', referente_nombre: 'Ocho', referente_apellido: 'Digitos' });
    });

    it('a cédula of nobody in the system warns and applies nothing', async () => {
        fetchMock.mockImplementation(() => json({ data: [] }));
        render(<Harness />);

        fireEvent.change(buscar(), { target: { value: '5555555' } });

        expect(await screen.findByRole('alert')).toHaveTextContent('Esta persona no está registrada en el sistema');
        expect(lastForm).toEqual(EMPTY);
        expect(screen.queryByTestId('referente-nombre')).not.toBeInTheDocument();
    });

    it('searches by name and picking a result applies it; one without cédula cannot be picked', async () => {
        fetchMock.mockImplementation(() => json({ data: [
            { id: 'l2', nombre: 'Pedro Rueda Gomez', cedula: '123', municipio: 'Girón', cargo: 'Líder' },
            { id: 'l3', nombre: 'Pedro Sin Documento', cedula: null, municipio: 'Girón', cargo: null },
        ] }));
        render(<Harness />);

        fireEvent.change(buscar(), { target: { value: 'pedro' } });
        await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(2));
        expect(screen.getByRole('button', { name: /Pedro Sin Documento/ })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: /Pedro Rueda Gomez/ }));
        expect(screen.getByTestId('referente-nombre')).toHaveTextContent('Pedro Rueda Gomez');
        expect(lastForm).toEqual({ referente_documento: '123', referente_nombre: 'Pedro', referente_apellido: 'Rueda Gomez' });
    });

    it('a referente written by hand in an old ficha is shown as is and can be removed', () => {
        render(<Harness initial={{ referente_documento: '', referente_nombre: 'Juan', referente_apellido: 'Manual' }} />);

        expect(screen.getByTestId('referente-nombre')).toHaveTextContent('Juan Manual');
        expect(screen.getByTestId('referente-documento')).toHaveTextContent('-');

        fireEvent.click(screen.getByRole('button', { name: 'Quitar referente' }));
        expect(lastForm).toEqual(EMPTY);
        expect(screen.queryByTestId('referente-nombre')).not.toBeInTheDocument();
        expect(buscar()).toHaveValue('');
    });

    it('an existing referente can be replaced by someone of the system', async () => {
        fetchMock.mockImplementation(() => json({ data: [{ id: 'l9', nombre: 'Ana Diaz', cedula: '999', municipio: 'Girón', cargo: 'Líder' }] }));
        render(<Harness initial={{ referente_documento: '111', referente_nombre: 'Viejo', referente_apellido: 'Referente' }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cambiar referente' }));
        fireEvent.change(buscar(), { target: { value: '999' } });

        await waitFor(() => expect(screen.getByTestId('referente-nombre')).toHaveTextContent('Ana Diaz'));
        expect(lastForm).toEqual({ referente_documento: '999', referente_nombre: 'Ana', referente_apellido: 'Diaz' });
    });

    it('offline it does not search and says so', async () => {
        const onLine = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
        render(<Harness />);

        fireEvent.change(buscar(), { target: { value: 'Ana Diaz' } });
        await new Promise(r => setTimeout(r, 350));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByRole('status')).toHaveTextContent(/Sin conexión/);
        expect(lastForm).toEqual(EMPTY);
        onLine.mockRestore();
    });

    it('a failed request applies nothing and does not claim the person is unregistered', async () => {
        fetchMock.mockImplementation(() => Promise.reject(new TypeError('Failed to fetch')));
        render(<Harness />);

        fireEvent.change(buscar(), { target: { value: '1098' } });

        expect(await screen.findByRole('status')).toHaveTextContent(/No se pudo buscar/);
        expect(screen.queryByText(/no está registrada/)).not.toBeInTheDocument();
        expect(lastForm).toEqual(EMPTY);
    });
});
