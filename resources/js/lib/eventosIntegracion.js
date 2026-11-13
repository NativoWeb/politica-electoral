// Eventos especiales, phase 4 (docs/eventos-api.md §12): events in the person's ficha, Votantes filters and the
// territory view.
import { normalizeBarrio } from '@/lib/eventos';

export const SOURCE_LABELS = { qr: 'Por QR', operador: 'Por operador', excel: 'Por Excel' };

// Who attended an event is shown only with view.eventos or manage.eventos (register.asistentes does not see it).
export function canSeeEventAttendees(user) {
    const perms = user?.permissions || [];

    return !!user?.isSuperadmin || perms.includes('*') || perms.includes('view.eventos') || perms.includes('manage.eventos');
}

export function eventCountLabel(n) {
    const count = Number(n) || 0;

    return count === 1 ? '1 evento' : `${count} eventos`;
}

// "Asistente · Por QR", "Organizador (Coordinador)".
export function participationLine(item) {
    const origin = item?.participacion === 'asistente' ? SOURCE_LABELS[item?.origen] : null;

    return [item?.rol, origin].filter(Boolean).join(' · ');
}

export function personEventsSummary(data) {
    const total = data?.total ?? 0;
    if (total === 0) return 'No ha asistido ni organizado eventos de los grupos políticos que usted puede ver.';
    const parts = [];
    if (data.asistio) parts.push(`asistió a ${eventCountLabel(data.asistio)}`);
    if (data.organizo) parts.push(`organizó ${eventCountLabel(data.organizo)}`);

    return `${parts.join(' y ').replace(/^./, c => c.toUpperCase())}.`;
}

export function eventOptionLabel(evento, formatDate) {
    const date = evento?.starts_at && formatDate ? formatDate(evento.starts_at) : '';

    return [evento?.nombre, date, evento?.municipio].filter(Boolean).join(' — ');
}

function query(params) {
    const search = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') search.set(k, v);
    });
    const text = search.toString();

    return text ? `?${text}` : '';
}

// The Excel of the detail's attendees with the filters on screen (same rows, unpaginated).
export function attendeesExcelUrl(eventId, filters = {}) {
    return `/eventos/${eventId}/asistentes.xlsx${query({ link_status: filters.link_status, source: filters.source, q: filters.q })}`;
}

export function territoryExcelUrl(filters = {}) {
    return `/eventos/territorio/excel${query({ municipio: filters.municipio, barrio: filters.barrio })}`;
}

export function territoryParams(filters = {}) {
    return Object.fromEntries(Object.entries({ municipio: filters.municipio, barrio: filters.barrio })
        .filter(([, v]) => v !== null && v !== undefined && v !== ''));
}

// Barrio suggestions ({nombre, key, total}) that contain what was typed, without accents or case; the typed barrio
// itself is not suggested again.
export function matchingBarrios(barrios, typed, limit = 8) {
    const q = normalizeBarrio(typed);
    const list = barrios || [];
    if (!q) return list.slice(0, limit);

    return list.filter(b => b.key.includes(q) && b.key !== q).slice(0, limit);
}

export function sameBarrio(a, b) {
    return normalizeBarrio(a) === normalizeBarrio(b);
}
