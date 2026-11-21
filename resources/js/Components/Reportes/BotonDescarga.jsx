import { useRef, useState } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import { descargarExcel, guardarBlob, mensajeError, nombreArchivo, nombreSugerido, numero } from '@/lib/reportes';
import { Boton, Girando, IconoAlerta, IconoCheck, IconoDescarga } from './ui';

function DialogoTope({ total, tope, onAceptar, onVolver }) {
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
                    className="pointer-events-auto w-full sm:max-w-lg bg-panel rounded-t-2xl sm:rounded-xl p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom,0px))] sm:pb-6 shadow-2xl"
                >
                    <h2 id="tope-t" className="font-heading text-2xl font-bold text-ink">Tu lista es muy grande</h2>
                    <p id="tope-d" className="mt-3 text-lg text-ink text-pretty">
                        Tu lista tiene {numero(total)} personas. El Excel trae las primeras {numero(tope)}. Para tener el resto, divide la lista por municipio.
                    </p>
                    <div className="mt-6 flex flex-col sm:flex-row-reverse gap-3">
                        <Boton onClick={onAceptar}>Descargar las primeras {numero(tope)}</Boton>
                        <Boton variante="secundario" onClick={onVolver}>Volver</Boton>
                    </div>
                </div>
            </div>
        </>
    );
}

// One download at a time: a second click while one is being prepared does nothing (the button is disabled
// and the ref guards the gap before React re-renders).
export default function BotonDescarga({ spec, total, tope, chips, vacia = false }) {
    const enCurso = useRef(false);
    const [estado, setEstado] = useState({ fase: 'listo', mensaje: '' });
    const [preguntar, setPreguntar] = useState(false);

    async function descargar() {
        if (enCurso.current) return;
        enCurso.current = true;
        setPreguntar(false);
        setEstado({ fase: 'preparando', mensaje: '' });
        try {
            const { blob, disposition } = await descargarExcel(spec);
            const nombre = nombreArchivo(disposition, nombreSugerido(chips));
            guardarBlob(blob, nombre);
            setEstado({ fase: 'ok', mensaje: `Listo. Se descargó «${nombre}».` });
        } catch (e) {
            setEstado({ fase: 'error', mensaje: mensajeError(e, 'descarga') });
        } finally {
            enCurso.current = false;
        }
    }

    function alTocar() {
        if (enCurso.current) return;
        if (tope && total > tope) setPreguntar(true);
        else descargar();
    }

    const preparando = estado.fase === 'preparando';
    return (
        <div>
            <Boton onClick={alTocar} disabled={preparando || vacia} aria-busy={preparando} className="w-full sm:w-auto">
                {preparando ? <Girando /> : <IconoDescarga />}
                {preparando ? 'Preparando tu archivo…' : 'Descargar Excel'}
            </Boton>
            <div aria-live="polite" className="mt-3">
                {estado.fase === 'ok' && (
                    <p className="flex items-start gap-2 text-lg font-semibold text-good">
                        <IconoCheck className="w-6 h-6 flex-shrink-0" />
                        <span className="text-ink">{estado.mensaje}</span>
                    </p>
                )}
                {estado.fase === 'error' && (
                    <p role="alert" className="flex items-start gap-2 text-lg font-semibold text-ink">
                        <IconoAlerta className="w-6 h-6 text-danger flex-shrink-0" />
                        {estado.mensaje}
                    </p>
                )}
            </div>
            {preguntar && <DialogoTope total={total} tope={tope} onAceptar={descargar} onVolver={() => setPreguntar(false)} />}
        </div>
    );
}
