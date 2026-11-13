import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const apiFetch = vi.fn();
const showGlobalToast = vi.fn();
vi.mock('@/lib/api', () => ({
    apiFetch: (...args) => apiFetch(...args),
    showGlobalToast: (...args) => showGlobalToast(...args),
}));

import ComunaSelector from './ComunaSelector';

const COMUNAS = [
    { id: 'c1', nombre: 'Comuna 1 - Norte', tipo: 'comuna' },
    { id: 'c8', nombre: 'Comuna 8 - Suroccidente', tipo: 'comuna' },
    { id: 'r1', nombre: 'Corregimiento 1', tipo: 'corregimiento' },
];

const reply = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

describe('ComunaSelector', () => {
    beforeEach(() => {
        apiFetch.mockReset();
        showGlobalToast.mockReset();
    });

    it('renders nothing for a municipio without comunas', () => {
        const { container } = render(<ComunaSelector liderId="l1" comunas={[]} editable />);
        expect(container).toBeEmptyDOMElement();
    });

    it('shows the comuna read-only and whether it was automatic', () => {
        render(<ComunaSelector liderId="l1" comunas={COMUNAS} comunaId="c8" source="auto" />);
        expect(screen.getByTestId('comuna-readonly')).toHaveTextContent('Comuna 8 - Suroccidente');
        expect(screen.getByText('automática')).toBeInTheDocument();
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('saves a comuna chosen by hand as manual', async () => {
        apiFetch.mockReturnValue(reply(200, { success: true, message: 'Comuna actualizada.', comuna_id: 'c1', comuna_source: 'manual', comuna_nombre: 'Comuna 1 - Norte' }));
        const onSaved = vi.fn();
        render(<ComunaSelector liderId="l1" comunas={COMUNAS} comunaId="c8" source="auto" editable onSaved={onSaved} />);

        fireEvent.change(screen.getByLabelText(/Comuna \/ corregimiento/), { target: { value: 'c1' } });

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(apiFetch).toHaveBeenCalledWith('/votantes/l1/comuna', { method: 'PATCH', body: JSON.stringify({ comuna_id: 'c1' }) });
        expect(screen.getByText('manual')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Volver a automática' })).toBeInTheDocument();
        expect(showGlobalToast).toHaveBeenCalledWith('Comuna actualizada.');
    });

    it('goes back to the automatic comuna', async () => {
        apiFetch.mockReturnValue(reply(200, { success: true, comuna_id: 'c8', comuna_source: 'auto' }));
        render(<ComunaSelector liderId="l1" comunas={COMUNAS} comunaId="c1" source="manual" editable />);

        fireEvent.click(screen.getByRole('button', { name: 'Volver a automática' }));

        await waitFor(() => expect(screen.getByText('automática')).toBeInTheDocument());
        expect(apiFetch).toHaveBeenCalledWith('/votantes/l1/comuna', { method: 'PATCH', body: JSON.stringify({ automatica: true }) });
        expect(screen.getByLabelText(/Comuna \/ corregimiento/)).toHaveValue('c8');
    });

    it('shows the server reason and keeps the previous comuna when it is rejected', async () => {
        apiFetch.mockReturnValue(reply(403, { message: 'Este registro pertenece a otro grupo político: solo se puede consultar.' }));
        render(<ComunaSelector liderId="l1" comunas={COMUNAS} comunaId="c8" source="auto" editable />);

        fireEvent.change(screen.getByLabelText(/Comuna \/ corregimiento/), { target: { value: 'r1' } });

        expect(await screen.findByRole('alert')).toHaveTextContent('Este registro pertenece a otro grupo político');
        expect(screen.getByLabelText(/Comuna \/ corregimiento/)).toHaveValue('c8');
        expect(showGlobalToast).toHaveBeenCalledWith(expect.stringContaining('otro grupo político'), 'error');
    });

    it('reports a network failure', async () => {
        apiFetch.mockReturnValue(Promise.reject(new Error('offline')));
        render(<ComunaSelector liderId="l1" comunas={COMUNAS} comunaId={null} source={null} editable />);

        fireEvent.change(screen.getByLabelText(/Comuna \/ corregimiento/), { target: { value: 'c1' } });

        expect(await screen.findByRole('alert')).toHaveTextContent('Sin conexión: la comuna no se guardó.');
    });
});
