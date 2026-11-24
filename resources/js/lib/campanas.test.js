import { describe, it, expect } from 'vitest';
import {
    TEXTO_NUMERO, TEXTO_SIN_RESULTADOS, borradorDe, cambiosDe, cuerpoFormulario, erroresDe, erroresDeMetas, erroresServidor,
    formularioInicial, modoMunicipio, muestraEsLider, normalizarDetalle, normalizarFilas, sugerenciaDe, totales, usarSugerido,
    validarFormulario, validarMeta, valorMunicipio,
} from './campanas';

const FACTORES = { factor_votos: 1.1, factor_registro: 1.0, votos_por_lider: 50 };

const BUCARAMANGA = {
    id: 'bga', nombre: 'Bucaramanga', tipo: 'municipality', codigo: '68001', padre_id: null, comunas: 2,
    meta: null, editable: true, suma_comunas: null,
    sugerencia: { votos: 1100, lideres: 22, votantes: 1100, base: 1000, texto: 'Sugerido: 1.100 (1.000 votos de CD a Asamblea 2023 × 1,10)' },
    sin_sugerencia: null, avance: { votantes: 38821, lideres: 3 }, sin_comuna: { votantes: 12, lideres: 0 },
};
const C1 = { id: 'c1', nombre: 'Comuna 1 Norte', tipo: 'comuna', codigo: '68001-C01', padre_id: 'bga', meta: null, editable: true, sugerencia: null, sin_sugerencia: 'Sin sugerencia: los resultados solo existen por municipio', avance: { votantes: 3, lideres: 0 }, sin_comuna: null };
const C2 = { ...C1, id: 'c2', nombre: 'Corregimiento 1', tipo: 'corregimiento', codigo: '68001-R01' };
const GIRON = { ...BUCARAMANGA, id: 'giron', nombre: 'Girón', codigo: '68307', comunas: 0, meta: { votos_meta: 500, lideres_meta: null, votantes_meta: 600 }, sugerencia: null, sin_comuna: null, avance: { votantes: 10, lideres: 1 } };

describe('validarMeta', () => {
    it('accepts empty (no goal), 0, whole numbers and dots as thousands separators', () => {
        expect(validarMeta('')).toEqual({ ok: true, valor: null });
        expect(validarMeta('  ')).toEqual({ ok: true, valor: null });
        expect(validarMeta('0')).toEqual({ ok: true, valor: 0 });
        expect(validarMeta('1.200')).toEqual({ ok: true, valor: 1200 });
        expect(validarMeta('10.000.000')).toEqual({ ok: true, valor: 10000000 });
    });

    it('refuses text, negatives, decimals and more than 10.000.000', () => {
        for (const malo of ['abc', '-5', '1,5', '1.5', '12.00', '2e3']) {
            expect(validarMeta(malo)).toEqual({ ok: false, error: TEXTO_NUMERO });
        }
        expect(validarMeta('10000001').ok).toBe(false);
        expect(validarMeta('10000001').error).toBe('Escribe un número hasta 10.000.000');
    });
});

describe('normalizarFilas', () => {
    it('nests the comunas under their municipio and keeps a lone comuna (JAL) at the top', () => {
        const filas = normalizarFilas([BUCARAMANGA, C1, C2, GIRON]);
        expect(filas.map(f => f.id)).toEqual(['bga', 'giron']);
        expect(filas[0].hijos.map(h => h.id)).toEqual(['c1', 'c2']);
        expect(filas[0].sinComuna).toEqual({ votantes: 12, lideres: 0 });
        expect(normalizarFilas([C1]).map(f => f.id)).toEqual(['c1']);
    });
});

