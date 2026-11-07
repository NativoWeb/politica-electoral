// Excel/CSV attendee import of an event (contract in docs/eventos-api.md §11).

export const TOO_LARGE = 'El archivo es demasiado grande para subirlo. Divídalo en varios archivos más pequeños e intente de nuevo.';

export const LIST_NOTE = 'Quien no está en la plataforma queda en el evento pendiente de aprobar para pasar a Votantes.';

export const STEPS = [
    { key: 'archivo', label: 'Archivo' },
    { key: 'columnas', label: 'Columnas' },
    { key: 'revisar', label: 'Revisar' },
    { key: 'confirmar', label: 'Confirmar' },
];

// Fields of a row in the order the preview shows them.
export const ROW_FIELDS = [
    { key: 'nombres', label: 'Nombres' },
    { key: 'apellidos', label: 'Apellidos' },
    { key: 'cedula', label: 'Cédula', inputMode: 'numeric' },
    { key: 'celular', label: 'Celular', inputMode: 'tel' },
    { key: 'fijo', label: 'Teléfono fijo', inputMode: 'tel' },
    { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', placeholder: 'DD/MM/AAAA' },
    { key: 'direccion', label: 'Dirección' },
    { key: 'barrio', label: 'Barrio' },
    { key: 'municipio', label: 'Municipio' },
    { key: 'liderazgo', label: 'Liderazgo o actividad' },
];

export const ROW_STATUS = {
    valida: { label: 'Lista para importar', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
    plataforma: { label: 'Ya estaba en la plataforma', cls: 'bg-blue-50 border-blue-200 text-[var(--color-primary)]' },
    error: { label: 'Con error', cls: 'bg-red-50 border-red-300 text-red-700' },
    duplicada: { label: 'Repetida en el archivo', cls: 'bg-amber-50 border-amber-300 text-amber-800' },
    en_evento: { label: 'Ya está en el evento', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
    pendiente: { label: 'Sin revisar', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
    sugerida: { label: '¿Es esta persona?', cls: 'bg-amber-50 border-amber-400 text-amber-900' },
    sin_ficha: { label: 'Nueva: pendiente de aprobar', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
};

export const ROW_FILTERS = [
    { value: null, label: 'Todas', count: 'total' },
    { value: 'nuevas', label: 'Nuevas', count: 'nuevas' },
    { value: 'plataforma', label: 'Ya en la plataforma', count: 'en_plataforma' },
    { value: 'error', label: 'Con error', count: 'errores' },
    { value: 'duplicada', label: 'Repetidas', count: 'duplicadas' },
    { value: 'en_evento', label: 'Ya en el evento', count: 'en_evento' },
    { value: 'sugerida', label: 'Por confirmar', count: 'sugeridas' },
    { value: 'sin_ficha', label: 'Nuevas por aprobar', count: 'sin_ficha' },
    { value: 'omitidas', label: 'No importar', count: 'omitidas' },
];

export function currentStep(importacion) {
    if (!importacion) return 'archivo';
    if (importacion.status === 'subido') return 'columnas';
    if (importacion.status === 'confirmado') return 'confirmar';

    return 'revisar';
}

export function rowStatusKey(row) {
    if (row?.status === 'valida' && row.en_plataforma) return 'plataforma';

    return ROW_STATUS[row?.status] ? row.status : 'pendiente';
}

// Same rules as the server (ImportColumns::missing), so the button explains what is missing before sending.
export function mappingProblem(mapping) {
    const m = mapping || {};
    const set = key => m[key] !== null && m[key] !== undefined && m[key] !== '';
    const hasName = set('nombre_completo') || (set('nombres') && set('apellidos'));
    const NAME = 'Elija la columna de nombres y la de apellidos, o una sola columna con el nombre completo.';
    if (!hasName) return NAME;
    if (!set('cedula') && !set('celular')) return 'Elija la columna de la cédula o la del teléfono: sin ninguna no se puede saber si la persona ya está en la plataforma.';
    const used = Object.values(m).filter(v => v !== null && v !== undefined && v !== '').map(Number);
    if (new Set(used).size !== used.length) return 'Una misma columna está elegida para dos datos distintos. Revise las columnas.';

    return null;
}

// "Cédula — ej.: 1098765432, 63525141" for the column select.
export function columnOptions(headers, sample) {
    return (headers || []).map((header, index) => {
        const examples = (sample || [])
            .map(row => row?.[index] ?? row?.[String(index)])
            .filter(v => v !== null && v !== undefined && v !== '')
            .slice(0, 2)
            .join(', ');

        return { value: index, label: examples ? `${header} — ej.: ${examples}` : header };
    });
}

export function setMapping(mapping, field, value) {
    return { ...mapping, [field]: value === '' || value === null || value === undefined ? null : Number(value) };
}

// The values sent to the server for a corrected row: only what changed.
export function changedValues(original, edited) {
    const changes = {};
    ROW_FIELDS.filter(f => !f.readOnly).forEach(({ key }) => {
        const before = original?.[key] ?? '';
        const after = edited?.[key] ?? '';
        if (String(before) !== String(after)) changes[key] = after === '' ? null : String(after);
    });

    return changes;
}

export function plural(n, one, many) {
    return `${n} ${n === 1 ? one : many}`;
}

export function confirmSummary(counts) {
    const total = counts?.a_importar ?? 0;
    if (total === 0) return 'No hay filas para importar. Revise las filas con error o cargue otro archivo.';
    if ((counts?.sin_autorizacion ?? 0) > 0) {
        const linked = total - Math.min(total, counts?.sin_ficha ?? 0);
        return `Se registrará${total === 1 ? '' : 'n'} ${plural(total, 'persona', 'personas')} en el evento: `
            + `${plural(linked, 'queda', 'quedan')} en el historial de su ficha (sin cambiarla) y el resto pendiente de aprobar para pasar a Votantes.`;
    }

    if (total === 1) {
        return 'Se registrará 1 persona en el evento. Si es nueva, queda pendiente de aprobar antes de pasar a Votantes.';
    }

    return `Se registrarán ${total} personas en el evento. Las nuevas quedan pendientes de aprobar antes de pasar a Votantes.`;
}

// "Quedan fuera: 2 con error, 1 repetida en el archivo…", only the non-zero groups; null when nothing is left out.
export function leftOutSummary(counts) {
    const parts = [
        [counts?.errores, 'con error', 'con error'],
        [counts?.duplicadas, 'repetida en el archivo', 'repetidas en el archivo'],
        [counts?.omitidas, 'marcada «No importar»', 'marcadas «No importar»'],
        [counts?.en_evento, 'que ya está en el evento', 'que ya están en el evento'],
        [counts?.sugeridas, 'por confirmar si es la persona sugerida', 'por confirmar si son las personas sugeridas'],
    ].filter(([n]) => n > 0).map(([n, one, many]) => `${n} ${n === 1 ? one : many}`);

    return parts.length ? `Quedan fuera: ${parts.join(', ')}.` : null;
}

export function confirmDialogTitle(counts) {
    return (counts?.a_importar ?? 0) === 1 ? '¿Importar a esta persona?' : '¿Importar estas personas?';
}

export function confirmDialogMessage(counts) {
    const total = counts?.a_importar ?? 0;
    const verb = total === 1 ? 'Se importará' : 'Se importarán';

    return `${verb} ${plural(total, 'persona', 'personas')}. Esta acción no se puede deshacer.`;
}

// Why the import cannot be confirmed yet, or null.
export function confirmBlocker({ counts }) {
    if ((counts?.a_importar ?? 0) === 0) return 'No hay filas para importar.';

    return null;
}

export function importErrorMessage(errors) {
    if (!errors) return '';
    const keys = ['archivo', 'hoja', 'columnas', 'decision', 'sugerencia', 'fila', 'confirmar', 'importacion', 'evento'];

    return keys.map(k => errors[k]).filter(Boolean).join(' ');
}

export function uploadProblem(file, limits) {
    if (!file) return 'Elija el archivo de Excel que quiere cargar.';
    const name = (file.name || '').toLowerCase();
    if (!/\.(xlsx|xls|csv)$/.test(name)) return 'El archivo debe ser de Excel (.xlsx o .xls) o .csv.';
    const maxMb = limits?.mb ?? 5;
    if (file.size > maxMb * 1024 * 1024) return `El archivo pesa más de ${maxMb} MB. Divídalo en varios archivos.`;

    return null;
}
