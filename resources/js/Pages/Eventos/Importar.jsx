import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import ImportRowCard from '@/Components/Eventos/ImportRowCard';
import { ImportConfirm, ImportMapping, ImportSheet, ImportStepper, ImportUpload } from '@/Components/Eventos/ImportSteps';
import Pagination from '@/Components/Eventos/Pagination';
import { formatEventDate, formatShortDate } from '@/lib/eventos';
import { LIST_NOTE, ROW_FILTERS, currentStep, importErrorMessage, plural } from '@/lib/eventosImport';

const IMPORT_STATUS = { subido: 'Columnas sin revisar', revisado: 'En revisión', confirmado: 'Importado' };

function Count({ label, value, tone }) {
    return (
        <div className="bg-white border border-[var(--color-line)] rounded-2xl px-4 py-3">
            <p className="text-[16px] font-bold text-[var(--color-ink-soft)] leading-tight">{label}</p>
            <p className={`text-[28px] font-extrabold leading-none mt-1.5 ${tone}`}>{value ?? 0}</p>
        </div>
    );
}

export default function Importar({ evento, importacion, muestra, filas, filters, campos, limites, recientes }) {
    const { props } = usePage();
    const success = props.flash?.success;
    const warning = props.flash?.error;
    const errorText = importErrorMessage(props.errors);
    const step = currentStep(importacion);
    const counts = importacion?.counts || {};
    const editable = importacion?.status === 'revisado';
    const list = !!importacion?.sin_autorizacion;
    const [discarding, setDiscarding] = useState(false);
    const base = `/eventos/${evento.id}/importar`;

    function filter(value) {
        router.get(`${base}/${importacion.id}`, value ? { estado: value } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ['filas', 'filters'] });
    }

    return (
        <AppLayout title="Cargar Excel" breadcrumb={[{ label: 'EVENTOS', href: '/eventos' }, { label: evento.nombre, href: `/eventos/${evento.id}` }, { label: 'Cargar Excel' }]}>
            <Head title={`Cargar Excel — ${evento.nombre}`} />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href={`/eventos/${evento.id}`} className="inline-flex items-center gap-1 min-h-[44px] text-[16px] font-semibold text-white/85 hover:text-white">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    Volver al evento
                </Link>
                <h1 className="text-[22px] lg:text-[26px] font-extrabold leading-tight mt-1">Cargar asistentes desde Excel</h1>
                <p className="text-[16px] text-white/85 mt-0.5 first-letter:uppercase">{evento.nombre}{evento.starts_at ? ` · ${formatEventDate(evento.starts_at, { withTime: false })}` : ''}</p>
            </div>

            <div className="p-4 lg:px-6 space-y-4">
                <ImportStepper step={step} />

                {success && <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[16px] font-semibold text-emerald-800">{success}</div>}
                {warning && <div role="status" className="px-4 py-3 rounded-xl bg-amber-50 border border-amber-300 text-[16px] font-semibold text-amber-900">{warning}</div>}
                {errorText && <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">{errorText}</div>}

                {!evento.acepta_cargas && (
                    <p role="note" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[16px] font-semibold text-red-700">El evento está cancelado: no se pueden cargar asistentes.</p>
                )}

                {step === 'archivo' && (
                    <ImportUpload eventId={evento.id} limits={limites} disabled={!evento.acepta_cargas} />
                )}

                {importacion && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[16px] text-[var(--color-ink-soft)]">
                        <span>Archivo: <strong className="text-[var(--color-ink)] break-all">{importacion.nombre}</strong></span>
                        <span>· {plural(importacion.filas, 'fila', 'filas')}</span>
                        {importacion.fila_encabezados > 1 && <span>· encabezados en la fila {importacion.fila_encabezados}</span>}
                        {importacion.status !== 'confirmado' && (
                            <button type="button" onClick={() => setDiscarding(true)} className="ml-auto min-h-[44px] px-4 py-2 rounded-xl border-2 border-gray-300 bg-white text-[16px] font-bold text-[var(--color-ink-soft)]">
                                Descartar y subir otro archivo
                            </button>
                        )}
                    </div>
                )}

                {importacion && importacion.status !== 'confirmado' && <ImportSheet eventId={evento.id} importacion={importacion} />}

                {step === 'columnas' && <ImportMapping eventId={evento.id} importacion={importacion} muestra={muestra} campos={campos} />}

                {(step === 'revisar' || step === 'confirmar') && (
                    <>
                        {step === 'confirmar' && (
                            <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-300 text-[16px] text-emerald-900">
                                <p className="font-extrabold">Carga confirmada{importacion.confirmed_at ? ` el ${formatShortDate(importacion.confirmed_at)}` : ''}</p>
                                <p>{plural(counts.importadas ?? 0, 'persona quedó registrada', 'personas quedaron registradas')} en el evento. Las nuevas están pendientes de aprobar en la página del evento.</p>
                                {list && <p>Quien ya estaba en la plataforma quedó con la asistencia en el historial de su ficha (sin cambiarla).</p>}
                                {importacion.datos_borrados && <p className="mt-1">Por protección de datos, el contenido de las filas de esta carga ya se borró de la vista previa; los asistentes siguen en el evento.</p>}
                            </div>
                        )}

                        {list ? (
                            <>
                                <p role="note" className="px-4 py-3 rounded-xl bg-blue-50 border border-blue-200 text-[17px] text-[var(--color-ink)]">
                                    Quien ya está en la plataforma (por cédula, o por teléfono y nombre que usted confirma) queda con la asistencia en su historial; su ficha no cambia. {LIST_NOTE}
                                </p>
                                <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                                    <Count label="Ya en la plataforma" value={counts.en_plataforma} tone="text-[var(--color-primary)]" />
                                    <Count label="¿Es esta persona? (por confirmar)" value={counts.sugeridas} tone="text-amber-700" />
                                    <Count label="Nuevas (por aprobar)" value={counts.sin_ficha} tone="text-emerald-700" />
                                    <Count label="Con error" value={counts.errores} tone="text-red-700" />
                                    <Count label="Repetidas en el archivo" value={counts.duplicadas} tone="text-amber-700" />
                                    <Count label="Ya en el evento" value={counts.en_evento} tone="text-[var(--color-ink-soft)]" />
                                </div>
                            </>
                        ) : (
                            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                                <Count label="Válidas" value={counts.validas} tone="text-emerald-700" />
                                <Count label="Ya en la plataforma (de las válidas)" value={counts.en_plataforma} tone="text-[var(--color-primary)]" />
                                <Count label="Con error" value={counts.errores} tone="text-red-700" />
                                <Count label="Repetidas en el archivo" value={counts.duplicadas} tone="text-amber-700" />
                                <Count label="Ya en el evento" value={counts.en_evento} tone="text-[var(--color-ink-soft)]" />
                            </div>
                        )}

                        {editable && (counts.errores ?? 0) > 0 && (
                            <p className="text-[16px] text-[var(--color-ink)]">
                                Las filas con error <strong>no se importan</strong>. Puede corregirlas aquí con «Corregir esta fila» o dejarlas por fuera.
                            </p>
                        )}

                        <section aria-labelledby="filas-title" className="bg-white border border-[var(--color-line)] rounded-2xl overflow-hidden">
                            <div className="px-4 pt-4 pb-3 space-y-3 border-b border-[var(--color-line)]">
                                <h2 id="filas-title" className="text-[17px] font-extrabold text-[var(--color-ink)]">Filas del archivo</h2>
                                <div className="flex gap-1.5 overflow-x-auto -mx-4 px-4 pb-0.5 [scrollbar-width:none]" role="group" aria-label="Filtrar filas">
                                    {ROW_FILTERS.filter(f => (list ? f.value !== 'nuevas' : !['sugerida', 'sin_ficha'].includes(f.value))).map(f => {
                                        const active = (filters?.estado ?? null) === f.value;
                                        return (
                                            <button key={f.label} type="button" aria-pressed={active} onClick={() => filter(f.value)} className={`flex-shrink-0 min-h-[44px] px-3.5 py-1.5 rounded-full border text-[16px] font-semibold ${active ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white' : 'bg-white border-[var(--color-line-strong)] text-[var(--color-ink-soft)]'}`}>
                                                {f.label} ({counts[f.count] ?? 0})
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                            {filas?.data?.length > 0 ? (
                                <ul className="divide-y divide-[var(--color-line)]" aria-label="Filas">
                                    {filas.data.map(r => <ImportRowCard key={r.id} row={r} eventId={evento.id} importId={importacion.id} editable={editable} />)}
                                </ul>
                            ) : (
                                <p className="px-4 py-8 text-center text-[16px] text-[var(--color-ink-faint)]">No hay filas con ese filtro.</p>
                            )}
                            {filas?.last_page > 1 && (
                                <div className="px-4 py-3 border-t border-[var(--color-line)]">
                                    <Pagination paginator={filas} label="Páginas de filas" only={['filas', 'filters']} />
                                </div>
                            )}
                        </section>

                        {editable && evento.acepta_cargas && <ImportConfirm eventId={evento.id} importacion={importacion} />}
                        {step === 'confirmar' && (
                            <div className="flex flex-wrap gap-2">
                                <Link href={`/eventos/${evento.id}`} className="min-h-[52px] px-5 py-3 rounded-xl bg-[var(--color-primary)] text-white text-[17px] font-extrabold flex items-center">Ver asistentes del evento</Link>
                                <Link href={base} className="min-h-[52px] px-5 py-3 rounded-xl border-2 border-[var(--color-primary)] text-[var(--color-primary)] text-[17px] font-extrabold flex items-center">Cargar otro archivo</Link>
                            </div>
                        )}
                    </>
                )}

                {step === 'archivo' && recientes?.length > 0 && (
                    <section aria-labelledby="recientes-title" className="bg-white border border-[var(--color-line)] rounded-2xl p-4">
                        <h2 id="recientes-title" className="text-[17px] font-extrabold text-[var(--color-ink)]">Cargas anteriores de este evento</h2>
                        <ul className="mt-2 divide-y divide-[var(--color-line)]">
                            {recientes.map(r => (
                                <li key={r.id} className="py-2 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                    <Link href={`${base}/${r.id}`} className="inline-flex items-center min-h-[44px] text-[18px] font-bold text-[var(--color-primary)] underline break-all">{r.nombre}</Link>
                                    <span className="text-[16px] text-[var(--color-ink-soft)]">
                                        {IMPORT_STATUS[r.status] ?? r.status} · {plural(r.filas, 'fila', 'filas')}{r.status === 'confirmado' ? ` · ${plural(r.importadas, 'importada', 'importadas')}` : ''} · {formatShortDate(r.created_at)}{r.created_by ? ` · ${r.created_by}` : ''}
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
                    message="No se registra a nadie de este archivo. Después puede subir otro archivo o el mismo corregido."
                    confirmLabel="Sí, descartar"
                    cancelLabel="No, seguir"
                    danger
                    onConfirm={() => { setDiscarding(false); router.delete(`${base}/${importacion.id}`); }}
                    onCancel={() => setDiscarding(false)}
                />
            )}
        </AppLayout>
    );
}