describe('the goals grid', () => {
    const filas = normalizarFilas([BUCARAMANGA, C1, C2, GIRON]);

    it('a municipio is its own goal or the sum of its comunas, never both', () => {
        const b = borradorDe(filas);
        expect(b.giron).toEqual({ votos: '500', lideres: '', votantes: '600' });
        expect(borradorDe(normalizarFilas([{ ...GIRON, meta: { votos_meta: 38821 } }])).giron.votos).toBe('38.821');
        expect(modoMunicipio(filas[0], b)).toBe('libre');
        b.c1 = { votos: '300', lideres: '', votantes: '' };
        b.c2 = { votos: '1.200', lideres: '4', votantes: '' };
        expect(modoMunicipio(filas[0], b)).toBe('comunas');
        expect(valorMunicipio(filas[0], b, 'votos')).toBe(1500);
        expect(totales(filas, b)).toMatchObject({ votos: 2000, lideres: 4, votantes: 600, avanceVotantes: 38831, avanceLideres: 4 });
    });

    it('sends only the changed rows, with null for an emptied cell', () => {
        const b = borradorDe(filas);
        expect(cambiosDe(filas, b)).toEqual([]);
        b.giron = { votos: '', lideres: '10', votantes: '600' };
        b.bga = { votos: '1.100', lideres: '', votantes: '' };
        expect(cambiosDe(filas, b)).toEqual([
            { geographic_unit_id: 'bga', votos_meta: 1100, lideres_meta: null, votantes_meta: null },
            { geographic_unit_id: 'giron', votos_meta: null, lideres_meta: 10, votantes_meta: 600 },
        ]);
    });

    it('marks the bad cells', () => {
        const b = borradorDe(filas);
        b.giron.votos = 'abc';
        b.c1.lideres = '-5';
        expect(erroresDe(b)).toEqual({ giron: { votos: TEXTO_NUMERO }, c1: { lideres: TEXTO_NUMERO } });
    });

    it('«Usar sugerido» fills the suggested votes and the leaders and voters that follow from them', () => {
        const s = sugerenciaDe(filas[0], { votos: '', lideres: '', votantes: '' }, FACTORES);
        expect(s).toMatchObject({ votos: 1100, lideres: 22, votantes: 1100, conVotosPropios: false });
        expect(usarSugerido({ votos: '', lideres: '', votantes: '' }, s)).toEqual({ votos: '1.100', lideres: '22', votantes: '1.100' });
    });

    it('with votes already typed, the suggestion keeps them and recalculates leaders and voters', () => {
        const s = sugerenciaDe(filas[0], { votos: '2.000', lideres: '', votantes: '' }, { ...FACTORES, factor_registro: 1.2 });
        expect(s).toMatchObject({ votos: null, base: 2000, lideres: 40, votantes: 2400, conVotosPropios: true });
        expect(usarSugerido({ votos: '2.000', lideres: '1', votantes: '' }, s)).toEqual({ votos: '2.000', lideres: '40', votantes: '2.400' });
    });

    it('no suggestion where the server sends none (comunas, JAL, a group without results)', () => {
        expect(sugerenciaDe(filas[0].hijos[0], { votos: '100' }, FACTORES)).toBeNull();
    });

    it('maps the 422 of PUT /metas to the cell of the row sent', () => {
        const enviados = [{ geographic_unit_id: 'bga' }, { geographic_unit_id: 'giron' }];
        expect(erroresDeMetas({ errors: { 'metas.1.votos_meta': ['Escribe un número entero, 0 o más'] } }, enviados))
            .toEqual({ celdas: { giron: { votos: 'Escribe un número entero, 0 o más' } }, general: null });
        expect(erroresDeMetas({ errors: { metas: ['Bucaramanga tiene metas por comuna: …'] } }, enviados))
            .toEqual({ celdas: {}, general: 'Bucaramanga tiene metas por comuna: …' });
    });
});

describe('normalizarDetalle', () => {
    it('read-only without can.edit, with the server reason without its prefix', () => {
        const d = normalizarDetalle({
            campana: { id: 'e1', name: 'Gob', cargo: 'gobernacion' }, can: { edit: false, archive: false },
            soloLectura: { motivo: 'subgrupo', texto: 'Solo lectura: esta campaña es de Centro Democrático.' }, filas: [],
            sugerencias: { disponible: true },
        });
        expect(d.soloLectura).toBe(true);
        expect(d.motivoSoloLectura).toBe('Esta campaña es de Centro Democrático.');
        expect(d.avisoSugerencias).toBe('');
    });

    it('a group without electoral results gets the notice', () => {
        const d = normalizarDetalle({ campana: { id: 'e1' }, can: { edit: true }, filas: [], sugerencias: { disponible: false, aviso: null } });
        expect(d.veResultados).toBe(false);
        expect(d.avisoSugerencias).toBe(TEXTO_SIN_RESULTADOS);
    });
});

