// Centro de configuración: its screens and the forms that take their lists from it (shared prop opcionesListas).
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within, waitFor, cleanup } from '@testing-library/react';

const mockApiFetch = vi.fn();
const mockReload = vi.fn();
const mockPage = { props: {}, url: '/admin/configuracion' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('@/lib/api', async (importOriginal) => ({ ...(await importOriginal()), apiFetch: (...args) => mockApiFetch(...args) }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, prefetch: _prefetch, preserveScroll: _preserveScroll, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => mockPage,
    useForm: (initial) => ({ data: initial, setData: vi.fn(), errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
    router: { reload: (...args) => mockReload(...args), on: () => () => {}, get: vi.fn(), post: vi.fn() },
}));

import ConfiguracionIndex from '@/Pages/Admin/Configuracion/Index';
import ConfiguracionLista from '@/Pages/Admin/Configuracion/Lista';
import ConfiguracionAjustes, { validarAjuste } from '@/Pages/Admin/Configuracion/Ajustes';
import ConfiguracionHistorial, { resumenCambio } from '@/Pages/Admin/Configuracion/Historial';
import CatalogoOptions from '@/Components/CatalogoOptions';
import VotanteContactos from '@/Components/VotanteContactos';
import { etiquetaDe, opcionesDe } from '@/lib/catalogos';

function json(body, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const LISTA = { clave: 'estado_civil', nombre: 'Estado civil', descripcion: 'Fichas.', fijo: false, codigo: false, protegidos: [] };
const OPCIONES = [
    { id: 'o1', valor: 'Soltero/a', etiqueta: 'Soltero/a', orden: 1, activo: true, color: null, protegido: false, usos: 12 },
    { id: 'o2', valor: 'Casado/a', etiqueta: 'Casado/a', orden: 2, activo: true, color: null, protegido: false, usos: 0 },
];

const AJUSTE = {
    clave: 'mapa.tamano_pagina', grupo: 'mapa', tipo: 'int', etiqueta: 'Fichas por sección antes de "Ver más"',
    descripcion: 'Cuántas fichas.', min: 50, max: 500, unidad: 'fichas', opciones: null, default: 200, valor: 200, cambiado: false,
};

describe('Centro de configuración', () => {
    beforeEach(() => {
        mockApiFetch.mockReset();
        mockReload.mockReset();
        mockPage.props = {};
    });

    afterEach(cleanup);

    it('the home lists the lists, the ajustes, the history and the screens that already exist', () => {
        render(<ConfiguracionIndex
            listas={[{ clave: 'genero', nombre: 'Género', descripcion: 'Fichas.', fijo: false, activas: 3, total: 4 }]}
            accesos={[{ titulo: 'Partidos', descripcion: 'Partidos y movimientos', href: '/admin/partidos' }]}
            resumenAjustes={{ total: 8, cambiados: 1 }}
            totalCambios={5}
        />);

        expect(screen.getByRole('link', { name: /Género/ })).toHaveAttribute('href', '/admin/configuracion/listas/genero');
        expect(screen.getByRole('link', { name: /Género/ })).toHaveTextContent('3 de 4 activas');
        expect(screen.getByRole('link', { name: /Ajustes del sistema/ })).toHaveTextContent('8 ajustes · 1 cambiado');
        expect(screen.getByRole('link', { name: /Historial de cambios/ })).toHaveTextContent('5 cambios registrados');
        expect(screen.getByRole('link', { name: /Partidos/ })).toHaveAttribute('href', '/admin/partidos');
    });

    it('adds an option and shows the server message when it is refused', async () => {
        mockApiFetch
            .mockImplementationOnce(() => json({ success: true, message: 'Opción agregada.', data: { id: 'o3', valor: 'Separado/a', etiqueta: 'Separado/a', orden: 3, activo: true, color: null, protegido: false } }, 201))
            .mockImplementationOnce(() => json({ success: false, message: '«Soltero/a» ya está en la lista (si está desactivada, actívela).' }, 422));
        render(<ConfiguracionLista lista={LISTA} opciones={OPCIONES} />);

        fireEvent.change(screen.getByLabelText('Nueva opción'), { target: { value: 'Separado/a' } });
        fireEvent.click(screen.getByRole('button', { name: 'Agregar' }));
        await waitFor(() => expect(screen.getByRole('listitem', { name: 'Separado/a' })).toBeInTheDocument());
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/configuracion/listas/estado_civil/opciones', expect.objectContaining({ method: 'POST', body: JSON.stringify({ etiqueta: 'Separado/a' }) }));
        expect(screen.getByRole('status')).toHaveTextContent('Opción agregada.');

        fireEvent.change(screen.getByLabelText('Nueva opción'), { target: { value: 'soltero/a' } });
        fireEvent.click(screen.getByRole('button', { name: 'Agregar' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('ya está en la lista'));
    });

    it('an option in use cannot be deleted and switching it off keeps it in the list', async () => {
        mockApiFetch.mockImplementationOnce(() => json({ success: true, message: 'Opción guardada.', data: { ...OPCIONES[0], activo: false } }));
        render(<ConfiguracionLista lista={LISTA} opciones={OPCIONES} />);

        const enUso = screen.getByRole('listitem', { name: 'Soltero/a' });
        expect(within(enUso).queryByRole('button', { name: 'Borrar' })).not.toBeInTheDocument();
        expect(within(enUso).getByText(/En 12 registros/)).toBeInTheDocument();
        expect(within(screen.getByRole('listitem', { name: 'Casado/a' })).getByRole('button', { name: 'Borrar' })).toBeInTheDocument();

        fireEvent.click(within(enUso).getByRole('button', { name: 'Desactivar' }));
        await waitFor(() => expect(within(enUso).getByText('Desactivada')).toBeInTheDocument());
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/configuracion/opciones/o1', expect.objectContaining({ method: 'PUT', body: JSON.stringify({ activo: false }) }));
        expect(within(enUso).getByRole('button', { name: 'Activar' })).toBeInTheDocument();
    });

    it('a list of fixed values offers no new option nor delete', () => {
        render(<ConfiguracionLista lista={{ ...LISTA, fijo: true }} opciones={OPCIONES} />);

        expect(screen.queryByLabelText('Nueva opción')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Borrar' })).not.toBeInTheDocument();
    });

    it('an ajuste out of its range cannot be saved; a valid one is saved and can go back to the default', async () => {
        mockApiFetch
            .mockImplementationOnce(() => json({ success: true, message: 'Ajuste guardado.', data: { clave: AJUSTE.clave, valor: 300, cambiado: true } }))
            .mockImplementationOnce(() => json({ success: true, message: 'Volvió al valor por defecto.', data: { clave: AJUSTE.clave, valor: 200, cambiado: false } }));
        render(<ConfiguracionAjustes grupos={{ mapa: 'Mapa político' }} ajustes={[AJUSTE]} />);

        const input = screen.getByLabelText(AJUSTE.etiqueta);
        fireEvent.change(input, { target: { value: '10' } });
        expect(screen.getByText('El valor mínimo es 50.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Guardar' })).toBeDisabled();

        fireEvent.change(input, { target: { value: '300' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));
        await waitFor(() => expect(screen.getByText('Cambiado')).toBeInTheDocument());
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/configuracion/ajustes/mapa.tamano_pagina', expect.objectContaining({ method: 'PUT', body: JSON.stringify({ valor: 300 }) }));

        fireEvent.click(screen.getByRole('button', { name: 'Volver al valor por defecto' }));
        await waitFor(() => expect(screen.getByLabelText(AJUSTE.etiqueta)).toHaveValue(200));
        expect(screen.queryByText('Cambiado')).not.toBeInTheDocument();
        expect(validarAjuste(AJUSTE, '501')).toBe('El valor máximo es 500.');
        expect(validarAjuste(AJUSTE, '2.5')).toBe('Escriba un número entero.');
    });

    it('the history summarises each change and reverts after confirming', async () => {
        mockApiFetch.mockImplementationOnce(() => json({ success: true, message: 'Cambio revertido.' }));
        const cambio = {
            id: 'c1', tipo: 'catalogo', accion: 'editar', referencia: 'Otro', donde: 'Género: Otro',
            antes: { etiqueta: 'Otro', orden: 3, activo: true, color: null }, despues: { etiqueta: 'Otra', orden: 3, activo: false, color: null },
            usuario: 'Super', fecha: '2026-11-19T10:00:00-05:00', revertido: false, revierte: false,
        };
        render(<ConfiguracionHistorial cambios={{ data: [cambio, { ...cambio, id: 'c2', revertido: true }], last_page: 1, links: [] }} filtro={null} />);

        expect(resumenCambio(cambio)).toBe('nombre: Otro → Otra · activa: sí → no');
        expect(resumenCambio({ tipo: 'ajuste', antes: null, despues: { valor: 300 } })).toBe('por defecto → 300');
        const [row, revertido] = screen.getAllByRole('listitem');
        expect(within(revertido).queryByRole('button', { name: 'Revertir' })).not.toBeInTheDocument();

        fireEvent.click(within(row).getByRole('button', { name: 'Revertir' }));
        expect(mockApiFetch).not.toHaveBeenCalled();
        fireEvent.click(within(row).getByRole('button', { name: 'Sí, revertir' }));
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Cambio revertido.'));
        expect(mockApiFetch).toHaveBeenCalledWith('/admin/configuracion/historial/c1/revertir', { method: 'POST' });
        expect(mockReload).toHaveBeenCalledWith({ only: ['cambios'] });
    });
});

describe('forms take their options from the Centro', () => {
    beforeEach(() => {
        mockPage.props = {
            opcionesListas: {
                genero: [
                    { valor: 'Masculino', etiqueta: 'Hombre', activo: true },
                    { valor: 'Otro', etiqueta: 'Otro', activo: false },
                    { valor: 'Prefiere no decir', etiqueta: 'Prefiere no decir', activo: true },
                ],
                tipo_contacto: [{ valor: 'celular', etiqueta: 'Móvil', activo: true }, { valor: 'fijo', etiqueta: 'Fijo', activo: false }],
            },
        };
    });

    afterEach(cleanup);

    it('a select offers the active options with their labels, and the ficha\'s own switched off value', () => {
        render(<select aria-label="Genero" defaultValue="Otro"><CatalogoOptions catalogo="genero" actual="Otro" /></select>);
        const options = within(screen.getByLabelText('Genero')).getAllByRole('option').map(o => [o.value, o.textContent]);

        expect(options).toEqual([['Masculino', 'Hombre'], ['Prefiere no decir', 'Prefiere no decir'], ['Otro', 'Otro (ya no se ofrece)']]);
    });

    it('the contacts of a votante use the list of contact types', () => {
        render(<VotanteContactos contactos={[{ id: 'k1', tipo: 'fijo', valor: '6076000000', principal: false }]} liderId="l1" apiFetch={vi.fn()} onUpdate={vi.fn()} />);

        expect(screen.getByText('Fijo')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Agregar/ }));
        const select = screen.getAllByRole('combobox')[0];
        expect(within(select).getAllByRole('option').map(o => o.textContent)).toEqual(['Móvil']);
    });

    it('without the shared prop the original options stay', () => {
        expect(opcionesDe(undefined, 'zona').map(o => o.label)).toEqual(['Rural', 'Urbana']);
        expect(etiquetaDe(undefined, 'nivel_confianza', 'no_responde')).toBe('No responde');
        expect(etiquetaDe(mockPage.props.opcionesListas, 'genero', 'Valor viejo')).toBe('Valor viejo');
    });
});
