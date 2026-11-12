import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ActaCompromisos from '@/Components/Eventos/ActaCompromisos';
import ActaEliminarConfirmada from '@/Components/Eventos/ActaEliminarConfirmada';
import ActaParticipantes, { inputCls } from '@/Components/Eventos/ActaParticipantes';
import { Desarrollo, OrdenDia } from '@/Components/Eventos/ActaPuntos';
import ActaSugerencias from '@/Components/Eventos/ActaSugerencias';
import CampaignChip from '@/Components/CampaignChip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { resetCampanaCache } from '@/Components/Eventos/CompromisosCampana';
import { applyAnswers, failure, formatDateTime, mergeRead, request, suggestionList, suggestionRequest, toForm, toPayload } from '@/lib/actas';

const MAX_BYTES = 10 * 1024 * 1024;

function Section({ title, children }) {
    return (
        <section className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-3">
            <h2 className="text-[16px] font-extrabold text-[var(--color-ink)]">{title}</h2>
            {children}
        </section>
    );
}

// Acta de reunión of an event or, with `origen.tipo === 'organizacion'`, of an organization (docs/empresarios.md).
// A BORRADOR is written by hand or prefilled from a Word file, saved as many times as needed and deleted freely;
// "Confirmar acta" writes Votantes and the attendees (an organization: its members) and leaves it read-only.
export default function Acta({ evento, acta, defaults, asistentes, can, origen = null }) {
    const isOrg = origen?.tipo === 'organizacion';
    const back = origen?.volver ?? `/eventos/${evento.id}`;
    const seccion = origen?.seccion ?? { label: 'EVENTOS', href: '/eventos' };
    const destino = isOrg ? 'como miembros de la organización' : 'como asistentes del evento';
    const editable = !!can?.edit;
    const confirmed = acta?.estado === 'confirmada';
    const [form, setForm] = useState(() => toForm(acta, defaults ?? {}));
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [avisos, setAvisos] = useState([]);
    const [questions, setQuestions] = useState(null);
    const [ask, setAsk] = useState(null);
    const fileRef = useRef(null);

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
        if (!res.ok) { setBusy(false); setError(failure(res, 'No se pudo guardar el acta.')); return; }
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
            message = done.ok ? done.body.message : `Borrador guardado, pero NO se confirmó: ${failure(done, 'intente de nuevo.')}`;
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
        run(false);
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
                                ? `Confirmada${acta.confirmed_at ? ` el ${formatDateTime(acta.confirmed_at)}` : ''}${acta.confirmed_by ? ` por ${acta.confirmed_by}` : ''}: ya no se puede editar ni borrar.`
                                : `Borrador: se puede editar y borrar. Nada pasa a Votantes ${isOrg ? 'ni a los miembros' : 'ni a los asistentes'} hasta confirmarla.`}
                        </p>
                    </div>
                    {acta && (
                        <div className="flex flex-wrap gap-2">
                            <a href={`${base}/${acta.id}/acta.docx`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Descargar Word</a>
                            <a href={`${base}/${acta.id}/acta.pdf`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Descargar PDF</a>
                            {acta.archivo && <a href={`${base}/${acta.id}/archivo`} className="min-h-[44px] px-4 py-2.5 bg-white/15 border border-white/20 rounded-xl text-[15px] font-bold flex items-center">Archivo original</a>}
                            {confirmed && can?.delete_confirmed && (
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
                {error && <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{error}</div>}
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
                        {acta?.archivo && <p className="text-[14px] text-[var(--color-ink)]">Archivo guardado: <b>{acta.archivo.nombre}</b>. Si sube otro, lo reemplaza.</p>}
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
                    {editable && <p className="text-[14px] text-[var(--color-ink-soft)]">Al confirmar el acta, cada participante queda en Votantes (si ya está, se vincula; si no, se crea su ficha) y {destino}.</p>}
                    <ActaParticipantes items={form.participantes} onChange={v => set('participantes', v)} asistentes={asistentes} municipio={form.municipio} disabled={!editable} confirmed={confirmed} searchUrl={origen?.buscar} />
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
                    <ActaCompromisos items={form.compromisos} onChange={v => set('compromisos', v)} disabled={!editable} confirmed={confirmed} />
                    {confirmed && <Link href={isOrg ? '/eventos/compromisos?origen=organizacion' : '/eventos/compromisos'} className="inline-flex min-h-[44px] items-center text-[15px] font-bold text-[var(--color-primary)] underline">Seguimiento de los compromisos (estados y avances)</Link>}
                </Section>

                <Section title="Cierre">
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">
                        <span className="sr-only">Cierre</span>
                        <textarea className={`${inputCls} min-h-[60px]`} value={form.cierre} disabled={!editable} maxLength={2000} placeholder="Siendo las 12:00 m., se dio por terminada la reunión." onChange={e => set('cierre', e.target.value)} />
                    </label>
                </Section>

                {editable && (
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
                    allowSkip={!questions.confirmNow}
                    doneLabel={questions.confirmNow ? 'Confirmar acta' : 'Guardar borrador'}
                    onCancel={() => setQuestions(null)}
                    onDone={answers => { const payload = applyAnswers(questions.payload, answers); const now = questions.confirmNow; setQuestions(null); send(payload, now); }}
                />
            )}

            {ask === 'confirm' && (
                <ConfirmDialog
                    title="¿Confirmar el acta?"
                    message={`Los participantes pasan a Votantes y quedan ${destino}. Si hay personas por confirmar, se le preguntará antes. Después de confirmar no se podrá editar ni borrar.`}
                    confirmLabel="Sí, confirmar"
                    onConfirm={() => { setAsk(null); run(true); }}
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
