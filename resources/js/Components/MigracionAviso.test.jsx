import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import MigracionAviso from './MigracionAviso';

const MIGRACION = {
    por_completar: [{ campo: 'barrio', label: 'Barrio', codigo_electo: '77' }],
    diferencias: [{ campo: 'telefono', label: 'Telefono', electo: '3109998888', actual: '3001112222' }],
    marcas: ['El género se dedujo del código de Electo: conviene confirmarlo.'],
};

describe('MigracionAviso', () => {
    it('no muestra nada sin marcas de Electo', () => {
        const { container } = render(<MigracionAviso migracion={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('resume y, al abrir, dice qué falta y qué difiere de Electo', () => {
        render(<MigracionAviso migracion={MIGRACION} />);

        expect(screen.getByText(/1 por completar · 1 diferencia con Electo · 1 aviso/)).toBeInTheDocument();
        expect(screen.queryByText(/código en Electo/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Datos de Electo/ }));

        expect(screen.getByText(/código en Electo: 77/)).toBeInTheDocument();
        expect(screen.getByText('3001112222')).toBeInTheDocument();
        expect(screen.getByText('3109998888')).toBeInTheDocument();
        expect(screen.getByText(/se dedujo del código/)).toBeInTheDocument();
    });

    it('a una ficha creada por un acta la rotula «Datos por completar», no «Datos de Electo»', () => {
        const acta = {
            por_completar: [{ campo: 'cedula', label: 'Cédula', codigo_electo: null }, { campo: 'telefono', label: 'Teléfono', codigo_electo: null }],
            diferencias: [],
            marcas: ['Puede ser la misma persona que otra ficha del mismo nombre y municipio.'],
        };
        const { unmount } = render(<MigracionAviso migracion={acta} />);
        expect(screen.getByRole('button', { name: /^Datos por completar:/ })).toBeInTheDocument();
        expect(screen.queryByText(/Datos de Electo/)).not.toBeInTheDocument();
        unmount();

        render(<MigracionAviso migracion={{ ...acta, origen: 'electo' }} />);
        expect(screen.getByRole('button', { name: /^Datos de Electo:/ })).toBeInTheDocument();
    });
});

describe('MigracionAviso: resolver diferencias con Electo', () => {
    const DIF = {
        por_completar: [],
        diferencias: [
            { campo: 'nombre', label: 'Nombre', electo: 'FRANKLIN ORTIZ', actual: 'FRANKLIN ORTIS', usar_electo: true },
            { campo: 'municipio', label: 'Municipio', electo: 'Lebrija', actual: 'Girón', usar_electo: false },
        ],
        marcas: [],
    };
    const originalFetch = globalThis.fetch;
    afterEach(() => { globalThis.fetch = originalFetch; });

    function responder(status, body) {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })));
    }

    function abrir(props = {}) {
        const onResuelta = vi.fn();
        render(<MigracionAviso migracion={DIF} liderId="l1" puedeResolver onResuelta={onResuelta} {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Datos de Electo/ }));
        return onResuelta;
    }

    it('sin permiso de edición no ofrece botones', () => {
        abrir({ puedeResolver: false });
        expect(screen.getByText('FRANKLIN ORTIS')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Dejar el de aquí' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Usar el de Electo' })).not.toBeInTheDocument();
    });

    it('«Dejar el de aquí» quita la diferencia sin recargar y sin confirmar', async () => {
        responder(200, { success: true, campo: 'nombre', valor: null, migracion: { por_completar: [], diferencias: [DIF.diferencias[1]], marcas: [] } });
        const onResuelta = abrir();

        fireEvent.click(screen.getAllByRole('button', { name: 'Dejar el de aquí' })[0]);

        await waitFor(() => expect(screen.queryByText('FRANKLIN ORTIS')).not.toBeInTheDocument());
        expect(globalThis.fetch).toHaveBeenCalledWith('/votantes/l1/electo/diferencias/nombre', expect.objectContaining({ method: 'POST', body: JSON.stringify({ accion: 'aqui' }) }));
        expect(onResuelta).toHaveBeenCalledWith(expect.objectContaining({ campo: 'nombre', accion: 'aqui', valor: null }));
        expect(screen.getByText('Lebrija')).toBeInTheDocument();
    });

    it('«Usar el de Electo» pide confirmación, y al no quedar nada el recuadro desaparece', async () => {
        responder(200, { success: true, campo: 'nombre', valor: 'FRANKLIN ORTIZ', migracion: null });
        const onResuelta = abrir();

        fireEvent.click(screen.getByRole('button', { name: 'Usar el de Electo' }));
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(screen.getByText('Se cambiará Nombre a «FRANKLIN ORTIZ». ¿Continuar?')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'No' }));
        expect(screen.queryByText(/Se cambiará Nombre/)).not.toBeInTheDocument();
        expect(globalThis.fetch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Usar el de Electo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, cambiar' }));

        await waitFor(() => expect(screen.queryByText(/Datos de Electo/)).not.toBeInTheDocument());
        expect(onResuelta).toHaveBeenCalledWith(expect.objectContaining({ campo: 'nombre', accion: 'electo', valor: 'FRANKLIN ORTIZ', migracion: null }));
    });

    it('no ofrece «Usar el de Electo» cuando el servidor dice que no se puede', () => {
        abrir();
        expect(screen.getAllByRole('button', { name: 'Dejar el de aquí' })).toHaveLength(2);
        expect(screen.getAllByRole('button', { name: 'Usar el de Electo' })).toHaveLength(1);
        expect(screen.getByText(/no se puede usar tal cual/)).toBeInTheDocument();
    });

    it('muestra el error del servidor y deja la diferencia', async () => {
        responder(422, { success: false, message: 'El valor de Electo no es válido para este campo.' });
        const onResuelta = abrir();

        fireEvent.click(screen.getByRole('button', { name: 'Usar el de Electo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, cambiar' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('El valor de Electo no es válido para este campo.');
        expect(screen.getByText('FRANKLIN ORTIS')).toBeInTheDocument();
        expect(onResuelta).not.toHaveBeenCalled();
    });

    it('sin conexión lo dice y no queda bloqueado', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        abrir();

        fireEvent.click(screen.getAllByRole('button', { name: 'Dejar el de aquí' })[0]);

        expect(await screen.findByRole('alert')).toHaveTextContent('No hay conexión');
        expect(screen.getAllByRole('button', { name: 'Dejar el de aquí' })[0]).not.toBeDisabled();
    });

    it('mientras guarda, un segundo clic no envía otra petición', async () => {
        let resolver;
        globalThis.fetch = vi.fn(() => new Promise(r => { resolver = r; }));
        abrir();

        const [boton] = screen.getAllByRole('button', { name: 'Dejar el de aquí' });
        fireEvent.click(boton);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Guardando…' })).toBeDisabled());
        fireEvent.click(screen.getByRole('button', { name: 'Guardando…' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Dejar el de aquí' })[0]);

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        resolver(new Response(JSON.stringify({ success: true, valor: null, migracion: null }), { status: 200 }));
        await waitFor(() => expect(screen.queryByText(/Datos de Electo/)).not.toBeInTheDocument());
    });
});
