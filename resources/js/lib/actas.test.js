import { describe, expect, it } from 'vitest';
import {
    CONFLICT_MESSAGE, applyAnswers, canCorrect, compromisoFaltantes, compromisosQuery, correctionLines, correctionPayload, correctionSummary, correctionUrl,
    compromisosBloqueados, confirmMessage, correctionDirty, correctionMessage, deleteActaMessage, errorFor, restoreCompromisos, rowError, historialItems, indicadores, isConflict, mergeRead, participanteFaltantes, participanteFromAsistente, sugeridoPorNombre,
    suggestionList, suggestionRequest, toForm, toPayload,
} from './actas';

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
        expect(req.body.participantes).toEqual([{ nombre: 'MARIA EJEMPLO', telefono: '3104445566', cedula: null, municipio: 'RIONEGRO' }]);
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

    it('explains what deleting a confirmed acta undoes', () => {
        const msg = deleteActaMessage({ asistentes: 3, asistentes_conservados: 1, fichas_borrar: 2, fichas_conservar: 1, vinculadas: 1, compromisos: 2, avances: 1 });
        expect(msg).toContain('Se quitan 3 asistentes que entraron');
        expect(msg).toContain('Se conservan 1 asistente que ya estaba');
        expect(msg).toContain('Se borran 2 fichas creadas');
        expect(msg).toContain('Se conservan 1 ficha creada que ya tiene otra información');
        expect(msg).toContain('1 ficha que ya existía');
        expect(msg).toContain('Se eliminan 2 compromisos con 1 avance');
        expect(deleteActaMessage({ asistentes: 0 })).toContain('No se quita ningún asistente');
    });
});

