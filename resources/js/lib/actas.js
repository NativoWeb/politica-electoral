import { getCsrfToken } from '@/lib/api';

// Actas de reunión and compromisos (docs/eventos-api.md §14): form state, payloads and labels.

export const ESTADOS = {
    pendiente: 'Pendiente',
    en_curso: 'En curso',
    cumplido: 'Cumplido',
    no_cumplido: 'No cumplido',
};

export const ESTADO_CLASSES = {
    pendiente: 'bg-amber-50 border-amber-300 text-amber-800',
    en_curso: 'bg-blue-50 border-blue-300 text-[var(--color-primary)]',
    cumplido: 'bg-emerald-50 border-emerald-300 text-emerald-800',
    no_cumplido: 'bg-gray-100 border-gray-300 text-gray-700',
};

let seq = 0;
export const newKey = () => `k${Date.now().toString(36)}${(seq += 1)}`;

export const emptyParticipante = () => ({ key: newKey(), id: null, nombre: '', perfil: '', municipio: '', telefono: '', cedula: '', lider_id: null, attendee_id: null, sugerencia_rechazada: null });
export const emptyResponsable = () => ({ key: newKey(), nombre: '', telefono: '', lider_id: null, sugerencia_rechazada: null });
export const emptyCompromiso = () => ({ key: newKey(), id: null, descripcion: '', fecha_limite: '', recordar_el: '', estado: 'pendiente', responsables: [emptyResponsable()] });

const str = v => (v === null || v === undefined ? '' : String(v));

// Form state from a saved acta, from what was read of a .docx, or from the event's defaults.
export function toForm(acta, defaults = {}) {
    const a = acta ?? {};
    const pick = field => str(a[field] ?? defaults[field]);
    return {
        titulo: pick('titulo'),
        fecha: pick('fecha'),
        municipio: pick('municipio'),
        sitio: pick('sitio'),
        hora_inicio: pick('hora_inicio'),
        hora_fin: pick('hora_fin'),
        introduccion: str(a.introduccion),
        orden_dia: (a.orden_dia ?? []).map(texto => ({ key: newKey(), texto: str(texto) })),
        desarrollo: (a.desarrollo ?? []).map(d => ({ key: newKey(), titulo: str(d.titulo), texto: str(d.texto) })),
        participantes: (a.participantes ?? []).map(p => ({ ...emptyParticipante(), ...nullsToEmpty(p), key: newKey() })),
        compromisos: (a.compromisos ?? []).map(c => ({
            ...emptyCompromiso(),
            ...nullsToEmpty(c),
            key: newKey(),
            responsables: (c.responsables ?? []).map(r => ({ ...emptyResponsable(), ...nullsToEmpty(r), key: newKey() })),
        })),
        cierre: pick('cierre'),
    };
}

function nullsToEmpty(obj) {
    const out = {};
    Object.entries(obj ?? {}).forEach(([k, v]) => {
        out[k] = v === null && !['id', 'lider_id', 'attendee_id', 'sugerencia_rechazada'].includes(k) ? '' : v;
    });
    return out;
}

// What a .docx read fills on top of the current form: the header fields only when the document brings them.
export function mergeRead(form, read) {
    const next = toForm(read);
    const keep = field => (str(read?.[field]).trim() !== '' ? next[field] : form[field]);
    return {
        ...next,
        titulo: keep('titulo'),
        fecha: keep('fecha'),
        municipio: keep('municipio'),
        sitio: keep('sitio'),
        hora_inicio: keep('hora_inicio'),
        hora_fin: keep('hora_fin'),
        cierre: keep('cierre'),
    };
}

export function participanteFromAsistente(a, municipio) {
    return { ...emptyParticipante(), nombre: str(a.nombre), telefono: str(a.telefono), cedula: str(a.cedula), municipio: str(municipio), lider_id: null, attendee_id: a.id };
}

const blankToNull = v => (str(v).trim() === '' ? null : str(v).trim());

