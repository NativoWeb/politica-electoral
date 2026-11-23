import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
    });

    it('shows a subcampaign record read-only, labelled with its campaign', () => {
        renderDetail(votante('x'));

        expect(screen.getByText('Grupo político Alejandro')).toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent('Este registro es del grupo político Alejandro. Puedes verlo, pero solo su equipo puede modificarlo.');
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Agregar' })).not.toBeInTheDocument();
    });

    it('keeps an own record editable and unlabelled', () => {
        renderDetail(votante('cd'));

        expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument();
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.queryByText(/^Grupo político /)).not.toBeInTheDocument();
    });

    it('shows the read-only notice when a save is rejected with 403 for another campaign', async () => {
        const apiFetch = vi.fn().mockRejectedValue({ status: 403, message: 'Este registro pertenece a otra campaña: solo se puede consultar.' });
        const { showToast } = renderDetail(votante('cd'), apiFetch);

        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Guardar Cambios' }));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith(
            'Este registro es de otro grupo político. Puedes verlo, pero solo su equipo puede modificarlo.', 'error',
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
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: [] };
        renderDetail(votante('cd'));

        expect(screen.queryByRole('note')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument();
    });
});

describe('VotanteDetail "Unificar con otra persona"', () => {
    it('is offered only on an editable own record to who may write', () => {
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
        const { unmount } = render(<VotanteDetail data={votante('cd')} loading={false} creating={false} onClose={vi.fn()} onSaved={vi.fn()} onDeleted={vi.fn()} apiFetch={vi.fn()} municipios={[]} showToast={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Unificar con otra persona' }));
        expect(screen.getByRole('dialog', { name: 'Unificar con otra persona' })).toBeInTheDocument();
        unmount();

        renderDetail(votante('x'));
        expect(screen.queryByRole('button', { name: 'Unificar con otra persona' })).not.toBeInTheDocument();
    });

    it('is hidden without write permission', () => {
        page.props = { auth: { user: { campaign: CD, canWrite: false } }, visibleCampaigns: VISIBLE };
        renderDetail(votante('cd'));
        expect(screen.queryByRole('button', { name: 'Unificar con otra persona' })).not.toBeInTheDocument();
    });

    it('without write permission the whole ficha is read-only with a plain notice', () => {
        page.props = { auth: { user: { campaign: CD, canWrite: false } }, visibleCampaigns: VISIBLE };
        renderDetail(votante('cd'));
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent('Puedes ver esta ficha, pero no tienes permiso para editarla.');
    });
});

describe('VotanteDetail comuna', () => {
    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
    });

    const COMUNAS = [{ id: 'c8', nombre: 'Comuna 8 - Suroccidente', tipo: 'comuna' }];

    it('lets the comuna be chosen outside edit mode and shows it read-only while editing', () => {
        renderDetail({ ...votante('cd'), comunas: COMUNAS, comuna_id: 'c8', comuna_source: 'auto' });
        fireEvent.click(screen.getByRole('button', { name: /Ubicacion/i }));

        expect(screen.getByLabelText(/Comuna \/ corregimiento/)).toHaveValue('c8');
        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        expect(screen.queryByLabelText(/Comuna \/ corregimiento/)).not.toBeInTheDocument();
        expect(screen.getByTestId('comuna-readonly')).toHaveTextContent('Comuna 8 - Suroccidente');
    });

    it('hides the comuna for a municipio without comunas', () => {
        renderDetail({ ...votante('cd'), comunas: [] });
        fireEvent.click(screen.getByRole('button', { name: /Ubicacion/i }));

        expect(screen.queryByText(/Comuna/)).not.toBeInTheDocument();
    });
});

describe('VotanteDetail: diferencias con Electo', () => {
    const MIGRACION = { por_completar: [], marcas: [], diferencias: [{ campo: 'fecha_nacimiento', label: 'Fecha de nacimiento', electo: '1990-09-16', actual: '1990-09-15', usar_electo: true }] };
    const originalFetch = globalThis.fetch;

    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
    });
    afterEach(() => { globalThis.fetch = originalFetch; });

    function renderConAviso(campaignId, onSaved = vi.fn()) {
        render(
            <VotanteDetail
                data={{ ...votante(campaignId), fecha_nacimiento: '1990-09-15', migracion: MIGRACION }}
                loading={false} creating={false} onClose={vi.fn()} onSaved={onSaved} onDeleted={vi.fn()}
                apiFetch={vi.fn()} municipios={[]} showToast={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Datos de Electo/ }));
        return onSaved;
    }

    it('en una ficha de otro grupo político solo muestra la diferencia', () => {
        renderConAviso('x');
        expect(screen.getByText('1990-09-16')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Dejar el de aquí' })).not.toBeInTheDocument();
    });

    it('sin write.data tampoco ofrece resolverla', () => {
        page.props.auth.user.canWrite = false;
        renderConAviso('cd');
        expect(screen.queryByRole('button', { name: 'Usar el de Electo' })).not.toBeInTheDocument();
    });

    it('al usar el de Electo la ficha muestra el valor nuevo y se recarga', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ success: true, campo: 'fecha_nacimiento', valor: '1990-09-16', migracion: null }), { status: 200 })));
        const onSaved = renderConAviso('cd');
        expect(screen.queryAllByText('1990-09-16')).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Usar el de Electo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, cambiar' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(screen.queryByText(/Datos de Electo/)).not.toBeInTheDocument();
        expect(screen.getByText('1990-09-16')).toBeInTheDocument();
        expect(screen.queryByText('1990-09-15')).not.toBeInTheDocument();
    });
});

describe('VotanteDetail: el aviso sigue abierto tras resolver', () => {
    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD, canWrite: true } }, visibleCampaigns: VISIBLE };
    });

    it('si quedan diferencias tras recargar la ficha, el aviso no se pliega', async () => {
        const dif = (campo, label, electo, actual) => ({ campo, label, electo, actual, usar_electo: true });
        const antes = { por_completar: [], marcas: [], diferencias: [dif('nombre', 'Nombre', 'BETO PEREZ', 'Beto Pérez'), dif('genero', 'Género', 'Femenino', 'Masculino')] };
        const despues = { ...antes, diferencias: [antes.diferencias[1]] };
        const originalFetch = globalThis.fetch;
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ success: true, campo: 'nombre', valor: 'BETO PEREZ', migracion: despues }), { status: 200 })));
        const props = { creating: false, onClose: vi.fn(), onDeleted: vi.fn(), apiFetch: vi.fn(), municipios: [], showToast: vi.fn() };
        const onSaved = vi.fn();
        const { rerender } = render(<VotanteDetail {...props} data={{ ...votante('cd'), migracion: antes }} loading={false} onSaved={onSaved} />);

        fireEvent.click(screen.getByRole('button', { name: /Datos de Electo/ }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Usar el de Electo' })[0]);
        fireEvent.click(screen.getByRole('button', { name: 'Sí, cambiar' }));
        await waitFor(() => expect(onSaved).toHaveBeenCalled());

        // The page reloads the ficha: spinner, then the new data.
        rerender(<VotanteDetail {...props} data={{ ...votante('cd'), migracion: antes }} loading onSaved={onSaved} />);
        rerender(<VotanteDetail {...props} data={{ ...votante('cd'), nombre: 'BETO PEREZ', migracion: despues }} loading={false} onSaved={onSaved} />);

        expect(screen.getByRole('button', { name: /Datos de Electo/ })).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText('Femenino')).toBeInTheDocument();
        globalThis.fetch = originalFetch;
    });
});
