import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { campaign: { id: 'cd', name: 'CD' } } }, visibleCampaigns: [{ id: 'cd', name: 'CD' }] } }),
    router: { get: vi.fn(), reload: vi.fn() },
}));

import VotanteFilters from '@/Components/VotanteFilters';

describe('VotanteFilters: datos de Electo', () => {
    it('envía por completar, diferencias e inactivos importados, y Limpiar los quita', () => {
        const onApply = vi.fn();
        const onClear = vi.fn();
        render(<VotanteFilters filters={{}} municipios={[]} onApply={onApply} onClear={onClear} />);

        fireEvent.change(screen.getByLabelText('Datos por completar'), { target: { value: 'barrio' } });
        fireEvent.change(screen.getByLabelText('Inactivos importados'), { target: { value: 'menor' } });
        fireEvent.click(screen.getByLabelText('Diferencias con Electo'));
        fireEvent.click(screen.getByRole('button', { name: /aplicar|filtrar/i }));

        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ por_completar: 'barrio', diferencias: '1', importados_inactivos: 'menor' }));

        fireEvent.click(screen.getByRole('button', { name: /limpiar/i }));
        expect(onClear).toHaveBeenCalled();
        expect(screen.getByLabelText('Datos por completar')).toHaveValue('');
        expect(screen.getByLabelText('Diferencias con Electo')).not.toBeChecked();
    });

    it('arranca con los filtros que vienen en la URL', () => {
        render(<VotanteFilters filters={{ por_completar: 'convenio', diferencias: '1', importados_inactivos: 'todos' }} municipios={[]} onApply={vi.fn()} onClear={vi.fn()} />);

        expect(screen.getByLabelText('Datos por completar')).toHaveValue('convenio');
        expect(screen.getByLabelText('Inactivos importados')).toHaveValue('todos');
        expect(screen.getByLabelText('Diferencias con Electo')).toBeChecked();
    });

    it('ofrece Cédula y Teléfono y el select sigue a la URL', () => {
        const onApply = vi.fn();
        const { rerender } = render(<VotanteFilters filters={{ por_completar: 'cedula' }} municipios={[]} onApply={onApply} onClear={vi.fn()} />);
        const select = screen.getByLabelText('Datos por completar');
        expect(select).toHaveValue('cedula');
        expect([...select.options].map(o => o.textContent)).toEqual(expect.arrayContaining(['Cédula', 'Teléfono']));

        rerender(<VotanteFilters filters={{ por_completar: 'telefono' }} municipios={[]} onApply={onApply} onClear={vi.fn()} />);
        expect(screen.getByLabelText('Datos por completar')).toHaveValue('telefono');
        fireEvent.click(screen.getByRole('button', { name: /aplicar|filtrar/i }));
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ por_completar: 'telefono' }));
    });
});
