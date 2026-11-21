import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { AVISO_COLUMNAS_QUITADAS, descargarExportacion } from './exportDownload';

const xlsx = (headers = {}) => new Response(new Blob(['x']), { status: 200, headers: {
    'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'Content-Disposition': 'attachment; filename="Votantes.xlsx"', ...headers,
} });
const toast = () => document.getElementById('global-offline-toast')?.firstChild?.textContent;

describe('descargarExportacion (header Exportar)', () => {
    const original = globalThis.fetch;
    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    });
    afterEach(() => { globalThis.fetch = original; vi.restoreAllMocks(); document.getElementById('global-offline-toast')?.remove(); });

    it('BUG-11: tells when the file left the personal columns out', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(xlsx({ 'X-Columnas-Quitadas': 'cedula,telefono' })));
        const r = await descargarExportacion('/exportar/excel?source=votantes');
        expect(r).toEqual({ estado: 'ok', quitadas: true });
        expect(toast()).toBe(AVISO_COLUMNAS_QUITADAS);
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
    });

    it('says nothing about columns when none were taken out', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(xlsx()));
        expect(await descargarExportacion('/exportar/excel')).toEqual({ estado: 'ok', quitadas: false });
        expect(toast()).toBe('Listo. Se descargó tu archivo.');
    });

    it('a refusal is said in words and nothing is saved', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ success: false, message: 'x' }), { status: 403, headers: { 'Content-Type': 'application/json' } })));
        expect(await descargarExportacion('/exportar/pdf')).toEqual({ estado: 'error', status: 403 });
        expect(toast()).toBe('No tienes permiso para descargar estos datos.');
        expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled();
    });
});
