import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import ImportRowCard from '@/Components/Eventos/ImportRowCard';
import { ImportConfirm, ImportStepper } from '@/Components/Eventos/ImportSteps';
import { OcrReader, OcrUpload, PageTabs, PageViewer } from '@/Components/Eventos/OcrSteps';
import { formatEventDate, formatShortDate } from '@/lib/eventos';
import { ROW_FILTERS, importErrorMessage, plural } from '@/lib/eventosImport';
import { OCR_ROW_FIELDS, OCR_STEPS, ocrConfirmSummary, ocrLeftOutSummary, ocrStep } from '@/lib/eventosOcr';

const LOAD_STATUS = { subido: 'Sin terminar de leer', revisado: 'En revisión', confirmado: 'Importada' };

function Count({ label, value, tone }) {
    return (
        <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
            <p className="text-[16px] font-bold text-[var(--color-ink-soft)] leading-tight">{label}</p>
            <p className={`text-[28px] font-extrabold leading-none mt-1.5 ${tone}`}>{value ?? 0}</p>
        </div>
    );
}

// Printed sign-in sheets read with AI (docs/eventos-api.md §13): photos → reading page by page → human review with the
// photo next to the rows → the same confirmation as the Excel load.
export default function Planillas({ evento, ocr, importacion, paginas, pagina_actual, filas, filters, recientes }) {
    const { props } = usePage();
    const success = props.flash?.success;
    const warning = props.flash?.error;
    const errorText = [importErrorMessage(props.errors), props.errors?.ocr, props.errors?.foto].filter(Boolean).join(' ');
    const step = ocrStep(importacion);
    const counts = importacion?.counts || {};
    const editable = importacion?.status === 'revisado';
    const [discarding, setDiscarding] = useState(false);
    const base = `/eventos/${evento.id}/importar/ocr`;
    const loadUrl = importacion ? `${base}/${importacion.id}` : base;
    const current = (paginas || []).find(p => p.numero === pagina_actual);
    const doubtful = (paginas || []).reduce((sum, p) => sum + (p.dudosas || 0), 0);
    const ready = ocr?.listo && evento.acepta_cargas;

    function visit(params) {
        router.get(loadUrl, params, { preserveState: true, preserveScroll: true, replace: true, only: ['filas', 'filters', 'pagina_actual'] });
    }

    return (
        <AppLayout title="Leer planillas con IA" breadcrumb={[{ label: 'EVENTOS', href: '/eventos' }, { label: evento.nombre, href: `/eventos/${evento.id}` }, { label: 'Leer planillas con IA' }]}>
            <Head title={`Leer planillas — ${evento.nombre}`} />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href={`/eventos/${evento.id}`} className="inline-flex items-center gap-1 min-h-[44px] text-[16px] font-semibold text-white/85 hover:text-white">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    Volver al evento
                </Link>
                <h1 className="text-[22px] lg:text-[26px] font-extrabold leading-tight mt-1">Leer planillas impresas con IA</h1>
                <p className="text-[16px] text-white/85 mt-0.5 first-letter:uppercase">{evento.nombre}{evento.starts_at ? ` · ${formatEventDate(evento.starts_at, { withTime: false })}` : ''}</p>
            </div>

            <div className="p-4 lg:px-6 space-y-4">
                <ImportStepper step={step} steps={OCR_STEPS} label="Pasos para leer las planillas" />

                {success && <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[16px] font-semibold text-emerald-800">{success}</div>}
                {warning && <div role="status" className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-300 text-[16px] font-semibold text-amber-900">{warning}</div>}
                {errorText && <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">{errorText}</div>}
                {ocr?.aviso && step !== 'confirmar' && <p role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-300 text-[17px] font-semibold text-red-800">{ocr.aviso}</p>}
                {!evento.acepta_cargas && (
                    <p role="note" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">El evento está cancelado: no se pueden cargar asistentes.</p>
                )}

                {step === 'fotos' && (
                    <>
                        <p className="text-[16px] text-[var(--color-ink-soft)]">
                            Las fotos se envían a un proveedor de inteligencia artificial en el exterior (Anthropic) para leerlas, y se guardan en la
                            plataforma como respaldo de la planilla.
                        </p>
                        <OcrUpload eventId={evento.id} limits={ocr?.limites} disabled={!ready} />
                    </>
                )}

                {importacion && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[16px] text-[var(--color-ink-soft)]">
                        <span>Carga: <strong className="text-[var(--color-ink)] break-all">{importacion.nombre}</strong></span>
                        <span>· {plural(paginas?.length ?? 0, 'página', 'páginas')}</span>
                        {importacion.status !== 'confirmado' && (
                            <button type="button" onClick={() => setDiscarding(true)} className="ml-auto min-h-[44px] px-4 py-2 rounded-xl border-2 border-gray-300 bg-white text-[16px] font-bold text-[var(--color-ink-soft)]">
                                Descartar esta carga
                            </button>
                        )}
                    </div>
                )}

                {step === 'leer' && (
                    <>
                        <OcrReader eventId={evento.id} importacion={importacion} ready={ready} />
                        <PageList paginas={paginas} />
                    </>
                )}

                {(step === 'revisar' || step === 'confirmar') && (
                    <>
                        {step === 'confirmar' && (
                            <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-300 text-[16px] text-emerald-900">
                                <p className="font-extrabold">Carga confirmada{importacion.confirmed_at ? ` el ${formatShortDate(importacion.confirmed_at)}` : ''}</p>
                                <p>{plural(counts.importadas ?? 0, 'persona quedó registrada', 'personas quedaron registradas')} en el evento. Las nuevas están pendientes de aprobar en la página del evento.</p>
                                {importacion.datos_borrados && <p className="mt-1">Por protección de datos, lo que leyó la IA ya se borró de esta vista; los asistentes siguen en el evento y las fotos quedan como respaldo.</p>}
                            </div>
                        )}

                        <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3">
                            <Count label="Filas leídas" value={counts.total} tone="text-[var(--color-ink)]" />
                            <Count label="Válidas" value={counts.validas} tone="text-emerald-700" />
                            <Count label="Ya en la plataforma (de las válidas)" value={counts.en_plataforma} tone="text-[var(--color-primary)]" />
                            <Count label="Con error" value={counts.errores} tone="text-red-700" />
                            <Count label="Repetidas" value={counts.duplicadas} tone="text-amber-700" />
                            <Count label="Ya en el evento" value={counts.en_evento} tone="text-[var(--color-ink-soft)]" />
                        </div>

                        <PageTabs paginas={paginas || []} current={pagina_actual} onSelect={n => visit({ pagina: n, ...(filters?.estado ? { estado: filters.estado } : {}) })} />

                        <div className="grid lg:grid-cols-2 gap-4 items-start">
                            <div className="lg:sticky lg:top-4 space-y-2">
                                {current?.mensaje && <p role="note" className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-300 text-[16px] font-semibold text-amber-900">{current.mensaje}</p>}
                                <PageViewer pagina={current} />
                            </div>

                            <section aria-labelledby="filas-title" className="bg-white border border-[var(--color-line)] rounded-2xl overflow-hidden">
                                <div className="px-4 pt-4 pb-3 space-y-3 border-b border-[var(--color-line)]">
                                    <h2 id="filas-title" className="text-[17px] font-extrabold text-[var(--color-ink)]">Filas que leyó la IA en la página {pagina_actual}</h2>
                                    <p className="text-[16px] text-[var(--color-ink-soft)]">Compare cada fila con la foto. Lo dudoso está en amarillo y lo que no es válido en rojo: corríjalo o deje la fila sin importar.</p>
                                    <div className="flex gap-1.5 overflow-x-auto -mx-4 px-4 pb-0.5 [scrollbar-width:none]" role="group" aria-label="Filtrar filas">
                                        {ROW_FILTERS.map(f => {
                                            const active = (filters?.estado ?? null) === f.value;
                                            return (
                                                <button key={f.label} type="button" aria-pressed={active} onClick={() => visit({ pagina: pagina_actual, ...(f.value ? { estado: f.value } : {}) })} className={`flex-shrink-0 min-h-[44px] px-3.5 py-1.5 rounded-full border text-[16px] font-semibold ${active ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white' : 'bg-white border-[var(--color-line-strong)] text-[var(--color-ink-soft)]'}`}>
                                                    {f.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                {filas?.data?.length > 0 ? (
                                    <ul className="divide-y divide-[var(--color-line)]" aria-label="Filas">
                                        {filas.data.map(r => (
                                            <ImportRowCard key={r.id} row={r} eventId={evento.id} importId={importacion.id} editable={editable} fields={OCR_ROW_FIELDS} umbral={ocr?.umbral} narrow />
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="px-4 py-8 text-center text-[16px] text-[var(--color-ink-faint)]">
                                        {current && current.status !== 'procesada' ? 'Esta página no se leyó.' : 'No hay filas con ese filtro en esta página.'}
                                    </p>
                                )}
                            </section>
                        </div>

                        {editable && evento.acepta_cargas && (
                            <ImportConfirm
                                eventId={evento.id}
                                importacion={importacion}
                                summary={ocrConfirmSummary(counts, doubtful)}
                                leftOutText={ocrLeftOutSummary(counts)}
                            />
                        )}
                        {step === 'confirmar' && (
                            <div className="flex flex-wrap gap-2">
                                <Link href={`/eventos/${evento.id}`} className="min-h-[52px] px-5 py-3 rounded-xl bg-[var(--color-primary)] text-white text-[17px] font-extrabold flex items-center">Ver asistentes del evento</Link>
                                <Link href={base} className="min-h-[52px] px-5 py-3 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[17px] font-extrabold flex items-center">Leer otras planillas</Link>
                            </div>
                        )}
                    </>
                )}

                {step === 'fotos' && recientes?.length > 0 && (
                    <section aria-labelledby="recientes-title" className="bg-white border border-[var(--color-line)] rounded-2xl p-4">
                        <h2 id="recientes-title" className="text-[17px] font-extrabold text-[var(--color-ink)]">Cargas anteriores de este evento</h2>
                        <ul className="mt-2 divide-y divide-[var(--color-line)]">
                            {recientes.map(r => (
                                <li key={r.id} className="py-2 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                    <Link href={`${base}/${r.id}`} className="inline-flex items-center min-h-[44px] text-[18px] font-bold text-[var(--color-primary)] underline break-all">{r.nombre}</Link>
                                    <span className="text-[16px] text-[var(--color-ink-soft)]">
                                        {LOAD_STATUS[r.status] ?? r.status} · {plural(r.paginas, 'página', 'páginas')}{r.status === 'confirmado' ? ` · ${plural(r.importadas, 'importada', 'importadas')}` : ''} · {formatShortDate(r.created_at)}{r.created_by ? ` · ${r.created_by}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {discarding && (
                <ConfirmDialog
                    title="¿Descartar esta carga?"
                    message="No se registra a nadie de estas fotos y se borran de la plataforma. Después puede subirlas de nuevo."
                    confirmLabel="Sí, descartar"
                    cancelLabel="No, seguir"
                    danger
                    onConfirm={() => { setDiscarding(false); router.delete(`/eventos/${evento.id}/importar/${importacion.id}`); }}
                    onCancel={() => setDiscarding(false)}
                />
            )}
        </AppLayout>
    );
}

function PageList({ paginas }) {
    if (!paginas?.length) return null;

    return (
        <ul className="bg-white border border-[var(--color-line)] rounded-2xl divide-y divide-[var(--color-line)]" aria-label="Páginas de la carga">
            {paginas.map(p => (
                <li key={p.id} className="px-4 py-2.5">
                    <p className="text-[17px] font-bold text-[var(--color-ink)] break-all">Página {p.numero} · {p.nombre}{p.pdf ? ` (pág. ${p.pagina_pdf} del PDF)` : ''}</p>
                    <p className="text-[16px] text-[var(--color-ink-soft)]">
                        {p.status === 'procesada' ? `Leída: ${plural(p.filas, 'fila', 'filas')}` : p.status === 'pendiente' ? 'Sin leer' : p.status === 'rechazada' ? 'No se leyó' : 'Falló'}
                        {p.mensaje ? ` — ${p.mensaje}` : ''}
                    </p>
                </li>
            ))}
        </ul>
    );
}
