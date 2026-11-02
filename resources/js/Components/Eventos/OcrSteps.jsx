import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    OCR_ACCEPT, PAGE_STATUS, filesProblem, processNextPage, progressPercent, progressText, responseMessage, uploadPhoto,
} from '@/lib/eventosOcr';
import { plural } from '@/lib/eventosImport';

const CARD = 'bg-white border border-[var(--color-line)] rounded-2xl p-4 lg:p-5';
const PRIMARY_BTN = 'min-h-[52px] px-5 py-3 rounded-xl bg-[var(--color-primary)] text-white text-[18px] font-extrabold disabled:opacity-50';
const SECONDARY_BTN = 'min-h-[48px] px-4 py-2.5 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[17px] font-bold disabled:opacity-50';

// Step 1: the photos (or PDF) of the filled sheets, uploaded one per request so PHP's size limit is never their sum.
export function OcrUpload({ eventId, limits, disabled }) {
    const [files, setFiles] = useState([]);
    const [problem, setProblem] = useState('');
    const [sent, setSent] = useState(null);

    async function submit(e) {
        e.preventDefault();
        const p = filesProblem(files, limits);
        setProblem(p || '');
        if (p) return;

        let importId = null;
        for (let i = 0; i < files.length; i++) {
            setSent(i);
            let result;
            try {
                result = await uploadPhoto(eventId, files[i], importId);
            } catch {
                setProblem('No hay conexión con el servidor. Intente de nuevo.');
                setSent(null);
                return;
            }
            if (!result.ok) {
                const where = importId ? ` Las fotos anteriores ya quedaron en la carga: ábrala abajo en «Cargas anteriores».` : '';
                setProblem(`«${files[i].name}»: ${responseMessage(result)}${where}`);
                setSent(null);
                return;
            }
            importId = result.body?.data?.id ?? importId;
        }
        router.visit(`/eventos/${eventId}/importar/ocr/${importId}`);
    }

    const busy = sent !== null;

    return (
        <form onSubmit={submit} className={CARD} aria-labelledby="fotos-title">
            <h2 id="fotos-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">Suba las fotos de las planillas llenas</h2>
            <p className="text-[17px] text-[var(--color-ink-soft)] mt-1.5">
                Una foto por página, derecha, con buena luz y que se vean las cuatro esquinas. JPG, PNG o PDF; máximo {limits?.mb ?? 10} MB
                por foto y {limits?.paginas ?? 20} páginas por carga. Use la planilla impresa desde la plataforma (tiene el código QR del evento).
            </p>
            <label htmlFor="fotos-planilla" className="mt-3 flex flex-col items-center justify-center gap-1 min-h-[96px] px-4 py-4 rounded-xl border-2 border-dashed border-[var(--color-line-strong)] bg-[var(--color-bg)] cursor-pointer text-center focus-within:ring-4 focus-within:ring-[var(--color-primary)]/40 focus-within:border-[var(--color-primary)]">
                <span className="text-[18px] font-extrabold text-[var(--color-primary)]">{files.length ? 'Cambiar fotos' : 'Elegir fotos'}</span>
                <span className="text-[16px] text-[var(--color-ink-soft)] break-all">
                    {files.length ? `${plural(files.length, 'archivo elegido', 'archivos elegidos')}: ${files.map(f => f.name).join(', ')}` : 'Toque aquí para tomarlas o buscarlas'}
                </span>
                <input
                    id="fotos-planilla"
                    type="file"
                    multiple
                    accept={OCR_ACCEPT}
                    className="sr-only"
                    disabled={disabled || busy}
                    onChange={e => { setFiles(Array.from(e.target.files || [])); setProblem(''); }}
                />
            </label>
            {problem && <p role="alert" className="mt-2 text-[17px] font-semibold text-red-700">{problem}</p>}
            {busy && <p role="status" className="mt-2 text-[17px] font-semibold text-[var(--color-ink)]">Subiendo {sent + 1} de {files.length}…</p>}
            <button type="submit" disabled={busy || disabled} className={`${PRIMARY_BTN} mt-3 w-full`}>
                {busy ? 'Subiendo las fotos…' : 'Subir fotos'}
            </button>
        </form>
    );
}

