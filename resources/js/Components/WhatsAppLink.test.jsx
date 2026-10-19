import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockPost = vi.fn();
const mockDelete = vi.fn();
const mockReload = vi.fn();

vi.mock('axios', () => ({
    default: {
        post: (...args) => mockPost(...args),
        delete: (...args) => mockDelete(...args),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: (...args) => mockReload(...args) },
}));

import WhatsAppLink, { buildWhatsAppUrl, composePhone } from './WhatsAppLink';

const UNLINKED = { linked: false, phoneLast4: null, verifiedAt: null };

function fillAndSubmit(number) {
    fireEvent.change(screen.getByLabelText('Tu número de WhatsApp'), { target: { value: number } });
    fireEvent.click(screen.getByRole('button', { name: 'Generar código' }));
}

describe('WhatsAppLink', () => {
    beforeEach(() => {
        mockPost.mockReset();
        mockDelete.mockReset();
        mockReload.mockReset();
    });

    it('builds the wa.me link without the plus sign', () => {
        expect(buildWhatsAppUrl('+57 313 445 9145', '012345')).toBe('https://wa.me/573134459145?text=VINCULAR%20012345');
        expect(buildWhatsAppUrl('', '012345')).toBeNull();
    });

    it('normalizes numbers pasted with their country code', () => {
        expect(composePhone('+57', '300 123 4567')).toEqual({ phone: '+573001234567' });
        expect(composePhone('+57', '+57 300 123 4567')).toEqual({ phone: '+573001234567' });
        expect(composePhone('+57', '57 300 123 4567')).toEqual({ phone: '+573001234567' });
        expect(composePhone('+57', '0057 300 123 4567')).toEqual({ phone: '+573001234567' });
        expect(composePhone('+57', '+1 202 555 0123')).toEqual({ phone: '+12025550123' });
        expect(composePhone('', '+57 300 123 4567')).toEqual({ phone: '+573001234567' });
        expect(composePhone('+1', '1 202 555 0123')).toEqual({ phone: '+12025550123' });
        expect(composePhone('+57', '5712345678')).toEqual({ phone: '+575712345678' });
    });

    it('rejects an empty country code or number before calling the server', () => {
        expect(composePhone('', '300 123 4567')).toEqual({ error: expect.stringContaining('indicativo'), field: 'prefix' });
        expect(composePhone('+57', '  -- ')).toEqual({ error: 'Escribe tu número de WhatsApp.', field: 'number' });

        render(<WhatsAppLink whatsapp={UNLINKED} agentNumber="+573134459145" />);
        fireEvent.change(screen.getByLabelText('Indicativo del país'), { target: { value: '' } });
        fillAndSubmit('3001234567');

        expect(screen.getByRole('alert')).toHaveTextContent('indicativo');
        expect(screen.getByLabelText('Indicativo del país')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('Indicativo del país')).toHaveFocus();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('sends the E.164 phone and shows the code with the WhatsApp link', async () => {
        const expires = new Date(Date.now() + 10 * 60 * 1000).toISOString();
        mockPost.mockResolvedValue({ data: { success: true, code: '048213', expires_at: expires }, headers: {} });

        render(<WhatsAppLink whatsapp={UNLINKED} agentNumber="+573134459145" />);
        fillAndSubmit('+57 300 123 4567');

        await waitFor(() => expect(screen.getByText('Tu código')).toBeInTheDocument());
        expect(mockPost).toHaveBeenCalledWith('/perfil/whatsapp/codigo', { phone: '+573001234567' }, expect.any(Object));
        expect(screen.getByText('0 4 8 2 1 3')).toHaveClass('sr-only');
        expect(screen.getByRole('link', { name: /Abrir WhatsApp/ })).toHaveAttribute(
            'href',
            'https://wa.me/573134459145?text=VINCULAR%20048213',
        );
        expect(screen.getByText(/Vence en/)).toHaveTextContent(/[89]:\d\d|10:00/);
    });

    it('shows the 422 message on the phone field', async () => {
        mockPost.mockRejectedValue({
            response: { status: 422, data: { errors: { phone: ['El número debe estar en formato internacional, por ejemplo +573001234567.'] } } },
        });

        render(<WhatsAppLink whatsapp={UNLINKED} agentNumber="+573134459145" />);
        fillAndSubmit('12');

        await waitFor(() => expect(screen.getByText(/formato internacional/)).toBeInTheDocument());
        expect(screen.getByLabelText('Tu número de WhatsApp')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.queryByText('Tu código')).not.toBeInTheDocument();
    });

    it('offers a new code once the code expired', async () => {
        mockPost.mockResolvedValue({ data: { success: true, code: '111111', expires_at: new Date(Date.now() - 1000).toISOString() }, headers: {} });

        render(<WhatsAppLink whatsapp={UNLINKED} agentNumber="+573134459145" />);
        fillAndSubmit('3001234567');

        await waitFor(() => expect(screen.getByText('El código venció')).toBeInTheDocument());
        expect(screen.queryByRole('link', { name: /Abrir WhatsApp/ })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Generar nuevo código' }));
        await waitFor(() => expect(mockPost).toHaveBeenCalledTimes(2));
        expect(mockPost.mock.calls[1][1]).toEqual({ phone: '+573001234567' });
    });

    it('shows the masked number and unlinks after confirming', async () => {
        mockDelete.mockResolvedValue({ data: { success: true } });

        render(<WhatsAppLink whatsapp={{ linked: true, phoneLast4: '4567', verifiedAt: '2026-10-19T15:00:00-05:00' }} agentNumber="+573134459145" />);

        expect(screen.getByText(/•••• 4567/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Desvincular' }));
        expect(mockDelete).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, desvincular' }));

        await waitFor(() => expect(mockReload).toHaveBeenCalledWith({ only: ['auth'] }));
        expect(mockDelete).toHaveBeenCalledWith('/perfil/whatsapp', expect.any(Object));
    });

    it('keeps the linked state and explains a failed unlink', async () => {
        mockDelete.mockRejectedValue({ response: { status: 500 } });

        render(<WhatsAppLink whatsapp={{ linked: true, phoneLast4: '4567', verifiedAt: null }} agentNumber="" />);
        fireEvent.click(screen.getByRole('button', { name: 'Desvincular' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, desvincular' }));

        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No se pudo completar la acción'));
        expect(mockReload).not.toHaveBeenCalled();
    });
});
