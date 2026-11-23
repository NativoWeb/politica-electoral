import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
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
