import { useRef, useState } from 'react';
import { AvisoError, Girando, IconoDescarga, focoCls } from '@/Components/Reportes/ui';
import { IconoSubir } from '@/Components/CampanasElectorales/ui';
import EnlaceDescarga from '@/Components/EnlaceDescarga';
import { importarMetas, plural, urlPlantilla, validarExcel } from '@/lib/campanas';

const botonCls = `inline-flex items-center justify-center gap-2 min-h-14 px-5 text-lg font-semibold rounded-md bg-panel text-primary border-2 border-primary hover:bg-primary/5 active:scale-[0.98] transition-[background-color,transform] duration-150 ${focoCls}`;

// Template download and goal upload. The upload is all or nothing on the server: one bad row and nothing is saved,
// with the list of rows to fix. With unsaved changes on screen the upload waits (it would mix both).
export default function ExcelMetas({ campanaId, nombre, puedeDescargar = true, puedeCargar, hayCambios, onCargado }) {
    const input = useRef(null);
    const [cargando, setCargando] = useState(false);
    const [resultado, setResultado] = useState(null);

    function abrir() {
        if (cargando) return;
        if (hayCambios) {
            setResultado({ ok: false, mensaje: 'Guarda o descarta tus cambios antes de cargar el Excel.', filas: [] });
            return;
        }
        setResultado(null);
        input.current?.click();
    }

    async function alElegir(e) {
        const archivo = e.target.files?.[0] ?? null;
        e.target.value = '';
        if (!archivo) return;
        const problema = validarExcel(archivo);
        if (problema) { setResultado({ ok: false, mensaje: problema, filas: [] }); return; }
        setCargando(true);
        const res = await importarMetas(campanaId, archivo);
        setCargando(false);
        if (res.ok) {
            setResultado({ ok: true, mensaje: res.cargadas > 0 ? `Se cargaron ${plural(res.cargadas, 'meta', 'metas')}` : 'El archivo no traía metas nuevas.', filas: [] });
            onCargado?.(res.data);
            return;
        }
        setResultado({ ok: false, mensaje: res.mensaje, filas: res.filas });
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col sm:flex-row flex-wrap gap-3">
                {puedeDescargar && (
                    <EnlaceDescarga href={urlPlantilla(campanaId)} nombre={`metas-${nombre}`} className={botonCls}>
                        <IconoDescarga />Descargar plantilla Excel
                    </EnlaceDescarga>
                )}
                {puedeCargar && (
                    <>
                        <button type="button" onClick={abrir} disabled={cargando} aria-busy={cargando || undefined} className={`${botonCls} disabled:opacity-60`}>
                            {cargando ? <><Girando className="w-5 h-5" />Cargando…</> : <><IconoSubir />Cargar metas desde Excel</>}
                        </button>
                        <input ref={input} type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" className="hidden" onChange={alElegir} data-testid="excel-metas" />
                    </>
                )}
            </div>
            <div aria-live="polite">
                {resultado?.ok && <p role="status" className="p-4 rounded-md border-2 border-good bg-good-light text-lg font-semibold text-ink">{resultado.mensaje}</p>}
                {resultado && !resultado.ok && (
                    <AvisoError>
                        <span>
                            {resultado.mensaje}
                            {resultado.filas.length > 0 && (
                                <ul className="mt-2 list-disc pl-6 space-y-1 max-h-64 overflow-y-auto">
                                    {resultado.filas.map((f, i) => <li key={i}>{f}</li>)}
                                </ul>
                            )}
                        </span>
                    </AvisoError>
                )}
            </div>
        </div>
    );
}
