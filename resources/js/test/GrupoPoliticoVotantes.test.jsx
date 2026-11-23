// Header selector of grupo político (superadmin), Votantes KPI cards as filters and the foldable side menu.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react';

const page = { props: {}, url: '/votantes' };
const routerMock = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn(), visit: vi.fn(), flushAll: vi.fn(), on: vi.fn(() => () => {}) }));
const toastMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: routerMock,
    Head: () => null,
    Link: ({ href, children, className, title, 'aria-label': ariaLabel }) => <a href={href} className={className} title={title} aria-label={ariaLabel}>{children}</a>,
    useForm: () => ({ data: {}, setData: () => {}, post: () => {}, processing: false, errors: {}, reset: () => {} }),
}));
vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null, default: () => null }));
vi.mock('@/Components/Eventos/CompromisosCampana', () => ({ default: () => null, resetCampanaCache: () => {} }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('@/lib/api', async (importOriginal) => ({ ...(await importOriginal()), showGlobalToast: toastMock }));

import AppLayout from '@/Layouts/AppLayout';
import Votantes from '@/Pages/Votantes';
import { campaignContext } from '@/lib/campaign';
import { withCardFilters } from '@/lib/votantesFiltros';

const GRUPOS = { options: [{ id: 'cd', name: 'Centro Democrático' }, { id: 'ac', name: 'Alejandro Cárdenas' }], selected: null };
const user = (extra = {}) => ({ name: 'Ana', permissions: ['*'], campaign: { id: 'cd', name: 'Centro Democrático' }, canEditElectoral: true, canWrite: true, ...extra });
const okJson = (body, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

const originalFetch = globalThis.fetch;
beforeEach(() => {
    vi.clearAllMocks();
    page.url = '/votantes';
    globalThis.fetch = vi.fn(() => okJson({ data: {} }));
    try { window.localStorage.clear(); } catch { /* none */ }
});
afterEach(() => { cleanup(); globalThis.fetch = originalFetch; vi.restoreAllMocks(); });

describe('Header selector of grupo político', () => {
    it('is a selector only for the superadmin; anyone else keeps the informative chip', () => {
        page.props = { auth: { user: user() }, visibleCampaigns: [], grupoVista: null };
        const { unmount } = render(<AppLayout title="t">x</AppLayout>);
        expect(screen.getByTitle('Grupo político: Centro Democrático')).toBeInTheDocument();
        expect(screen.queryByLabelText('Grupo político:')).not.toBeInTheDocument();
        unmount();

        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: GRUPOS };
        render(<AppLayout title="t">x</AppLayout>);
        const select = screen.getByLabelText('Grupo político:');
        expect(select).toHaveValue('');
        expect([...select.options].map(o => o.textContent)).toEqual(['Todos los grupos', 'Centro Democrático', 'Alejandro Cárdenas']);
        expect(screen.queryByTitle('Grupo político: Centro Democrático')).not.toBeInTheDocument();
    });

    it('saves the choice, forgets the prefetched pages and reloads the current page', async () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: GRUPOS };
        globalThis.fetch = vi.fn(() => okJson({ success: true, selected: 'ac' }));
        render(<AppLayout title="t">x</AppLayout>);

        fireEvent.change(screen.getByLabelText('Grupo político:'), { target: { value: 'ac' } });

        await waitFor(() => expect(routerMock.reload).toHaveBeenCalled());
        const [url, options] = globalThis.fetch.mock.calls.find(([u]) => u === '/grupo-politico/vista');
        expect(url).toBe('/grupo-politico/vista');
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body)).toEqual({ campaign_id: 'ac' });
        expect(routerMock.flushAll).toHaveBeenCalled();

        globalThis.fetch.mockClear();
        fireEvent.change(screen.getByLabelText('Grupo político:'), { target: { value: '' } });
        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ campaign_id: null });
    });

    it('says why when the server refuses, and does not reload', async () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: GRUPOS };
        globalThis.fetch = vi.fn((u) => (u === '/grupo-politico/vista'
            ? okJson({ success: false, message: 'No puedes ver ese grupo político.' }, 422)
            : okJson({ data: {} })));
        render(<AppLayout title="t">x</AppLayout>);

        fireEvent.change(screen.getByLabelText('Grupo político:'), { target: { value: 'ac' } });

        await waitFor(() => expect(toastMock).toHaveBeenCalledWith('No puedes ver ese grupo político.', 'error'));
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Grupo político:')).not.toBeDisabled();
        // Controlled by the server's choice: the select goes back to it.
        expect(screen.getByLabelText('Grupo político:')).toHaveValue('');
    });

    it('keeps the group names when the choice leaves a single visible group', () => {
        const ctx = campaignContext({ campaign: { id: 'cd', name: 'Centro Democrático' } }, [], { ...GRUPOS, selected: 'ac' });
        expect(ctx.hasMultiple).toBe(false);
        expect(ctx.nameOf('ac')).toBe('Alejandro Cárdenas');
        expect(ctx.isOther('ac')).toBe(true);
    });
});

