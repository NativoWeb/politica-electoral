// Lives outside Pages/: app.jsx bundles every Pages/**/*.jsx file into the app.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

const mockSubmit = vi.fn();
const mockRouterPost = vi.fn();
const mockRouterDelete = vi.fn();
const mockPage = { props: {}, url: '/admin/usuarios' };

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    function useForm(initial) {
        const [data, setDataState] = useState(initial);
        const [errors, setErrors] = useState({});
        const setData = (key, value) => setDataState(prev => (typeof key === 'object' ? key : { ...prev, [key]: value }));
        const submit = method => (url, options = {}) => {
            setErrors({});
            const outcome = mockSubmit(method, url, data, options) || {};
            if (outcome.errors) setErrors(outcome.errors);
            if (outcome.httpException) outcome.handled = options.onHttpException?.(outcome.httpException);
            if (outcome.success) options.onSuccess?.();
        };
        return { data, setData, errors, processing: false, post: submit('post'), put: submit('put'), reset: () => setDataState(initial) };
    }

    return {
        Head: () => null,
        Link: ({ children, href }) => <a href={href}>{children}</a>,
        usePage: () => mockPage,
        useForm,
        router: {
            get: vi.fn(),
            post: (...args) => mockRouterPost(...args),
            delete: (...args) => mockRouterDelete(...args),
        },
    };
});

import Users from '@/Pages/Admin/Users';

const ME = { id: 'u-me', name: 'Admin Alejandro', email: 'admin@x.test', roleCode: 'R02_ADMIN_FUNCIONAL', permissions: ['view.mapa', 'write.data', 'manage.users'], canEditElectoral: false, campaign: { id: 'c-x', name: 'Alejandro' } };

const GRANTABLE = [
    { key: 'view.mapa', label: 'Ver Mapa Político', group: 'Vistas' },
    { key: 'write.data', label: 'Editar datos (líderes, personas)', group: 'Acciones' },
    { key: 'manage.users', label: 'Gestionar usuarios', group: 'Administración' },
];

const LABELS = {
    'view.mapa': 'Ver Mapa Político', 'write.data': 'Editar datos (líderes, personas)', 'manage.users': 'Gestionar usuarios',
    'manage.territory': 'Gestionar territorio', 'manage.partidos': 'Gestionar partidos',
};

const ROLES = [{ id: 'r-op', name: 'Operador', code: 'R09_CAMPO' }, { id: 'r-adm', name: 'Administrador de campaña', code: 'R02_ADMIN_FUNCIONAL' }];

function userRow(overrides) {
    return { role: 'Usuario de campo', roleCode: 'R09_CAMPO', isActive: true, permissions: ['view.mapa'], territoryScope: [], campaignId: 'c-x', ...overrides };
}

const OPERADOR = userRow({ id: 'u-op', name: 'Pedro Operador', email: 'pedro@x.test', permissions: ['view.mapa', 'write.data'] });
const STRONGER = userRow({ id: 'u-strong', name: 'Luisa Partidos', email: 'luisa@x.test', permissions: ['view.mapa', 'manage.partidos'] });
const SELF = userRow({ id: ME.id, name: ME.name, email: ME.email, role: 'Administrador de campaña', roleCode: 'R02_ADMIN_FUNCIONAL' });

function renderUsers(list = [SELF, OPERADOR, STRONGER]) {
    return render(
        <Users
            users={{ data: list }}
            roles={ROLES}
            municipios={[]}
            provincias={[]}
            availablePermissions={GRANTABLE}
            permissionLabels={LABELS}
            filters={{}}
        />,
    );
}

function rowOf(name) {
    return screen.getByText(name).closest('.bg-white');
}

function editRow(name) {
    fireEvent.click(within(rowOf(name)).getByTitle('Editar'));
}

