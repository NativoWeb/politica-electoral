import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// The lists come from the shared prop "opcionesListas"; without it the forms use the original options.
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: {} }) }));
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

import CrearLiderModal from './CrearLiderModal';

const MUNICIPIOS = [{ id: 'm1', name: 'Girón', provincia: 'Soto' }];

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function renderModal() {
    render(<CrearLiderModal open onClose={vi.fn()} municipios={MUNICIPIOS} provincias={['Soto']} />);
}

describe('CrearLiderModal', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('opens with only nombre, municipio, tipo and cargo visible and every section closed', () => {
        renderModal();

        expect(screen.getByPlaceholderText('Nombre del lider')).toBeInTheDocument();
        expect(screen.getByLabelText('Municipio *')).toBeInTheDocument();
        for (const hidden of ['3XX XXX XXXX', 'correo@ejemplo.com', 'Numero de cedula', 'Ej: Abogado, Ingeniero...', 'Calle / Carrera', 'Barrio, vereda o corregimiento', 'Notas adicionales...']) {
            expect(screen.queryByPlaceholderText(hidden)).not.toBeInTheDocument();
        }
        expect(screen.queryByText('Convenio')).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Buscar referente/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Informacion Personal/ }));
        expect(screen.getByPlaceholderText('3XX XXX XXXX')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Numero de cedula')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Notas adicionales...')).toBeInTheDocument();
        expect(screen.getByText('Convenio')).toBeInTheDocument();
    });

    it('the referente section has no partido nor nivel de confianza', () => {
        renderModal();
        fireEvent.click(screen.getByRole('button', { name: /^Referente/ }));

        expect(screen.getByLabelText(/Buscar referente/)).toBeInTheDocument();
        expect(screen.queryByText('Partido')).not.toBeInTheDocument();
        expect(screen.queryByText('Nivel de confianza')).not.toBeInTheDocument();
    });

    it('a server error on a field of a closed section opens it and shows the error', async () => {
        fetchMock.mockImplementation(() => json({
            message: 'El email no es válido.',
            errors: { email: ['El email no es válido.'], referente_documento: ['Esta persona no está registrada en el sistema.'] },
        }, 422));
        renderModal();

        fireEvent.change(screen.getByPlaceholderText('Nombre del lider'), { target: { value: 'Ana Diaz' } });
        fireEvent.change(screen.getByLabelText('Municipio *'), { target: { value: 'Girón (Soto)' } });
        expect(screen.queryByPlaceholderText('correo@ejemplo.com')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Crear Lider' }));

        await waitFor(() => expect(screen.getByPlaceholderText('correo@ejemplo.com')).toBeInTheDocument());
        expect(screen.getByPlaceholderText('correo@ejemplo.com')).toHaveAttribute('aria-describedby', 'crear-lider-error-email');
        expect(document.getElementById('crear-lider-error-email')).toHaveTextContent('El email no es válido.');
        expect(screen.getByLabelText(/Buscar referente/)).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getAllByText('El email no es válido.').length).toBeGreaterThan(1);
        expect(screen.getByLabelText(/Buscar referente/)).toBeInTheDocument();
        expect(screen.getAllByText('Esta persona no está registrada en el sistema.').length).toBeGreaterThan(1);
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ nombre: 'Ana Diaz', municipio_id: 'm1' });
    });
});
