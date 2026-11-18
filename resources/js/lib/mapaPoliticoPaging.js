// "Ver más" of the Mapa Político: the server sends the first rows of each person section and, for the sections it cut
// (or did not load, like Votantes), sectionPaging[label] = { key, loaded, total }. Further pages come from
// GET /mapa-politico/seccion and are kept here per section label.

const splitTipos = (tipos) => (tipos || '').split(',').map(t => t.trim()).filter(Boolean);

// state: { offset, hasMore } after the extra pages already fetched (offset = rows the server READ, which may be more
// than it returned: it leaves out fichas already shown merged into a candidate's row).
// Rows still on the server for a section.
export function remainingInSection(paging, state = null) {
    if (!paging || state?.hasMore === false) return 0;
    return Math.max(0, (paging.total || 0) - nextOffset(paging, state));
}

// The offset of the next page of a section.
export function nextOffset(paging, state = null) {
    return state?.offset ?? (paging?.loaded || 0);
}

// The rows of a new page that the section does not have yet.
export function appendNew(existing = [], incoming = []) {
    const seen = new Set(existing.map(r => r.id));
    return [...existing, ...incoming.filter(r => !r.id || !seen.has(r.id))];
}

// Server rows plus the extra pages: a ficha already listed (e.g. in Líderes and Directorio) gets the new section's
// label instead of a second row.
export function mergeSectionRows(serverRows, extraBySection) {
    const extras = Object.values(extraBySection || {}).flat();
    if (extras.length === 0) return serverRows;

    const out = [...serverRows];
    const indexById = new Map();
    out.forEach((row, i) => { if (row.id) indexById.set(row.id, i); });
    extras.forEach(row => {
        const i = row.id ? indexById.get(row.id) : undefined;
        if (i === undefined) {
            if (row.id) indexById.set(row.id, out.length);
            out.push(row);
            return;
        }
        const labels = [...new Set([...splitTipos(out[i].tipo_registro), ...splitTipos(row.tipo_registro)])];
        out[i] = { ...out[i], tipo_registro: labels.join(', ') };
    });

    return out;
}

// Query string for /mapa-politico/seccion: the page's filters plus the section and offset.
export function sectionQuery(filters, key, offset) {
    const params = new URLSearchParams();
    Object.entries(filters || {}).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
    });
    params.set('seccion', key);
    params.set('offset', String(offset));

    return params.toString();
}
