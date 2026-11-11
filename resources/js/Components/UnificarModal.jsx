import { useEffect, useRef, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';
import useEscapeKey from '@/lib/useEscapeKey';
import useDialogFocus from '@/lib/useDialogFocus';

const MIN_CHARS = 2;

async function errorMessage(r, fallback) {
    const body = await r.json().catch(() => null);
    if (r.status === 401 || r.status === 419) return 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.';
    if (r.status === 404) return body?.message && body.message !== 'Not Found' ? body.message : 'Ese registro ya no existe o no lo puedes ver.';
    const firstError = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return firstError || body?.message || body?.error || fallback;
}

function detailOf(r) {
    if (r.tipo === 'candidato') {
        const c = r.candidatura || {};
        return [c.cargo, c.territorio, c.anio, c.partido].filter(Boolean).join(' · ');
    }
    return [r.cargo, r.municipio, r.cedula ? `CC ${r.cedula}` : null, r.telefono].filter(Boolean).join(' · ');
}

function Row({ label, children }) {
    return (
        <div className="py-2 border-b border-[var(--color-line)] last:border-0">
            <p className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">{label}</p>
            <div className="text-[14px] text-[var(--color-ink)] mt-0.5">{children}</div>
        </div>
    );
}

// "Unificar con otra persona": search a lider of the campaign or a candidate, see how the ficha will look and confirm.
// onDone(id) gets the record that remains, which the ficha then opens.
export default function UnificarModal({ recordId, recordName, onClose, onDone }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [chosen, setChosen] = useState(null);
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState('');
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [cedula, setCedula] = useState(null);
    const [saving, setSaving] = useState(false);
    const dialogRef = useRef(null);

    useEscapeKey(() => { if (!saving) onClose(); }, dialogRef);
    useDialogFocus(dialogRef);

    useEffect(() => {
        const q = query.trim();
        if (q.length < MIN_CHARS) {
            setResults([]);
            setSearchError('');
            return undefined;
        }
        let active = true;
        const timer = setTimeout(async () => {
            setSearching(true);
            try {
                const r = await apiFetch(`/mapa-politico/persona/${recordId}/unificar-con/buscar?q=${encodeURIComponent(q)}`, { method: 'GET' });
                if (!active) return;
                if (!r.ok) {
                    setResults([]);
                    setSearchError(await errorMessage(r, 'No se pudo buscar. Intenta de nuevo.'));
                    return;
                }
                const res = await r.json();
                setResults(res.data || []);
                setSearchError('');
            } catch {
                if (active) setSearchError('No se pudo buscar. Revisa tu conexión.');
            } finally {
                if (active) setSearching(false);
            }
        }, 300);
        return () => { active = false; clearTimeout(timer); };
    }, [query, recordId]);

    async function loadPreview(other, cedulaElegida = null) {
        setLoadingPreview(true);
        setPreviewError('');
        try {
            const r = await apiFetch(`/mapa-politico/persona/${recordId}/unificar-con/vista-previa`, {
                method: 'POST',
                body: JSON.stringify({ con: other.id, cedula: cedulaElegida }),
            });
            if (!r.ok) {
                setPreview(null);
                setPreviewError(await errorMessage(r, 'No se puede unificar con este registro.'));
                return;
            }
            setPreview(await r.json());
        } catch {
            setPreview(null);
            setPreviewError('No se pudo preparar la vista previa. Revisa tu conexión.');
        } finally {
            setLoadingPreview(false);
        }
    }

    function choose(other) {
        setChosen(other);
        setCedula(null);
        setPreview(null);
        loadPreview(other);
    }

    function pickCedula(c) {
        setCedula(c);
        loadPreview(chosen, c);
    }

    function back() {
        setChosen(null);
        setPreview(null);
        setPreviewError('');
        setCedula(null);
    }

    async function confirmMerge() {
        setSaving(true);
        try {
            const r = await apiFetch(`/mapa-politico/persona/${recordId}/unificar-con`, {
                method: 'POST',
                body: JSON.stringify({ con: chosen.id, cedula }),
            });
            if (!r.ok) {
                const msg = await errorMessage(r, 'No se pudo unificar. Intenta de nuevo.');
                setPreviewError(msg);
                showGlobalToast(msg, 'error');
                return;
            }
            const res = await r.json();
            showGlobalToast(res.message || 'Registros unificados.');
            onDone(res.id);
        } catch {
            setPreviewError('No se pudo unificar. Revisa tu conexión e intenta de nuevo.');
        } finally {
            setSaving(false);
        }
    }

    const needsCedula = Boolean(preview?.cedulas) && !cedula;
    const final = preview?.final;

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-[60]" onClick={() => { if (!saving) onClose(); }} />
            <div className="fixed inset-0 z-[70] flex items-end lg:items-center justify-center lg:p-4 pointer-events-none">
                <div
                    ref={dialogRef}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="unificar-title"
                    className="pointer-events-auto bg-white w-full max-w-[560px] max-h-[90vh] overflow-y-auto rounded-t-2xl lg:rounded-2xl shadow-2xl"
                >
                    <div className="flex items-start justify-between gap-3 px-5 py-4 border-b border-[var(--color-line)]">
                        <div className="min-w-0">
                            <h2 id="unificar-title" className="text-[17px] font-extrabold text-[var(--color-ink)]">Unificar con otra persona</h2>
                            <p className="text-[13px] text-[var(--color-ink-faint)] truncate">«{recordName}» y la persona que elijas quedarán como un solo registro.</p>
                        </div>
                        <button type="button" onClick={onClose} disabled={saving} aria-label="Cerrar" className="w-9 h-9 flex-shrink-0 flex items-center justify-center rounded-full hover:bg-gray-100">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    {!chosen ? (
                        <div className="px-5 py-4">
                            <label htmlFor="unificar-q" className="text-[13px] font-semibold text-[var(--color-ink)]">Buscar por nombre, cédula o teléfono</label>
                            <input
                                id="unificar-q"
                                autoFocus
                                value={query}
                                onChange={e => setQuery(e.target.value)}
                                placeholder="Escribe al menos 2 letras o números"
                                className="mt-1 w-full px-4 py-3 text-[15px] border-2 border-[var(--color-line-strong)] rounded-xl focus:border-[var(--color-primary)] outline-none"
                            />
                            <div className="mt-3 min-h-[60px]" aria-live="polite">
                                {query.trim().length < MIN_CHARS && <p className="text-[13px] text-[var(--color-ink-faint)]">Escribe al menos 2 caracteres para buscar.</p>}
                                {searching && <p className="text-[13px] text-[var(--color-ink-faint)]">Buscando...</p>}
                                {searchError && <p className="text-[13px] text-red-700">{searchError}</p>}
                                {!searching && !searchError && query.trim().length >= MIN_CHARS && results.length === 0 && (
                                    <p className="text-[13px] text-[var(--color-ink-faint)]">No se encontró a nadie con esa búsqueda.</p>
                                )}
                                <ul className="space-y-2">
                                    {results.map(r => (
                                        <li key={r.id}>
                                            <button type="button" onClick={() => choose(r)} className="w-full text-left px-4 py-3 rounded-xl border border-[var(--color-line)] hover:border-[var(--color-primary)] hover:bg-blue-50 transition-colors">
                                                <span className="flex items-center gap-2">
                                                    <strong className="text-[14px] text-[var(--color-ink)]">{r.nombre}</strong>
                                                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${r.tipo === 'candidato' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-[var(--color-primary)]'}`}>
                                                        {r.tipo === 'candidato' ? 'Candidato' : 'Líder'}
                                                    </span>
                                                </span>
                                                {detailOf(r) && <span className="block text-[12px] text-[var(--color-ink-faint)] mt-0.5">{detailOf(r)}</span>}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    ) : (
                        <div className="px-5 py-4">
                            <p className="text-[13px] text-[var(--color-ink-faint)]">
                                Unificar con <strong className="text-[var(--color-ink)]">{chosen.nombre}</strong>
                            </p>

                            {preview?.cedulas && (
                                <fieldset className="mt-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200">
                                    <legend className="sr-only">Cédula correcta</legend>
                                    <p className="text-[13px] font-bold text-amber-800">Los dos registros tienen cédulas distintas. ¿Cuál es la correcta?</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {preview.cedulas.map(c => (
                                            <label key={c} className={`flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-[14px] ${cedula === c ? 'border-[var(--color-primary)] bg-white font-bold' : 'border-amber-300'}`}>
                                                <input type="radio" name="cedula" value={c} checked={cedula === c} onChange={() => pickCedula(c)} disabled={loadingPreview || saving} />
                                                {c}
                                            </label>
                                        ))}
                                    </div>
                                    <p className="mt-2 text-[12px] text-amber-800">La otra queda anotada como cédula anterior.</p>
                                </fieldset>
                            )}

                            {loadingPreview && <p className="mt-3 text-[13px] text-[var(--color-ink-faint)]">Preparando la vista previa...</p>}
                            {previewError && <p role="alert" className="mt-3 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[14px] font-semibold text-red-700">{previewError}</p>}

                            {final && !loadingPreview && (
                                <div className="mt-3 px-4 py-2 rounded-xl border border-[var(--color-line)]">
                                    <p className="pt-2 text-[12px] font-bold uppercase tracking-wider text-[var(--color-primary)]">Así quedará</p>
                                    <Row label="Nombre">{final.nombre}</Row>
                                    <Row label="Cédula">{needsCedula ? 'Elige la cédula correcta' : (final.cedula || '—')}</Row>
                                    <Row label="Teléfonos">{final.telefonos?.length ? final.telefonos.join(', ') : '—'}</Row>
                                    <Row label="Cargos">{final.cargos?.length ? final.cargos.join(', ') : '—'}</Row>
                                    <Row label="Candidaturas">
                                        {final.candidaturas?.length ? (
                                            <ul className="space-y-0.5">
                                                {final.candidaturas.map((c, i) => (
                                                    <li key={i}>{[c.cargo, c.territorio, c.anio, c.partido].filter(Boolean).join(' · ')}</li>
                                                ))}
                                            </ul>
                                        ) : '—'}
                                    </Row>
                                </div>
                            )}

                            <div className="mt-4 flex flex-wrap gap-2 justify-end">
                                <button type="button" onClick={back} disabled={saving} className="px-4 py-2.5 bg-white border border-[var(--color-line-strong)] text-[13px] font-semibold rounded-lg hover:bg-gray-50">
                                    Elegir otra persona
                                </button>
                                <button
                                    type="button"
                                    onClick={confirmMerge}
                                    disabled={!preview || needsCedula || loadingPreview || saving}
                                    className="px-5 py-2.5 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg hover:opacity-90 disabled:opacity-50"
                                >
                                    {saving ? 'Unificando...' : 'Sí, unificar'}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