describe('the form', () => {
    const base = { ...formularioInicial(), nombre: 'Alcaldía Girón 2027' };

    it('starts in 2027 with the default factors', () => {
        expect(formularioInicial()).toMatchObject({ anio: '2027', factor_votos: '1,10', factor_registro: '1,00', votos_por_lider: '50', territorioIds: [] });
    });

    it('asks for the name, the cargo and the territory the cargo needs', () => {
        expect(validarFormulario({ ...formularioInicial() })).toMatchObject({ nombre: 'Escribe el nombre', cargo: 'Elige el cargo' });
        expect(validarFormulario({ ...base, cargo: 'alcaldia' }).territorio).toBe('Elige un municipio');
        expect(validarFormulario({ ...base, cargo: 'jal' }).territorio).toBe('Elige una comuna o corregimiento');
        expect(validarFormulario({ ...base, cargo: 'senado', alcance: 'municipios' }).territorio).toBe('Elige al menos un municipio');
        expect(validarFormulario({ ...base, cargo: 'gobernacion' })).toEqual({});
    });

    it('checks the year, the date and the factors', () => {
        expect(validarFormulario({ ...base, cargo: 'gobernacion', anio: '2050' }).anio).toBe('Escribe un año entre 2024 y 2040');
        expect(validarFormulario({ ...base, cargo: 'gobernacion', fecha: '2026-10-25' }).fecha).toBe('La fecha debe ser del año de la elección');
        expect(validarFormulario({ ...base, cargo: 'gobernacion', factor_votos: '5' }).factor_votos).toBe('Escribe un número entre 0,50 y 3,00');
        expect(validarFormulario({ ...base, cargo: 'gobernacion', votos_por_lider: '2,5' }).votos_por_lider).toBe('Escribe un número entre 5 y 1.000');
        expect(validarFormulario({ ...base, cargo: 'gobernacion', factor_registro: '' }).factor_registro).toBeTruthy();
    });

    it('builds the body the server expects for each cargo', () => {
        expect(cuerpoFormulario({ ...base, cargo: 'gobernacion', candidatoNombre: ' Ana ' })).toMatchObject({
            name: 'Alcaldía Girón 2027', cargo: 'gobernacion', election_year: 2027, scope_level: 'department', territorio_ids: [],
            candidate_person_id: null, candidate_name: 'Ana', factor_votos: 1.1, factor_registro: 1, votos_por_lider: 50,
        });
        expect(cuerpoFormulario({ ...base, cargo: 'alcaldia', territorioIds: ['giron'] })).toMatchObject({ scope_level: 'municipality', territorio_ids: ['giron'] });
        expect(cuerpoFormulario({ ...base, cargo: 'jal', territorioIds: ['c1'] })).toMatchObject({ scope_level: 'comuna', territorio_ids: ['c1'] });
        expect(cuerpoFormulario({ ...base, cargo: 'senado', alcance: 'departamento', territorioIds: ['giron'] })).toMatchObject({ scope_level: 'department', territorio_ids: [] });
        expect(cuerpoFormulario({ ...base, cargo: 'camara', alcance: 'municipios', territorioIds: ['giron', 'bga'] })).toMatchObject({ scope_level: 'municipality', territorio_ids: ['giron', 'bga'] });
        // A chosen candidate travels by id; the typed name is not sent with it.
        expect(cuerpoFormulario({ ...base, cargo: 'gobernacion', candidatoId: 'p1', candidatoNombre: 'Ana' })).toMatchObject({ candidate_person_id: 'p1', candidate_name: null });
    });

    it('puts the 422 of the server under each field', () => {
        expect(erroresServidor({ message: 'x', errors: { name: ['Ya tienes una campaña activa con ese nombre'], 'territorio_ids.0': ['Ese territorio no está en Santander'], grupo: ['Estás viendo «X».'] } }))
            .toEqual({ nombre: 'Ya tienes una campaña activa con ese nombre', territorio: 'Ese territorio no está en Santander', general: 'Estás viendo «X».' });
    });
});

describe('muestraEsLider', () => {
    it('only when the server sends the key (module on)', () => {
        expect(muestraEsLider({ id: 1, es_lider: false })).toBe(true);
        expect(muestraEsLider({ id: 1 })).toBe(false);
        expect(muestraEsLider(null)).toBe(false);
    });
});
