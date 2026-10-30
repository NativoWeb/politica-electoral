import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const page = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn() },
}));

import VotanteDetail from './VotanteDetail';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };
const VISIBLE = [{ id: 'cd', name: 'Centro Democrático' }, { id: 'x', name: 'Alejandro' }];

function votante(campaignId) {
    return { id: 'v1', nombre: 'Beto Pérez', cedula: '222', municipio: 'Girón', campaign_id: campaignId, contactos: [], info_politica: [], nexos: [], referidos: [] };
}

function renderDetail(data, apiFetch = vi.fn(), showToast = vi.fn()) {
    render(
        <VotanteDetail
            data={data}
            loading={false}
            creating={false}
            onClose={vi.fn()}
            onSaved={vi.fn()}
            onDeleted={vi.fn()}
            apiFetch={apiFetch}
            municipios={[]}
            showToast={showToast}
        />,
    );
    return { apiFetch, showToast };
}

describe('VotanteDetail campaign read-only', () => {
    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD } }, visibleCampaigns: VISIBLE };
    });

    it('shows a subcampaign record read-only, labelled with its campaign', () => {
        renderDetail(votante('x'));

        expect(screen.getByText('Campaña Alejandro')).toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent('Este registro es de la campaña Alejandro. Puedes verlo, pero solo su equipo puede modificarlo.');
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Agregar' })).not.toBeInTheDocument();
    });

    it('keeps an own record editable and unlabelled', () => {
        renderDetail(votante('cd'));

        expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.queryByText(/^Campaña /)).not.toBeInTheDocument();
    });

    it('shows the read-only notice when a save is rejected with 403 for another campaign', async () => {
        const apiFetch = vi.fn().mockRejectedValue({ status: 403, message: 'Este registro pertenece a otra campaña: solo se puede consultar.' });
        const { showToast } = renderDetail(votante('cd'), apiFetch);

        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith(
            'Este registro es de otra campaña. Puedes verlo, pero solo su equipo puede modificarlo.', 'error',
        ));
    });

    it('keeps the server reason for a 403 outside the territory', async () => {
        const apiFetch = vi.fn().mockRejectedValue({ status: 403, message: 'Este registro está fuera de tu territorio.' });
        const { showToast } = renderDetail(votante('cd'), apiFetch);

        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Este registro está fuera de tu territorio.', 'error'));
    });

    it('labels nothing for a user who only sees their own campaign', () => {
        page.props = { auth: { user: { campaign: CD } }, visibleCampaigns: [] };
        renderDetail(votante('cd'));

        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument();
    });
});
