import { describe, expect, it } from 'vitest';
import { applyAnswers, compromisosQuery, errorFor, mergeRead, participanteFromAsistente, suggestionList, suggestionRequest, toForm, toPayload } from './actas';

const read = {
    titulo: 'ACTA REUNIÓN VEREDA BERLÍN', fecha: '2026-11-08', municipio: 'RIONEGRO', sitio: null, hora_inicio: '10:45', hora_fin: null,
    introduccion: 'Propuestas:', orden_dia: ['Seguridad', 'Vías'], desarrollo: [{ titulo: 'Seguridad', texto: '• Cámaras' }],
    participantes: [{ nombre: 'MARIA EJEMPLO', perfil: 'LIDER', municipio: null, telefono: '3104445566', cedula: null }],
    compromisos: [{ descripcion: 'Gestionar', fecha_limite: null, responsables: [{ nombre: 'SANDRA', telefono: '3177778888' }] }],
    cierre: null,
};

describe('actas', () => {
    it('keeps the event defaults where the document brings nothing', () => {
        const form = toForm(null, { titulo: 'ACTA DEL EVENTO', sitio: 'Parque', cierre: 'Siendo las 12:00 m.' });
        const merged = mergeRead(form, read);
        expect(merged.titulo).toBe('ACTA REUNIÓN VEREDA BERLÍN');
        expect(merged.sitio).toBe('Parque');
        expect(merged.cierre).toBe('Siendo las 12:00 m.');
        expect(merged.orden_dia.map(o => o.texto)).toEqual(['Seguridad', 'Vías']);
        expect(merged.participantes[0].nombre).toBe('MARIA EJEMPLO');
        expect(merged.participantes[0].municipio).toBe('');
    });

    it('drops empty rows and blanks from the payload', () => {
        const form = toForm(read);
        form.orden_dia.push({ key: 'x', texto: '   ' });
        form.participantes.push({ ...form.participantes[0], key: 'y', nombre: ' ' });
        form.compromisos[0].responsables.push({ key: 'z', nombre: '', telefono: '1', lider_id: null });
        const payload = toPayload(form);
        expect(payload.orden_dia).toEqual(['Seguridad', 'Vías']);
        expect(payload.participantes).toHaveLength(1);
        expect(payload.participantes[0]).toMatchObject({ nombre: 'MARIA EJEMPLO', municipio: null, telefono: '3104445566', lider_id: null });
        expect(payload.compromisos[0].responsables).toEqual([{ nombre: 'SANDRA', telefono: '3177778888', lider_id: null, sugerencia_rechazada: null }]);
        expect(payload.sitio).toBeNull();
        expect(payload.hora_inicio).toBe('10:45');
    });

    it('asks only about people without a ficha and applies the answers', () => {
        const payload = toPayload(toForm(read));
        payload.participantes.push({ nombre: 'Ya vinculado', lider_id: 'L1' });
        payload.participantes.push({ nombre: 'Asistente con ficha', attendee_id: 'A1' });
        const req = suggestionRequest(payload, [{ id: 'A1', lider_id: 'L9' }]);
        expect(req.body.participantes).toEqual([{ nombre: 'MARIA EJEMPLO', telefono: '3104445566', cedula: null }]);
        expect(req.body.responsables).toEqual([{ nombre: 'SANDRA', telefono: '3177778888' }]);

        const list = suggestionList({
            participantes: { 0: { id: 'L2', nombre: 'Maria Ejemplo Gomez', por: 'telefono' } },
            responsables: { 0: { id: 'L3', nombre: 'Sandra Pachon', por: 'telefono' } },
        }, req, payload);
        expect(list.map(s => [s.kind, s.nombre])).toEqual([['participante', 'MARIA EJEMPLO'], ['responsable', 'SANDRA']]);

        const next = applyAnswers(payload, [{ ...list[0], yes: true }, { ...list[1], yes: false }]);
        expect(next.participantes[0].lider_id).toBe('L2');
        expect(next.compromisos[0].responsables[0].lider_id).toBeNull();
        expect(next.compromisos[0].responsables[0].sugerencia_rechazada).toBe('L3');
        expect(payload.participantes[0].lider_id).toBeNull();

        // A "no" is not asked again; an unanswered one stays pending (asked again before confirming).
        expect(suggestionList({ responsables: { 0: { id: 'L3', por: 'telefono' } } }, suggestionRequest(next), next)).toEqual([]);
        const pending = applyAnswers(payload, [{ ...list[0], yes: undefined }]);
        expect(pending.participantes[0]).toMatchObject({ lider_id: null, sugerencia_rechazada: null });
    });

    it('does not ask about a cédula match: the server links it', () => {
        const payload = toPayload(toForm(read));
        const req = suggestionRequest(payload);
        expect(suggestionList({ participantes: { 0: { id: 'L2', por: 'cedula' } } }, req, payload)).toEqual([]);
    });

    it('adds an attendee as a participant with its data', () => {
        expect(participanteFromAsistente({ id: 'A1', nombre: 'Ana Pérez', telefono: '300', cedula: '123' }, 'GIRÓN'))
            .toMatchObject({ nombre: 'Ana Pérez', telefono: '300', cedula: '123', municipio: 'GIRÓN', attendee_id: 'A1', lider_id: null });
    });

    it('builds the export query and explains errors', () => {
        expect(compromisosQuery({ estado: 'pendiente', vencidos: null, q: '' })).toBe('?estado=pendiente');
        expect(compromisosQuery({})).toBe('');
        expect(errorFor(422, { errors: { archivo: ['El archivo pesa más de 10 MB.'] } })).toBe('El archivo pesa más de 10 MB.');
        expect(errorFor(404, null)).toMatch(/Recargue/);
    });
});
