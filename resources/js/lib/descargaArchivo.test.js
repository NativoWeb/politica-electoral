import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { esAppInstalada, guardarOAbrir } from './descargaArchivo';
import { guardarBlob } from './reportes';
import { isStandalone } from '@/Components/Eventos/PlanillaLink';

describe('descargaArchivo: the one way to save a download', () => {
    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        vi.useFakeTimers();
    });
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('saves through a download link and frees the blob after a minute (iOS reads it late)', () => {
        expect(guardarOAbrir(new Blob(['x']), 'Reporte.xlsx', '/x')).toBe('guardado');
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(59000);
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1000);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x');
    });

    it('a GET opens its URL when the blob cannot be saved; without a URL (Reportes POST) the error goes up', () => {
        URL.createObjectURL = vi.fn(() => { throw new Error('blob not supported'); });
        const navegar = vi.fn();
        expect(guardarOAbrir(new Blob(['x']), 'a.xlsx', '/eventos/territorio/excel', navegar)).toBe('navegador');
        expect(navegar).toHaveBeenCalledWith('/eventos/territorio/excel');
        expect(() => guardarOAbrir(new Blob(['x']), 'a.xlsx', null, navegar)).toThrow('blob not supported');
        expect(navegar).toHaveBeenCalledTimes(1);
    });

    it('Reportes, the Planilla and the downloads share the same functions', () => {
        expect(isStandalone).toBe(esAppInstalada);
        guardarBlob(new Blob(['x']), 'Reporte.pdf');
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
    });
});
