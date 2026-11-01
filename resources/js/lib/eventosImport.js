// Excel/CSV attendee import of an event (contract in docs/eventos-api.md §11).

// Same text as EventoImportController::NEEDS_APPROVED_TEXT.
export const NEEDS_APPROVED_TEXT = 'Falta el texto de autorización aprobado por la campaña; no se puede cargar Excel hasta tenerlo.';

export const TOO_LARGE = 'El archivo es demasiado grande para subirlo. Divídalo en varios archivos más pequeños e intente de nuevo.';

// Everything an Excel load imports is authorized, so it needs the campaign's approved text (not the provisional one).
export function consentReady(consent) {
    return !!consent?.approved;
}

export const STEPS = [
    { key: 'archivo', label: 'Archivo' },
    { key: 'columnas', label: 'Columnas' },
    { key: 'revisar', label: 'Revisar' },
    { key: 'confirmar', label: 'Confirmar' },
];

// Fields of a row in the order the preview shows them. "Autorizó" is read-only: only the paper sheet counts.
export const ROW_FIELDS = [
    { key: 'nombres', label: 'Nombres' },
    { key: 'apellidos', label: 'Apellidos' },
    { key: 'cedula', label: 'Cédula', inputMode: 'numeric' },
    { key: 'celular', label: 'Celular', inputMode: 'tel' },
    { key: 'fijo', label: 'Teléfono fijo', inputMode: 'tel' },
    { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', placeholder: 'DD/MM/AAAA' },
    { key: 'direccion', label: 'Dirección' },
    { key: 'barrio', label: 'Barrio' },
    { key: 'autoriza', label: 'Autorizó', readOnly: true },
];

export const ROW_STATUS = {
    valida: { label: 'Lista para importar', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
    plataforma: { label: 'Ya estaba en la plataforma', cls: 'bg-blue-50 border-blue-200 text-[var(--color-primary)]' },
    error: { label: 'Con error', cls: 'bg-red-50 border-red-300 text-red-700' },
    no_autorizo: { label: 'No autorizó', cls: 'bg-gray-100 border-gray-400 text-gray-800' },
    duplicada: { label: 'Repetida en el archivo', cls: 'bg-amber-50 border-amber-300 text-amber-800' },
    en_evento: { label: 'Ya está en el evento', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
    pendiente: { label: 'Sin revisar', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
};

export const ROW_FILTERS = [
    { value: null, label: 'Todas', count: 'total' },
    { value: 'nuevas', label: 'Nuevas', count: 'nuevas' },
    { value: 'plataforma', label: 'Ya en la plataforma', count: 'en_plataforma' },
    { value: 'error', label: 'Con error', count: 'errores' },
    { value: 'no_autorizo', label: 'No autorizaron', count: 'no_autorizaron' },
    { value: 'duplicada', label: 'Repetidas', count: 'duplicadas' },
    { value: 'en_evento', label: 'Ya en el evento', count: 'en_evento' },
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
    if (!set('cedula')) return 'Elija la columna que tiene la cédula.';
    if (!set('nombre_completo') && !(set('nombres') && set('apellidos'))) {
        return 'Elija la columna de nombres y la de apellidos, o una sola columna con el nombre completo.';
    }
    if (!set('celular')) return 'Elija la columna que tiene el celular.';
    if (!set('autoriza')) return 'Elija la columna «Autorizó»: solo se importan las personas que tienen SÍ.';
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

// Only rows with SÍ in "Autorizó" are importable, so everything imported keeps its data authorization.
export function confirmSummary(counts) {
    const total = counts?.a_importar ?? 0;
    if (total === 0) return 'No hay filas para importar. Revise las filas con error o cargue otro archivo.';

    if (total === 1) {
        return 'Se registrará 1 persona en el evento, con su autorización de datos. '
            + 'Si es nueva, queda pendiente de aprobar antes de pasar a Votantes.';
    }

    return `Se registrarán ${total} personas en el evento, con su autorización de datos. `
        + 'Las nuevas quedan pendientes de aprobar antes de pasar a Votantes.';
}

// "Quedan fuera: 2 con error, 1 repetida en el archivo…", only the non-zero groups; null when nothing is left out.
export function leftOutSummary(counts) {
    const parts = [
        [counts?.errores, 'con error', 'con error'],
        [counts?.duplicadas, 'repetida en el archivo', 'repetidas en el archivo'],
        [counts?.omitidas, 'marcada «No importar»', 'marcadas «No importar»'],
        [counts?.no_autorizaron, 'que no autorizó (no dice SÍ)', 'que no autorizaron (no dice SÍ)'],
        [counts?.en_evento, 'que ya está en el evento', 'que ya están en el evento'],
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
export function confirmBlocker({ consent, declared, counts }) {
    if (!consentReady(consent)) return NEEDS_APPROVED_TEXT;
    if ((counts?.a_importar ?? 0) === 0) return 'No hay filas para importar.';
    if (!declared) return 'Marque la declaración de las planillas firmadas para poder importar.';

    return null;
}

export function importErrorMessage(errors) {
    if (!errors) return '';
    const keys = ['consent', 'archivo', 'columnas', 'decision', 'fila', 'confirmar', 'importacion', 'evento', 'declaracion'];

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
