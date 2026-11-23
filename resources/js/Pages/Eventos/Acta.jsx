import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ActaCompromisos from '@/Components/Eventos/ActaCompromisos';
import ActaCorreccionResumen from '@/Components/Eventos/ActaCorreccionResumen';
import ActaEliminarConfirmada from '@/Components/Eventos/ActaEliminarConfirmada';
import ActaHistorial from '@/Components/Eventos/ActaHistorial';
import ActaParticipantes, { inputCls } from '@/Components/Eventos/ActaParticipantes';
import { Desarrollo, OrdenDia } from '@/Components/Eventos/ActaPuntos';
import ActaSugerencias from '@/Components/Eventos/ActaSugerencias';
import CampaignChip from '@/Components/CampaignChip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { resetCampanaCache } from '@/Components/Eventos/CompromisosCampana';
import {
    applyAnswers, canCorrect, compromisosBloqueados, confirmMessage, correctionDirty, correctionLines, correctionMessage, correctionPayload, correctionSummary,
    correctionUrl, failure, fieldId, formatDateTime, historialItems, isConflict, mergeRead, request, restoreCompromisos, rowError, suggestionList,
    suggestionRequest, toForm, toPayload,
} from '@/lib/actas';

const MAX_BYTES = 10 * 1024 * 1024;

function Section({ title, children }) {
    return (
        <section className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3">
            <h2 className="text-[16px] font-extrabold text-[var(--color-ink)]">{title}</h2>
            {children}
        </section>
    );
}

// What the confirm dialog promises about afterwards, by what this user may do with a confirmed acta.
function despuesDeConfirmar(canEdit, canDelete) {
    if (canEdit && canDelete) return 'Después de confirmar, solo se podrá cambiar con «Corregir acta» o «Eliminar acta».';
    if (canEdit) return 'Después de confirmar, solo se podrá cambiar con «Corregir acta».';
    if (canDelete) return 'Después de confirmar no se podrá editar; solo eliminar con «Eliminar acta».';
    return 'Después de confirmar no se podrá editar ni borrar.';
}