// Body of POST/PUT /eventos/{id}/actas: empty rows are dropped.
export function toPayload(form) {
    return {
        titulo: str(form.titulo).trim(),
        fecha: blankToNull(form.fecha),
        municipio: blankToNull(form.municipio),
        sitio: blankToNull(form.sitio),
        hora_inicio: blankToNull(form.hora_inicio)?.slice(0, 5) ?? null,
        hora_fin: blankToNull(form.hora_fin)?.slice(0, 5) ?? null,
        introduccion: blankToNull(form.introduccion),
        orden_dia: form.orden_dia.map(o => str(o.texto).trim()).filter(Boolean),
        desarrollo: form.desarrollo.filter(d => str(d.titulo).trim() || str(d.texto).trim()).map(d => ({ titulo: str(d.titulo).trim(), texto: str(d.texto).trim() })),
        participantes: form.participantes.filter(p => str(p.nombre).trim()).map(p => ({
            id: p.id || null,
            nombre: str(p.nombre).trim(),
            perfil: blankToNull(p.perfil),
            municipio: blankToNull(p.municipio),
            telefono: blankToNull(p.telefono),
            cedula: blankToNull(p.cedula),
            lider_id: p.lider_id || null,
            attendee_id: p.attendee_id || null,
            sugerencia_rechazada: p.sugerencia_rechazada || null,
        })),
        compromisos: form.compromisos.filter(c => str(c.descripcion).trim()).map(c => ({
            id: c.id || null,
            descripcion: str(c.descripcion).trim(),
            fecha_limite: blankToNull(c.fecha_limite),
            recordar_el: blankToNull(c.recordar_el),
            responsables: c.responsables.filter(r => str(r.nombre).trim()).map(r => ({
                nombre: str(r.nombre).trim(), telefono: blankToNull(r.telefono), lider_id: r.lider_id || null, sugerencia_rechazada: r.sugerencia_rechazada || null,
            })),
        })),
        cierre: blankToNull(form.cierre),
    };
}

// Who still needs a "¿Es esta persona?": participants and responsables without a ficha (participants of an attendee
// that already has one are linked by the server). Keys let the answers go back to the right row.
// original (correcting a confirmed acta): a responsable left as it was in the same compromiso is not asked again.
export function suggestionRequest(payload, asistentes = [], original = null) {
    const withFicha = new Set(asistentes.filter(a => a.lider_id).map(a => a.id));
    const unchanged = new Set((original?.compromisos ?? []).flatMap(c => (c.responsables ?? []).map(r => responsableKey(c.id, r))));
    const participantes = [];
    const pKeys = [];
    payload.participantes.forEach((p, i) => {
        if (p.lider_id || p.id || (p.attendee_id && withFicha.has(p.attendee_id))) return;
        // municipio (the row's, else the acta's): a person without cédula nor phone is suggested by name there.
        participantes.push({ nombre: p.nombre, telefono: p.telefono, cedula: p.cedula, municipio: p.municipio ?? payload.municipio ?? null });
        pKeys.push(i);
    });
    const responsables = [];
    const rKeys = [];
    payload.compromisos.forEach((c, ci) => c.responsables.forEach((r, ri) => {
        if (r.lider_id || (c.id && unchanged.has(responsableKey(c.id, r)))) return;
        responsables.push({ nombre: r.nombre, telefono: r.telefono });
        rKeys.push([ci, ri]);
    }));
    return { body: { participantes, responsables }, pKeys, rKeys };
}

const responsableKey = (compromisoId, r) => `${compromisoId}|${str(r.nombre).trim()}|${str(r.telefono).trim()}`;

// [{kind, path, nombre, lider}] from the server's answer, ready to ask one by one.
export function suggestionList(answer, request, payload) {
    const list = [];
    Object.entries(answer?.participantes ?? {}).forEach(([i, lider]) => {
        const index = request.pKeys[Number(i)];
        // The same cédula is linked by the server without asking; a suggestion already answered "no" is not asked again.
        if (index === undefined || lider?.por === 'cedula' || payload.participantes[index].sugerencia_rechazada === lider?.id) return;
        list.push({ kind: 'participante', index, nombre: payload.participantes[index].nombre, lider });
    });
    Object.entries(answer?.responsables ?? {}).forEach(([i, lider]) => {
        const path = request.rKeys[Number(i)];
        if (!path || payload.compromisos[path[0]].responsables[path[1]].sugerencia_rechazada === lider?.id) return;
        list.push({ kind: 'responsable', path, nombre: payload.compromisos[path[0]].responsables[path[1]].nombre, lider });
    });
    return list;
}

// The payload with the answers (answers: [{...suggestion, yes: true|false|undefined}]): yes keeps the ficha as the
// choice, no remembers the rejected one so it is not asked again, unanswered stays pending.
export function applyAnswers(payload, answers) {
    const next = structuredClone(payload);
    answers.filter(a => a.yes !== undefined).forEach(a => {
        const row = a.kind === 'participante' ? next.participantes[a.index] : next.compromisos[a.path[0]].responsables[a.path[1]];
        if (a.yes) row.lider_id = a.lider.id;
        else row.sugerencia_rechazada = a.lider.id;
    });
    return next;
}

