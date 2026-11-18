import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import SectionLoadMore from './SectionLoadMore';

describe('SectionLoadMore', () => {
    it('no muestra nada cuando no falta nada', () => {
        const { container } = render(<SectionLoadMore remaining={0} onLoad={vi.fn()} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('dice cuántos faltan y pide la siguiente página', () => {
        const onLoad = vi.fn();
        render(<SectionLoadMore remaining={38887} shown={200} onLoad={onLoad} />);

        expect(screen.getByText(/Mostrando 200\./)).toBeInTheDocument();
        expect(screen.getByText('38.887')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));
        expect(onLoad).toHaveBeenCalledTimes(1);
    });

    it('mientras carga deshabilita el botón y si falla lo dice', () => {
        const { rerender } = render(<SectionLoadMore remaining={10} loading onLoad={vi.fn()} />);
        expect(screen.getByRole('button', { name: 'Cargando...' })).toBeDisabled();

        rerender(<SectionLoadMore remaining={10} error onLoad={vi.fn()} />);
        expect(screen.getByText(/No se pudieron cargar/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ver más' })).toBeEnabled();
    });
});
