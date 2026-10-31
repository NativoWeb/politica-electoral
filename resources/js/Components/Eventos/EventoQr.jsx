import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { formatEventRange, placeLine, qrClosedReason } from '@/lib/eventos';

const QR_OPTIONS = { errorCorrectionLevel: 'M', margin: 2, width: 640, color: { dark: '#002244', light: '#ffffff' } };

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function wrapLines(ctx, text, maxWidth) {
    const words = String(text ?? '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    words.forEach(word => {
        const next = line ? `${line} ${word}` : word;
        if (ctx.measureText(next).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = next;
        }
    });
    if (line) lines.push(line);

    return lines;
}

// A printable PNG: event name, date and place above the QR.
async function posterDataUrl(evento, qrDataUrl) {
    const width = 900;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.font = 'bold 44px system-ui, sans-serif';
    const titleLines = wrapLines(ctx, evento.nombre, width - 120);
    ctx.font = '30px system-ui, sans-serif';
    const infoLines = [
        ...wrapLines(ctx, formatEventRange(evento.starts_at, evento.ends_at), width - 120),
        ...wrapLines(ctx, placeLine(evento), width - 120),
    ];
    const qrSize = 640;
    canvas.width = width;
    canvas.height = 80 + titleLines.length * 54 + 20 + infoLines.length * 40 + 30 + qrSize + 110;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.textAlign = 'center';
    let y = 90;
    ctx.fillStyle = '#003B71';
    ctx.font = 'bold 44px system-ui, sans-serif';
    titleLines.forEach(l => { ctx.fillText(l, width / 2, y); y += 54; });
    y += 10;
    ctx.fillStyle = '#1A1A2E';
    ctx.font = '30px system-ui, sans-serif';
    infoLines.forEach(l => { ctx.fillText(l, width / 2, y); y += 40; });

    const img = new Image();
    await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = reject; img.src = qrDataUrl; });
    ctx.drawImage(img, (width - qrSize) / 2, y, qrSize, qrSize);
    y += qrSize + 60;
    ctx.fillStyle = '#4A4A68';
    ctx.font = 'bold 32px system-ui, sans-serif';
    ctx.fillText('Escanee el código para registrar su asistencia', width / 2, y);

    return canvas.toDataURL('image/png');
}

function fileName(evento) {
    const slug = String(evento.nombre ?? 'evento').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase();

    return `qr-${slug || 'evento'}.png`;
}

export default function EventoQr({ evento, canOpen = false }) {
    const url = evento.public_url;
    const [qr, setQr] = useState('');
    const [message, setMessage] = useState('');
    const reason = qrClosedReason(evento, { canOpen });

    useEffect(() => {
        let alive = true;
        if (!url) return undefined;
        QRCode.toDataURL(url, QR_OPTIONS)
            .then(data => { if (alive) setQr(data); })
            .catch(() => { if (alive) setMessage('No se pudo dibujar el código QR.'); });
        return () => { alive = false; };
    }, [url]);

    if (!url) return null;

    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setMessage('Enlace copiado.');
        } catch {
            setMessage('No se pudo copiar. Mantenga presionado el enlace para copiarlo.');
        }
    }

    async function download() {
        try {
            const data = await posterDataUrl(evento, qr);
            const a = document.createElement('a');
            a.href = data;
            a.download = fileName(evento);
            document.body.appendChild(a);
            a.click();
            a.remove();
        } catch {
            setMessage('No se pudo preparar la imagen del QR.');
        }
    }

    async function print() {
        const win = window.open('', '_blank');
        if (!win) {
            setMessage('El navegador bloqueó la ventana de impresión. Use "Descargar QR" e imprima la imagen.');
            return;
        }
        const data = await posterDataUrl(evento, qr).catch(() => qr);
        win.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${escapeHtml(evento.nombre)}</title>
<style>body{margin:0;display:flex;justify-content:center;font-family:system-ui,sans-serif}img{max-width:100%;max-height:100vh}@page{margin:12mm}</style></head>
<body><img src="${data}" alt="Código QR de ${escapeHtml(evento.nombre)}" onload="window.focus();window.print()"></body></html>`);
        win.document.close();
    }

    const btnCls = 'min-h-[44px] px-4 py-2.5 rounded-xl text-[15px] font-bold border-2 disabled:opacity-40';

    return (
        <section aria-labelledby="qr-title" className="bg-white border border-[var(--color-line)] rounded-2xl p-4">
            <h2 id="qr-title" className="text-[16px] font-extrabold text-[var(--color-ink)]">QR para el registro público</h2>
            <div className="mt-3 flex flex-col sm:flex-row gap-4 items-center sm:items-start">
                <div className={`relative w-[200px] h-[200px] flex-shrink-0 rounded-xl border border-[var(--color-line)] bg-white overflow-hidden ${reason ? 'opacity-35 grayscale' : ''}`}>
                    {qr ? <img src={qr} alt={`Código QR del enlace de registro de ${evento.nombre}`} className="w-full h-full" /> : <div className="w-full h-full animate-pulse bg-gray-100" />}
                </div>
                <div className="flex-1 min-w-0 w-full space-y-3">
                    {reason ? (
                        <p role="note" className="px-3 py-2.5 rounded-xl bg-amber-50 border border-amber-300 text-[14px] font-semibold text-amber-900">{reason}</p>
                    ) : (
                        <p className="px-3 py-2.5 rounded-xl bg-emerald-50 border border-emerald-300 text-[14px] font-semibold text-emerald-800">El QR está recibiendo registros.</p>
                    )}
                    <p className="text-[13px] text-[var(--color-ink-soft)] break-all font-mono bg-[var(--color-bg)] rounded-lg px-3 py-2">{url}</p>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={copy} className={`${btnCls} border-[var(--color-line-strong)] text-[var(--color-primary)] bg-white`}>Copiar enlace</button>
                        <button type="button" onClick={download} disabled={!qr} className={`${btnCls} border-[var(--color-line-strong)] text-[var(--color-primary)] bg-white`}>Descargar QR</button>
                        <button type="button" onClick={print} disabled={!qr} className={`${btnCls} border-[var(--color-line-strong)] text-[var(--color-primary)] bg-white`}>Imprimir QR</button>
                    </div>
                    {message && <p role="status" className="text-[14px] font-semibold text-[var(--color-ink-soft)]">{message}</p>}
                </div>
            </div>
        </section>
    );
}
