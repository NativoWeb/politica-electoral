import { describe, it, expect } from 'vitest';
import { appendNew, mergeSectionRows, nextOffset, remainingInSection, sectionQuery } from './mapaPoliticoPaging';

describe('mapaPoliticoPaging', () => {
    const paging = { key: 'votantes', loaded: 200, total: 450 };

    it('cuenta lo que falta por filas LEÍDAS y para cuando el servidor dice que no hay más', () => {
        expect(remainingInSection(paging, null)).toBe(250);
        expect(remainingInSection(paging, { offset: 400, hasMore: true })).toBe(50);
        expect(remainingInSection(paging, { offset: 400, hasMore: false })).toBe(0);
        expect(remainingInSection(undefined, null)).toBe(0);
        expect(nextOffset(paging, null)).toBe(200);
        expect(nextOffset(paging, { offset: 400, hasMore: true })).toBe(400);
    });

    it('no agrega dos veces la misma ficha a una sección', () => {
        const rows = appendNew([{ id: 'a' }, { id: 'b' }], [{ id: 'b' }, { id: 'c' }]);
        expect(rows.map(r => r.id)).toEqual(['a', 'b', 'c']);
    });

    it('sin páginas extra devuelve las filas del servidor tal cual', () => {
        const rows = [{ id: 'a', tipo_registro: 'Líderes' }];
        expect(mergeSectionRows(rows, {})).toBe(rows);
    });

    it('agrega las páginas nuevas y une la etiqueta de una ficha que ya estaba', () => {
        const server = [{ id: 'a', nombre: 'Ana', tipo_registro: 'Líderes' }];
        const extra = {
            'Directorio Municipal': [{ id: 'a', nombre: 'Ana', tipo_registro: 'Directorio Municipal' }],
            Votantes: [{ id: 'b', nombre: 'Beto', tipo_registro: 'Votantes' }, { id: 'c', nombre: 'Caro', tipo_registro: 'Votantes' }],
        };

        const merged = mergeSectionRows(server, extra);

        expect(merged.map(r => r.id)).toEqual(['a', 'b', 'c']);
        expect(merged[0].tipo_registro).toBe('Líderes, Directorio Municipal');
        expect(server[0].tipo_registro).toBe('Líderes');
    });

    it('arma la consulta con los filtros de la página, sin vacíos', () => {
        const qs = new URLSearchParams(sectionQuery({ municipio: 'm1', search: '', tipo: 'todos', campana: undefined }, 'votantes', 400));

        expect(Object.fromEntries(qs)).toEqual({ municipio: 'm1', tipo: 'todos', seccion: 'votantes', offset: '400' });
    });
});
