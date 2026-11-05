import { afterEach, describe, expect, it } from 'vitest';
import { partyColor, partyKey, partyLogo, pctTrunc, setUploadedPartyLogos } from './electoral';

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

describe('party logos and colors with names in capitals', () => {
    afterEach(() => setUploadedPartyLogos({}));

    it('finds the bundled logo and color whatever the case or accents', () => {
        expect(partyLogo('CENTRO DEMOCRÁTICO')).toBe('/img/partidos/centro-democratico.png');
        expect(partyLogo('CENTRO DEMOCRATICO')).toBe('/img/partidos/centro-democratico.png');
        expect(partyLogo('PARTIDO DE LA U')).toBe('/img/partidos/la-u.png');
        expect(partyLogo('PACTO HISTORICO')).toBe('/img/partidos/pacto-historico.png');
        expect(partyLogo('Partido Liberal')).toBe('/img/partidos/liberal.png');
        expect(partyColor('CENTRO DEMOCRÁTICO')).toBe('#1B3A63');
        expect(partyColor('PARTIDO LIBERAL')).toBe('#E31937');
        expect(partyLogo('PARTIDO INVENTADO')).toBeNull();
        expect(partyLogo(null)).toBeNull();
    });

    it('prefers the uploaded logo, by name or acronym, over the bundled one', () => {
        setUploadedPartyLogos({ 'CENTRO DEMOCRÁTICO': '/storage/partidos/cd.webp', CR: '/storage/partidos/cr.png' });

        expect(partyLogo('Centro Democrático')).toBe('/storage/partidos/cd.webp');
        expect(partyLogo('CAMBIO RADICAL', 'CR')).toBe('/storage/partidos/cr.png');
        expect(partyLogo('PARTIDO LIBERAL')).toBe('/img/partidos/liberal.png');

        setUploadedPartyLogos(null);
        expect(partyLogo('CENTRO DEMOCRÁTICO')).toBe('/img/partidos/centro-democratico.png');
    });

    it('builds the same key for spellings of one party', () => {
        expect(partyKey('  Centro   Democrático ')).toBe(partyKey('CENTRO DEMOCRATICO'));
        expect(partyKey('')).toBe('');
    });
});
