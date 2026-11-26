import { describe, it, expect } from 'vitest';
import escalaTexto, { escalarValor } from './escalaTexto';

describe('escalarValor', () => {
    it('multiplica los tamaños fijos de letra por --a11y-texto', () => {
        expect(escalarValor('font-size', '15px')).toBe('calc((15px) * var(--a11y-texto, 1))');
        expect(escalarValor('font-size', '.875rem')).toBe('calc((.875rem) * var(--a11y-texto, 1))');
        expect(escalarValor('--text-sm', '0.875rem')).toBe('calc((0.875rem) * var(--a11y-texto, 1))');
        expect(escalarValor('line-height', '18px')).toBe('calc((18px) * var(--a11y-texto, 1))');
        expect(escalarValor('--tw-leading', 'calc(var(--spacing) * 5)')).toBe('calc((calc(var(--spacing) * 5)) * var(--a11y-texto, 1))');
    });

    it('deja quieto lo que ya escala solo o no es texto', () => {
        expect(escalarValor('font-size', 'var(--text-sm)')).toBeNull();
        expect(escalarValor('font-size', 'inherit')).toBeNull();
        expect(escalarValor('font-size', '1.2em')).toBeNull();
        expect(escalarValor('line-height', '1.5')).toBeNull();
        expect(escalarValor('--text-sm--line-height', 'calc(1.25 / 0.875)')).toBeNull();
        expect(escalarValor('width', '15px')).toBeNull();
        expect(escalarValor('padding', '1rem')).toBeNull();
        expect(escalarValor('font-size', 'calc((15px) * var(--a11y-texto, 1))')).toBeNull();
    });
});

describe('plugin PostCSS', () => {
    const plugin = escalaTexto();
    const decl = (prop, value, file = 'D:/app/resources/css/app.css') => ({ prop, value, source: { input: { file } } });

    it('reescribe el CSS de la app', () => {
        const d = decl('font-size', '15px');
        plugin.Declaration(d);
        expect(d.value).toBe('calc((15px) * var(--a11y-texto, 1))');
    });

    it('no toca el CSS de node_modules (Leaflet)', () => {
        const d = decl('font-size', '12px', 'D:/app/node_modules/leaflet/dist/leaflet.css');
        plugin.Declaration(d);
        expect(d.value).toBe('12px');
    });
});