describe('Admin Users page', () => {
    beforeEach(() => {
        mockSubmit.mockReset();
        mockRouterPost.mockReset();
        mockRouterDelete.mockReset();
        mockPage.props = { auth: { user: ME } };
    });

    it('offers only the permissions the actor can grant', () => {
        renderUsers();
        fireEvent.click(screen.getByRole('button', { name: '+ Crear' }));

        expect(screen.getByLabelText('Ver Mapa Político')).toBeInTheDocument();
        expect(screen.getByLabelText('Gestionar usuarios')).toBeInTheDocument();
        expect(screen.queryByLabelText('Gestionar territorio')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Gestionar partidos')).not.toBeInTheDocument();
        // The chips of the list still name a permission the actor cannot grant.
        expect(screen.getByText('Gestionar partidos')).toBeInTheDocument();
    });

    it('locks the users holding permissions the actor cannot grant', () => {
        renderUsers();
        const locked = rowOf('Luisa Partidos');

        expect(locked).toHaveTextContent('No puedes modificar a este usuario porque tiene permisos que tú no tienes.');
        expect(within(locked).queryByTitle('Editar')).not.toBeInTheDocument();
        expect(within(locked).queryByTitle('Eliminar')).not.toBeInTheDocument();
        expect(within(locked).queryByRole('button', { name: /Desactivar/ })).not.toBeInTheDocument();

        const editable = rowOf('Pedro Operador');
        expect(editable).not.toHaveTextContent('No puedes modificar a este usuario');
        expect(within(editable).getByTitle('Editar')).toBeInTheDocument();
        expect(within(editable).getByTitle('Eliminar')).toBeInTheDocument();
    });

    it('lets a superadmin manage every user', () => {
        mockPage.props = { auth: { user: { ...ME, isSuperadmin: true, roleCode: 'R01_SUPERADMIN' } } };
        renderUsers();
        const row = rowOf('Luisa Partidos');

        expect(row).not.toHaveTextContent('No puedes modificar a este usuario');
        expect(within(row).getByTitle('Editar')).toBeInTheDocument();
        expect(within(row).getByRole('button', { name: 'Desactivar a Luisa Partidos' })).toBeInTheDocument();
        expect(within(row).getByTitle('Eliminar')).toBeInTheDocument();
    });

    it('shows a 403 on save as a message in the form instead of the raw error page', () => {
        const page = '<html><body><h1>403</h1><div>No puedes asignar ese rol.</div></body></html>';
        const outcome = { httpException: { status: 403, data: page } };
        mockSubmit.mockReturnValue(outcome);
        renderUsers();
        editRow('Pedro Operador');
        fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(outcome.handled).toBe(false);
        expect(screen.getByRole('alert')).toHaveTextContent('No puedes asignar ese rol.');
    });

    it('shows validation errors of fields without their own message', () => {
        mockSubmit.mockReturnValue({ errors: { role_id: 'El rol seleccionado no es válido.' } });
        renderUsers();
        fireEvent.click(screen.getByRole('button', { name: '+ Crear' }));
        fireEvent.change(screen.getByPlaceholderText('Nombre completo'), { target: { value: 'Ana' } });
        fireEvent.change(screen.getByPlaceholderText('correo@ejemplo.com'), { target: { value: 'ana@x.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Crear Usuario' }));

        expect(screen.getByRole('alert')).toHaveTextContent('El rol seleccionado no es válido.');
    });

    it('asks before deactivating a user and only then calls the server', () => {
        renderUsers();
        fireEvent.click(screen.getByRole('button', { name: 'Desactivar a Pedro Operador' }));

        const dialog = screen.getByRole('alertdialog');
        expect(dialog).toHaveTextContent('¿Desactivar a Pedro Operador?');
        expect(dialog).toHaveTextContent('No podrá entrar hasta que lo actives de nuevo.');
        expect(mockRouterPost).not.toHaveBeenCalled();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(mockRouterPost).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Desactivar a Pedro Operador' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, desactivar' }));
        expect(mockRouterPost).toHaveBeenCalledWith('/admin/usuarios/u-op/toggle', {}, expect.any(Object));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('asks before activating an inactive user', () => {
        renderUsers([SELF, { ...OPERADOR, isActive: false }]);
        fireEvent.click(screen.getByRole('button', { name: 'Activar a Pedro Operador' }));

        expect(screen.getByRole('alertdialog')).toHaveTextContent('¿Activar a Pedro Operador?');
        fireEvent.click(screen.getByRole('button', { name: 'Sí, activar' }));
        expect(mockRouterPost).toHaveBeenCalledTimes(1);
    });

    it('shows the server refusal of a toggle as a visible message', () => {
        mockRouterPost.mockImplementation((url, data, options) => options.onError({ toggle: 'No puedes desactivar tu propia cuenta' }));
        renderUsers();
        fireEvent.click(screen.getByRole('button', { name: 'Desactivar a Pedro Operador' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, desactivar' }));

        expect(screen.getByRole('alert')).toHaveTextContent('No puedes desactivar tu propia cuenta');
    });

    it('shows a 403 of a toggle as a message instead of the raw error page', () => {
        let handled;
        mockRouterPost.mockImplementation((url, data, options) => { handled = options.onHttpException({ status: 403, data: '' }); });
        renderUsers();
        fireEvent.click(screen.getByRole('button', { name: 'Desactivar a Pedro Operador' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, desactivar' }));

        expect(handled).toBe(false);
        expect(screen.getByRole('alert')).toHaveTextContent('No tienes permiso para hacer este cambio.');
    });

    it('does not offer deactivating the own account', () => {
        renderUsers();

        expect(screen.queryByRole('button', { name: `Desactivar a ${ME.name}` })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Desactivar a Pedro Operador' })).toBeInTheDocument();
    });
});
