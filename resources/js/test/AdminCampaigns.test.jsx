// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within, cleanup } from '@testing-library/react';

const mockRouterPost = vi.fn();
const mockPage = { props: {}, url: '/admin/campanas' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    function useForm(initial) {
        const [data, setDataState] = useState(initial);
        const setData = (key, value) => setDataState(prev => (
            typeof key === 'function' ? key(prev) : typeof key === 'object' ? key : { ...prev, [key]: value }
        ));
        return { data, setData, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() };
    }

    return {
        Head: () => null,
        Link: ({ children, href, prefetch: _prefetch, ...rest }) => <a href={href} {...rest}>{children}</a>,
        usePage: () => mockPage,
        useForm,
        router: { post: (...args) => mockRouterPost(...args), on: () => () => {}, get: vi.fn(), reload: vi.fn() },
    };
});

import Campaigns from '@/Pages/Admin/Campaigns';
import AdminIndex from '@/Pages/Admin/Index';

const SUPERADMIN = { id: 'u1', name: 'Marly', isSuperadmin: true, permissions: ['*'], canEditElectoral: true };

function campaign(overrides) {
    return {
        parentId: null, parentName: null, whatsappEnabled: false, isActive: true, isDefault: false,
        candidateId: null, candidateName: null, usersCount: 0, lideresCount: 0, ...overrides,
    };
}

const CAMPAIGNS = [
    campaign({ id: 'ind', name: 'Independiente Prueba', slug: 'independiente-prueba' }),
    campaign({ id: 'sub-b', name: 'Sub Beta', slug: 'sub-beta', parentId: 'cd', isActive: false, usersCount: 1 }),
    campaign({ id: 'cd', name: 'Centro Democrático', slug: 'centro-democratico', isDefault: true, whatsappEnabled: true, usersCount: 1234, lideresCount: 931 }),
    campaign({ id: 'sub-a', name: 'Sub Alfa', slug: 'sub-alfa', parentId: 'cd', candidateId: 'p1', candidateName: 'Alejandro Cárdenas', usersCount: 3 }),
];

function rowOf(name) {
    return screen.getByText(name).closest('li');
}

function renderPage() {
    return render(<Campaigns campaigns={CAMPAIGNS} defaultId="cd" />);
}

