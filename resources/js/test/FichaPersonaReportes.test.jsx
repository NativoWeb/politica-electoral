import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

const page = { props: {} };
vi.mock('@inertiajs/react', () => ({ usePage: () => page, router: { reload: vi.fn() } }));
vi.mock('@/lib/offlineDb', () => ({
    getOfflinePersona: vi.fn().mockResolvedValue(null), offlineUpdatePersona: vi.fn(), offlineCreateNexo: vi.fn(), offlineDeleteNexo: vi.fn(),
}));

import FichaPersona from '@/Components/Reportes/FichaPersona';

const json = (body) => Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));

describe('Ficha opened from Reportes (Mapa)', () => {
    const original = globalThis.fetch;
    beforeEach(() => {
        page.props = { auth: { user: { campaign: { id: 'cd', name: 'CD' }, canWrite: true } }, visibleCampaigns: [] };
        Element.prototype.scrollIntoView = vi.fn();
        globalThis.fetch = vi.fn((url) => (String(url).includes('posibles-duplicados') ? json({ data: [] })
            : json({ id: 'l1', source: 'lider', campaign_id: 'cd', nombre: 'Beto Pérez', municipio: 'Girón', nexos: [], contactos: [], info_politica: [], referidos: [], municipios_influencia: [], partidos: [] })));
    });
    afterEach(() => { cleanup(); globalThis.fetch = original; });

    it('reads the ficha once when it opens', async () => {
        render(<FichaPersona personId="l1" puedeMapa onClose={vi.fn()} onSaved={vi.fn()} />);
        expect(await screen.findByText('Beto Pérez')).toBeInTheDocument();
        await new Promise(r => setTimeout(r, 50));
        const lecturas = globalThis.fetch.mock.calls.filter(([url, o]) => /\/mapa-politico\/persona\/l1$/.test(String(url)) && (o?.method ?? 'GET') === 'GET');
        expect(lecturas).toHaveLength(1);
    });
});