// Acta de reunión of an event or, with `origen.tipo === 'organizacion'`, of an organization (docs/empresarios.md).
// A BORRADOR is written by hand or prefilled from a Word file, saved as many times as needed and deleted freely;
// "Confirmar acta" writes Votantes and the attendees (an organization: its members) and leaves it read-only; with
// can.edit_confirmed (edit.actas) "Corregir acta" opens the same editor and saves through PUT …/correccion.
export default function Acta({ evento, acta, defaults, asistentes, can, origen = null, historial = null }) {
    const isOrg = origen?.tipo === 'organizacion';
    const back = origen?.volver ?? `/eventos/${evento.id}`;
    const seccion = origen?.seccion ?? { label: 'EVENTOS', href: '/eventos' };
    const destino = isOrg ? 'como miembros de la organización' : 'como asistentes del evento';
    const confirmed = acta?.estado === 'confirmada';
    const correctable = canCorrect(can, acta);
    const [correcting, setCorrecting] = useState(false);
    const editable = !!can?.edit || (correctable && correcting);
    const historia = historialItems(historial);
    const permissions = usePage()?.props?.auth?.user?.permissions ?? [];
    const hasPermission = name => permissions.includes('*') || permissions.includes(name);
    const [form, setForm] = useState(() => toForm(acta, defaults ?? {}));
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [avisos, setAvisos] = useState([]);
    const [questions, setQuestions] = useState(null);
    const [ask, setAsk] = useState(null);
    const [summary, setSummary] = useState(null);
    const [conflict, setConflict] = useState(false);
    const fileRef = useRef(null);
    // One correction request at a time, whatever the clicks: state updates are too late for a double click.
    const pending = useRef(false);
    // The row the server refused (422 participantes.N.* / compromisos.N.*): marked and focused.
    const [rowErr, setRowErr] = useState(null);
    // Our own visits (after saving, «Recargar») leave without asking.
    const leaving = useRef(false);
    const dirty = useMemo(() => correcting && correctionDirty(acta, form, file), [correcting, acta, form, file]);

    useEffect(() => {
        if (rowErr) document.getElementById(fieldId(rowErr.key, rowErr.field))?.focus();
    }, [rowErr]);

    // A correction with changes is not lost by closing the tab or following a link without being asked.
    useEffect(() => {
        if (!dirty) return undefined;
        const onBeforeUnload = e => { e.preventDefault(); e.returnValue = ''; };
        window.addEventListener('beforeunload', onBeforeUnload);
        const off = router.on?.('before', event => {
            // A prefetch (hovering a menu link) is not leaving the page.
            if (leaving.current || event.detail?.visit?.prefetch) return;
            if (!window.confirm('¿Descartar los cambios de la corrección? Lo que cambió no se guardará.')) event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            if (typeof off === 'function') off();
        };
    }, [dirty]);

    function showFailure(res, fallback) {
        const row = res.status === 422 ? rowError(res.body, form) : null;
        setRowErr(row);
        setError(row ? row.summary : failure(res, fallback));
    }

    const set = (field, value) => setForm(f => ({ ...f, [field]: value }));
    const base = origen?.base ?? `/eventos/${evento.id}/actas`;

    async function onFile(e) {
        const chosen = e.target.files?.[0] ?? null;
        setError('');
        setNotice('');
        setAvisos([]);
        if (!chosen) { setFile(null); return; }
        if (chosen.size > MAX_BYTES) { setError('El archivo pesa más de 10 MB.'); e.target.value = ''; setFile(null); return; }
        setBusy(true);
        const data = new FormData();
        data.append('archivo', chosen);
        const res = await request(`${base}/leer`, { method: 'POST', form: data });
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo leer el archivo.')); e.target.value = ''; setFile(null); return; }
        setFile(chosen);
        const read = res.body.data;
        if (correcting) { setNotice('El archivo reemplazará al original cuando guarde la corrección. El formulario no cambia.'); return; }
        if ((read.tipo === 'docx' || read.tipo === 'excel') && read.acta) {
            setForm(f => mergeRead(f, read.acta));
            setAvisos(read.acta.avisos ?? []);
        }
        setNotice(read.message);
    }

    // Saves the borrador (and the chosen file); with confirmNow, confirms it right after. Then shows the saved acta.
    async function send(payload, confirmNow = false) {
        setBusy(true);
        const res = await request(acta ? `${base}/${acta.id}` : base, { method: acta ? 'PUT' : 'POST', json: payload });
        if (!res.ok) { setBusy(false); showFailure(res, 'No se pudo guardar el acta.'); return; }
        const saved = res.body.data;
        let message = res.body.message;
        if (file) {
            const data = new FormData();
            data.append('archivo', file);
            const up = await request(`${base}/${saved.id}/archivo`, { method: 'POST', form: data });
            if (!up.ok) message += ` El archivo original NO se guardó: ${failure(up, 'intente subirlo de nuevo.')}`;
        }
        let failed = false;
        if (confirmNow) {
            const done = await request(`${base}/${saved.id}/confirmar`, { method: 'POST', json: {} });
            failed = !done.ok;
            if (done.ok) resetCampanaCache();
            message = done.ok ? confirmMessage(done.body, isOrg) : `Borrador guardado, pero NO se confirmó: ${(done.status === 422 && rowError(done.body, form)?.summary) || failure(done, 'intente de nuevo.')}`;
        }
        setBusy(false);
        // Same page with the saved acta: the form takes the saved rows, so the next save replaces them.
        router.visit(`${base}/${saved.id}`, {
            preserveState: true,
            onSuccess: page => {
                setForm(toForm(page.props.acta));
                setFile(null);
                if (fileRef.current) fileRef.current.value = '';
                if (failed) setError(message);
                else setNotice(message);
                window.scrollTo?.(0, 0);
            },
        });
    }

    // "¿Es esta persona?" for the rows without a choice yet. Saving a borrador lets them wait; confirming asks them all.
    async function run(confirmNow) {
        setError('');
        setNotice('');
        const payload = toPayload(form);
        if (!payload.titulo) { setError('Escriba el título del acta.'); return; }
        setBusy(true);
        const req = suggestionRequest(payload, asistentes);
        let list = [];
        if (req.body.participantes.length || req.body.responsables.length) {
            const res = await request(`${base}/sugerencias`, { method: 'POST', json: req.body });
            if (!res.ok) { setBusy(false); setError(failure(res, 'No se pudo revisar quién ya está en Votantes.')); return; }
            list = suggestionList(res.body.data, req, payload);
        }
        setBusy(false);
        if (list.length) { setQuestions({ list, payload, confirmNow }); return; }
        send(payload, confirmNow);
    }

    function save(e) {
        e.preventDefault();
        if (correcting) reviewCorrection();
        else run(false);
    }

    function clearMessages() {
        setRowErr(null);
        setError('');
        setNotice('');
        setConflict(false);
    }

    function startCorrection() {
        clearMessages();
        setCorrecting(true);
    }

    function askCancelCorrection() {
        if (dirty) setAsk('discard');
        else cancelCorrection();
    }

    function cancelCorrection() {
        setAsk(null);
        clearMessages();
        setForm(toForm(acta));
        setFile(null);
        if (fileRef.current) fileRef.current.value = '';
        setCorrecting(false);
    }

    // Shows what the correction changes before saving it; nothing changed and no file: nothing to send.
    function reviewCorrection() {
        clearMessages();
        const payload = toPayload(form);
        if (!payload.titulo) { setError('Escriba el título del acta.'); return; }
        // An existing row left blank would be dropped from the payload and count as removed without saying so.
        const sinNombre = form.participantes.findIndex(p => p.id && !String(p.nombre ?? '').trim());
        if (sinNombre >= 0) { setError(`Escriba el nombre del participante ${sinNombre + 1} o use «Quitar».`); return; }
        const sinDescripcion = form.compromisos.findIndex(c => c.id && !String(c.descripcion ?? '').trim());
        if (sinDescripcion >= 0) { setError(`Escriba el compromiso ${sinDescripcion + 1} o use «Quitar».`); return; }
        const changes = correctionSummary(acta, payload, asistentes);
        if (!changes.changed && !file) { setNotice('No hay cambios para guardar.'); return; }
        const lines = changes.changed ? correctionLines(changes, isOrg) : [];
        if (file) lines.unshift(`Se reemplaza el archivo original por «${file.name}». El anterior no se borra.`);
        setSummary({ lines, payload, changed: changes.changed });
    }

    // "¿Es esta persona?" for the people added (all must be answered, as when confirming), then the correction.
    async function runCorrection() {
        if (pending.current) return;
        pending.current = true;
        const { payload, changed } = summary;
        setBusy(true);
        if (changed) {
            const req = suggestionRequest(payload, asistentes, acta);
            if (req.body.participantes.length || req.body.responsables.length) {
                const res = await request(`${base}/sugerencias`, { method: 'POST', json: req.body });
                if (!res.ok) { pending.current = false; setBusy(false); setSummary(null); setError(failure(res, 'No se pudo revisar quién ya está en Votantes.')); return; }
                const list = suggestionList(res.body.data, req, payload);
                if (list.length) {
                    pending.current = false;
                    setBusy(false);
                    setSummary(null);
                    setQuestions({ list, payload, correccion: true });
                    return;
                }
            }
        }
        await sendCorrection(payload, changed);
    }

    async function answerCorrection(payload) {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        await sendCorrection(payload, true);
    }

    // PUT …/correccion with the version the page was opened with; a 409 means someone else saved first.
    async function sendCorrection(payload, changed) {
        let message = 'Archivo original reemplazado.';
        if (changed) {
            const res = await request(correctionUrl(base, acta.id), { method: 'PUT', json: correctionPayload(payload, acta) });
            if (!res.ok) {
                pending.current = false;
                setBusy(false);
                setSummary(null);
                setConflict(isConflict(res));
                const bloqueos = res.status === 422 ? compromisosBloqueados(res.body) : {};
                if (Object.keys(bloqueos).length) {
                    setForm(f => restoreCompromisos(f, acta, bloqueos));
                    setError('No se guardó nada: hay compromisos con avances que no se pueden quitar. Volvieron a la sección «4. Compromisos» con la explicación.');
                } else {
                    showFailure(res, 'No se pudo guardar la corrección.');
                }
                window.scrollTo?.(0, 0);
                return;
            }
            message = correctionMessage(res.body, isOrg);
            resetCampanaCache();
        }
        let failed = false;
        if (file) {
            const data = new FormData();
            data.append('archivo', file);
            const up = await request(`${base}/${acta.id}/archivo`, { method: 'POST', form: data });
            if (!up.ok) {
                failed = !changed;
                message = changed ? `${message} El archivo original NO se reemplazó: ${failure(up, 'intente subirlo de nuevo.')}` : failure(up, 'No se pudo reemplazar el archivo original.');
            }
        }
        setSummary(null);
        leaving.current = true;
        router.visit(`${base}/${acta.id}`, {
            preserveState: true,
            onSuccess: page => {
                setForm(toForm(page.props.acta));
                setFile(null);
                if (fileRef.current) fileRef.current.value = '';
                setCorrecting(false);
                if (failed) setError(message);
                else setNotice(message);
                window.scrollTo?.(0, 0);
            },
            onFinish: () => { pending.current = false; leaving.current = false; setBusy(false); },
        });
    }

    function reload() {
        leaving.current = true;
        router.visit(`${base}/${acta.id}`, { preserveState: false });
    }

    async function remove() {
        setAsk(null);
        setBusy(true);
        const res = await request(`${base}/${acta.id}`, { method: 'DELETE' });
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo borrar el acta.')); return; }
        router.visit(back);
    }

    return (
        <AppLayout title="Acta de reunión" breadcrumb={[seccion, { label: evento.nombre, href: back }, { label: 'Acta' }]}>
            <Head title={`Acta — ${evento.nombre}`} />
            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href={back} className="inline-flex items-center min-h-[36px] text-[14px] font-semibold text-white/75 hover:text-white">← {evento.nombre}</Link>
                <div className="flex flex-wrap items-center justify-between gap-3 mt-1">
                    <div>
                        <CampaignChip campaignId={evento.campaign?.id} />
                        <h1 className="text-[20px] lg:text-[24px] font-extrabold leading-tight">{acta ? 'Acta de reunión' : 'Nueva acta de reunión'}</h1>
                        <p className="text-[14px] text-white/85 mt-0.5">
                            {confirmed
                                ? `Confirmada${acta.confirmed_at ? ` el ${formatDateTime(acta.confirmed_at)}` : ''}${acta.confirmed_by ? ` por ${acta.confirmed_by}` : ''}${correctable ? '. Si quedó algo mal, use «Corregir acta».' : ': ya no se puede editar ni borrar.'}`
                                : `Borrador: se puede editar y borrar. Nada pasa a Votantes ${isOrg ? 'ni a los miembros' : 'ni a los asistentes'} hasta confirmarla.`}
                        </p>
                    </div>
                    {acta && (
                        <div className="flex flex-wrap gap-2">
                            <a href={`${base}/${acta.id}/acta.docx`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Descargar Word</a>
                            <a href={`${base}/${acta.id}/acta.pdf`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Descargar PDF</a>
                            {acta.archivo && <a href={`${base}/${acta.id}/archivo`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Archivo original</a>}
                            {correctable && !correcting && (
                                <button type="button" onClick={startCorrection} className="min-h-[48px] px-4 py-2.5 bg-white rounded-xl text-[16px] font-bold text-[var(--color-primary)] flex items-center">
                                    Corregir acta
                                </button>
                            )}
                            {confirmed && can?.delete_confirmed && !correcting && (
                                <ActaEliminarConfirmada
                                    url={`${base}/${acta.id}`}
                                    onError={setError}
                                    onDeleted={() => { resetCampanaCache(); router.visit(back); }}
                                />
                            )}
                        </div>
                    )}
                </div>
            </div>

            <form onSubmit={save} className="p-4 lg:px-6 space-y-4">
                {notice && <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{notice}</div>}
                {error && (
                    <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700 flex flex-wrap items-center justify-between gap-3">
                        <span>{error}</span>
                        {conflict && (
                            <button type="button" onClick={reload} className="min-h-[48px] px-5 rounded-xl bg-red-700 text-white text-[16px] font-bold">Recargar</button>
                        )}
                    </div>
                )}
                {correcting && (
                    <div className="px-4 py-3 rounded-xl bg-amber-50 border-2 border-amber-300 text-[16px] text-amber-900 space-y-1">
                        <p className="font-extrabold">Está corrigiendo un acta ya confirmada.</p>
                        <p>Nada cambia hasta que pulse «Guardar corrección». Las personas que agregue pasan a Votantes y quedan {destino}; las que quite dejan de figurar {isOrg ? 'como miembros' : 'como asistentes'}, pero su ficha no se borra. Los avances de los compromisos se conservan.</p>
                    </div>
                )}
                {avisos.length > 0 && (
                    <div role="status" className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-[15px] text-amber-900 space-y-1">
                        {avisos.map(a => <p key={a}>{a}</p>)}
                    </div>
                )}

                {editable && (
                    <Section title={acta ? 'Archivo del acta' : '¿Ya tiene el acta en Word o Excel?'}>
                        {!acta && (
                            <p className="text-[15px] text-[var(--color-ink-soft)]">
                                Si ya la escribieron en Word o en la plantilla Excel, súbala aquí: <b>el sistema la lee y llena el formulario de abajo</b> para que solo lo revise.
                                Si no, déjelo así y llene el formulario directamente.
                            </p>
                        )}
                        {acta?.archivo && <p className="text-[14px] text-[var(--color-ink)]">Archivo guardado: <b>{acta.archivo.nombre}</b>. Si sube otro, lo reemplaza{correcting ? ' (el anterior no se borra)' : ''}.</p>}
                        {correcting && <p className="text-[16px] text-[var(--color-ink-soft)]">En una corrección el archivo solo se guarda como original: el formulario no se llena con él.</p>}
                        <input ref={fileRef} id="acta-archivo" type="file" accept=".docx,.xlsx,.xls,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/pdf,image/jpeg,image/png" onChange={onFile} disabled={busy} className="sr-only" />
                        <div className="flex flex-col sm:flex-row sm:items-center gap-3 p-4 rounded-xl border-2 border-dashed border-[var(--color-line-strong)] bg-[var(--color-surface,#f8fafc)]">
                            <label
                                htmlFor="acta-archivo"
                                className={`inline-flex items-center justify-center gap-2 min-h-[48px] px-5 rounded-xl bg-[var(--color-primary)] text-white text-[15px] font-bold ${busy ? 'opacity-60 pointer-events-none' : 'cursor-pointer hover:opacity-90'}`}
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                                {busy ? 'Leyendo el acta…' : (file ? 'Cambiar archivo' : 'Cargar Word o Excel')}
                            </label>
                            <a href={`${base}/plantilla.xlsx`} className="inline-flex items-center justify-center gap-2 min-h-[48px] px-5 rounded-xl border border-[var(--color-line-strong)] bg-white text-[var(--color-primary)] text-[15px] font-bold hover:bg-blue-50">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                Descargar plantilla Excel
                            </a>
                            <p className="text-[14px] text-[var(--color-ink-soft)]" aria-live="polite">
                                {file
                                    ? <>Archivo: <b className="text-[var(--color-ink)]">{file.name}</b></>
                                    : 'Word (.docx) o la plantilla Excel (.xlsx o .xls) para llenar el formulario solo. También acepta PDF o foto, que se guarda como adjunto (el formulario se llena a mano). Máximo 10 MB.'}
                            </p>
                        </div>
                    </Section>
                )}

                <Section title="Datos de la reunión">
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Título
                        <input className={inputCls} value={form.titulo} disabled={!editable} maxLength={255} required onChange={e => set('titulo', e.target.value)} />
                    </label>
                    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Fecha
                            <input type="date" className={inputCls} value={form.fecha} disabled={!editable} onChange={e => set('fecha', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Municipio
                            <input className={inputCls} value={form.municipio} disabled={!editable} maxLength={120} onChange={e => set('municipio', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Hora de inicio
                            <input type="time" className={inputCls} value={form.hora_inicio} disabled={!editable} onChange={e => set('hora_inicio', e.target.value)} />
                        </label>
                        <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Hora de fin
                            <input type="time" className={inputCls} value={form.hora_fin} disabled={!editable} onChange={e => set('hora_fin', e.target.value)} />
                        </label>
                    </div>
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Sitio
                        <input className={inputCls} value={form.sitio} disabled={!editable} maxLength={255} onChange={e => set('sitio', e.target.value)} />
                    </label>
                </Section>

                <Section title="1. Participantes">
                    {editable && !correcting && <p className="text-[14px] text-[var(--color-ink-soft)]">Al confirmar el acta, cada participante queda en Votantes (si ya está, se vincula; si no, se crea su ficha) y {destino}.</p>}
                    {correcting && <p className="text-[16px] text-[var(--color-ink-soft)]">Si corrige el nombre, el teléfono o la cédula de alguien que ya estaba, su ficha en Votantes no cambia: solo se completan los datos que estén vacíos. Al guardar, las personas nuevas pasan a Votantes y quedan {destino}.</p>}
                    <ActaParticipantes error={rowErr} items={form.participantes} onChange={v => set('participantes', v)} asistentes={asistentes} municipio={form.municipio} disabled={!editable} confirmed={confirmed} searchUrl={origen?.buscar} />
                </Section>

                <Section title="2. Puntos del orden del día">
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Introducción (opcional)
                        <textarea className={`${inputCls} min-h-[60px]`} value={form.introduccion} disabled={!editable} maxLength={5000} onChange={e => set('introduccion', e.target.value)} />
                    </label>
                    <OrdenDia items={form.orden_dia} onChange={v => set('orden_dia', v)} disabled={!editable} />
                </Section>

                <Section title="3. Desarrollo de la reunión">
                    <Desarrollo items={form.desarrollo} onChange={v => set('desarrollo', v)} orden={form.orden_dia} disabled={!editable} />
                </Section>

                <Section title="4. Compromisos">
                    {editable && <p className="text-[14px] text-[var(--color-ink-soft)]">Si un responsable ya está en Votantes, se le pregunta si es esa persona; si no, queda solo con nombre y teléfono.</p>}
                    {correcting && <p className="text-[16px] text-[var(--color-ink-soft)]">El estado y los avances de cada compromiso se conservan. Un compromiso con avances no se puede quitar.</p>}
                    <ActaCompromisos error={rowErr} items={form.compromisos} onChange={v => set('compromisos', v)} disabled={!editable} confirmed={confirmed} />
                    {confirmed && !correcting && <Link href={isOrg ? '/eventos/compromisos?origen=organizacion' : '/eventos/compromisos'} className="inline-flex min-h-[44px] items-center text-[15px] font-bold text-[var(--color-primary)] underline">Seguimiento de los compromisos (estados y avances)</Link>}
                </Section>

                <Section title="Cierre">
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">
                        <span className="sr-only">Cierre</span>
                        <textarea className={`${inputCls} min-h-[60px]`} value={form.cierre} disabled={!editable} maxLength={2000} placeholder="Siendo las 12:00 m., se dio por terminada la reunión." onChange={e => set('cierre', e.target.value)} />
                    </label>
                </Section>

                {confirmed && historia && (
                    <Section title="Historial de cambios">
                        <ActaHistorial items={historia} />
                    </Section>
                )}

                {correcting && (
                    <div className="sticky bottom-[72px] lg:bottom-0 bg-white/95 border-t border-[var(--color-line)] py-3 flex flex-wrap gap-3">
                        <button type="button" disabled={busy} onClick={askCancelCorrection} className="min-h-[48px] px-5 rounded-xl border-2 border-gray-200 text-[16px] font-bold text-[var(--color-ink-soft)] disabled:opacity-50">
                            Cancelar corrección
                        </button>
                        <button type="submit" disabled={busy} className="flex-1 sm:flex-none min-h-[48px] px-6 rounded-xl bg-emerald-600 text-white text-[16px] font-bold disabled:opacity-50">
                            {busy ? 'Guardando…' : 'Guardar corrección'}
                        </button>
                    </div>
                )}

                {editable && !correcting && (
                    <div className="sticky bottom-[72px] lg:bottom-0 bg-white/95 border-t border-[var(--color-line)] py-3 flex flex-wrap gap-3">
                        <Link href={back} className="min-h-[48px] px-5 rounded-xl border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)] flex items-center">Cancelar</Link>
                        <button type="submit" disabled={busy} className="flex-1 sm:flex-none min-h-[48px] px-6 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[16px] font-bold disabled:opacity-50">
                            {busy ? 'Guardando…' : 'Guardar borrador'}
                        </button>
                        <button type="button" disabled={busy} onClick={() => setAsk('confirm')} className="flex-1 sm:flex-none min-h-[48px] px-6 rounded-xl bg-emerald-600 text-white text-[16px] font-bold disabled:opacity-50">
                            Confirmar acta
                        </button>
                        {can?.delete && (
                            <button type="button" disabled={busy} onClick={() => setAsk('delete')} className="min-h-[48px] px-4 rounded-xl border-2 border-red-300 text-red-700 text-[15px] font-bold disabled:opacity-50 sm:ml-auto">
                                Borrar acta
                            </button>
                        )}
                    </div>
                )}
            </form>

            {questions && (
                <ActaSugerencias
                    items={questions.list}
                    allowSkip={!questions.confirmNow && !questions.correccion}
                    doneLabel={questions.correccion ? 'Guardar corrección' : (questions.confirmNow ? 'Confirmar acta' : 'Guardar borrador')}
                    onCancel={() => setQuestions(null)}
                    onDone={answers => {
                        const payload = applyAnswers(questions.payload, answers);
                        const { confirmNow, correccion } = questions;
                        setQuestions(null);
                        if (correccion) answerCorrection(payload);
                        else send(payload, confirmNow);
                    }}
                />
            )}

            {summary && (
                <ActaCorreccionResumen lines={summary.lines} busy={busy} onConfirm={runCorrection} onCancel={() => setSummary(null)} />
            )}

            {ask === 'confirm' && (
                <ConfirmDialog
                    title="¿Confirmar el acta?"
                    message={`Los participantes pasan a Votantes y quedan ${destino}. Si hay personas por confirmar, se le preguntará antes. ${despuesDeConfirmar(hasPermission('edit.actas'), hasPermission('delete.actas'))}`}
                    confirmLabel="Sí, confirmar"
                    onConfirm={() => { setAsk(null); run(true); }}
                    onCancel={() => setAsk(null)}
                />
            )}
            {ask === 'discard' && (
                <ConfirmDialog
                    title="¿Descartar los cambios?"
                    message="Lo que cambió en esta corrección no se guardará. El acta queda como estaba."
                    confirmLabel="Sí, descartar"
                    cancelLabel="No, seguir corrigiendo"
                    danger
                    onConfirm={cancelCorrection}
                    onCancel={() => setAsk(null)}
                />
            )}
            {ask === 'delete' && (
                <ConfirmDialog
                    title="¿Borrar el acta?"
                    message="Se borra el borrador completo: participantes, compromisos, responsables y el archivo original. No se puede deshacer."
                    confirmLabel="Sí, borrar"
                    danger
                    cancelLabel="No, mantener"
                    onConfirm={remove}
                    onCancel={() => setAsk(null)}
                />
            )}
        </AppLayout>
    );
}
