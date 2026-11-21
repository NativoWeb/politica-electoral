import { useState } from 'react';
import { descargarExportacion } from '@/lib/exportDownload';

// A download link that goes through fetch (descargarExportacion), like the header "Exportar": a refusal (403, 429,
// network) is said in words, a second tap while the file is prepared sends nothing and X-Columnas-Quitadas shows
// the notice. The href stays so the link still reads as one (and opens with a long press).
export default function EnlaceDescarga({ href, nombre = 'Exportacion', className = '', children }) {
    const [preparando, setPreparando] = useState(false);

    async function alTocar(e) {
        e.preventDefault();
        if (preparando) return;
        setPreparando(true);
        try {
            await descargarExportacion(href, nombre);
        } finally {
            setPreparando(false);
        }
    }

    return (
        <a href={href} onClick={alTocar} aria-busy={preparando || undefined} aria-disabled={preparando || undefined}
            className={`${className} ${preparando ? 'opacity-60 cursor-wait' : ''}`}>
            {preparando ? 'Preparando…' : children}
        </a>
    );
}
