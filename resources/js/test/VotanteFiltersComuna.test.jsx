import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { campaign: { id: 'cd', name: 'CD' } } }, visibleCampaigns: [{ id: 'cd', name: 'CD' }] } }),
    router: { get: vi.fn(), reload: vi.fn() },
}));

import VotanteFilters from '@/Components/VotanteFilters';

const MUNICIPIOS = [
    { id: 'm-bga', name: 'Bucaramanga', provincia: 'Metropolitana' },
    { id: 'm-giron', name: 'Girón', provincia: 'Metropolitana' },
];
const COMUNAS = {
    Bucaramanga: [
        { id: 'c1', nombre: 'Comuna 1 - Norte', tipo: 'comuna' },
        { id: 'r1', nombre: 'Corregimiento 1', tipo: 'corregimiento' },
    ],
};

function renderBar(filters = {}) {
    const onApply = vi.fn();
    render(<VotanteFilters filters={filters} municipios={MUNICIPIOS} comunasPorMunicipio={COMUNAS} onApply={onApply} onClear={vi.fn()} />);
    return onApply;
}

function chooseMunicipio(name) {
    fireEvent.click(screen.getByRole('button', { name: /^Municipio/ }));
    fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
}

const apply = () => fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }));

describe('VotanteFilters: comuna', () => {
    it('only appears for a municipio that has comunas', () => {
        renderBar();
        expect(screen.queryByLabelText('Comuna')).not.toBeInTheDocument();

        chooseMunicipio('Girón');
        expect(screen.queryByLabelText('Comuna')).not.toBeInTheDocument();

        chooseMunicipio('Bucaramanga');
        const select = screen.getByLabelText('Comuna');
        expect([...select.options].map(o => o.textContent)).toEqual(['Todas', 'Sin comuna', 'Comuna 1 - Norte', 'Corregimiento 1']);
    });

    it('sends «Sin comuna» and a comuna with the municipio', () => {
        const onApply = renderBar();
        chooseMunicipio('Bucaramanga');
        fireEvent.change(screen.getByLabelText('Comuna'), { target: { value: 'sin_comuna' } });
        apply();
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ municipio: 'Bucaramanga', comuna: 'sin_comuna' }));

        fireEvent.change(screen.getByLabelText('Comuna'), { target: { value: 'r1' } });
        apply();
        expect(onApply).toHaveBeenLastCalledWith(expect.objectContaining({ comuna: 'r1' }));
    });

    it('another municipio drops the comuna and so does Limpiar', () => {
        const onApply = renderBar({ municipio: 'Bucaramanga', comuna: 'c1' });
        expect(screen.getByLabelText('Comuna')).toHaveValue('c1');

        chooseMunicipio('Girón');
        apply();
        expect(onApply.mock.lastCall[0]).not.toHaveProperty('comuna');

        chooseMunicipio('Bucaramanga');
        expect(screen.getByLabelText('Comuna')).toHaveValue('');
        fireEvent.change(screen.getByLabelText('Comuna'), { target: { value: 'c1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Limpiar' }));
        expect(screen.queryByLabelText('Comuna')).not.toBeInTheDocument();
    });

    it('the select is big enough for older users', () => {
        renderBar({ municipio: 'Bucaramanga' });
        expect(screen.getByLabelText('Comuna').className).toMatch(/min-h-\[48px\].*text-\[16px\]/);
    });
});
