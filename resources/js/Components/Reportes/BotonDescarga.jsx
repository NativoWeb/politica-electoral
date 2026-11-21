import { useRef, useState } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import {
    FORMATOS, PDF_MAX_COLUMNAS, descargarArchivo, guardarBlob, mensajeError, nombreArchivo, nombreSugerido, numero,
} from '@/lib/reportes';
import { Boton, Girando, IconoAlerta, IconoCheck, IconoDescarga } from './ui';

function Dialogo({ titulo, children, acciones, onVolver }) {
    const ref = useRef(null);
    useDialogFocus(ref);
    useEscapeKey(onVolver, ref);
    return (
        <>
            <div className="fixed inset-0 z-50 bg-ink/60" onClick={onVolver} aria-hidden="true" />
            <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 pointer-events-none">
                <div
                    ref={ref}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="tope-t"
                    aria-describedby="tope-d"
                    className="pointer-events-auto w-full sm:max-w-lg max-h-[100dvh] overflow-y-auto bg-panel rounded-t-2xl sm:rounded-xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 shadow-2xl"
                >
                    <h2 id="tope-t" className="font-heading text-2xl font-bold text-ink">{titulo}</h2>
                    <div id="tope-d" className="mt-3 space-y-3 text-lg text-ink text-pretty">{children}</div>
                    <div className="mt-6 flex flex-col sm:flex-row-reverse sm:flex-wrap gap-3">{acciones}</div>
                </div>
            </div>
        </>
    );
}

// What the PDF leaves out, said before downloading: rows above its cap and columns beyond the ones that fit.
function avisosPdf(total, topePdf, columnas) {
    const recorta = !!topePdf && total > topePdf;
    const omite = columnas.length > PDF_MAX_COLUMNAS;
    return { recorta, omite, alguno: recorta || omite };
}

// Two buttons, one download at a time: while one file is being prepared both are disabled (the ref guards
// the gap before React re-renders, so a double click or a click on the other button sends nothing).
export default function BotonDescarga({ spec, total, tope, topePdf = 0, columnas = [], chips, vacia = false }) {
    const enCurso = useRef(false);
    const [estado, setEstado] = useState({ fase: 'listo', formato: null, mensaje: '' });
    const [preguntar, setPreguntar] = useState(null);

    async function descargar(formato) {
        if (enCurso.current) return;
        enCurso.current = true;
        setPreguntar(null);
        setEstado({ fase: 'preparando', formato, mensaje: '' });
        try {
            const { blob, disposition } = await descargarArchivo(spec, formato);
            const nombre = nombreArchivo(disposition, nombreSugerido(chips, new Date(), FORMATOS[formato].extension));
            guardarBlob(blob, nombre);
            setEstado({ fase: 'ok', formato, mensaje: `Listo. Se descargó «${nombre}».` });
        } catch (e) {
            setEstado({ fase: 'error', formato, mensaje: mensajeError(e, 'descarga', formato) });
        } finally {
            enCurso.current = false;
        }
    }

    function alTocar(formato) {
        if (enCurso.current) return;
        if (formato === 'excel' && tope && total > tope) setPreguntar('excel');
        else if (formato === 'pdf' && avisosPdf(total, topePdf, columnas).alguno) setPreguntar('pdf');
        else descargar(formato);
    }

    const preparando = estado.fase === 'preparando';
    const volver = () => setPreguntar(null);
    const pdf = avisosPdf(total, topePdf, columnas);

    function botonFormato({ formato, texto }) {
        const este = preparando && estado.formato === formato;
        return (
            <Boton
                onClick={() => alTocar(formato)}
                disabled={preparando || vacia}
                aria-busy={este}
                variante={formato === 'excel' ? 'primario' : 'secundario'}
                className="w-full sm:w-auto"
            >
                {este ? <Girando /> : <IconoDescarga />}
                {este ? 'Preparando tu archivo…' : texto}
            </Boton>
        );
    }

    return (
        <div>
            <div className="flex flex-col sm:flex-row gap-3">
                {botonFormato({ formato: 'excel', texto: 'Descargar Excel' })}
                {botonFormato({ formato: 'pdf', texto: 'Descargar PDF' })}
            </div>
            <div aria-live="polite" className="mt-3">
                {estado.fase === 'ok' && (
                    <p className="flex items-start gap-2 text-lg font-semibold text-good">
                        <IconoCheck className="w-6 h-6 flex-shrink-0" />
                        <span className="text-ink break-words min-w-0">{estado.mensaje}</span>
                    </p>
                )}
                {estado.fase === 'error' && (
                    <p role="alert" className="flex items-start gap-2 text-lg font-semibold text-ink">
                        <IconoAlerta className="w-6 h-6 text-danger flex-shrink-0" />
                        {estado.mensaje}
                    </p>
                )}
            </div>

            {preguntar === 'excel' && (
                <Dialogo
                    titulo="Tu lista es muy grande"
                    onVolver={volver}
                    acciones={<>
                        <Boton onClick={() => descargar('excel')}>Descargar las primeras {numero(tope)}</Boton>
                        <Boton variante="secundario" onClick={volver}>Volver</Boton>
                    </>}
                >
                    <p>Tu lista tiene {numero(total)} personas. El Excel trae las primeras {numero(tope)}. Para tener el resto, divide la lista por municipio.</p>
                </Dialogo>
            )}

            {preguntar === 'pdf' && (
                <Dialogo
                    titulo={pdf.recorta && pdf.omite ? 'Antes de descargar el PDF' : pdf.recorta ? 'Tu lista es muy grande para el PDF' : 'El PDF no cabe con tantos datos'}
                    onVolver={volver}
                    acciones={<>
                        <Boton onClick={() => descargar('pdf')}>
                            {pdf.recorta ? `Descargar PDF con las primeras ${numero(topePdf)}` : 'Descargar PDF'}
                        </Boton>
                        <Boton variante="secundario" onClick={() => alTocar('excel')}>Descargar Excel</Boton>
                        <Boton variante="secundario" onClick={volver}>Volver</Boton>
                    </>}
                >
                    {pdf.recorta && (
                        <p>Tu lista tiene {numero(total)} personas. El PDF trae las primeras {numero(topePdf)}. Para tenerlas todas, descarga el Excel.</p>
                    )}
                    {pdf.omite && (
                        <p>
                            El PDF solo cabe con {PDF_MAX_COLUMNAS} datos por persona. Saldrán: {columnas.slice(0, PDF_MAX_COLUMNAS).join(', ')}.
                            Para ver todos, usa el Excel.
                        </p>
                    )}
                </Dialogo>
            )}
        </div>
    );
}
