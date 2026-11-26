import { useEffect, useId, useRef, useState } from 'react';
import { Boton, Girando, IconoX, focoCls } from '@/Components/CampanasElectorales/ui';
import { Avance } from '@/Components/CampanasElectorales/TarjetaAvance';
import { MiniSemanas } from '@/Components/CampanasElectorales/GraficoSemanal';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import { useEsMovil } from '@/lib/useMediaQuery';
import { MINIMO_EVOLUCION, TEXTO_MENOS_DE_5, TEXTO_POCOS, fichasDe, mensajeTablero, numero, textoEstado, textoFueraDeMeta, verTerritorioTablero } from '@/lib/campanas';

const TIPOS = { municipality: 'Municipio', comuna: 'Comuna', corregimiento: 'Corregimiento' };
const DESLIZAR_PARA_CERRAR = 80;

// por_origen only exists for comunas; null puesto = 1 to 4 (hidden). The detail's por_puesto_texto wins when it comes.
function TextoPuesto({ porOrigen, texto }) {
    if (texto) return <p className="text-sm text-ink">{texto.replace(/\.$/, '')}.</p>;
    if (!porOrigen || porOrigen.puesto === 0) return null;
    return <p className="text-sm text-ink">Incluye {porOrigen.puesto === null ? TEXTO_MENOS_DE_5 : numero(porOrigen.puesto)} por puesto de votación.</p>;
}

function Semanas({ estado, pocos, onReintentar }) {

    if (pocos) return <p className="text-sm text-ink-soft">{estado.pocosTexto || TEXTO_POCOS}</p>;
    if (estado.cargando) return <p className="flex items-center gap-2 text-sm text-ink-soft"><Girando className="w-4 h-4" />Cargando semanas…</p>;
    if (estado.error) {
        return (
            <div role="alert" className="space-y-2">
                <p className="text-sm text-ink">{estado.error}</p>
                <Boton variante="secundario" onClick={onReintentar}>Reintentar</Boton>
            </div>
        );
    }
    if (!estado.semanas.length) return <p className="text-sm text-ink-soft">{estado.pocosTexto || TEXTO_POCOS}</p>;
    return <MiniSemanas semanas={estado.semanas} />;
}

const SIN_DETALLE = { semanas: [], pocos: null, pocosTexto: '', porPuestoTexto: '' };

// The detail request: the weeks of the territory and the texts the server words (por puesto, pocos registros).
function useDetalle(campanaId, unitId) {
    const [estado, setEstado] = useState({ cargando: true, error: '', ...SIN_DETALLE });
    const [intento, setIntento] = useState(0);
    useEffect(() => {
        const ctrl = new AbortController();
        setEstado(e => ({ ...e, cargando: true, error: '' }));
        verTerritorioTablero(campanaId, unitId, { signal: ctrl.signal }).then(res => {
            if (ctrl.signal.aborted) return;
            setEstado(res.ok
                ? { cargando: false, error: '', semanas: res.semanas, pocos: res.pocosRegistros, pocosTexto: res.pocosTexto, porPuestoTexto: res.porPuestoTexto }
                : { cargando: false, error: res.status === 404 ? 'Este territorio ya no está en tu tablero.' : mensajeTablero(res, 'No pudimos cargar las semanas.'), ...SIN_DETALLE });
        }).catch(() => { /* aborted: the panel closed */ });
        return () => ctrl.abort();
    }, [campanaId, unitId, intento]);
    return [estado, () => setIntento(i => i + 1)];
}

