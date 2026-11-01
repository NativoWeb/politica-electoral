import { useState } from 'react';

export const PLANILLA_THROTTLED = 'Ha descargado muchas planillas seguidas. Espere un minuto e intente de nuevo.';

export function planillaUrl(eventId) {
    return `/eventos/${eventId}/planilla.pdf`;
}

export function planillaErrorMessage(status) {
    switch (status) {
        case 429: return PLANILLA_THROTTLED;
        case 401:
        case 419: return 'Su sesión se cerró. Inicie sesión de nuevo para descargar la planilla.';
        case 403: return 'No tiene permiso para descargar la planilla de este evento.';
        case 404: return 'Este evento ya no existe o no lo puede ver. Recargue la página.';
        case 0: return 'Sin conexión: la planilla se descarga cuando haya señal.';
        default: return 'No se pudo descargar la planilla. Intente de nuevo en un momento.';
    }
}

// Installed app (home screen): there a blob download may do nothing (iOS), so the browser opens the URL itself.
export function isStandalone() {
    if (typeof window === 'undefined') return false;
    if (window.navigator?.standalone === true) return true;
    try {
        return !!window.matchMedia?.('(display-mode: standalone)')?.matches;
    } catch {
        return false;
    }
}

function goToUrl(url) {
    window.location.href = url;
}

function fileName(res, eventId) {
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') || '');
    return match ? decodeURIComponent(match[1]) : `planilla-${eventId}.pdf`;
}

function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// Download of the printable attendance sheet. It goes through fetch so a refusal (too many downloads,
// session, permission) is told in Spanish instead of failing silently; the href keeps "open in new tab" working.
// In the installed app a HEAD request checks first (429, session...) and then the browser opens the URL.
export default function PlanillaLink({ eventId, className, messageClassName = '', navigate = goToUrl }) {
    const [state, setState] = useState({ busy: false, error: '' });

    async function download(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (state.busy) return;
        setState({ busy: true, error: '' });
        const url = planillaUrl(eventId);
        const standalone = isStandalone();
        let res;
        try {
            res = await fetch(url, {
                method: standalone ? 'HEAD' : 'GET',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: { Accept: 'application/pdf' },
            });
        } catch {
            setState({ busy: false, error: planillaErrorMessage(0) });
            return;
        }
        const isPdf = (res.headers.get('Content-Type') || '').includes('application/pdf');
        if (res.ok && isPdf && standalone) {
            setState({ busy: false, error: '' });
            navigate(url);
            return;
        }
        if (!res.ok || !isPdf) {
            // A redirect (opaque, status 0) is not a missing signal: it gets the generic message.
            const status = res.ok || res.type === 'opaqueredirect' ? -1 : res.status;
            setState({ busy: false, error: planillaErrorMessage(status) });
            return;
        }
        try {
            saveBlob(await res.blob(), fileName(res, eventId));
        } catch {
            // The blob could not be saved here: the browser downloads the URL itself.
            navigate(url);
        }
        setState({ busy: false, error: '' });
    }

    return (
        <span className="inline-flex flex-col gap-1.5">
            <a href={planillaUrl(eventId)} download onClick={download} aria-busy={state.busy || undefined} className={className}>
                {state.busy ? 'Descargando planilla...' : 'Planilla para imprimir'}
            </a>
            {state.error && <span role="alert" className={`text-[18px] font-semibold ${messageClassName}`}>{state.error}</span>}
        </span>
    );
}
