import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react';

const page = { props: {}, url: '/votantes' };

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

// The filters applied on /votantes (bar, cards, grupo político, estado, evento, Electo marks) live in the URL.
const QUERY = 'search=Ana+Mar%C3%ADa&gran_elector=1&con_referidos=1&campana=g-2&estado=todos&evento=ev-1&por_completar=barrio&diferencias=1&municipio=Gir%C3%B3n';

function exportRequests() {
    return globalThis.fetch.mock.calls.map(([url]) => String(url)).filter((url) => url.startsWith('/exportar/'));
}

function paramsOf(url) {
    return Object.fromEntries(new URLSearchParams(url.split('?')[1]));
}

describe('header "Exportar" on /votantes', () => {
    const original = globalThis.fetch;
    beforeEach(() => {
        window.history.pushState({}, '', `/votantes?${QUERY}`);
        page.url = `/votantes?${QUERY}`;
        page.props = { auth: { user: { name: 'Marly', permissions: ['*'], campaign: { id: 'cd', name: 'CD' }, canEditElectoral: true } }, visibleCampaigns: [] };
        // A refusal keeps the test away from saving a blob; the request is what matters here.
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 403, headers: new Headers({ 'content-type': 'application/json' }), json: () => Promise.resolve({}) }));
    });
    afterEach(() => {
        cleanup();
        globalThis.fetch = original;
        window.history.pushState({}, '', '/');
    });

    it.each(['Excel (.xlsx)', 'PDF (.pdf)'])('desktop %s downloads Votantes with every filter of the screen', async (label) => {
        render(<AppLayout title="t">x</AppLayout>);
        fireEvent.click(screen.getByTitle('Exportar'));
        fireEvent.click(screen.getByText(label));

        await waitFor(() => expect(exportRequests()).toHaveLength(1));
        const [url] = exportRequests();
        expect(url.startsWith(label.startsWith('Excel') ? '/exportar/excel?' : '/exportar/pdf?')).toBe(true);
        expect(paramsOf(url)).toEqual({
            ...paramsOf(`?${QUERY}`),
            source: 'votantes',
            title: 'Votantes',
        });
    });

    it('the mobile "Exportar" sheet sends the same request', async () => {
        render(<AppLayout title="t">x</AppLayout>);
        const tab = screen.getAllByRole('button').find((b) => b.textContent.trim() === 'Exportar' && !b.title);
        expect(tab, 'mobile Exportar tab').toBeTruthy();
        fireEvent.click(tab);
        fireEvent.click(screen.getByText('Archivo .xlsx para abrir en Excel'));

        await waitFor(() => expect(exportRequests()).toHaveLength(1));
        expect(paramsOf(exportRequests()[0])).toEqual({ ...paramsOf(`?${QUERY}`), source: 'votantes', title: 'Votantes' });
    });

    it('is not offered without view.votantes (the server answers 403 too)', () => {
        page.props.auth.user.permissions = ['view.mapa'];
        render(<AppLayout title="t">x</AppLayout>);
        expect(screen.queryByTitle('Exportar')).not.toBeInTheDocument();
    });
});
