import { useEffect, useState } from 'react';
import { AvisoError, Boton, Dialogo, Girando } from '@/Components/Reportes/ui';
import { mensajeDe, textoFecha, verHistorial } from '@/lib/campanas';

// Who changed what in a campaign (audit_logs), newest first.
export default function Historial({ campanaId, onCerrar }) {
    const [estado, setEstado] = useState({ cargando: true, error: '', entradas: [] });
    const [intento, setIntento] = useState(0);

    useEffect(() => {
        let vivo = true;
        setEstado({ cargando: true, error: '', entradas: [] });
        verHistorial(campanaId).then(res => {
            if (!vivo) return;
            setEstado(res.ok
                ? { cargando: false, error: '', entradas: res.entradas }
                : { cargando: false, error: mensajeDe(res, 'No pudimos cargar el historial.'), entradas: [] });
        });
        return () => { vivo = false; };
    }, [campanaId, intento]);

    return (
        <Dialogo titulo="Historial de cambios" onVolver={onCerrar} acciones={<Boton variante="secundario" onClick={onCerrar}>Cerrar</Boton>}>
            {estado.cargando && <p className="flex items-center gap-3 text-ink-soft"><Girando className="w-5 h-5" />Cargando historial…</p>}
            {estado.error && (
                <AvisoError accion={<Boton variante="secundario" onClick={() => setIntento(i => i + 1)}>Reintentar</Boton>}>{estado.error}</AvisoError>
            )}
            {!estado.cargando && !estado.error && estado.entradas.length === 0 && <p>Todavía no hay cambios registrados.</p>}
            {estado.entradas.length > 0 && (
                <ol className="max-h-[55dvh] overflow-y-auto divide-y-2 divide-line -mx-1 px-1">
                    {estado.entradas.map((e, i) => (
                        <li key={e.id ?? i} className="py-3">
                            <p className="font-semibold">{e.texto}</p>
                            <p className="text-base text-ink-soft">{[e.usuario, textoFecha(e.fecha)].filter(Boolean).join(' · ')}</p>
                            {e.detalles.length > 0 && (
                                <ul className="mt-1 list-disc pl-6 text-base text-ink">
                                    {e.detalles.map((d, j) => <li key={j}>{d}</li>)}
                                </ul>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </Dialogo>
    );
}
