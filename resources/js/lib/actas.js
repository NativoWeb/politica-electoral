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
export function suggestionRequest(payload, asistentes = []) {
    const withFicha = new Set(asistentes.filter(a => a.lider_id).map(a => a.id));
    const participantes = [];
    const pKeys = [];
    payload.participantes.forEach((p, i) => {
        if (p.lider_id || p.id || (p.attendee_id && withFicha.has(p.attendee_id))) return;
        participantes.push({ nombre: p.nombre, telefono: p.telefono, cedula: p.cedula });
        pKeys.push(i);
    });
    const responsables = [];
    const rKeys = [];
    payload.compromisos.forEach((c, ci) => c.responsables.forEach((r, ri) => {
        if (r.lider_id) return;
        responsables.push({ nombre: r.nombre, telefono: r.telefono });
        rKeys.push([ci, ri]);
    }));
    return { body: { participantes, responsables }, pKeys, rKeys };
}

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
