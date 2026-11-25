import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const page = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/lib/offlineDb', () => ({ offlineCreateLider: vi.fn() }));
vi.mock('@/lib/useAllMunicipios', () => ({ useAllMunicipios: list => list }));
vi.mock('@/Components/SearchableDropdown', () => ({
    default: ({ label, options, onChange }) => (
        <select aria-label={label} onChange={e => onChange(e.target.value || undefined)}>
            <option value="" />
            {options.map(o => <option key={o} value={o}>{o}</option>)}
        </select>
    ),
}));

import { NOMBRE_CON_NUMEROS, errorNombre, errorDeCampo, tieneNumeros } from '@/lib/nombreFicha';
import { attendeeClientErrors } from '@/lib/eventos';
import VotanteFilters from '@/Components/VotanteFilters';
import CrearLiderModal from '@/Components/CrearLiderModal';
import VotanteDetail from '@/Components/VotanteDetail';
import MiembrosTab from '@/Components/Empresarios/MiembrosTab';

const MSG = 'El nombre no puede tener números: si es la cédula o el teléfono, escríbelo en su campo.';

describe('nombreFicha', () => {
    it('rechaza dígitos, salvo el nombre guardado sin cambios', () => {
        expect(NOMBRE_CON_NUMEROS).toBe(MSG);
        expect(tieneNumeros('Ana Díaz')).toBe(false);
        expect(tieneNumeros('Ana Díaz 3001234567')).toBe(true);
        expect(errorNombre('JUAN PEREZ2')).toBe(MSG);
        expect(errorNombre('JUAN  PEREZ2 ', 'juan perez2')).toBeNull();
        expect(errorNombre('JUAN PEREZ3', 'JUAN PEREZ2')).toBe(MSG);
        expect(errorNombre('Juan Pérez')).toBeNull();
        expect(errorDeCampo({ errors: { nombre: [MSG] } }, 'nombre')).toBe(MSG);
        expect(errorDeCampo({}, 'nombre')).toBeNull();
    });

    it('el formulario de asistentes marca nombres y apellidos con números', () => {
        const errors = attendeeClientErrors({ nombres: 'Ana 2', apellidos: '1098765432', cedula: '1098765432', celular: '3001234567', barrio: 'Centro', birth: {} });
        expect(errors.nombres).toBe(MSG);
        expect(errors.apellidos).toBe(MSG);
        expect(attendeeClientErrors({ nombres: 'Ana', apellidos: 'Pérez', cedula: '1098765432', celular: '3001234567', barrio: 'Centro', birth: {} }).nombres).toBeUndefined();
    });
});

describe('VotanteFilters: Nombre con números', () => {
    it('envía el filtro, arranca con la URL y Limpiar lo quita', () => {
        const onApply = vi.fn();
        render(<VotanteFilters filters={{}} municipios={[]} onApply={onApply} onClear={vi.fn()} />);

        fireEvent.click(screen.getByLabelText('Nombre con números'));
        fireEvent.click(screen.getByRole('button', { name: /aplicar/i }));
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ nombre_con_numeros: '1' }));

        fireEvent.click(screen.getByRole('button', { name: /limpiar/i }));
        expect(screen.getByLabelText('Nombre con números')).not.toBeChecked();
    });

    it('marca la casilla cuando viene en la URL', () => {
        render(<VotanteFilters filters={{ nombre_con_numeros: '1' }} municipios={[]} onApply={vi.fn()} onClear={vi.fn()} />);
        expect(screen.getByLabelText('Nombre con números')).toBeChecked();
    });
});

describe('Formularios de ficha: nombre con números', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        page.props = { auth: { user: { campaign: { id: 'cd', name: 'CD' }, canWrite: true } }, visibleCampaigns: [{ id: 'cd', name: 'CD' }] };
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('Crear líder no envía y muestra el mensaje junto al nombre', () => {
        render(<CrearLiderModal open onClose={vi.fn()} municipios={[{ id: 'm1', name: 'Girón', provincia: 'Soto' }]} provincias={['Soto']} />);

        fireEvent.change(screen.getByPlaceholderText('Nombre del lider'), { target: { value: 'Ana Diaz 1098765432' } });
        fireEvent.change(screen.getByLabelText('Municipio *'), { target: { value: 'Girón (Soto)' } });
        fireEvent.click(screen.getByRole('button', { name: 'Crear Lider' }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByPlaceholderText('Nombre del lider')).toHaveAttribute('aria-describedby', 'crear-lider-error-nombre');
        expect(document.getElementById('crear-lider-error-nombre')).toHaveTextContent(MSG);
    });

    function detalle(apiFetch, showToast = vi.fn()) {
        const data = { id: 'v1', nombre: 'Beto Pérez', cedula: '222', municipio: 'Girón', campaign_id: 'cd', contactos: [], info_politica: [], nexos: [], referidos: [] };
        render(<VotanteDetail data={data} loading={false} creating={false} onClose={vi.fn()} onSaved={vi.fn()} onDeleted={vi.fn()} apiFetch={apiFetch} municipios={[]} showToast={showToast} />);
        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        return screen.getByDisplayValue('Beto Pérez');
    }

    it('Votantes no guarda un nombre nuevo con números y lo dice junto al campo', () => {
        const apiFetch = vi.fn();
        const showToast = vi.fn();
        fireEvent.change(detalle(apiFetch, showToast), { target: { value: 'Beto Pérez 3001234567' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));

        expect(apiFetch).not.toHaveBeenCalled();
        expect(showToast).toHaveBeenCalledWith(MSG, 'error');
        expect(document.getElementById('votante-nombre-error')).toHaveTextContent(MSG);
    });

    it('Votantes muestra junto al campo el rechazo del servidor', async () => {
        const apiFetch = vi.fn().mockRejectedValue({ status: 422, message: MSG, errors: { nombre: [MSG] } });
        detalle(apiFetch);
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));

        await waitFor(() => expect(document.getElementById('votante-nombre-error')).toHaveTextContent(MSG));
    });

    it('Empresarios: una persona nueva con la cédula en el nombre no se envía', async () => {
        fetchMock.mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        render(<MiembrosTab org={{ id: 'o1', municipio: 'Girón', miembros: [] }} editable roles={[{ value: 'afiliado', label: 'Afiliado' }]} />);

        fireEvent.change(screen.getByLabelText('Agregar miembro'), { target: { value: '1098765432' } });
        fireEvent.click(await screen.findByText('+ Agregar «1098765432» como persona nueva'));

        expect(document.getElementById('nuevo-miembro-nombre-error')).toHaveTextContent(MSG);
        fireEvent.click(screen.getByText('Agregar miembro'));
        expect(fetchMock.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
    });
});