// What one territory carries, opened from the map, the ranking or the table: a bottom sheet on a phone, a side panel
// on a desktop. Escape, the backdrop, «Cerrar» and swiping the sheet down close it.
export default function DetalleTerritorio({ campanaId, territorio, tieneComunas = false, pocosRegistros = MINIMO_EVOLUCION, onVerComunas, onCerrar }) {
    const ref = useRef(null);
    const id = useId();
    const movil = useEsMovil();
    const inicioToque = useRef(null);
    useDialogFocus(ref);
    useEscapeKey(onCerrar, ref);

    const t = territorio;
    const tipo = TIPOS[t.tipo] ?? 'Territorio';
    const [detalle, reintentar] = useDetalle(campanaId, t.id);
    const pocos = detalle.pocos ?? (fichasDe(t) ?? 0) < pocosRegistros;

    const alTocar = (e) => { inicioToque.current = e.touches?.[0]?.clientY ?? null; };
    const alSoltar = (e) => {
        const fin = e.changedTouches?.[0]?.clientY;
        if (inicioToque.current !== null && fin !== undefined && fin - inicioToque.current > DESLIZAR_PARA_CERRAR) onCerrar();
        inicioToque.current = null;
    };

    return (
        <>
            <div className="fixed inset-0 z-50 bg-ink/50" onClick={onCerrar} aria-hidden="true" />
            <div
                ref={ref}
                role="dialog"
                aria-modal="true"
                aria-labelledby={`${id}-t`}
                data-forma={movil ? 'hoja' : 'panel'}
                className={movil
                    ? 'fixed z-50 inset-x-0 bottom-0 max-h-[88dvh] overflow-y-auto bg-panel rounded-t-2xl shadow-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))]'
                    : 'fixed z-50 inset-y-0 right-0 w-[400px] max-w-full overflow-y-auto bg-panel shadow-2xl border-l border-line'}
            >
                <div onTouchStart={alTocar} onTouchEnd={alSoltar} className="sticky top-0 z-10 bg-panel px-4 sm:px-5 pt-3 pb-3 border-b border-line">
                    {movil && <div aria-hidden="true" className="mx-auto mb-3 h-1.5 w-12 rounded-full bg-line-strong" />}
                    <div className="flex items-start gap-2">
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold uppercase tracking-wider text-ink-soft">{tipo}</p>
                            <h2 id={`${id}-t`} className="font-heading text-lg font-bold text-ink break-words">{t.nombre}</h2>
                        </div>
                        <button type="button" onClick={onCerrar} aria-label="Cerrar el detalle" className={`flex-shrink-0 w-11 h-11 sm:w-9 sm:h-9 inline-flex items-center justify-center rounded-md text-ink hover:bg-bg ${focoCls}`}>
                            <IconoX className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <div className="px-4 sm:px-5 py-4 space-y-4">
                    <div className="flex items-center gap-3 p-3 rounded-lg bg-bg">
                        <p className="font-heading text-3xl font-bold text-primary tabular-nums">{t.indice === null ? '—' : numero(t.indice)}</p>
                        <div>
                            <p className="text-sm font-semibold text-ink">{t.indice === null ? 'Sin índice' : 'de 100 en el índice'}</p>
                            <p className="text-[13px] text-ink-soft">Estado: {textoEstado(t)}</p>
                        </div>
                    </div>
                    {t.motivo && <p role="note" className="p-3 rounded-md border border-line-strong text-sm text-ink">{t.motivo}</p>}

                    <Avance titulo="Votantes registrados" medida={t.votantes} unidad="votantes" />
                    {textoFueraDeMeta(t) && <p className="text-sm text-ink text-pretty">{textoFueraDeMeta(t)}</p>}
                    <Avance titulo="Líderes" medida={t.lideres} unidad="lideres" />
                    <TextoPuesto porOrigen={t.porOrigen} texto={detalle.porPuestoTexto} />

                    <div>
                        <h3 className="text-sm font-semibold text-ink">Fichas nuevas por semana</h3>
                        <div className="mt-1"><Semanas estado={detalle} pocos={pocos} onReintentar={reintentar} /></div>
                    </div>

                    <div className="flex flex-col gap-2">
                        {tieneComunas && <Boton onClick={() => onVerComunas(t)}>Ver comunas</Boton>}
                        <Boton variante="secundario" onClick={onCerrar}>Cerrar</Boton>
                    </div>
                </div>
            </div>
        </>
    );
}