describe('Side menu folds on desktop', () => {
    beforeEach(() => {
        page.props = { auth: { user: user() }, visibleCampaigns: [], grupoVista: null };
    });

    it('folds to the icons with a tooltip and is remembered after a reload', () => {
        const { unmount } = render(<AppLayout title="t">x</AppLayout>);
        const aside = screen.getByRole('complementary', { name: 'Menú principal' });
        expect(aside.className).toContain('w-[200px]');

        fireEvent.click(screen.getByRole('button', { name: 'Ocultar el menú' }));
        expect(aside.className).toContain('w-[64px]');
        const mapa = [...aside.querySelectorAll('a')].find(a => a.getAttribute('href') === '/mapa-politico');
        expect(mapa.getAttribute('title')).toBeTruthy();
        expect(mapa.getAttribute('aria-label')).toBe(mapa.getAttribute('title'));
        expect(window.localStorage.getItem('sidebar-plegado')).toBe('1');
        unmount();

        render(<AppLayout title="t">x</AppLayout>);
        expect(screen.getByRole('complementary', { name: 'Menú principal' }).className).toContain('w-[64px]');
        fireEvent.click(screen.getByRole('button', { name: 'Mostrar el menú' }));
        expect(screen.getByRole('complementary', { name: 'Menú principal' }).className).toContain('w-[200px]');
        expect(window.localStorage.getItem('sidebar-plegado')).toBe('0');
    });

    it('still folds when the browser blocks the storage', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
        render(<AppLayout title="t">x</AppLayout>);
        fireEvent.click(screen.getByRole('button', { name: 'Ocultar el menú' }));
        expect(screen.getByRole('complementary', { name: 'Menú principal' }).className).toContain('w-[64px]');
    });

    it('leaves the mobile tab bar as it was', () => {
        render(<AppLayout title="t">x</AppLayout>);
        fireEvent.click(screen.getByRole('button', { name: 'Ocultar el menú' }));
        const tabBar = document.querySelector('nav.lg\\:hidden.fixed.bottom-0');
        expect(tabBar).toBeTruthy();
        expect(tabBar.textContent).toContain('Mapa');
    });
});

describe('Votantes KPI cards filter the table', () => {
    const votantes = { data: [], current_page: 1, last_page: 1, links: [], from: 0, to: 0, total: 0 };
    const counters = { total: 120, gran_elector: 7, con_referidos: 15 };
    const renderPage = (filters) => {
        page.props = { auth: { user: user() }, visibleCampaigns: [], grupoVista: null };
        return render(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={filters} />);
    };
    const card = (name) => screen.getByRole('button', { name: new RegExp(name, 'i') });

    it('Total is pressed without card filters; Gran elector adds its filter keeping the others', () => {
        renderPage({ municipio: 'Girón', estado: 'activos' });
        expect(card('Total Votantes')).toHaveAttribute('aria-pressed', 'true');
        expect(card('Gran Elector')).toHaveAttribute('aria-pressed', 'false');
        expect(card('Gran Elector').textContent).toContain('7');

        fireEvent.click(card('Gran Elector'));
        expect(routerMock.get).toHaveBeenLastCalledWith('/votantes', { municipio: 'Girón', estado: 'activos', gran_elector: '1' }, expect.objectContaining({ preserveState: true }));

        fireEvent.click(card('Con Referidos'));
        expect(routerMock.get).toHaveBeenLastCalledWith('/votantes', { municipio: 'Girón', estado: 'activos', con_referidos: '1' }, expect.anything());
    });

    it('a pressed card unpresses itself, and Total removes both card filters', () => {
        renderPage({ gran_elector: '1', con_referidos: '1', partido: 'CD' });
        expect(card('Gran Elector')).toHaveAttribute('aria-pressed', 'true');
        expect(card('Con Referidos')).toHaveAttribute('aria-pressed', 'true');
        expect(card('Total Votantes')).toHaveAttribute('aria-pressed', 'false');
        expect(card('Con Referidos').className).toContain('border-[var(--color-primary)]');

        fireEvent.click(card('Gran Elector'));
        expect(routerMock.get).toHaveBeenLastCalledWith('/votantes', { con_referidos: '1', partido: 'CD' }, expect.anything());

        fireEvent.click(card('Total Votantes'));
        expect(routerMock.get).toHaveBeenLastCalledWith('/votantes', { partido: 'CD' }, expect.anything());
    });

    it('the bar checkboxes follow the cards', () => {
        const { rerender } = renderPage({});
        expect(screen.getByLabelText('Con referidos')).not.toBeChecked();
        rerender(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={{ con_referidos: '1', gran_elector: '1' }} />);
        expect(screen.getByLabelText('Con referidos')).toBeChecked();
        expect(screen.getByLabelText('Gran Elector')).toBeChecked();
    });

    it('builds the URL params without empty values', () => {
        expect(withCardFilters({ a: '1', gran_elector: '1', b: '' }, { gran_elector: null, con_referidos: '1' })).toEqual({ a: '1', con_referidos: '1' });
    });
});

describe('Creating while the header shows another group', () => {
    const BLOQUEO = 'Estás viendo «Alejandro Cárdenas». Para crear, elige tu grupo o «Todos los grupos» arriba.';
    const votantes = { data: [], current_page: 1, last_page: 1, links: [], from: 0, to: 0, total: 0 };
    const counters = { total: 5, gran_elector: 2, con_referidos: 1 };

    it('hides Nuevo votante and says why', () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: { ...GRUPOS, selected: 'ac', createBlocked: BLOQUEO } };
        render(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={{}} />);
        expect(screen.queryByRole('button', { name: /Nuevo Votante/ })).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(BLOQUEO);
    });

    it('keeps Nuevo votante with the own group or every group, and a big enough selector', () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: { ...GRUPOS, createBlocked: null } };
        render(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={{}} />);
        expect(screen.getByRole('button', { name: /Nuevo Votante/ })).toBeInTheDocument();
        expect(screen.queryByText(/Para crear, elige/)).not.toBeInTheDocument();
        expect(screen.getByLabelText('Grupo político:').className).toMatch(/text-\[16px\].*min-h-\[44px\]|min-h-\[44px\].*text-\[16px\]/);
    });
});
