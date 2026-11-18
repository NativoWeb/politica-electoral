import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useAllMunicipios, resetAllMunicipiosCache } from './useAllMunicipios';

const PAGE = [{ id: 'buc', name: 'Bucaramanga', provincia: 'Soto' }];
const TODOS = [...PAGE, { id: 'med', name: 'Medellín', provincia: 'Antioquia', santander: false }];

describe('useAllMunicipios', () => {
    const originalFetch = globalThis.fetch;

    beforeEach(() => { resetAllMunicipiosCache(); globalThis.fetch = vi.fn(); });
    afterEach(() => { globalThis.fetch = originalFetch; });

    it('usa la lista de la página y luego la de todo el país', async () => {
        globalThis.fetch.mockResolvedValue(new Response(JSON.stringify({ success: true, data: TODOS }), { status: 200 }));
        const { result } = renderHook(() => useAllMunicipios(PAGE));

        expect(result.current).toBe(PAGE);
        await waitFor(() => expect(result.current).toEqual(TODOS));
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/api/geo/municipios-todos');
    });

    it('sin conexión se queda con la lista de la página', async () => {
        globalThis.fetch.mockRejectedValue(new Error('offline'));
        const { result } = renderHook(() => useAllMunicipios(PAGE));

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());
        expect(result.current).toBe(PAGE);
    });

    it('no pide nada mientras no se necesita', () => {
        renderHook(() => useAllMunicipios(PAGE, false));
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});
