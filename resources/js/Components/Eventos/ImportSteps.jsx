import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import {
    NEEDS_APPROVED_TEXT, STEPS, TOO_LARGE, columnOptions, confirmBlocker, confirmDialogMessage, confirmDialogTitle, confirmSummary,
    consentReady, leftOutSummary, mappingProblem, plural, setMapping, uploadProblem,
} from '@/lib/eventosImport';

const CARD = 'bg-white border border-[var(--color-line)] rounded-2xl p-4 lg:p-5';
const PRIMARY_BTN = 'min-h-[52px] px-5 py-3 rounded-xl bg-[var(--color-primary)] text-white text-[18px] font-extrabold disabled:opacity-50';

export function ImportStepper({ step }) {
    const current = STEPS.findIndex(s => s.key === step);

    return (
        <ol className="grid grid-cols-2 sm:grid-cols-4 gap-1.5" aria-label="Pasos para cargar el Excel">
            {STEPS.map((s, i) => {
                const state = i < current ? 'hecho' : i === current ? 'actual' : 'pendiente';
                const cls = state === 'actual'
                    ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                    : state === 'hecho' ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : 'bg-white text-[var(--color-ink-soft)] border-[var(--color-line)]';
                return (
                    <li key={s.key} aria-current={state === 'actual' ? 'step' : undefined} className={`min-w-0 rounded-xl border px-2 py-2 text-center ${cls}`}>
                        <span className="block text-[16px] font-bold leading-tight">Paso {i + 1}</span>
                        <span className="block text-[17px] font-extrabold leading-tight">{s.label}</span>
                    </li>
                );
            })}
        </ol>
    );
}

// Excel loads need the campaign's APPROVED authorization text (everything imported is authorized), like the public QR.
export function ConsentNote({ consent }) {
    if (!consentReady(consent)) {
        return (
            <p role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-300 text-[17px] font-semibold text-red-800">
                {NEEDS_APPROVED_TEXT}
            </p>
        );
    }

    return <p className="text-[16px] text-[var(--color-ink-soft)]">Texto de autorización aprobado: versión <strong>{consent.version}</strong>.</p>;
}

export function ImportUpload({ eventId, limits, disabled }) {
    const [file, setFile] = useState(null);
    const [problem, setProblem] = useState('');
    const [busy, setBusy] = useState(false);

    function submit(e) {
        e.preventDefault();
        const p = uploadProblem(file, limits);
        setProblem(p || '');
        if (p) return;
        setBusy(true);
        router.post(`/eventos/${eventId}/importar`, { archivo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onHttpException: response => {
                if (response?.status === 413) {
                    setProblem(TOO_LARGE);
                    return false;
                }
                return undefined;
            },
            onNetworkError: () => { setProblem('No hay conexión con el servidor. Intente de nuevo.'); return false; },
            onFinish: () => setBusy(false),
        });
    }

    return (
        <div className="grid lg:grid-cols-2 gap-4">
            <section className={CARD} aria-labelledby="plantilla-title">
                <h2 id="plantilla-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">1. Descargue la plantilla</h2>
                <p className="text-[17px] text-[var(--color-ink-soft)] mt-1.5">
                    Es un Excel con las columnas listas. Escriba una persona por fila. Si ya tiene su propio Excel, puede subirlo igual: después elige qué columna es cada dato.
                </p>
                <a href={`/eventos/${eventId}/plantilla.xlsx`} download className="mt-3 inline-flex items-center min-h-[52px] px-5 py-3 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[18px] font-extrabold">
                    Descargar plantilla de Excel
                </a>
            </section>

            <form onSubmit={submit} className={CARD} aria-labelledby="subir-title">
                <h2 id="subir-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">2. Suba el archivo lleno</h2>
                <p className="text-[17px] text-[var(--color-ink-soft)] mt-1.5">
                    Excel (.xlsx o .xls) o .csv. Máximo {limits?.filas ?? 2000} personas y {limits?.mb ?? 5} MB.
                </p>
                <label htmlFor="archivo-excel" className="mt-3 flex flex-col items-center justify-center gap-1 min-h-[96px] px-4 py-4 rounded-xl border-2 border-dashed border-[var(--color-line-strong)] bg-[var(--color-bg)] cursor-pointer text-center focus-within:ring-4 focus-within:ring-[var(--color-primary)]/40 focus-within:border-[var(--color-primary)]">
                    <span className="text-[18px] font-extrabold text-[var(--color-primary)]">{file ? 'Cambiar archivo' : 'Elegir archivo'}</span>
                    <span className="text-[16px] text-[var(--color-ink-soft)] break-all">{file ? file.name : 'Toque aquí para buscarlo'}</span>
                    <input
                        id="archivo-excel"
                        type="file"
                        accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                        className="sr-only"
                        disabled={disabled}
                        onChange={e => { setFile(e.target.files?.[0] ?? null); setProblem(''); }}
                    />
                </label>
                {problem && <p role="alert" className="mt-2 text-[17px] font-semibold text-red-700">{problem}</p>}
                <button type="submit" disabled={busy || disabled} className={`${PRIMARY_BTN} mt-3 w-full`}>
                    {busy ? 'Subiendo y leyendo el archivo…' : 'Subir archivo'}
                </button>
            </form>
        </div>
    );
}

