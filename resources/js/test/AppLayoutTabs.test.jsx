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

describe('AppLayout campaign wording and the "Campañas electorales" entry', () => {
    const original = globalThis.fetch;
    const user = (permissions) => ({ name: 'Ana', permissions, campaign: { id: 'cd', name: 'Centro Democrático' }, canEditElectoral: true });
    const electoralLinks = () => [...document.querySelectorAll('a')].filter(a => a.getAttribute('href') === '/campanas-electorales');

    beforeEach(() => {
        page.url = '/mapa-politico';
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: {} }) }));
    });
    afterEach(() => { cleanup(); globalThis.fetch = original; });

    it('labels the header with the grupo político of the user', () => {
        page.props = { auth: { user: user(ALL) }, visibleCampaigns: [] };
        render(<AppLayout title="t">x</AppLayout>);
        expect(screen.getByTitle('Grupo político: Centro Democrático')).toHaveTextContent('Grupo político:Centro Democrático');
        expect(screen.queryByText(/Campaña:/)).not.toBeInTheDocument();
    });

    it('hides the entry when the features prop does not arrive or the module is off', () => {
        page.props = { auth: { user: user(['*']) }, visibleCampaigns: [] };
        const { unmount } = render(<AppLayout title="t">x</AppLayout>);
        expect(electoralLinks()).toHaveLength(0);
        unmount();

        page.props = { auth: { user: user(['*']) }, visibleCampaigns: [], features: { campanas: false } };
        render(<AppLayout title="t">x</AppLayout>);
        expect(electoralLinks()).toHaveLength(0);
    });

    it('shows the entry in the sidebar only with the module on and view.campanas or manage.campanas', () => {
        page.props = { auth: { user: user(ALL) }, visibleCampaigns: [], features: { campanas: true } };
        const { unmount } = render(<AppLayout title="t">x</AppLayout>);
        expect(electoralLinks()).toHaveLength(0);
        unmount();

        for (const perm of ['view.campanas', 'manage.campanas']) {
            page.props = { auth: { user: user([...ALL, perm]) }, visibleCampaigns: [], features: { campanas: true } };
            const view = render(<AppLayout title="t">x</AppLayout>);
            const links = electoralLinks();
            expect(links, perm).toHaveLength(1);
            expect(links[0]).toHaveTextContent('Campañas electorales');
            view.unmount();
        }
    });
});
