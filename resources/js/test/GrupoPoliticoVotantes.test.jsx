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
    const TRES = { options: [...GRUPOS.options, { id: 'ot', name: 'Otro Grupo' }], selected: null };
    const boton = () => screen.getByRole('button', { name: /^Grupo político:/ });
    const panel = () => screen.queryByRole('dialog', { name: 'Ver grupos políticos' });
    const casilla = (name) => screen.getByRole('checkbox', { name });
    const asSuper = (vista) => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: vista };
        return render(<AppLayout title="t">x</AppLayout>);
    };
    const enviado = () => JSON.parse(globalThis.fetch.mock.calls.find(([u]) => u === '/grupo-politico/vista')[1].body);

    it('is a selector only for the superadmin; anyone else keeps the informative chip', () => {
        page.props = { auth: { user: user() }, visibleCampaigns: [], grupoVista: null };
        const { unmount } = render(<AppLayout title="t">x</AppLayout>);
        expect(screen.getByTitle('Grupo político: Centro Democrático')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Grupo político:/ })).not.toBeInTheDocument();
        unmount();

        asSuper(GRUPOS);
        expect(boton()).toHaveTextContent('Todos los grupos');
        expect(boton()).toHaveAttribute('aria-expanded', 'false');
        expect(panel()).not.toBeInTheDocument();
        expect(screen.queryByTitle('Grupo político: Centro Democrático')).not.toBeInTheDocument();
    });

    it('shows the name of one group, «N grupos» for several, and reads a single id of an old session', () => {
        const { unmount } = asSuper({ ...TRES, selected: ['ac'] });
        expect(boton()).toHaveTextContent('Alejandro Cárdenas');
        unmount();
        const second = asSuper({ ...TRES, selected: ['ac', 'ot'] });
        expect(boton()).toHaveTextContent('2 grupos');
        expect(boton()).toHaveAccessibleName('Grupo político: Alejandro Cárdenas, Otro Grupo');
        second.unmount();
        asSuper({ ...TRES, selected: 'ot' });
        expect(boton()).toHaveTextContent('Otro Grupo');
    });

    it('opens a panel with big boxes, every one marked under «Todos los grupos»', () => {
        asSuper(TRES);
        fireEvent.click(boton());

        expect(panel()).toBeInTheDocument();
        expect(boton()).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getAllByRole('checkbox').map(c => c.closest('label').textContent)).toEqual(['Todos los grupos', 'Centro Democrático', 'Alejandro Cárdenas', 'Otro Grupo']);
        expect(screen.getAllByRole('checkbox').every(c => c.checked)).toBe(true);
        expect(casilla('Todos los grupos')).toHaveFocus();
        expect(casilla('Alejandro Cárdenas').closest('label').className).toMatch(/min-h-\[48px\].*text-\[16px\]/);
    });

    it('marks two groups and applies them: saves the list, forgets the prefetched pages and reloads', async () => {
        asSuper(TRES);
        globalThis.fetch = vi.fn((u) => okJson(u === '/grupo-politico/vista' ? { success: true, selected: ['ac', 'ot'] } : { data: {} }));
        fireEvent.click(boton());
        fireEvent.click(casilla('Todos los grupos'));
        expect(screen.getAllByRole('checkbox').some(c => c.checked)).toBe(false);
        fireEvent.click(casilla('Otro Grupo'));
        fireEvent.click(casilla('Alejandro Cárdenas'));
        expect(casilla('Todos los grupos').indeterminate).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));

        await waitFor(() => expect(routerMock.reload).toHaveBeenCalled());
        expect(enviado()).toEqual({ campaign_ids: ['ac', 'ot'] });
        expect(globalThis.fetch.mock.calls.find(([u]) => u === '/grupo-politico/vista')[1].method).toBe('POST');
        expect(routerMock.flushAll).toHaveBeenCalled();
        expect(panel()).not.toBeInTheDocument();
    });

    it('«Todos los grupos» marks them all again and sends the empty list', async () => {
        asSuper({ ...TRES, selected: ['ac'] });
        globalThis.fetch = vi.fn((u) => okJson(u === '/grupo-politico/vista' ? { success: true, selected: null } : { data: {} }));
        fireEvent.click(boton());
        expect(casilla('Alejandro Cárdenas')).toBeChecked();
        expect(casilla('Centro Democrático')).not.toBeChecked();

        fireEvent.click(casilla('Todos los grupos'));
        expect(screen.getAllByRole('checkbox').every(c => c.checked)).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));

        await waitFor(() => expect(routerMock.reload).toHaveBeenCalled());
        expect(enviado()).toEqual({ campaign_ids: [] });
    });

    it('with nothing marked Aplicar is off and says what to do', () => {
        asSuper(TRES);
        fireEvent.click(boton());
        fireEvent.click(casilla('Todos los grupos'));

        expect(screen.getByRole('button', { name: 'Aplicar' })).toBeDisabled();
        expect(screen.getByText('Marca al menos un grupo o «Todos los grupos».')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
        expect(globalThis.fetch.mock.calls.some(([u]) => u === '/grupo-politico/vista')).toBe(false);
    });

    it('Cancelar and Escape close without saving, forget the marks and give the focus back', () => {
        asSuper({ ...TRES, selected: ['ac'] });
        fireEvent.click(boton());
        fireEvent.click(casilla('Otro Grupo'));
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
        expect(panel()).not.toBeInTheDocument();
        expect(boton()).toHaveFocus();

        fireEvent.click(boton());
        expect(casilla('Otro Grupo')).not.toBeChecked();
        fireEvent.keyDown(casilla('Centro Democrático'), { key: 'Escape' });
        expect(panel()).not.toBeInTheDocument();
        expect(boton()).toHaveFocus();
        expect(globalThis.fetch.mock.calls.some(([u]) => u === '/grupo-politico/vista')).toBe(false);
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it('applying the same groups only closes the panel', () => {
        asSuper({ ...TRES, selected: ['ac'] });
        fireEvent.click(boton());
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
        expect(panel()).not.toBeInTheDocument();
        expect(globalThis.fetch.mock.calls.some(([u]) => u === '/grupo-politico/vista')).toBe(false);
    });

    it('says why when the server refuses, and does not reload', async () => {
        asSuper(TRES);
        globalThis.fetch = vi.fn((u) => (u === '/grupo-politico/vista'
            ? okJson({ success: false, message: 'No puedes ver ese grupo político.' }, 422)
            : okJson({ data: {} })));
        fireEvent.click(boton());
        fireEvent.click(casilla('Centro Democrático'));
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));

        await waitFor(() => expect(toastMock).toHaveBeenCalledWith('No puedes ver ese grupo político.', 'error'));
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Aplicar' })).not.toBeDisabled();
        expect(boton()).toHaveTextContent('Todos los grupos');
    });

    it('without connection it says so and keeps the panel', async () => {
        asSuper(TRES);
        globalThis.fetch = vi.fn((u) => (u === '/grupo-politico/vista' ? Promise.reject(new TypeError('offline')) : okJson({ data: {} })));
        fireEvent.click(boton());
        fireEvent.click(casilla('Centro Democrático'));
        fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));

        await waitFor(() => expect(toastMock).toHaveBeenCalledWith('Sin conexión: no se pudo cambiar el grupo político.', 'error'));
        expect(panel()).toBeInTheDocument();
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it('keeps the group names when the choice leaves a single visible group', () => {
        const ctx = campaignContext({ campaign: { id: 'cd', name: 'Centro Democrático' } }, [], { ...GRUPOS, selected: ['ac'] });
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
    const BLOQUEO = 'Estás viendo «Alejandro Cárdenas». Para crear, incluye tu grupo o elige «Todos los grupos» arriba.';
    const votantes = { data: [], current_page: 1, last_page: 1, links: [], from: 0, to: 0, total: 0 };
    const counters = { total: 5, gran_elector: 2, con_referidos: 1 };

    it('hides Nuevo votante and says why', () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: { ...GRUPOS, selected: ['ac'], createBlocked: BLOQUEO } };
        render(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={{}} />);
        expect(screen.queryByRole('button', { name: /Nuevo Votante/ })).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(BLOQUEO);
    });

    it('keeps Nuevo votante with the own group or every group, and a big enough selector', () => {
        page.props = { auth: { user: user({ isSuperadmin: true }) }, visibleCampaigns: [], grupoVista: { ...GRUPOS, createBlocked: null } };
        render(<Votantes votantes={votantes} counters={counters} municipios={[]} filters={{}} />);
        expect(screen.getByRole('button', { name: /Nuevo Votante/ })).toBeInTheDocument();
        expect(screen.queryByText(/Para crear, incluye/)).not.toBeInTheDocument();
        const selector = screen.getByRole('button', { name: /^Grupo político:/ });
        expect(selector.className).toMatch(/min-h-\[44px\]/);
        expect(selector.querySelector('.font-semibold').className).toMatch(/text-\[16px\]/);
    });
});