export function ImportMapping({ eventId, importacion, muestra, campos }) {
    const [mapping, setMappingState] = useState(() => ({ ...(importacion.columnas || {}) }));
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState('');
    const options = columnOptions(importacion.encabezados, muestra);

    function submit(e) {
        e.preventDefault();
        const p = mappingProblem(mapping);
        setProblem(p || '');
        if (p) return;
        setBusy(true);
        router.put(`/eventos/${eventId}/importar/${importacion.id}/columnas`, { columnas: mapping }, {
            preserveScroll: true,
            onNetworkError: () => { setProblem('No hay conexión con el servidor. Intente de nuevo.'); return false; },
            onFinish: () => setBusy(false),
        });
    }

    return (
        <form onSubmit={submit} className={CARD} aria-labelledby="columnas-title">
            <h2 id="columnas-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">Revise qué columna es cada dato</h2>
            <p className="text-[17px] text-[var(--color-ink-soft)] mt-1.5">
                Ya elegimos las columnas que reconocimos. Revise que estén bien y corrija las que falten. Si un dato no está en su archivo, deje «No está en el archivo».
            </p>
            <div className="mt-4 grid sm:grid-cols-2 gap-x-4 gap-y-3">
                {(campos || []).map(c => (
                    <label key={c.key} className="block min-w-0">
                        <span className="block text-[17px] font-bold text-[var(--color-ink)] mb-1">{c.label}</span>
                        <select
                            value={mapping[c.key] ?? ''}
                            onChange={e => setMappingState(prev => setMapping(prev, c.key, e.target.value))}
                            className="w-full min-w-0 max-w-full min-h-[48px] px-3 py-2 border border-[var(--color-line-strong)] rounded-lg bg-white text-[17px] focus:outline-none focus:border-[var(--color-primary)]"
                        >
                            <option value="">No está en el archivo</option>
                            {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </label>
                ))}
            </div>
            {problem && <p role="alert" className="mt-3 text-[17px] font-semibold text-red-700">{problem}</p>}
            <button type="submit" disabled={busy} className={`${PRIMARY_BTN} mt-4 w-full sm:w-auto`}>
                {busy ? 'Revisando las filas…' : 'Revisar las filas'}
            </button>
        </form>
    );
}

export function ImportConfirm({ eventId, importacion, consent }) {
    const [declared, setDeclared] = useState(false);
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const counts = importacion.counts || {};
    const ready = consentReady(consent);
    const blocker = confirmBlocker({ consent, declared, counts });
    const leftOut = leftOutSummary(counts);

    function submit(e) {
        e.preventDefault();
        if (!blocker) setAsking(true);
    }

    function importNow() {
        setAsking(false);
        setBusy(true);
        router.post(`/eventos/${eventId}/importar/${importacion.id}/confirmar`, { declaracion: true }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    return (
        <form onSubmit={submit} className={`${CARD} border-2 border-[var(--color-primary)]`} aria-labelledby="confirmar-title">
            <h2 id="confirmar-title" className="text-[20px] font-extrabold text-[var(--color-ink)]">Confirmar la carga</h2>
            <p className="text-[18px] text-[var(--color-ink)] mt-1.5">{confirmSummary(counts)}</p>
            {leftOut && <p className="text-[17px] text-[var(--color-ink-soft)] mt-1">{leftOut}</p>}
            <label className="mt-3 flex items-start gap-3 min-h-[48px] cursor-pointer px-3 py-3 rounded-xl bg-[var(--color-bg)] border border-[var(--color-line)]">
                <input type="checkbox" checked={declared} disabled={!ready} onChange={e => setDeclared(e.target.checked)} className="mt-0.5 w-7 h-7 flex-shrink-0 accent-[var(--color-primary)]" />
                <span className="text-[18px] font-semibold text-[var(--color-ink)]">
                    Tengo las planillas firmadas que respaldan las autorizaciones de este archivo.
                </span>
            </label>
            {blocker && ready && <p className="mt-2 text-[17px] font-semibold text-amber-800" aria-live="polite">{blocker}</p>}
            <div className="mt-3"><ConsentNote consent={consent} /></div>
            <button type="submit" disabled={busy || !!blocker} className={`${PRIMARY_BTN} mt-4 w-full sm:w-auto`}>
                {busy ? 'Registrando…' : `Importar ${plural(counts.a_importar ?? 0, 'persona', 'personas')}`}
            </button>
            {asking && (
                <ConfirmDialog
                    title={confirmDialogTitle(counts)}
                    message={confirmDialogMessage(counts)}
                    confirmLabel="Sí, importar"
                    cancelLabel="No, revisar otra vez"
                    onConfirm={importNow}
                    onCancel={() => setAsking(false)}
                />
            )}
        </form>
    );
}