// Step 2: reads the pages one by one (one request each) with a progress bar. Never starts by itself: reading costs money.
export function OcrReader({ eventId, importacion, ready }) {
    const [progress, setProgress] = useState(importacion.progreso);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState('');
    const [failedPage, setFailedPage] = useState(false);

    async function run(options = {}) {
        setRunning(true);
        setError('');
        setFailedPage(false);
        let opts = options;
        for (let guard = 0; guard < 200; guard++) {
            let result;
            try {
                result = await processNextPage(eventId, importacion.id, opts);
            } catch {
                setError('Se perdió la conexión con el servidor. Lo leído hasta ahora quedó guardado: toque «Seguir leyendo».');
                break;
            }
            opts = {};
            const data = result.body?.data;
            if (data) setProgress(data);
            if (!result.ok) {
                setError(responseMessage(result));
                break;
            }
            if (data?.ocupado) {
                await new Promise(resolve => setTimeout(resolve, 3000));
                continue;
            }
            if (result.body?.success === false) {
                setError(result.body.message);
                setFailedPage(true);
                break;
            }
            if (data?.listo) {
                router.reload();
                return;
            }
            if ((data?.pendientes ?? 0) === 0) {
                setError('Algunas páginas no se pudieron leer. Intente de nuevo o siga sin ellas.');
                setFailedPage(true);
                break;
            }
        }
        setRunning(false);
    }

    const pending = progress?.pendientes ?? 0;

    return (
        <section className={CARD} aria-labelledby="leer-title">
            <h2 id="leer-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">Leer las planillas con IA</h2>
            <p className="text-[17px] text-[var(--color-ink-soft)] mt-1.5">
                La IA lee cada página y usted revisa todo antes de importar: nada se guarda en el evento hasta que confirme.
                Cada página tarda alrededor de un minuto. No cierre esta pantalla mientras lee.
            </p>
            <div className="mt-3" aria-live="polite">
                <div className="h-4 rounded-full bg-[var(--color-bg)] border border-[var(--color-line)] overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progressPercent(progress)} aria-label="Páginas leídas">
                    <div className="h-full bg-[var(--color-primary)] transition-[width] duration-300" style={{ width: `${progressPercent(progress)}%` }} />
                </div>
                <p className="mt-1.5 text-[17px] font-semibold text-[var(--color-ink)]">
                    {running ? `Leyendo… ${progressText(progress)}` : progressText(progress)}
                </p>
            </div>
            {error && <p role="alert" className="mt-2 text-[17px] font-semibold text-red-700">{error}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
                {!running && !failedPage && (pending > 0 || (progress?.errores ?? 0) === 0) && (
                    <button type="button" disabled={!ready} onClick={() => run()} className={PRIMARY_BTN}>
                        {progress && progress.pendientes < progress.total ? 'Seguir leyendo' : `Leer ${plural(pending, 'página', 'páginas')} con IA`}
                    </button>
                )}
                {!running && (failedPage || (progress?.errores ?? 0) > 0) && (
                    <>
                        <button type="button" disabled={!ready} onClick={() => run({ retry: true })} className={PRIMARY_BTN}>Intentar de nuevo</button>
                        <button type="button" disabled={!ready} onClick={() => run({ skipErrors: true })} className={SECONDARY_BTN}>Seguir sin las páginas que fallaron</button>
                    </>
                )}
            </div>
        </section>
    );
}

export function PageTabs({ paginas, current, onSelect }) {
    return (
        <div className="flex gap-1.5 overflow-x-auto -mx-4 px-4 pb-0.5 [scrollbar-width:none]" role="group" aria-label="Páginas de la carga">
            {paginas.map(p => {
                const active = p.numero === current;
                const status = PAGE_STATUS[p.status] ?? PAGE_STATUS.pendiente;
                return (
                    <button
                        key={p.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onSelect(p.numero)}
                        className={`flex-shrink-0 min-h-[48px] px-3.5 py-1.5 rounded-xl border-2 text-left ${active ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : `bg-white ${status.cls.split(' ').filter(c => c.startsWith('border-')).join(' ')} text-[var(--color-ink)]`}`}
                    >
                        <span className="block text-[16px] font-extrabold leading-tight">Página {p.numero}</span>
                        <span className="block text-[15px] font-semibold leading-tight">
                            {p.status === 'procesada' ? plural(p.filas, 'fila', 'filas') : status.label}
                            {p.dudosas > 0 ? ` · ${p.dudosas} dudosa${p.dudosas === 1 ? '' : 's'}` : ''}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

// The photo of the page (or the PDF at that page) served by an authenticated route; never a public URL.
export function PageViewer({ pagina }) {
    if (!pagina) return null;
    const url = pagina.pdf ? `${pagina.archivo}#page=${pagina.pagina_pdf}` : pagina.archivo;

    return (
        <figure className="bg-white border border-[var(--color-line)] rounded-2xl p-2">
            {pagina.pdf ? (
                <iframe title={`Página ${pagina.numero}`} src={url} className="w-full h-[70vh] rounded-xl border border-[var(--color-line)]" />
            ) : (
                <a href={url} target="_blank" rel="noopener noreferrer" title="Abrir la foto en grande">
                    <img src={url} alt={`Foto de la página ${pagina.numero}`} className="w-full h-auto rounded-xl" />
                </a>
            )}
            <figcaption className="px-1 pt-2 text-[16px] text-[var(--color-ink-soft)] break-all">
                {pagina.nombre}{pagina.pdf ? ` · página ${pagina.pagina_pdf} del PDF` : ''}{pagina.pagina_impresa ? ` · dice «Página ${pagina.pagina_impresa}»` : ''}
                {' · '}
                <a href={url} target="_blank" rel="noopener noreferrer" className="font-semibold text-[var(--color-primary)] underline">Abrir en grande</a>
            </figcaption>
        </figure>
    );
}