describe('corrección de actas confirmadas (contrato §14, 2026-11-22)', () => {
    const confirmed = {
        id: 'a1', estado: 'confirmada', version: 2, titulo: 'Acta', fecha: '2026-11-12', municipio: 'GIRÓN', sitio: null, hora_inicio: null, hora_fin: null,
        introduccion: null, orden_dia: [], desarrollo: [{ titulo: 'Vías', texto: 'a' }], cierre: null,
        participantes: [
            { id: 'p1', nombre: 'Ana', perfil: null, municipio: null, telefono: '300', cedula: null, lider_id: 'L1', attendee_id: null },
            { id: 'p2', nombre: 'Luis', perfil: null, municipio: null, telefono: null, cedula: null, lider_id: 'L2', attendee_id: null },
        ],
        compromisos: [
            { id: 'c1', descripcion: 'Uno', fecha_limite: null, recordar_el: null, avances: 1, responsables: [{ nombre: 'Sandra', telefono: '317', lider_id: null }] },
            { id: 'c2', descripcion: 'Dos', fecha_limite: null, recordar_el: null, avances: 0, responsables: [] },
        ],
    };

    it('finds nothing to change in the acta as it was', () => {
        const s = correctionSummary(confirmed, toPayload(toForm(confirmed)));
        expect(s.changed).toBe(false);
        expect(correctionLines(s)).toEqual(['Los avances de los compromisos se conservan.']);
    });

    it('counts added, removed and edited rows by id and the changed fields', () => {
        const form = toForm(confirmed);
        form.fecha = '2026-11-14';
        form.desarrollo[0].texto = 'b';
        form.participantes = [{ ...form.participantes[0], telefono: '301' }, { ...form.participantes[1], key: 'n', id: null, lider_id: null, nombre: 'Nueva' }, { ...form.participantes[1], key: 'm', id: null, lider_id: null, nombre: 'Otra' }];
        form.compromisos = [{ ...form.compromisos[0], fecha_limite: '2026-12-01' }, { ...form.compromisos[1], key: 'x', id: null, descripcion: 'Tres' }];
        const s = correctionSummary(confirmed, toPayload(form));
        expect(s).toMatchObject({ datos: ['fecha', 'desarrollo'], agregados: 2, agregadosConFicha: 0, quitados: 1, participantesEditados: 1, compromisosNuevos: 1, compromisosQuitados: 1, compromisosEditados: 1, changed: true });
        expect(correctionLines(s, true)).toEqual([
            'Se agregarán 2 personas al acta y a la organización.',
            '2 pasan a Votantes (si su cédula ya está, se unen a esa ficha).',
            'Se quitará 1 persona del acta: deja de figurar como miembro, pero su ficha en Votantes no se borra.',
            'Se corrigen los datos de 1 participante. Su ficha en Votantes solo se completa donde está vacía.',
            'Compromisos: 1 editado, 1 nuevo, 1 quitado.',
            'Cambia: fecha, desarrollo.',
            'Los avances de los compromisos se conservan.',
        ]);
    });

    it('sends the version of the acta and builds the route of events and organizations', () => {
        expect(correctionPayload({ titulo: 'x' }, confirmed)).toEqual({ titulo: 'x', version: 2 });
        expect(correctionPayload({ titulo: 'x' }, { ...confirmed, version: undefined }).version).toBe(0);
        expect(correctionUrl('/eventos/e1/actas', 'a1')).toBe('/eventos/e1/actas/a1/correccion');
        expect(correctionUrl('/empresarios/o1/actas', 'a1')).toBe('/empresarios/o1/actas/a1/correccion');
        expect(canCorrect({ edit_confirmed: true }, confirmed)).toBe(true);
        expect(canCorrect({ edit_confirmed: true }, { ...confirmed, estado: 'borrador' })).toBe(false);
        expect(canCorrect({ edit_confirmed: false }, confirmed)).toBe(false);
        expect(canCorrect(undefined, confirmed)).toBe(false);
    });

    it('explains a 409 with the server message or its own', () => {
        expect(errorFor(409, { message: 'Otra persona cambió esta acta.' })).toBe('Otra persona cambió esta acta.');
        expect(errorFor(409, null)).toBe(CONFLICT_MESSAGE);
        expect(isConflict({ status: 409 })).toBe(true);
        expect(isConflict({ status: 422 })).toBe(false);
    });

    it('does not ask again about an unchanged responsable of an existing compromiso', () => {
        const payload = toPayload(toForm(confirmed));
        expect(suggestionRequest(payload, [], confirmed).body.responsables).toEqual([]);
        payload.compromisos[0].responsables[0].telefono = '318';
        expect(suggestionRequest(payload, [], confirmed).body.responsables).toEqual([{ nombre: 'Sandra', telefono: '318' }]);
        // Without the original (borrador), every responsable without ficha is asked, as before.
        expect(suggestionRequest(toPayload(toForm(confirmed))).body.responsables).toHaveLength(1);
    });

    it('decides what a participant lacks: the ficha when the server says, the row otherwise', () => {
        expect(participanteFaltantes({ cedula: '', telefono: '', municipio: '', por_completar: ['cedula'] })).toEqual(['cedula']);
        expect(participanteFaltantes({ cedula: '1', por_completar: ['cedula'] })).toEqual([]);
        expect(participanteFaltantes({ cedula: '', telefono: '300', municipio: ' ' })).toEqual(['cedula', 'municipio']);
        expect(participanteFaltantes({ cedula: '', telefono: '', municipio: '', por_completar: ['otro'] })).toEqual([]);
        expect(compromisoFaltantes({ fecha_limite: '', responsables: [{ nombre: ' ' }] })).toEqual(['fecha_limite', 'responsable']);
        expect(compromisoFaltantes({ fecha_limite: '2026-12-01', responsables: [{ nombre: 'Sandra' }] })).toEqual([]);
        const form = toForm(confirmed);
        expect(indicadores(form)).toEqual({ participantes: 2, compromisos: 2 });
    });

    it('recognizes «Sugerido por nombre» and reads the historial', () => {
        expect(sugeridoPorNombre({ por: 'nombre', motivo: 'nombre' })).toBe(true);
        expect(sugeridoPorNombre({ por: 'telefono', motivo: 'telefono' })).toBe(false);
        expect(sugeridoPorNombre(null)).toBe(false);
        expect(historialItems(undefined)).toBeNull();
        expect(historialItems([
            { id: 'h1', accion: 'corregida', fecha: '2026-11-14T09:30:00-05:00', usuario: null, resumen: 'Cambió: fecha' },
            { id: 'h2', accion: 'archivo', fecha: null, usuario: 'Ana', resumen: null },
        ])).toEqual([
            { key: 'h1', titulo: 'Corrigió el acta', fecha: '2026-11-14T09:30:00-05:00', usuario: '', resumen: 'Cambió: fecha' },
            { key: 'h2', titulo: 'Cambió el archivo original', fecha: null, usuario: 'Ana', resumen: '' },
        ]);
    });

    it('reads the compromisos that could not be removed and puts them back in place', () => {
        const body = { errors: { 'compromisos_quitados.c1': ['Tiene 1 avances'], 'participantes.0.cedula': ['otra'] } };
        expect(compromisosBloqueados(body)).toEqual({ c1: 'Tiene 1 avances' });
        expect(compromisosBloqueados(null)).toEqual({});
        const form = toForm(confirmed);
        form.compromisos = [{ ...form.compromisos[1], descripcion: 'Dos editado' }, { ...form.compromisos[1], key: 'n', id: null, descripcion: 'Nuevo' }];
        const restored = restoreCompromisos(form, confirmed, { c1: 'Tiene 1 avances' });
        expect(restored.compromisos.map(c => [c.id, c.descripcion, c.bloqueo ?? null])).toEqual([['c1', 'Uno', 'Tiene 1 avances'], ['c2', 'Dos editado', null], [null, 'Nuevo', null]]);
        expect(restoreCompromisos(form, confirmed, {})).toBe(form);
    });

    it('sends the municipio of each participant to the suggestions', () => {
        const payload = toPayload(toForm({ ...confirmed, participantes: [{ nombre: 'Sin Datos Nadie', municipio: 'GIRÓN' }, { nombre: 'Otro Sin Nada' }] }));
        expect(suggestionRequest(payload).body.participantes).toEqual([
            { nombre: 'Sin Datos Nadie', telefono: null, cedula: null, municipio: 'GIRÓN' },
            { nombre: 'Otro Sin Nada', telefono: null, cedula: null, municipio: 'GIRÓN' },
        ]);
    });

    it('counts apart the added people who already have a ficha', () => {
        const form = toForm(confirmed);
        form.participantes.push({ ...form.participantes[0], key: 'a', id: null, nombre: 'Con ficha', lider_id: 'L9' });
        form.participantes.push({ ...form.participantes[0], key: 'b', id: null, nombre: 'Asistente', lider_id: null, attendee_id: 'AT1' });
        form.participantes.push({ ...form.participantes[0], key: 'c', id: null, nombre: 'Nueva', lider_id: null, attendee_id: null });
        const s = correctionSummary(confirmed, toPayload(form), [{ id: 'AT1', lider_id: 'L7' }]);
        expect(s).toMatchObject({ agregados: 3, agregadosConFicha: 2 });
        expect(correctionLines(s)).toEqual([
            'Se agregarán 3 personas al acta y al evento.',
            '2 ya están en Votantes: se unen a sus fichas.',
            '1 pasa a Votantes (si su cédula ya está, se une a esa ficha).',
            'Los avances de los compromisos se conservan.',
        ]);
    });

    it('writes the result of a confirmation and a correction with plurals and without zeros', () => {
        expect(confirmMessage({ message: 'srv', resumen: { participantes: 1, vinculados: 0, fichas_creadas: 1, asistentes_nuevos: 1, compromisos: 1 } }))
            .toBe('Acta confirmada. 1 participante: 1 ficha nueva, 1 agregado como asistente del evento. 1 compromiso.');
        expect(confirmMessage({ message: 'srv', resumen: { participantes: 2, vinculados: 2, fichas_creadas: 0, miembros_nuevos: 2, compromisos: 0 } }, true))
            .toBe('Acta confirmada. 2 participantes: 2 ya estaban en Votantes, 2 agregados como miembros de la organización.');
        expect(confirmMessage({ message: 'Acta confirmada (servidor).' })).toBe('Acta confirmada (servidor).');
        expect(correctionMessage({ resumen: { participantes_agregados: 1, fichas_creadas: 1, vinculados: 0, participantes_quitados: 1, asistentes_quitados: 1, participantes_editados: 0, compromisos_agregados: 0, compromisos_editados: 1, compromisos_quitados: 0 } }))
            .toBe('Acta corregida. 1 participante agregado (1 ficha nueva). 1 participante quitado. Se quitó 1 asistente que había agregado esta acta; su ficha en Votantes se conserva. Compromisos: 1 editado.');
        expect(correctionMessage({ resumen: { participantes_agregados: 0, participantes_quitados: 0, compromisos_editados: 0, cambios: ['sitio'] } })).toBe('Acta corregida.');
        expect(correctionMessage({ message: 'srv' })).toBe('srv');
    });

    it('maps a 422 on a payload row to the form row it came from', () => {
        const form = toForm(confirmed);
        form.participantes.splice(1, 0, { ...form.participantes[0], key: 'blank', id: null, nombre: '  ' });
        const err = rowError({ errors: { 'participantes.1.cedula': ['Esa cédula ya pertenece a otra persona en Votantes.'] } }, form);
        // Payload row 1 is Luis, the third row of the form (the blank row is not sent).
        expect(err).toEqual({ key: form.participantes[2].key, field: 'cedula', message: 'Esa cédula ya pertenece a otra persona en Votantes.', summary: 'Participante 3: Esa cédula ya pertenece a otra persona en Votantes.' });
        expect(rowError({ errors: { 'participantes.0.lider_id': ['x'] } }, form).field).toBe('nombre');
        expect(rowError({ errors: { 'compromisos.0.responsables.0.lider_id': ['y'] } }, form).field).toBe(`responsable:${form.compromisos[0].responsables[0].key}`);
        expect(rowError({ errors: { 'compromisos.1.fecha_limite': ['z'] } }, form)).toMatchObject({ key: form.compromisos[1].key, field: 'fecha_limite', summary: 'Compromiso 2: z' });
        expect(rowError({ errors: { titulo: ['t'] } }, form)).toBeNull();
        expect(rowError({ errors: { 'participantes.9.cedula': ['t'] } }, form)).toBeNull();
    });

    it('knows when a correction has something to lose', () => {
        const form = toForm(confirmed);
        expect(correctionDirty(confirmed, form, null)).toBe(false);
        expect(correctionDirty(confirmed, form, new File(['x'], 'a.pdf'))).toBe(true);
        expect(correctionDirty(confirmed, { ...form, sitio: 'Otro' }, null)).toBe(true);
    });
});
