import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup, act } from '@testing-library/react';
import EnlaceDescarga from '@/Components/EnlaceDescarga';
import { AVISO_COLUMNAS_QUITADAS } from '@/lib/exportDownload';

// The old downloads of Eventos and Empresarios (now behind DescargaGuard) go through fetch, like the header Exportar.
const xlsx = (headers = {}) => new Response(new Blob(['x']), { status: 200, headers: {
    'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'Content-Disposition': 'attachment; filename="Compromisos.xlsx"', ...headers,
} });
const json = (body, status) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const toast = () => document.getElementById('global-offline-toast')?.firstChild?.textContent;

const original = globalThis.fetch;
beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    globalThis.fetch = original;
    vi.restoreAllMocks();
    document.getElementById('global-offline-toast')?.remove();
});

describe('EnlaceDescarga', () => {
    it('keeps the href but downloads through fetch; X-Columnas-Quitadas shows the notice', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(xlsx({ 'X-Columnas-Quitadas': 'telefono' })));
        render(<EnlaceDescarga href="/eventos/compromisos.xlsx?estado=pendiente" nombre="Compromisos">Descargar Excel</EnlaceDescarga>);
        const enlace = screen.getByRole('link', { name: 'Descargar Excel' });
        expect(enlace).toHaveAttribute('href', '/eventos/compromisos.xlsx?estado=pendiente');

        const evento = new MouseEvent('click', { bubbles: true, cancelable: true });
        act(() => { enlace.dispatchEvent(evento); });
        expect(evento.defaultPrevented).toBe(true);
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/eventos/compromisos.xlsx?estado=pendiente');
        await waitFor(() => expect(toast()).toBe(AVISO_COLUMNAS_QUITADAS));
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('link', { name: 'Descargar Excel' })).not.toHaveAttribute('aria-busy');
    });

    it('a double tap sends one request and says it is preparing', async () => {
        let soltar;
        globalThis.fetch = vi.fn(() => new Promise(r => { soltar = r; }));
        render(<EnlaceDescarga href="/empresarios/exportar.pdf" nombre="Empresarios">PDF</EnlaceDescarga>);
        fireEvent.click(screen.getByRole('link', { name: 'PDF' }));
        fireEvent.click(screen.getByRole('link', { name: 'Preparando…' }));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('link', { name: 'Preparando…' })).toHaveAttribute('aria-busy', 'true');
        await act(async () => { soltar(xlsx({ 'Content-Type': 'application/pdf' })); });
        await waitFor(() => expect(screen.getByRole('link', { name: 'PDF' })).toBeInTheDocument());
    });

    it('403, 429 and a network failure are said in words and save nothing', async () => {
        render(<EnlaceDescarga href="/eventos/ev1/asistentes.xlsx">Descargar Excel</EnlaceDescarga>);
        const enlace = () => screen.getByRole('link', { name: 'Descargar Excel' });

        globalThis.fetch = vi.fn(() => Promise.resolve(json({ success: false, message: 'No.' }, 403)));
        fireEvent.click(enlace());
        await waitFor(() => expect(toast()).toBe('No tienes permiso para descargar estos datos.'));

        globalThis.fetch = vi.fn(() => Promise.resolve(json({ success: false, message: 'Llegaste al límite de 30 descargas por hora.', motivo: 'por_hora' }, 429)));
        await waitFor(() => expect(enlace()).not.toHaveAttribute('aria-busy'));
        fireEvent.click(enlace());
        await waitFor(() => expect(toast()).toBe('Llegaste al límite de 30 descargas por hora.'));

        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        await waitFor(() => expect(enlace()).not.toHaveAttribute('aria-busy'));
        fireEvent.click(enlace());
        await waitFor(() => expect(toast()).toBe('No se pudo descargar. Revisa tu conexión e intenta de nuevo.'));
        expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled();
    });
});
