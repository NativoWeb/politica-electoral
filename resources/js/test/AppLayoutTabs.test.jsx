import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

const page = { props: {}, url: '/empresarios' };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}) },
    Link: ({ href, children, className }) => <a href={href} className={className}>{children}</a>,
    useForm: () => ({ data: {}, setData: () => {}, post: () => {}, processing: false, errors: {} }),
}));
vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null, default: () => null }));
vi.mock('@/Components/Eventos/CompromisosCampana', () => ({ default: () => null, resetCampanaCache: () => {} }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));

import AppLayout from '@/Layouts/AppLayout';

const ALL = ['view.gobernador', 'view.mapa', 'view.votantes', 'view.eventos', 'view.empresarios', 'view.admin'];

describe('AppLayout mobile tab bar', () => {
    const original = globalThis.fetch;
    beforeEach(() => {
        page.url = '/empresarios';
        page.props = { auth: { user: { name: 'Ana', permissions: ALL, campaign: { id: 'cd', name: 'CD' }, canEditElectoral: true } }, visibleCampaigns: [] };
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: {} }) }));
    });
    afterEach(() => { cleanup(); globalThis.fetch = original; });

    it('uses the short "Empresas" label and lets every tab shrink and truncate', () => {
        render(<AppLayout title="t">x</AppLayout>);
        const tab = [...document.querySelectorAll('a')].find(a => a.getAttribute('href') === '/empresarios' && a.textContent.trim() === 'Empresas');
        expect(tab, 'mobile tab with the short label').toBeTruthy();
        expect(tab.className).toContain('min-w-0');
        expect(tab.querySelector('span.truncate')).toBeTruthy();
        // The desktop sidebar keeps the full name.
        expect(screen.getAllByText('Empresarios').length).toBeGreaterThan(0);
    });
});
