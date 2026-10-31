import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

const page = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn(), visit: vi.fn() },
    Link: ({ children }) => children,
    useForm: () => ({}),
}));

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));

vi.mock('@/lib/offlineDb', () => ({
    getOfflinePersona: vi.fn().mockResolvedValue(null),
    offlineUpdatePersona: vi.fn(),
    offlineCreateNexo: vi.fn(),
    offlineDeleteNexo: vi.fn(),
}));

import PersonaPanel from '@/Components/PersonaPanel';
import VotanteDetail from '@/Components/VotanteDetail';
import { GlobalSearch } from '@/Layouts/AppLayout';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };

const PERSONA = {
    id: 'l1', source: 'lider', campaign_id: 'cd', nombre: 'Beto Pérez', municipio: 'Girón', cargo: 'Líder',
    nexos: [], contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [],
};

const VOTANTE = { id: 'v1', nombre: 'Ana Gómez', cedula: '222', municipio: 'Girón', campaign_id: 'cd', contactos: [], info_politica: [], nexos: [], referidos: [] };

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function pressEscape() {
    fireEvent.keyDown(document, { key: 'Escape' });
}

function renderVotante(props = {}) {
    const onClose = vi.fn();
    render(
        <VotanteDetail
            data={VOTANTE} loading={false} creating={false} onClose={onClose} onSaved={vi.fn()} onDeleted={vi.fn()}
            apiFetch={vi.fn()} municipios={[]} showToast={vi.fn()} {...props}
        />,
    );
    return onClose;
}

describe('Escape closes the ficha', () => {
    const originalFetch = globalThis.fetch;

    beforeEach(() => {
        page.props = { auth: { user: { campaign: CD } }, visibleCampaigns: [{ id: 'cd', name: 'Centro Democrático' }] };
        Element.prototype.scrollIntoView = vi.fn();
        globalThis.fetch = vi.fn((url) => json(url.includes('posibles-duplicados') ? { data: [] } : PERSONA));
    });

    afterEach(() => {
        cleanup();
        globalThis.fetch = originalFetch;
    });

    it('PersonaPanel: Escape calls onClose like the X button', async () => {
        const onClose = vi.fn();
        render(<PersonaPanel personId="l1" onClose={onClose} />);
        await screen.findByRole('button', { name: 'Editar' });

        pressEscape();

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('PersonaPanel: Escape closes the confirmation dialog first and keeps the ficha open', async () => {
        const onClose = vi.fn();
        render(<PersonaPanel personId="l1" onClose={onClose} />);
        fireEvent.click(await screen.findByTitle('Desactivar persona'));
        expect(screen.getByRole('button', { name: 'Confirmar' })).toBeInTheDocument();

        pressEscape();

        expect(screen.queryByRole('button', { name: 'Confirmar' })).not.toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
        expect(globalThis.fetch).not.toHaveBeenCalledWith(expect.stringContaining('/toggle'), expect.anything());

        pressEscape();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('PersonaPanel: Escape in edit mode with unsaved changes closes without saving', async () => {
        const onClose = vi.fn();
        render(<PersonaPanel personId="l1" onClose={onClose} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Otro nombre' } });

        pressEscape();

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(globalThis.fetch.mock.calls.every(([, o = {}]) => (o.method || 'GET') === 'GET')).toBe(true);
    });

    it('ignores other keys and an Escape already handled by someone else', async () => {
        const onClose = vi.fn();
        render(<PersonaPanel personId="l1" onClose={onClose} />);
        await screen.findByRole('button', { name: 'Editar' });

        fireEvent.keyDown(document, { key: 'Enter' });
        const handled = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        handled.preventDefault();
        document.dispatchEvent(handled);

        expect(onClose).not.toHaveBeenCalled();
    });

    it('VotanteDetail: Escape calls onClose', () => {
        const onClose = renderVotante();

        pressEscape();

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('VotanteDetail: Escape closes the deactivate confirmation first', () => {
        const onClose = renderVotante();
        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
        fireEvent.click(screen.getByTitle('Desactivar votante'));
        expect(screen.getByRole('heading', { name: 'Desactivar votante' })).toBeInTheDocument();

        pressEscape();

        expect(screen.queryByRole('heading', { name: 'Desactivar votante' })).not.toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('two mounted copies (desktop + mobile): only the one holding the focus reacts', () => {
        const desktopClose = renderVotante();
        const mobileClose = renderVotante();
        const [desktopEdit] = screen.getAllByRole('button', { name: 'Editar' });
        fireEvent.click(desktopEdit);
        const toggle = screen.getByTitle('Desactivar votante');
        fireEvent.click(toggle);
        toggle.focus();

        pressEscape();

        expect(screen.queryByRole('heading', { name: 'Desactivar votante' })).not.toBeInTheDocument();
        expect(desktopClose).not.toHaveBeenCalled();
        expect(mobileClose).not.toHaveBeenCalled();
    });

    it('/votantes: the display:none copy never reacts, the visible one does even without focus', () => {
        const desktopClose = renderVotante();
        const hiddenMobileClose = renderVotante();
        const [desktopRoot, mobileRoot] = screen.getAllByRole('heading', { name: 'Ana Gómez' }).map(h => h.closest('div.bg-white'));
        desktopRoot.checkVisibility = () => true;
        mobileRoot.checkVisibility = () => false;
        document.body.focus();

        pressEscape();

        expect(hiddenMobileClose).not.toHaveBeenCalled();
        expect(desktopClose).toHaveBeenCalledTimes(1);
    });

    it('/votantes: with the confirmation open in the visible copy, Escape closes only that confirmation', () => {
        const desktopClose = renderVotante();
        const hiddenMobileClose = renderVotante();
        const [desktopRoot, mobileRoot] = screen.getAllByRole('heading', { name: 'Ana Gómez' }).map(h => h.closest('div.bg-white'));
        desktopRoot.checkVisibility = () => true;
        mobileRoot.checkVisibility = () => false;
        fireEvent.click(screen.getAllByRole('button', { name: 'Editar' })[0]);
        fireEvent.click(screen.getByTitle('Desactivar votante'));
        document.body.focus();

        pressEscape();

        expect(screen.queryByRole('heading', { name: 'Desactivar votante' })).not.toBeInTheDocument();
        expect(desktopClose).not.toHaveBeenCalled();
        expect(hiddenMobileClose).not.toHaveBeenCalled();
    });

    it('header search: Escape closes its dropdown without closing the open ficha, the next one closes the ficha', async () => {
        globalThis.fetch = vi.fn(() => json({ results: [{ type: 'persona', id: 'p1', name: 'Beto Pérez', url: '/persona/p1' }] }));
        const onClose = renderVotante();
        render(<GlobalSearch />);
        const input = screen.getByPlaceholderText('Buscar persona, municipio, partido...');
        fireEvent.change(input, { target: { value: 'Beto' } });
        await screen.findByText('Beto Pérez');
        input.focus();

        fireEvent.keyDown(input, { key: 'Escape' });

        expect(screen.queryByText('Beto Pérez')).not.toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.keyDown(input, { key: 'Escape' });
        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    });

    it('stops listening once the panel unmounts', () => {
        const onClose = renderVotante();
        cleanup();

        pressEscape();

        expect(onClose).not.toHaveBeenCalled();
    });
});
