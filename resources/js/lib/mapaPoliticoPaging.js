// "Ver más" of the Mapa Político: the server sends the first rows of each person section and, for the sections it cut
// (or did not load, like Votantes), sectionPaging[label] = { key, loaded, total }. Further pages come from
// GET /mapa-politico/seccion and are kept here per section label.

const splitTipos = (tipos) => (tipos || '').split(',').map(t => t.trim()).filter(Boolean);

// Rows still on the server for a section, after the extra pages already fetched.
export function remainingInSection(paging, extraRows = []) {
    if (!paging) return 0;
    return Math.max(0, (paging.total || 0) - (paging.loaded || 0) - extraRows.length);
}

// The offset of the next page of a section.
export function nextOffset(paging, extraRows = []) {
    return (paging?.loaded || 0) + extraRows.length;
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
