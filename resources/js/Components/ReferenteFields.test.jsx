import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useState } from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ReferenteFields, { splitNombre } from './ReferenteFields';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const EMPTY = { referente_documento: '', referente_nombre: '', referente_apellido: '' };

function Harness({ excludeId }) {
    const [form, setForm] = useState(EMPTY);
    const set = (field, value) => setForm(prev => ({ ...prev, [field]: value }));
    return <ReferenteFields values={form} onChange={set} excludeId={excludeId} />;
}

const documento = () => screen.getByLabelText('Documento');
const nombre = () => screen.getByLabelText('Nombre');
const apellido = () => screen.getByLabelText('Apellido');

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

    it('searches by name from two characters and picking a result fills the three fields', async () => {
        fetchMock.mockImplementation(() => json({ data: [
            { id: 'l2', nombre: 'Maria Fernanda Rueda Gomez', cedula: '1098765432', municipio: 'Girón', cargo: 'Concejal' },
        ] }));
        render(<Harness excludeId="l1" />);

        fireEvent.change(nombre(), { target: { value: 'm' } });
        await new Promise(r => setTimeout(r, 350));
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.change(nombre(), { target: { value: 'mari' } });
        const option = await screen.findByRole('option');
        expect(option).toHaveTextContent('CC 1098765432 · Girón · Concejal');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/mapa-politico/search-lideres?q=mari&exclude_id=l1');

        fireEvent.click(screen.getByRole('button', { name: /Maria Fernanda Rueda Gomez/ }));
        expect(documento()).toHaveValue('1098765432');
        expect(nombre()).toHaveValue('Maria Fernanda');
        expect(apellido()).toHaveValue('Rueda Gomez');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(screen.queryByText(/Sin cédula/)).not.toBeInTheDocument();
    });

    it('searches by document and shows at most eight matches', async () => {
        fetchMock.mockImplementation(() => json({ data: Array.from({ length: 10 }, (_, i) => ({ id: `l${i}`, nombre: `Persona ${i}`, cedula: `10${i}`, municipio: 'Girón', cargo: 'Líder' })) }));
        render(<Harness />);

        fireEvent.change(documento(), { target: { value: '10' } });
        await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(8));
        expect(fetchMock.mock.calls[0][0]).toBe('/mapa-politico/search-lideres?q=10');
    });

    it('lets the user keep typing by hand when nobody matches, and warns without a cédula', async () => {
        fetchMock.mockImplementation(() => json({ data: [] }));
        render(<Harness />);

        fireEvent.change(nombre(), { target: { value: 'Nadie Conocido' } });
        expect(await screen.findByText(/No está en el sistema/)).toBeInTheDocument();
        fireEvent.change(apellido(), { target: { value: 'Perez' } });

        expect(nombre()).toHaveValue('Nadie Conocido');
        expect(apellido()).toHaveValue('Perez');
        expect(screen.getByText(/Sin cédula, esta persona no queda vinculada/)).toBeInTheDocument();

        fireEvent.change(documento(), { target: { value: '5' } });
        expect(screen.queryByText(/Sin cédula/)).not.toBeInTheDocument();
    });

    it('a referente without cédula fills the name and keeps the warning', async () => {
        fetchMock.mockImplementation(() => json({ data: [{ id: 'l3', nombre: 'Luis Sin Documento', cedula: null, municipio: 'Girón', cargo: null }] }));
        render(<Harness />);

        fireEvent.change(nombre(), { target: { value: 'luis' } });
        fireEvent.click(await screen.findByRole('button', { name: /Luis Sin Documento/ }));

        expect(documento()).toHaveValue('');
        expect(nombre()).toHaveValue('Luis');
        expect(apellido()).toHaveValue('Sin Documento');
        expect(screen.getByText(/Sin cédula, esta persona no queda vinculada/)).toBeInTheDocument();
    });

    it('offline it does not search and the fields stay editable', async () => {
        const onLine = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
        render(<Harness />);

        fireEvent.change(nombre(), { target: { value: 'Ana Diaz' } });
        await new Promise(r => setTimeout(r, 350));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(nombre()).toHaveValue('Ana Diaz');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        onLine.mockRestore();
    });

    it('a failed request leaves the fields as plain inputs', async () => {
        fetchMock.mockImplementation(() => Promise.reject(new TypeError('Failed to fetch')));
        render(<Harness />);

        fireEvent.change(documento(), { target: { value: '1098' } });
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        await new Promise(r => setTimeout(r, 20));

        expect(documento()).toHaveValue('1098');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(screen.queryByText(/No está en el sistema/)).not.toBeInTheDocument();
    });
});
