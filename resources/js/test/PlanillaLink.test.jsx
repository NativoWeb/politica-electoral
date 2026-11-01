import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';
import PlanillaLink, { PLANILLA_THROTTLED, isStandalone, planillaErrorMessage } from '@/Components/Eventos/PlanillaLink';

function pdfResponse() {
    return new Response(new Blob(['%PDF-1.7']), {
        status: 200,
        headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="planilla-giron.pdf"' },
    });
}

describe('Planilla para imprimir', () => {
    let clicked;

    beforeEach(() => {
        clicked = [];
        globalThis.fetch = vi.fn();
        URL.createObjectURL = vi.fn(() => 'blob:planilla');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function click() { clicked.push({ href: this.href, download: this.download }); });
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        delete window.matchMedia;
        delete window.navigator.standalone;
    });

    function installApp() {
        window.matchMedia = vi.fn(query => ({ matches: query === '(display-mode: standalone)' }));
    }

    it('keeps a normal link (new tab, no JavaScript) to the same URL', () => {
        render(<PlanillaLink eventId="ev1" />);
        const link = screen.getByRole('link', { name: 'Planilla para imprimir' });
        expect(link).toHaveAttribute('href', '/eventos/ev1/planilla.pdf');
        expect(link).toHaveAttribute('download');
    });

    it('downloads the PDF with the server file name', async () => {
        globalThis.fetch.mockResolvedValueOnce(pdfResponse());
        render(<PlanillaLink eventId="ev1" />);

        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));

        await waitFor(() => expect(clicked).toHaveLength(1));
        expect(globalThis.fetch).toHaveBeenCalledWith('/eventos/ev1/planilla.pdf', expect.objectContaining({ credentials: 'same-origin', redirect: 'manual' }));
        expect(clicked[0]).toMatchObject({ href: 'blob:planilla', download: 'planilla-giron.pdf' });
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('says clearly in Spanish when there were too many downloads (429) instead of failing silently', async () => {
        globalThis.fetch.mockResolvedValueOnce(new Response('Too Many Attempts.', { status: 429, headers: { 'Retry-After': '60' } }));
        render(<PlanillaLink eventId="ev1" />);

        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Ha descargado muchas planillas seguidas. Espere un minuto e intente de nuevo.');
        expect(clicked).toHaveLength(0);
        expect(screen.getByRole('link', { name: 'Planilla para imprimir' })).toBeInTheDocument();
    });

    it('explains no signal, a redirect or an HTML answer instead of saving a wrong file', async () => {
        globalThis.fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        render(<PlanillaLink eventId="ev1" />);
        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Sin conexión');

        globalThis.fetch.mockResolvedValueOnce({ ok: false, status: 0, type: 'opaqueredirect', headers: new Headers() });
        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No se pudo descargar la planilla'));

        globalThis.fetch.mockResolvedValueOnce(new Response('<html></html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));
        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));
        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledTimes(3));
        expect(clicked).toHaveLength(0);
    });

    it('words every refusal', () => {
        expect(planillaErrorMessage(429)).toBe(PLANILLA_THROTTLED);
        expect(planillaErrorMessage(403)).toMatch('permiso');
        expect(planillaErrorMessage(419)).toMatch('sesión');
    });

    it('detects the installed app (display-mode standalone or iOS navigator.standalone)', () => {
        expect(isStandalone()).toBe(false);
        installApp();
        expect(isStandalone()).toBe(true);
        delete window.matchMedia;
        Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: true });
        expect(isStandalone()).toBe(true);
    });

    it('in the installed app checks with HEAD and then lets the browser open the URL (no blob)', async () => {
        installApp();
        const navigate = vi.fn();
        globalThis.fetch.mockResolvedValueOnce(new Response(null, { status: 200, headers: { 'Content-Type': 'application/pdf' } }));
        render(<PlanillaLink eventId="ev1" navigate={navigate} />);

        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));

        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/eventos/ev1/planilla.pdf'));
        expect(globalThis.fetch.mock.calls[0][1]).toMatchObject({ method: 'HEAD', redirect: 'manual' });
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        expect(clicked).toHaveLength(0);
    });

    it('in the installed app a 429 on the check shows the notice and does not navigate', async () => {
        installApp();
        const navigate = vi.fn();
        globalThis.fetch.mockResolvedValueOnce(new Response(null, { status: 429 }));
        render(<PlanillaLink eventId="ev1" navigate={navigate} />);

        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(PLANILLA_THROTTLED);
        expect(navigate).not.toHaveBeenCalled();
    });

    it('if saving the blob fails, the browser downloads the URL itself', async () => {
        const navigate = vi.fn();
        URL.createObjectURL = vi.fn(() => { throw new Error('blob not supported'); });
        globalThis.fetch.mockResolvedValueOnce(pdfResponse());
        render(<PlanillaLink eventId="ev1" navigate={navigate} />);

        fireEvent.click(screen.getByRole('link', { name: 'Planilla para imprimir' }));

        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/eventos/ev1/planilla.pdf'));
        expect(screen.queryByRole('alert')).toBeNull();
    });
});
