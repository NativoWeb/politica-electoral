// Shared logic of the Eventos especiales module (contract in docs/eventos-api.md).

export const TZ = 'America/Bogota';

export const EVENT_STATUS = {
    borrador: { label: 'Borrador', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
    abierto: { label: 'Abierto', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
    cerrado: { label: 'Cerrado', cls: 'bg-blue-50 border-blue-200 text-[var(--color-primary)]' },
    cancelado: { label: 'Cancelado', cls: 'bg-red-50 border-red-200 text-red-700' },
};

export const LINK_STATUS = {
    vinculado: { label: 'Ya estaba en la plataforma', cls: 'bg-blue-50 border-blue-200 text-[var(--color-primary)]' },
    pendiente: { label: 'Pendiente de aprobar', cls: 'bg-amber-50 border-amber-300 text-amber-800' },
    aprobado: { label: 'Aprobado', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
    descartado: { label: 'Descartado', cls: 'bg-gray-100 border-gray-300 text-gray-600' },
    sin_cedula: { label: 'Sin cédula', cls: 'bg-gray-100 border-gray-300 text-gray-600' },
    sin_autorizacion: { label: 'Pendiente de aprobar', cls: 'bg-amber-50 border-amber-300 text-amber-800' },
};

export const MONTHS = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

export function formatEventDate(iso, { withTime = true } = {}) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const day = new Intl.DateTimeFormat('es-CO', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    if (!withTime) return day;
    const time = new Intl.DateTimeFormat('es-CO', { timeZone: TZ, hour: 'numeric', minute: '2-digit', hour12: true }).format(date);

    return `${day} · ${time}`;
}

// Start date and time, plus the end time (same day) or the full end date (another day) when there is one.
export function formatEventRange(startIso, endIso) {
    const start = formatEventDate(startIso);
    if (!start || !endIso) return start;
    const end = new Date(endIso);
    if (Number.isNaN(end.getTime())) return start;
    const sameDay = formatEventDate(startIso, { withTime: false }) === formatEventDate(endIso, { withTime: false });
    if (sameDay) {
        const time = new Intl.DateTimeFormat('es-CO', { timeZone: TZ, hour: 'numeric', minute: '2-digit', hour12: true }).format(end);
        return `${start} a ${time}`;
    }

    return `${start} hasta el ${formatEventDate(endIso)}`;
}

export function formatShortDate(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('es-CO', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }).format(date);
}

// The server sends ISO dates with the Bogotá offset, so the local wall time is the first 16 characters.
export function toDateTimeLocal(iso) {
    return typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(iso) ? iso.slice(0, 16) : '';
}

export function placeLine(evento) {
    const where = [evento?.municipio?.nombre ?? evento?.municipio, evento?.provincia?.nombre ?? evento?.provincia]
        .filter(v => typeof v === 'string' && v)
        .join(', ');

    return [evento?.sitio_encuentro, where].filter(Boolean).join(' — ');
}

export function newClientUuid() {
    const c = globalThis.crypto;
    if (c?.randomUUID) return c.randomUUID();
    // randomUUID only exists in secure contexts (https/localhost); a LAN http address still needs an id.
    const bytes = c?.getRandomValues ? c.getRandomValues(new Uint8Array(16)) : Uint8Array.from({ length: 16 }, () => Math.floor(Math.random() * 256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/* ── Attendee selection (pending ones to approve or discard, discarded ones to recover; both can be deleted) ── */

export function isSelectable(attendee) {
    return attendee?.link_status === 'pendiente' || attendee?.link_status === 'descartado';
}

/* ── Actions on one attendee ── */

// Already in Votantes: corrected in the ficha, not in the event.
export const LOCKED_LINK_STATUSES = ['aprobado', 'vinculado'];

export function canSeeFicha(user) {
    const perms = user?.permissions || [];

    return !!user?.isSuperadmin || perms.includes('*') || perms.includes('view.mapa');
}

// Keys, in display order: ficha, edit, approve, discard, recover, unlink, delete.
export function attendeeActions(attendee, { editable = false, fichaVisible = false } = {}) {
    if (!attendee) return [];
    const locked = LOCKED_LINK_STATUSES.includes(attendee.link_status);
    const actions = [];
    if (locked && fichaVisible && attendee.lider_id && attendee.ficha) actions.push('ficha');
    if (!editable) return actions;
    if (!locked) actions.push('edit');
    if (attendee.link_status === 'pendiente') actions.push('approve', 'discard');
    if (attendee.link_status === 'descartado') actions.push('recover');
    if (locked) actions.push('unlink');
    actions.push('delete');

    return actions;
}

export function deleteAttendeesMessage(attendees) {
    const inVotantes = attendees.filter(a => LOCKED_LINK_STATUSES.includes(a?.link_status)).length;
    const base = 'Se borran de la lista del evento. Esto no se puede deshacer.';
    if (!inVotantes) return base;

    return `${base} ${attendees.length === 1 ? 'Su ficha en Votantes NO se borra.' : 'Las fichas en Votantes de los aprobados NO se borran.'}`;
}

export function attendeeEditData(attendee) {
    const value = v => v ?? '';

    return {
        nombres: value(attendee?.nombres), apellidos: value(attendee?.apellidos), cedula: value(attendee?.cedula),
        celular: value(attendee?.celular), fijo: value(attendee?.fijo), fecha_nacimiento: value(attendee?.fecha_nacimiento),
        direccion: value(attendee?.direccion), barrio: value(attendee?.barrio), nota: value(attendee?.nota),
    };
}

// First message of each field of a 422 body.
export function fieldErrors(body) {
    return Object.fromEntries(Object.entries(body?.errors || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]));
}

/* ── Confirmed loads (Deshacer carga) ── */

export function loadLabel(carga) {
    const origin = carga?.kind === 'ocr' ? 'Planilla leída con IA' : 'Por Excel';
    const who = carga?.created_by ? `: ${carga.created_by}` : '';
    const when = carga?.confirmed_at ? ` · ${formatShortDate(carga.confirmed_at)}` : '';
    const n = carga?.asistentes ?? 0;

    return `${origin}${who}${when} · ${n === 1 ? '1 persona' : `${n} personas`}`;
}

export function undoLoadMessage(preview) {
    const n = preview?.asistentes ?? 0;
    const parts = [`Se eliminarán ${n === 1 ? '1 asistente' : `${n} asistentes`} de esta carga.`];
    if (preview?.fichas_borrar) parts.push(`También se borrarán ${preview.fichas_borrar === 1 ? '1 ficha creada' : `${preview.fichas_borrar} fichas creadas`} al aprobarlos, que no tienen otra información.`);
    if (preview?.fichas_conservar) parts.push(`Se conservarán ${preview.fichas_conservar === 1 ? '1 ficha que ya tiene' : `${preview.fichas_conservar} fichas que ya tienen`} otra información.`);
    if (preview?.vinculadas) parts.push(`Las ${preview.vinculadas === 1 ? 'ficha que ya existía' : `${preview.vinculadas} fichas que ya existían`} en Votantes no se tocan.`);
    if (preview?.con_autorizacion) parts.push(`${preview.con_autorizacion} con autorización de datos registrada se conservan.`);
    parts.push('Esto no se puede deshacer.');

    return parts.join(' ');
}

export function toggleSelected(selected, id) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);

    return next;
}

export function selectableIds(attendees) {
    return (attendees || []).filter(isSelectable).map(a => a.id);
}

export function allSelected(selected, attendees) {
    const ids = selectableIds(attendees);

    return ids.length > 0 && ids.every(id => selected.has(id));
}

export function toggleAll(selected, attendees) {
    const ids = selectableIds(attendees);
    if (allSelected(selected, attendees)) {
        const next = new Set(selected);
        ids.forEach(id => next.delete(id));
        return next;
    }

    return new Set([...selected, ...ids]);
}

// Drops ids that are no longer pending (after an approval the page reloads with new statuses).
export function pruneSelection(selected, attendees) {
    const allowed = new Set(selectableIds(attendees));
    const kept = [...selected].filter(id => allowed.has(id));

    return kept.length === selected.size ? selected : new Set(kept);
}

export const APPROVE_MESSAGE = 'Se crea su ficha en Votantes. Si la cédula ya existe en la campaña, se vincula a esa ficha sin cambiarla.';

export function sourceLabel(attendee) {
    if (attendee?.source === 'qr') return 'Por QR';
    const who = attendee?.registered_by?.name ? `: ${attendee.registered_by.name}` : '';

    if (attendee?.source === 'excel') return `Por Excel${who}`;
    if (attendee?.source === 'ocr') return `Planilla leída con IA${who}`;
    if (attendee?.source === 'acta') return `Acta de reunión${who}`;

    return `Operador${who}`;
}

/* ── Birth date with three selects ── */

export function birthYears(now = new Date()) {
    // Youth councillors register from 14.
    const newest = now.getFullYear() - 14;

    return Array.from({ length: newest - 1900 + 1 }, (_, i) => newest - i);
}

// '' when all three are empty, the ISO date when they form a real date, null when incomplete or impossible.
export function composeBirthDate({ day, month, year }) {
    if (!day && !month && !year) return '';
    if (!day || !month || !year) return null;
    const d = Number(day);
    const m = Number(month);
    const y = Number(year);
    const date = new Date(Date.UTC(y, m - 1, d));
    if (date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== d) return null;

    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/* ── Attendee form (operator and public QR) ── */

export const EMPTY_ATTENDEE = {
    nombres: '', apellidos: '', cedula: '', celular: '', barrio: '', fijo: '', direccion: '',
    birth: { day: '', month: '', year: '' },
};

export function attendeeClientErrors(data) {
    const errors = {};
    const required = { nombres: 'Escriba los nombres.', apellidos: 'Escriba los apellidos.', cedula: 'Escriba la cédula.', celular: 'Escriba el celular.', barrio: 'Escriba el barrio.' };
    Object.entries(required).forEach(([field, message]) => {
        if (!String(data[field] ?? '').trim()) errors[field] = message;
    });
    const cedula = String(data.cedula ?? '').replace(/\D/g, '');
    if (!errors.cedula && (cedula.length < 5 || cedula.length > 12)) errors.cedula = 'La cédula debe tener entre 5 y 12 números.';
    const birth = data.birth ?? {};
    if (composeBirthDate(birth) === null) {
        errors.fecha_nacimiento = birth.day && birth.month && birth.year
            ? 'Esa fecha no existe.'
            : 'Elija día, mes y año, o deje los tres vacíos.';
    }

    return errors;
}

export function attendeePayload(data, { clientUuid }) {
    return {
        client_uuid: clientUuid,
        nombres: data.nombres.trim(),
        apellidos: data.apellidos.trim(),
        cedula: data.cedula.trim(),
        celular: data.celular.trim(),
        barrio: data.barrio.trim(),
        fijo: data.fijo.trim() || null,
        fecha_nacimiento: composeBirthDate(data.birth) || null,
        direccion: data.direccion.trim() || null,
    };
}

// A queued payload back into the form (to correct a refused registration). A payload queued before 2026-11-07 may
// still carry consent_text_id / autoriza_datos: they are left out.
export function attendeeFormFromPayload(payload) {
    const [year = '', month = '', day = ''] = String(payload?.fecha_nacimiento ?? '').split('-');
    const text = v => (typeof v === 'string' ? v : '');

    return {
        nombres: text(payload?.nombres),
        apellidos: text(payload?.apellidos),
        cedula: text(payload?.cedula),
        celular: text(payload?.celular),
        barrio: text(payload?.barrio),
        fijo: text(payload?.fijo),
        direccion: text(payload?.direccion),
        birth: year && month && day ? { day: String(Number(day)), month: String(Number(month)), year } : { day: '', month: '', year: '' },
    };
}

// Laravel 422 → { field: 'first message' }.
export function firstErrors(errors) {
    return Object.fromEntries(Object.entries(errors || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
}

/* ── Event form ── */

export function eventFormData(evento) {
    return {
        nombre: evento?.nombre ?? '',
        event_type_id: evento?.tipo?.id ?? '',
        starts_at: toDateTimeLocal(evento?.starts_at),
        ends_at: toDateTimeLocal(evento?.ends_at),
        sitio_encuentro: evento?.sitio_encuentro ?? '',
        provincia_id: evento?.provincia?.id ?? '',
        municipio_id: evento?.municipio?.id ?? '',
        barrios: evento?.barrios ?? [],
        descripcion: evento?.descripcion ?? '',
        organizadores: (evento?.organizadores ?? []).map(({ lider_id, nombre, cedula, telefono, rol }) => ({ lider_id, nombre, cedula: cedula ?? '', telefono: telefono ?? '', rol: rol ?? '' })),
        figuras: (evento?.figuras ?? []).map(({ person_id, nombre, cargo }) => ({ person_id, nombre, cargo: cargo ?? '' })),
    };
}

export function eventFormErrors(data) {
    const required = {
        nombre: 'Escriba el nombre del evento.',
        event_type_id: 'Elija el tipo de evento.',
        starts_at: 'Elija la fecha y la hora.',
        sitio_encuentro: 'Escriba el sitio de encuentro.',
        municipio_id: 'Elija la provincia y el municipio.',
    };
    const errors = Object.fromEntries(Object.entries(required).filter(([field]) => !String(data[field] ?? '').trim()));
    if (!errors.starts_at && data.ends_at && data.ends_at < data.starts_at) errors.ends_at = 'La hora de fin no puede ser antes del inicio.';

    return errors;
}

export function eventPayload(data) {
    const blank = v => (typeof v === 'string' && v.trim() ? v.trim() : null);

    return {
        nombre: data.nombre.trim(),
        event_type_id: data.event_type_id,
        starts_at: data.starts_at,
        ends_at: data.ends_at || null,
        sitio_encuentro: data.sitio_encuentro.trim(),
        municipio_id: data.municipio_id,
        barrios: data.barrios,
        descripcion: blank(data.descripcion),
        organizadores: data.organizadores.map(o => ({ lider_id: o.lider_id || null, nombre: o.nombre.trim(), cedula: blank(o.cedula), telefono: blank(o.telefono), rol: blank(o.rol) })),
        figuras: data.figuras.map(f => ({ person_id: f.person_id || null, nombre: f.nombre.trim(), cargo: blank(f.cargo) })),
    };
}

// "Senado 2026" / "Alcaldía 2023 · Girón": the last candidacy, to tell namesakes apart.
export function figureSummary(person) {
    const role = [person?.ultimo_cargo, person?.ultimo_anio].filter(v => v !== null && v !== undefined && v !== '').join(' ');

    return [role, person?.ultimo_lugar].filter(Boolean).join(' · ');
}

export function normalizeBarrio(value) {
    return String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
}

export function addBarrio(list, value) {
    const clean = String(value ?? '').replace(/\s+/g, ' ').trim();
    if (!clean || list.some(b => normalizeBarrio(b) === normalizeBarrio(clean))) return list;

    return [...list, clean];
}

export function barrioSuggestions(barrios, query, limit = 6) {
    const q = normalizeBarrio(query);
    const list = barrios || [];
    if (!q) return list.slice(0, limit);

    return list.filter(b => normalizeBarrio(b).includes(q) && normalizeBarrio(b) !== q).slice(0, limit);
}

// Why the QR does not take registrations right now. Only who can open the event is told to press "Abrir registro".
export function qrClosedReason(evento, { canOpen = false } = {}) {
    if (evento?.public_open) return null;
    switch (evento?.status) {
        case 'borrador': return canOpen
            ? 'El evento está en borrador. Pulse "Abrir registro" para que el QR empiece a recibir registros.'
            : 'El evento está en borrador. El QR recibirá registros cuando el equipo del evento abra el registro.';
        case 'cerrado': return canOpen
            ? 'El registro está cerrado. El QR no recibe registros hasta que lo vuelva a abrir.'
            : 'El registro está cerrado. El QR no recibe registros hasta que el equipo del evento lo vuelva a abrir.';
        case 'cancelado': return 'El evento fue cancelado. El QR ya no recibe registros.';
        default: return 'El QR no recibe registros: el tiempo de registro terminó.';
    }
}

// manage.eventos alone allows creating and running events (write.data is not needed). The server flag wins
// when it is there; otherwise the permissions decide.
export function canManageEventos(user) {
    if (typeof user?.canManageEventos === 'boolean') return user.canManageEventos;
    const perms = user?.permissions || [];

    return !!user?.isSuperadmin || perms.includes('*') || perms.includes('manage.eventos');
}

function serverReason(data) {
    return data && typeof data === 'object' && typeof data.message === 'string' ? data.message : '';
}

// A failed event action (non-Inertia response) as a sentence for the team.
export function eventErrorMessage(response) {
    switch (response?.status) {
        case 403: return serverReason(response.data) || 'No tiene permiso para hacer este cambio.';
        case 404: return 'Este evento ya no existe o no lo puede ver. Recargue la página.';
        case 419: return 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.';
        case 422: return serverReason(response.data) || 'Revise los datos del formulario.';
        default: return 'No se pudo guardar el cambio. Intente de nuevo en un momento.';
    }
}
