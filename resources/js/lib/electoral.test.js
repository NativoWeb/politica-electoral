import { describe, expect, it } from 'vitest';
import { pctTrunc } from './electoral';

describe('pctTrunc', () => {
    it('truncates to 2 decimals like the Registraduría (Gobernación Santander 2023, boletín 51)', () => {
        const validos = 1085625;
        expect(pctTrunc(423130, validos)).toBe('38,97');
        expect(pctTrunc(208781, validos)).toBe('19,23');
        expect(pctTrunc(181399, validos)).toBe('16,70');
        expect(pctTrunc(10868, validos)).toBe('1,00');
        expect(pctTrunc(3502, validos)).toBe('0,32');
        expect(pctTrunc(76612, validos)).toBe('7,05');
    });

    it('has no percentage without votes or without a base', () => {
        expect(pctTrunc(null, 100)).toBeNull();
        expect(pctTrunc(5, 0)).toBeNull();
        expect(pctTrunc(0, 100)).toBe('0,00');
    });
});
