import { showGlobalToast } from '@/lib/api';
import { guardarBlob, nombreArchivo } from '@/lib/reportes';

export const AVISO_COLUMNAS_QUITADAS = 'El archivo no incluye cédula, teléfono ni dirección porque no tienes el permiso para descargar datos personales.';

function mensajeDe(status, data) {
    if (status === 401 || status === 419) return 'La página estuvo abierta mucho tiempo. Recárgala e intenta de nuevo.';
    if (status === 403) return 'No tienes permiso para descargar estos datos.';
    if (status === 429) return data?.message || 'Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.';
    return 'No se pudo descargar. Revisa tu conexión e intenta de nuevo.';
}

let enCurso = false;

// The header "Exportar" (GET /exportar/{excel|pdf}) downloaded through fetch, so the answer can be read: a refusal
// is said in words and X-Columnas-Quitadas tells the file left the personal columns out (DescargaGuard).
export async function descargarExportacion(url, fallbackNombre = 'Exportacion') {
    if (enCurso) return { estado: 'ocupado' };
    enCurso = true;
    showGlobalToast('Preparando tu archivo…', 'info');
    try {
        let res;
        try {
            res = await fetch(url, { credentials: 'same-origin', headers: { Accept: '*/*', 'X-Requested-With': 'XMLHttpRequest' } });
        } catch {
            showGlobalToast(mensajeDe(0), 'error');
            return { estado: 'error', status: 0 };
        }
        const tipo = res.headers.get('content-type') || '';
        if (!res.ok || tipo.includes('json') || tipo.includes('html')) {
            const data = await res.json().catch(() => null);
            showGlobalToast(mensajeDe(res.ok ? 500 : res.status, data), 'error');
            return { estado: 'error', status: res.status };
        }
        const blob = await res.blob();
        const extension = tipo.includes('pdf') ? 'pdf' : 'xlsx';
        guardarBlob(blob, nombreArchivo(res.headers.get('content-disposition'), `${fallbackNombre}.${extension}`));
        const quitadas = (res.headers.get('x-columnas-quitadas') || '').trim();
        const huboQuitadas = quitadas !== '' && quitadas !== '0';
        showGlobalToast(huboQuitadas ? AVISO_COLUMNAS_QUITADAS : 'Listo. Se descargó tu archivo.', huboQuitadas ? 'info' : 'success');
        return { estado: 'ok', quitadas: huboQuitadas };
    } catch {
        showGlobalToast(mensajeDe(0), 'error');
        return { estado: 'error', status: 0 };
    } finally {
        enCurso = false;
    }
}