describe('Admin Campaigns page', () => {
    beforeEach(() => {
        mockRouterPost.mockReset();
        mockPage.url = '/admin/campanas';
        mockPage.props = { auth: { user: SUPERADMIN } };
    });

    afterEach(cleanup);

    it('lists the CD first with its subcampaigns nested by name, then the independent campaign', () => {
        renderPage();
        const list = screen.getByRole('list', { name: 'Campañas y subcampañas' });
        const roots = [...list.children].map(li => li.querySelector('p').textContent);
        expect(roots).toEqual(['Centro Democrático', 'Independiente Prueba']);

        const cd = rowOf('Centro Democrático');
        const nested = within(cd).getAllByRole('listitem').map(li => li.querySelector('p').textContent);
        expect(nested).toEqual(['Sub Alfa', 'Sub Beta']);
    });

    it('shows the data line, the candidate and the inactive tag, but not the slug', () => {
        renderPage();
        expect(screen.getByText('1.234 usuarios · 931 líderes · WhatsApp sí')).toBeInTheDocument();
        expect(rowOf('Sub Alfa')).toHaveTextContent('Candidato: Alejandro Cárdenas');
        expect(within(rowOf('Sub Beta')).getByText('Inactiva')).toBeInTheDocument();
        expect(screen.getAllByText('Inactiva')).toHaveLength(1);
        expect(screen.queryByText('centro-democratico')).not.toBeInTheDocument();
        expect(screen.getByText('4 campañas registradas')).toBeInTheDocument();
    });

    it('has no on/off button for the CD and asks before deactivating', () => {
        renderPage();
        expect(screen.queryByRole('button', { name: 'Desactivar Centro Democrático' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Desactivar Sub Alfa' }));
        const dialog = screen.getByRole('alertdialog');
        expect(dialog).toHaveTextContent('¿Desactivar la campaña Sub Alfa?');
        expect(dialog).toHaveTextContent('Sus 3 usuarios no podrán entrar');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
        expect(mockRouterPost).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Activar Sub Beta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, activar' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/admin/campanas/sub-b/toggle', {}, expect.any(Object));
    });

    it('links each campaign to its new-user form', () => {
        renderPage();
        expect(screen.getByRole('link', { name: 'Crear usuario para Sub Alfa' })).toHaveAttribute('href', '/admin/usuarios?campana=sub-a');
    });

    it('opens create in a modal with the slug folded under advanced options', () => {
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: '+ Nueva campaña' }));
        const dialog = screen.getByRole('dialog', { name: 'NUEVA CAMPAÑA' });

        expect(within(dialog).queryByLabelText('Identificador (slug)')).not.toBeInTheDocument();
        fireEvent.change(within(dialog).getByLabelText('Nombre *'), { target: { value: 'Campaña Año 2027' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Opciones avanzadas' }));
        expect(within(dialog).getByLabelText('Identificador (slug)')).toHaveValue('campana-ano-2027');

        const options = [...within(dialog).getByLabelText('Campaña madre').options].map(o => o.textContent.replace(/ /g, ' '));
        expect(options).toEqual(['Ninguna (independiente)', 'Centro Democrático', '   └ Sub Alfa', '   └ Sub Beta (inactiva)', 'Independiente Prueba']);
    });

    it('edits in a modal that cannot pick the campaign itself as its parent', () => {
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Editar Sub Alfa' }));
        const dialog = screen.getByRole('dialog', { name: 'EDITAR CAMPAÑA' });

        expect(within(dialog).getByLabelText('Nombre *')).toHaveValue('Sub Alfa');
        const parent = within(dialog).getByLabelText('Campaña madre');
        expect(parent).toHaveValue('cd');
        expect([...parent.options].map(o => o.value)).not.toContain('sub-a');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cerrar' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});

describe('Admin Campaigns page details', () => {
    beforeEach(() => {
        mockRouterPost.mockReset();
        mockPage.url = '/admin/campanas';
        mockPage.props = { auth: { user: SUPERADMIN } };
    });

    afterEach(cleanup);

    it('dims only the avatar and texts of an inactive campaign, not its buttons', () => {
        renderPage();
        expect(screen.getByText('Sub Beta').closest('.opacity-60')).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Activar Sub Beta' }).closest('.opacity-60')).toBeNull();
        expect(screen.getByText('Sub Alfa').closest('.opacity-60')).toBeNull();
    });

    it('narrows the phone indent from the third level down', () => {
        const deep = [campaign({ id: 'cd', name: 'CD', isDefault: true })];
        for (let level = 1; level <= 5; level++) {
            deep.push(campaign({ id: `n${level}`, name: `Nivel ${level}`, parentId: level === 1 ? 'cd' : `n${level - 1}` }));
        }
        render(<Campaigns campaigns={deep} defaultId="cd" />);

        const childList = name => screen.getByText(name).closest('li').querySelector(':scope > ul');
        expect(screen.getByText('Nivel 5').closest('li')).toHaveAttribute('data-depth', '5');
        expect(childList('CD').className).toContain('ml-5');
        expect(childList('Nivel 1').className).toContain('ml-5');
        expect(childList('Nivel 2').className).toContain('ml-1.5');
        expect(childList('Nivel 4').className).toContain('ml-1.5');
        expect(childList('Nivel 4').className).toContain('lg:ml-7');
    });

    it('keeps Tab inside the modal and gives the focus back to the button that opened it', () => {
        renderPage();
        const opener = screen.getByRole('button', { name: 'Editar Sub Alfa' });
        opener.focus();
        fireEvent.click(opener);

        const dialog = screen.getByRole('dialog', { name: 'EDITAR CAMPAÑA' });
        const close = within(dialog).getByRole('button', { name: 'Cerrar' });
        const cancel = within(dialog).getByRole('button', { name: 'Cancelar' });

        cancel.focus();
        fireEvent.keyDown(cancel, { key: 'Tab' });
        expect(document.activeElement).toBe(close);

        fireEvent.keyDown(close, { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(cancel);

        fireEvent.click(close);
        expect(document.activeElement).toBe(opener);
    });

    it('gives the focus back to the on/off button when the confirmation is cancelled', () => {
        renderPage();
        const toggle = screen.getByRole('button', { name: 'Desactivar Sub Alfa' });
        toggle.focus();
        fireEvent.click(toggle);
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancelar' }));
        expect(document.activeElement).toBe(toggle);
    });

    it('closes the edit modal instead of turning it into a create form when the campaign goes away', () => {
        const { rerender } = renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Editar Sub Alfa' }));
        expect(screen.getByRole('dialog', { name: 'EDITAR CAMPAÑA' })).toBeInTheDocument();

        rerender(<Campaigns campaigns={CAMPAIGNS.filter(c => c.id !== 'sub-a')} defaultId="cd" />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});

describe('Reaching Campañas', () => {
    beforeEach(() => {
        mockPage.url = '/admin';
    });

    afterEach(cleanup);

    it('is not in the side menu, not even for a superadmin', async () => {
        mockPage.props = { auth: { user: SUPERADMIN } };
        const { default: AppLayout } = await import('@/Layouts/AppLayout');
        render(<AppLayout title="Admin"><p>contenido</p></AppLayout>);

        const hrefs = screen.getAllByRole('link').map(a => a.getAttribute('href'));
        expect(hrefs).toContain('/admin/territorio');
        expect(hrefs).not.toContain('/admin/campanas');
        expect(screen.queryByText('Campañas')).not.toBeInTheDocument();
    });

    it('is offered on the Admin home only to a superadmin', () => {
        mockPage.props = { auth: { user: SUPERADMIN } };
        const { unmount } = render(<AdminIndex stats={{}} />);
        expect(screen.getByRole('link', { name: /CAMPAÑAS/ })).toHaveAttribute('href', '/admin/campanas');
        unmount();

        mockPage.props = { auth: { user: { ...SUPERADMIN, isSuperadmin: false, permissions: ['view.admin'] } } };
        render(<AdminIndex stats={{}} />);
        expect(screen.queryByRole('link', { name: /CAMPAÑAS/ })).not.toBeInTheDocument();
    });
});
