// Printed sign-in sheets read with AI (contract in docs/eventos-api.md §13).
import { getCsrfToken } from '@/lib/api';
import { leftOutSummary, plural } from '@/lib/eventosImport';

export const OCR_ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf';

export const HEIC_MESSAGE = 'Las fotos HEIC del iPhone no se pueden leer. Súbalas desde la galería con este botón (el iPhone las convierte a JPG) o cambie en Ajustes > Cámara > Formatos a «Más compatible».';

export const OCR_STEPS = [
    { key: 'fotos', label: 'Fotos' },
    { key: 'leer', label: 'Leer con IA' },
    { key: 'revisar', label: 'Revisar' },
    { key: 'confirmar', label: 'Confirmar' },
];

export const PAGE_STATUS = {
    pendiente: { label: 'Sin leer', cls: 'bg-gray-100 border-gray-300 text-gray-700' },
    procesada: { label: 'Leída', cls: 'bg-emerald-50 border-emerald-300 text-emerald-800' },
    rechazada: { label: 'No se leyó', cls: 'bg-amber-50 border-amber-300 text-amber-900' },
    error: { label: 'Falló', cls: 'bg-red-50 border-red-300 text-red-700' },
};

// Fields of an OCR row: the Excel ones, each with the confidence the AI gave it.
export const OCR_ROW_FIELDS = [
    { key: 'nombres', label: 'Nombres', confidence: 'nombres' },
    { key: 'apellidos', label: 'Apellidos', confidence: 'apellidos' },
    { key: 'cedula', label: 'Cédula', inputMode: 'numeric', confidence: 'cedula' },
    { key: 'celular', label: 'Celular', inputMode: 'tel', confidence: 'celular' },
    { key: 'fijo', label: 'Teléfono fijo', inputMode: 'tel', confidence: 'fijo' },
    { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', placeholder: 'DD/MM/AAAA', confidence: 'fecha_nacimiento' },
    { key: 'direccion', label: 'Dirección', confidence: 'direccion' },
    { key: 'barrio', label: 'Barrio', confidence: 'barrio' },
];

export function ocrStep(importacion) {
    if (!importacion) return 'fotos';
    if (importacion.status === 'subido') return 'leer';
    if (importacion.status === 'confirmado') return 'confirmar';

    return 'revisar';
}

export function isLowConfidence(confianza, key, umbral) {
    if (!confianza || typeof umbral !== 'number') return false;
    const value = confianza[key];

    return typeof value === 'number' && value < umbral;
}

// Why a file cannot be uploaded, or null. The server checks the real type by its content again.
export function fileProblem(file, limits) {
    if (!file) return 'Elija las fotos de las planillas.';
    const name = (file.name || '').toLowerCase();
    const type = (file.type || '').toLowerCase();
    if (/\.(heic|heif)$/.test(name) || type.includes('heic') || type.includes('heif')) return HEIC_MESSAGE;
    if (!/\.(jpe?g|png|webp|pdf)$/.test(name)) return 'Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.';
    const maxMb = limits?.mb ?? 10;
    if (file.size > maxMb * 1024 * 1024) return `«${file.name}» pesa más de ${maxMb} MB. Tómela de nuevo con menos resolución.`;

    return null;
}

export function filesProblem(files, limits) {
    const list = Array.from(files || []);
    if (list.length === 0) return 'Elija las fotos de las planillas.';
    const max = limits?.paginas ?? 20;
    if (list.length > max) return `Máximo ${max} fotos por carga. Haga otra carga con el resto.`;
    for (const file of list) {
        const problem = fileProblem(file, limits);
        if (problem) return problem;
    }

    return null;
}

export function progressText(progress) {
    if (!progress || !progress.total) return 'No hay páginas.';
    const done = progress.total - progress.pendientes;
    const parts = [`${done} de ${plural(progress.total, 'página', 'páginas')}`];
    if (progress.rechazadas) parts.push(plural(progress.rechazadas, 'no se leyó', 'no se leyeron'));
    if (progress.errores) parts.push(plural(progress.errores, 'falló', 'fallaron'));

    return parts.join(' · ');
}

export function progressPercent(progress) {
    if (!progress?.total) return 0;

    return Math.round(((progress.total - progress.pendientes) / progress.total) * 100);
}

// fetch with the session cookies and the CSRF header; resolves to { ok, status, body }.
async function send(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        body,
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    });
    let json = null;
    try {
        json = await response.json();
    } catch {
        json = null;
    }

    return { ok: response.ok, status: response.status, body: json };
}

export function uploadPhoto(eventId, file, importId) {
    const form = new FormData();
    form.append('foto', file);
    if (importId) form.append('importacion', importId);

    return send(`/eventos/${eventId}/importar/ocr`, form);
}

export function processNextPage(eventId, importId, { retry = false, skipErrors = false } = {}) {
    const form = new FormData();
    if (retry) form.append('reintentar', '1');
    if (skipErrors) form.append('continuar', '1');

    return send(`/eventos/${eventId}/importar/ocr/${importId}/procesar`, form);
}

// The first message of a JSON error (422 errors.*, 403/404 message, 429...).
export function responseMessage(result, fallback = 'No se pudo completar. Intente de nuevo.') {
    const body = result?.body;
    if (body?.errors) {
        const first = Object.values(body.errors).flat().find(Boolean);
        if (first) return first;
    }
    if (result?.status === 413) return 'La foto es demasiado grande para subirla. Tómela de nuevo con menos resolución.';

    return body?.message || fallback;
}

export function ocrLeftOutSummary(counts) {
    return leftOutSummary(counts);
}

export function ocrConfirmSummary(counts, doubtful) {
    const base = (counts?.a_importar ?? 0) === 0
        ? 'No hay filas para importar.'
        : `Se registrará${counts.a_importar === 1 ? '' : 'n'} ${plural(counts.a_importar, 'persona', 'personas')} en el evento.`;

    return doubtful > 0 ? `${base} Aún hay ${plural(doubtful, 'fila con datos dudosos', 'filas con datos dudosos')} (en amarillo): revíselas contra la foto.` : base;
}
