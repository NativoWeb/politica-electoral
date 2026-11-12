// Empresarios y gremios (docs/empresarios.md): grouping of the list, the form state and payloads.

export const TIPOS = [
    { value: 'empresa', label: 'Empresas', single: 'Empresa' },
    { value: 'gremio', label: 'Gremios', single: 'Gremio' },
];

export const SIN_SECTOR = 'Sin sector económico';

// [[tipo, [[sector, rows], ...]], ...]: one section per tipo (Empresas, Gremios) with its rows by sector económico,
// sectors with more organizations first and "Sin sector económico" last.
export function groupRows(rows) {
    return TIPOS.map(t => {
        const groups = {};
        rows.filter(r => r.tipo === t.value).forEach(r => {
            const key = r.sector_economico || SIN_SECTOR;
            (groups[key] ??= []).push(r);
        });
        const sectors = Object.entries(groups).sort(([a, ra], [b, rb]) => {
            if (a === SIN_SECTOR) return 1;
            if (b === SIN_SECTOR) return -1;
            return rb.length - ra.length || a.localeCompare(b, 'es');
        });
        return [t, sectors];
    });
}

export function membersLabel(n) {
    const count = Number(n) || 0;
    return count === 1 ? '1 miembro' : `${count} miembros`;
}

export const EMPTY_FORM = {
    tipo: 'empresa', nombre: '', nit: '', representante_nombre: '', representante_cedula: '', representante_celular: '',
    representante_lider_id: null, representante_ficha: null, telefono: '', correo: '', sitio_web: '', sector_economico_id: '', sector_id: '',
    departamento: 'Santander', municipio_id: '', direccion: '', barrio: '', empleados: '', empleados_rango: '', observaciones: '',
    is_active: true, gremio_ids: [],
};

export function toForm(org) {
    if (!org) return { ...EMPTY_FORM };
    const v = k => org[k] ?? '';
    return {
        ...EMPTY_FORM,
        tipo: org.tipo, nombre: v('nombre'), nit: org.nit_input ?? '', representante_nombre: v('representante_nombre'),
        representante_cedula: v('representante_cedula'), representante_celular: v('representante_celular'),
        representante_lider_id: org.representante_lider_id ?? null, telefono: v('telefono'), correo: v('correo'), sitio_web: v('sitio_web'),
        sector_economico_id: v('sector_economico_id'), sector_id: v('sector_id'), departamento: org.departamento || 'Santander',
        municipio_id: v('municipio_id'), direccion: v('direccion'), barrio: v('barrio'),
        empleados: org.empleados ?? '', empleados_rango: v('empleados_rango'), observaciones: v('observaciones'),
        is_active: org.is_active !== false, gremio_ids: (org.gremios ?? []).map(g => g.id),
    };
}

// Body of POST/PUT /empresarios: empty texts as null, empleados as a number.
export function toPayload(form) {
    const out = {};
    Object.entries(form).forEach(([k, v]) => {
        if (k === 'representante_ficha') return;
        out[k] = typeof v === 'string' ? (v.trim() === '' ? null : v.trim()) : v;
    });
    out.empleados = out.empleados === null || out.empleados === undefined || out.empleados === '' ? null : Number(out.empleados);
    if (out.tipo !== 'empresa') out.gremio_ids = [];
    return out;
}

// "?tipo=empresa&municipio=…" of the current filters, for the export links.
export function filtersQuery(filters) {
    const params = new URLSearchParams();
    Object.entries(filters ?? {}).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') params.set(k, v); });
    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

export const ACCIONES = {
    crear: { label: 'Nueva', cls: 'bg-emerald-50 text-emerald-800 border-emerald-300' },
    completar: { label: 'Ya existe: completar vacíos', cls: 'bg-blue-50 text-blue-800 border-blue-300' },
    saltar: { label: 'Ya existe: saltar', cls: 'bg-gray-100 text-gray-700 border-gray-300' },
    error: { label: 'Con errores', cls: 'bg-red-50 text-red-800 border-red-300' },
};
