import { describe, it, expect } from 'vitest';
import { inAreaMetropolitana, municipioByName, municipioLabel } from './mapaPoliticoConstants';

const BARBOSA_SAN = { id: 's', name: 'Barbosa', provincia: 'Vélez', santander: true };
const BARBOSA_ANT = { id: 'a', name: 'Barbosa', provincia: 'Antioquia', santander: false };
const RIONEGRO_ANT = { id: 'r', name: 'Rionegro', provincia: 'Fuera de Santander', departamento: 'Antioquia', santander: false };

describe('municipios con nombre repetido en otro departamento', () => {
    it('los de fuera dicen su departamento y los de Santander no', () => {
        expect(municipioLabel(BARBOSA_SAN)).toBe('Barbosa');
        expect(municipioLabel(BARBOSA_ANT)).toBe('Barbosa (Antioquia)');
        expect(municipioLabel(RIONEGRO_ANT)).toBe('Rionegro (Antioquia)');
        expect(municipioLabel({ name: 'San Andrés (Santander)', santander: false, provincia: 'Santander' })).toBe('San Andrés (Santander)');
    });

    it('un nombre solo se toma por el de Santander', () => {
        expect(municipioByName([BARBOSA_ANT, BARBOSA_SAN], 'Barbosa')).toBe(BARBOSA_SAN);
        expect(municipioByName([BARBOSA_ANT], 'Barbosa')).toBe(BARBOSA_ANT);
        expect(municipioByName([], 'Barbosa')).toBeNull();
    });

    it('el Área Metropolitana es solo la de Santander', () => {
        expect(inAreaMetropolitana({ name: 'Rionegro', santander: true })).toBe(true);
        expect(inAreaMetropolitana(RIONEGRO_ANT)).toBe(false);
    });
});
