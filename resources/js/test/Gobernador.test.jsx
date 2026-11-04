import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import Gobernador from '@/Pages/Gobernador';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }) => <a href={href} {...rest}>{children}</a>,
}));
vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/GeoNavigator', () => ({ default: () => null }));
// jsdom has no layout: the donut is replaced by its data so the legend can be read.
vi.mock('recharts', () => ({
    ResponsiveContainer: ({ children }) => <div>{children}</div>,
    PieChart: ({ children }) => <div>{children}</div>,
    Pie: ({ data }) => <ul data-testid="donut">{data.map(d => <li key={d.name}>{d.name}</li>)}</ul>,
    Cell: () => null,
    Tooltip: () => null,
}));

const candidatos = [
    { id: 'c1', name: 'Juvenal Diaz Mateus', partido: 'General Juvenal Gobernador', acronym: null, color: '#123456', votos: 423130, electo: true },
    { id: 'c2', name: 'Rodolfo Hernandez', partido: 'Liga', acronym: 'LIGA', color: '#654321', votos: 135183, electo: false },
];
const resumen = { potencial: 1828912, votantes: 1168968, validos: 1085625, blanco: 76612, nulos: 19718, noMarcados: 63625, participacion: 63.91, fuente: 'Registraduría', fuenteUrl: null };

describe('Gobernador', () => {
    afterEach(cleanup);

    it('shows the participation once, with two decimals', () => {
        render(<Gobernador candidatos={candidatos} resumen={resumen} />);

        expect(screen.getAllByText('63,91%')).toHaveLength(1);
        expect(screen.queryByText('63,9%')).toBeNull();
    });

    it('shows a dash in the ring when there is no participation', () => {
        render(<Gobernador candidatos={candidatos} resumen={{ ...resumen, participacion: null }} />);

        expect(screen.getByText('Participación').previousSibling.textContent).toBe('—');
        expect(screen.queryByText('0,0%')).toBeNull();
    });

    it('names each party in the donut as its card does, not by acronym', () => {
        render(<Gobernador candidatos={candidatos} resumen={resumen} />);

        const donut = screen.getByTestId('donut');
        expect(donut.textContent).toContain('Liga');
        expect(donut.textContent).not.toContain('LIGA');
        expect([...donut.querySelectorAll('li')].map(li => li.textContent)).toEqual(['General Juvenal Gobernador', 'Liga', 'Voto en blanco']);
    });
});
