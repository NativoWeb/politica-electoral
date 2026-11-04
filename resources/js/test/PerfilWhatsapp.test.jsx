// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

const mockPage = { props: {} };

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('axios', () => ({ default: { post: vi.fn(), delete: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => mockPage, router: { reload: vi.fn() } }));

import Perfil from '@/Pages/Perfil';

const LINKED = { linked: true, phoneLast4: '4567', verifiedAt: '2026-10-19T10:00:00-05:00' };
const UNLINKED = { linked: false, phoneLast4: null, verifiedAt: null };

function renderAs(whatsapp) {
    mockPage.props = {
        auth: { user: { name: 'Pedro Operador', email: 'pedro@x.test', role: 'Operador', whatsapp } },
        agent: { whatsappNumber: '+573134459145' },
    };
    return render(<Perfil />);
}

describe('Perfil: Asistente de WhatsApp', () => {
    it('offers the linking form to a user allowed to use the assistant', () => {
        renderAs({ allowed: true, ...UNLINKED });

        expect(screen.getByRole('heading', { name: 'Vincular WhatsApp' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Generar código' })).toBeInTheDocument();
    });

    it('hides the card without the permission and without a linked number', () => {
        renderAs({ allowed: false, ...UNLINKED });

        expect(screen.getByText('Pedro Operador')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Vincular WhatsApp' })).not.toBeInTheDocument();
    });

    it('lets a linked user whose assistant was revoked see the number and unlink it, but not get a code', () => {
        renderAs({ allowed: false, ...LINKED });

        expect(screen.getByRole('heading', { name: 'Vincular WhatsApp' })).toBeInTheDocument();
        expect(screen.getByText(/•••• 4567/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Desvincular' })).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('El administrador desactivó tu Asistente de WhatsApp');
        expect(screen.queryByRole('button', { name: 'Generar código' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Puedes escribirle al asistente/)).not.toBeInTheDocument();
    });

    it('keeps the usual linked state for a user with the assistant', () => {
        renderAs({ allowed: true, ...LINKED });

        expect(screen.getByText(/Puedes escribirle al asistente/)).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