export function formatDate(iso) {
    if (!iso) return '';
    const [y, m, d] = String(iso).slice(0, 10).split('-');
    return d && m && y ? `${d}/${m}/${y}` : '';
}

export function formatDateTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString('es-CO', { timeZone: 'America/Bogota', day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function serverMessage(body, fallback) {
    const errors = body?.errors ? Object.values(body.errors).flat() : [];
    return errors[0] || body?.message || fallback;
}

export function errorFor(status, body) {
    if (status === 403) return serverMessage(body, 'No tiene permiso para hacer este cambio.');
    if (status === 404) return 'Ya no existe o no lo puede ver. Recargue la página.';
    if (status === 409) return serverMessage(body, CONFLICT_MESSAGE);
    if (status === 413) return 'El archivo pesa más de lo permitido (10 MB).';
    if (status === 419) return 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.';
    if (status === 422) return serverMessage(body, 'Revise los datos del formulario.');
    if (status === 429) return 'Demasiados intentos seguidos. Espere un minuto e intente de nuevo.';
    return 'No se pudo completar. Intente de nuevo en un momento.';
}

// fetch with the session and the CSRF token; JSON body or FormData. Resolves {ok, status, body}; a network failure
// resolves {ok: false, status: 0}.
export async function request(url, { method = 'GET', json, form } = {}) {
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() };
    let body;
    if (json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(json);
    } else if (form) {
        body = form;
    }
    try {
        const r = await fetch(url, { method, headers, body, credentials: 'same-origin', cache: 'no-store' });
        const data = await r.json().catch(() => null);
        return { ok: r.ok, status: r.status, body: data };
    } catch {
        return { ok: false, status: 0, body: null };
    }
}

export function failure(res, fallback) {
    if (res.status === 0) return 'No hay conexión con el servidor. Intente de nuevo.';
    return res.status ? errorFor(res.status, res.body) : fallback;
}

export function compromisosQuery(filters) {
    const params = new URLSearchParams();
    Object.entries(filters ?? {}).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') params.set(k, v); });
    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

const count = (n, one, many) => (n === 1 ? `1 ${one}` : `${n} ${many}`);

// What deleting a confirmed acta undoes (preview from GET /eventos/{id}/actas/{acta}/eliminar).
export function deleteActaMessage(p = {}) {
    const parts = ['Se elimina el acta con sus participantes y archivos.'];
    parts.push(p.asistentes
        ? `Se quitan ${count(p.asistentes, 'asistente que entró', 'asistentes que entraron')} al evento por esta acta.`
        : 'No se quita ningún asistente del evento.');
    if (p.asistentes_conservados) parts.push(`Se conservan ${count(p.asistentes_conservados, 'asistente que ya estaba', 'asistentes que ya estaban')} en el evento.`);
    if (p.fichas_borrar) parts.push(`Se borran ${count(p.fichas_borrar, 'ficha creada', 'fichas creadas')} al confirmarla, sin otra información.`);
    if (p.fichas_conservar) parts.push(`Se conservan ${count(p.fichas_conservar, 'ficha creada que ya tiene', 'fichas creadas que ya tienen')} otra información.`);
    if (p.vinculadas) parts.push(`${count(p.vinculadas, 'ficha que ya existía', 'fichas que ya existían')} en Votantes no se tocan.`);
    if (p.compromisos) parts.push(`Se eliminan ${count(p.compromisos, 'compromiso', 'compromisos')} con ${count(p.avances ?? 0, 'avance', 'avances')}.`);
    parts.push('Esto no se puede deshacer.');

    return parts.join(' ');
}

// ---------------------------------------------------------------------------------------------------------------------
// Corrección de un acta confirmada y datos por completar (docs/eventos-api.md §14, 2026-11-22). Everything read from
// the server for these features goes through this block, so a change in the contract is adapted here only.

export const CONFLICT_MESSAGE = 'Otra persona cambió esta acta mientras usted la corregía. Recargue la página para ver los cambios; lo suyo no se guardó.';

export const correctionUrl = (base, actaId) => `${base}/${actaId}/correccion`;

export const canCorrect = (can, acta) => acta?.estado === 'confirmada' && !!can?.edit_confirmed;

export const isConflict = res => res?.status === 409;

export function actaVersion(acta) {
    const v = Number(acta?.version ?? 0);
    return Number.isFinite(v) ? v : 0;
}

export const correctionPayload = (payload, acta) => ({ ...payload, version: actaVersion(acta) });

// «Sugerido por nombre»: the server found the ficha by name and municipio only (no cédula, no phone).
export const sugeridoPorNombre = lider => (lider?.motivo ?? lider?.por) === 'nombre';

export const FALTANTES = { cedula: 'cédula', telefono: 'teléfono', municipio: 'municipio' };

const blank = v => str(v).trim() === '';

// What a participant still lacks. A row from the server brings por_completar (for a row with ficha: what the FICHA
// lacks); what the row has now fills the ficha's empty field when saved, so it no longer counts. A row without
// por_completar (new, or edited so it may be another person) is judged by what is written in it.
export function participanteFaltantes(p) {
    const server = Array.isArray(p?.por_completar) ? p.por_completar.filter(k => k in FALTANTES) : null;
    return (server ?? Object.keys(FALTANTES)).filter(k => blank(p?.[k]));
}

// Same rule as the server's compromisos[i].por_completar, computed live from the form.
export function compromisoFaltantes(c) {
    const missing = [];
    if (blank(c?.fecha_limite)) missing.push('fecha_limite');
    if (!(c?.responsables ?? []).some(r => !blank(r.nombre))) missing.push('responsable');
    return missing;
}

export const COMPROMISO_FALTANTES = { fecha_limite: 'Sin fecha límite', responsable: 'Sin responsable' };

// «N participantes con datos por completar» and «N compromisos sin fecha límite o sin responsable».
export function indicadores(form) {
    return {
        participantes: form.participantes.filter(p => !blank(p.nombre) && participanteFaltantes(p).length > 0).length,
        compromisos: form.compromisos.filter(c => !blank(c.descripcion) && compromisoFaltantes(c).length > 0).length,
    };
}

const DATOS = {
    titulo: 'título', fecha: 'fecha', municipio: 'municipio', sitio: 'sitio', hora_inicio: 'hora de inicio', hora_fin: 'hora de fin',
    introduccion: 'introducción', orden_dia: 'orden del día', desarrollo: 'desarrollo', cierre: 'cierre',
};
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const participanteDatos = p => ['nombre', 'perfil', 'municipio', 'telefono', 'cedula'].map(k => p[k] ?? null);
const compromisoDatos = c => [c.descripcion, c.fecha_limite ?? null, c.recordar_el ?? null, c.responsables.map(r => [r.nombre, r.telefono ?? null])];

// What a correction changes: the confirmed acta against the payload to send, rows compared by id (as the server does).
export function correctionSummary(acta, payload, asistentes = []) {
    const withFicha = new Set(asistentes.filter(a => a.lider_id).map(a => a.id));
    const before = toPayload(toForm(acta));
    const datos = Object.keys(DATOS).filter(k => !same(before[k], payload[k])).map(k => DATOS[k]);
    const pBefore = new Map(before.participantes.filter(p => p.id).map(p => [p.id, p]));
    const pIds = new Set(payload.participantes.map(p => p.id).filter(Boolean));
    const cBefore = new Map(before.compromisos.filter(c => c.id).map(c => [c.id, c]));
    const cIds = new Set(payload.compromisos.map(c => c.id).filter(Boolean));
    const summary = {
        datos,
        agregados: payload.participantes.filter(p => !p.id).length,
        agregadosConFicha: payload.participantes.filter(p => !p.id && (p.lider_id || (p.attendee_id && withFicha.has(p.attendee_id)))).length,
        quitados: [...pBefore.keys()].filter(id => !pIds.has(id)).length,
        participantesEditados: payload.participantes.filter(p => p.id && pBefore.has(p.id) && !same(participanteDatos(p), participanteDatos(pBefore.get(p.id)))).length,
        compromisosNuevos: payload.compromisos.filter(c => !c.id).length,
        compromisosQuitados: [...cBefore.keys()].filter(id => !cIds.has(id)).length,
        compromisosEditados: payload.compromisos.filter(c => c.id && cBefore.has(c.id) && !same(compromisoDatos(c), compromisoDatos(cBefore.get(c.id)))).length,
    };
    summary.changed = datos.length > 0 || Object.entries(summary).some(([k, v]) => k !== 'datos' && k !== 'agregadosConFicha' && v > 0);
    return summary;
}

// The lines of the dialog shown before saving a correction.
export function correctionLines(s, isOrg = false) {
    const lines = [];
    if (s.agregados) {
        const destino = isOrg ? 'a la organización' : 'al evento';
        const conFicha = s.agregadosConFicha ?? 0;
        const nuevas = s.agregados - conFicha;
        lines.push(`${s.agregados === 1 ? 'Se agregará' : 'Se agregarán'} ${count(s.agregados, 'persona', 'personas')} al acta y ${destino}.`);
        if (conFicha) lines.push(conFicha === 1 ? '1 ya está en Votantes: se une a su ficha.' : `${conFicha} ya están en Votantes: se unen a sus fichas.`);
        if (nuevas) lines.push(nuevas === 1 ? '1 pasa a Votantes (si su cédula ya está, se une a esa ficha).' : `${nuevas} pasan a Votantes (si su cédula ya está, se unen a esa ficha).`);
    }
    if (s.quitados) {
        const rol = isOrg ? ['miembro', 'miembros'] : ['asistente', 'asistentes'];
        lines.push(s.quitados === 1
            ? `Se quitará 1 persona del acta: deja de figurar como ${rol[0]}, pero su ficha en Votantes no se borra.`
            : `Se quitarán ${s.quitados} personas del acta: dejan de figurar como ${rol[1]}, pero su ficha en Votantes no se borra.`);
    }
    if (s.participantesEditados) lines.push(`Se corrigen los datos de ${count(s.participantesEditados, 'participante', 'participantes')}. Su ficha en Votantes solo se completa donde está vacía.`);
    const comp = [
        s.compromisosEditados && count(s.compromisosEditados, 'editado', 'editados'),
        s.compromisosNuevos && count(s.compromisosNuevos, 'nuevo', 'nuevos'),
        s.compromisosQuitados && count(s.compromisosQuitados, 'quitado', 'quitados'),
    ].filter(Boolean);
    if (comp.length) lines.push(`Compromisos: ${comp.join(', ')}.`);
    if (s.datos.length) lines.push(`Cambia: ${s.datos.join(', ')}.`);
    lines.push('Los avances de los compromisos se conservan.');
    return lines;
}

const ACCIONES = { confirmada: 'Confirmó el acta', corregida: 'Corrigió el acta', archivo: 'Cambió el archivo original' };

// «Historial de cambios», newest first as the server sends it. null = the page brought no historial.
export function historialItems(raw) {
    if (!Array.isArray(raw)) return null;
    return raw.map((e, i) => ({
        key: e?.id ?? `h${i}`,
        titulo: ACCIONES[e?.accion] ?? 'Cambio en el acta',
        fecha: e?.fecha ?? null,
        usuario: str(e?.usuario),
        resumen: str(e?.resumen),
    }));
}

// 422 compromisos_quitados.{id}: compromisos with avances that cannot be removed → {id: message}.
export function compromisosBloqueados(body) {
    const out = {};
    Object.entries(body?.errors ?? {}).forEach(([key, messages]) => {
        const m = key.match(/^compromisos_quitados\.(.+)$/);
        if (m) out[m[1]] = [messages].flat()[0] ?? '';
    });
    return out;
}

// Puts the refused compromisos back in the form (where they were in the acta) with the server's message beside them.
export function restoreCompromisos(form, acta, bloqueos) {
    const ids = Object.keys(bloqueos);
    if (!ids.length) return form;
    const original = toForm(acta).compromisos;
    const kept = form.compromisos.filter(c => !ids.includes(c.id));
    const restored = [];
    original.forEach(c => {
        if (ids.includes(c.id)) restored.push({ ...c, avances: Math.max(Number(c.avances) || 0, 1), bloqueo: bloqueos[c.id] });
        else {
            const current = kept.find(k => k.id && k.id === c.id);
            if (current) restored.push(current);
        }
    });
    return { ...form, compromisos: [...restored, ...kept.filter(c => !c.id)] };
}

// O-2: the server's resumen of a confirmation or a correction, with plurals and without the parts in zero. Without a
// resumen (an older server), its own message.
const nz = (n, one, many) => (Number(n) > 0 ? count(Number(n), one, many) : null);
const joinParts = parts => parts.filter(Boolean).join(', ');

export function confirmMessage(body, isOrg = false) {
    const r = body?.resumen;
    if (!r) return body?.message ?? 'Acta confirmada.';
    const quien = isOrg ? ['miembro de la organización', 'miembros de la organización'] : ['asistente del evento', 'asistentes del evento'];
    const parts = [
        nz(r.vinculados, 'ya estaba en Votantes', 'ya estaban en Votantes'),
        nz(r.fichas_creadas, 'ficha nueva', 'fichas nuevas'),
        nz(r.asistentes_nuevos ?? r.miembros_nuevos, `agregado como ${quien[0]}`, `agregados como ${quien[1]}`),
    ];
    const lines = ['Acta confirmada.'];
    if (r.participantes) lines.push(`${count(r.participantes, 'participante', 'participantes')}${joinParts(parts) ? `: ${joinParts(parts)}` : ''}.`);
    if (r.compromisos) lines.push(`${count(r.compromisos, 'compromiso', 'compromisos')}.`);
    return lines.join(' ');
}

export function correctionMessage(body, isOrg = false) {
    const r = body?.resumen;
    if (!r) return body?.message ?? 'Acta corregida.';
    const lines = ['Acta corregida.'];
    const agregados = Number(r.participantes_agregados) || 0;
    if (agregados) {
        const detalle = joinParts([nz(r.fichas_creadas, 'ficha nueva', 'fichas nuevas'), nz(r.vinculados, 'ya estaba en Votantes', 'ya estaban en Votantes')]);
        lines.push(`${count(agregados, 'participante agregado', 'participantes agregados')}${detalle ? ` (${detalle})` : ''}.`);
    }
    if (r.participantes_quitados) lines.push(`${count(r.participantes_quitados, 'participante quitado', 'participantes quitados')}.`);
    const quitadosEvento = Number(r.asistentes_quitados ?? r.miembros_quitados) || 0;
    if (quitadosEvento) {
        const quien = isOrg ? ['miembro', 'miembros'] : ['asistente', 'asistentes'];
        lines.push(quitadosEvento === 1 ? `Se quitó 1 ${quien[0]} que había agregado esta acta; su ficha en Votantes se conserva.` : `Se quitaron ${quitadosEvento} ${quien[1]} que había agregado esta acta; sus fichas en Votantes se conservan.`);
    }
    if (r.participantes_editados) lines.push(`${count(r.participantes_editados, 'participante corregido', 'participantes corregidos')}.`);
    const comp = joinParts([
        nz(r.compromisos_agregados, 'nuevo', 'nuevos'),
        nz(r.compromisos_editados, 'editado', 'editados'),
        nz(r.compromisos_quitados, 'quitado', 'quitados'),
    ]);
    if (comp) lines.push(`Compromisos: ${comp}.`);
    return lines.join(' ');
}

// BUG-01: a 422 on one row (participantes.N.campo / compromisos.N.campo, N = index in the PAYLOAD) as the row of the
// FORM it came from, so the screen can say which one and mark the field. null = no row error.
const keptParticipantes = form => form.participantes.filter(p => str(p.nombre).trim());
const keptCompromisos = form => form.compromisos.filter(c => str(c.descripcion).trim());

export function rowError(body, form) {
    const entry = Object.entries(body?.errors ?? {}).find(([key]) => /^(participantes|compromisos)\.\d+\./.test(key));
    if (!entry) return null;
    const [key, messages] = entry;
    const [, section, n, rest] = key.match(/^(participantes|compromisos)\.(\d+)\.(.+)$/);
    const row = (section === 'participantes' ? keptParticipantes(form) : keptCompromisos(form))[Number(n)];
    if (!row) return null;
    const rows = section === 'participantes' ? form.participantes : form.compromisos;
    const numero = rows.indexOf(row) + 1;
    let field = rest;
    if (section === 'participantes' && !['nombre', 'perfil', 'municipio', 'telefono', 'cedula'].includes(rest)) field = 'nombre';
    if (section === 'compromisos') {
        const resp = rest.match(/^responsables\.(\d+)\./);
        if (resp) {
            const kept = row.responsables.filter(r => str(r.nombre).trim());
            const r = kept[Number(resp[1])];
            field = r ? `responsable:${r.key}` : 'descripcion';
        } else if (!['descripcion', 'fecha_limite', 'recordar_el'].includes(rest)) field = 'descripcion';
    }
    const text = [messages].flat()[0] ?? '';
    return {
        key: row.key,
        field,
        message: text,
        summary: `${section === 'participantes' ? 'Participante' : 'Compromiso'} ${numero}: ${text}`,
    };
}

// The id of a field of a row, the same in the components and in the page that focuses it.
export const fieldId = (rowKey, field) => `acta-${rowKey}-${String(field).replace(/[^a-z0-9_-]/gi, '-')}`;

// BUG-09: whether a correction in progress has something to lose.
export const correctionDirty = (acta, form, file) => !!file || correctionSummary(acta, toPayload(form)).changed;
