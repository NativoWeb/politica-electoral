import { cuantos, falta as textoFalta, numero, porcentaje } from '@/lib/campanas';

// A progress bar that never overflows: past the goal it is simply full.
export function BarraAvance({ pct, etiqueta, grande = false }) {
    const ancho = pct === null || pct === undefined ? 0 : Math.min(Math.max(Number(pct), 0), 1) * 100;
    return (
        <div
            role="progressbar"
            aria-label={etiqueta}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={pct === null || pct === undefined ? undefined : Math.round(Number(pct) * 100)}
            aria-valuetext={pct === null || pct === undefined ? 'Sin meta' : porcentaje(pct)}
            className={`w-full rounded-full bg-line overflow-hidden ${grande ? 'h-2.5' : 'h-2'}`}
        >
            <div className={`h-full rounded-full ${ancho >= 100 ? 'bg-primary-dark' : 'bg-primary'}`} style={{ width: `${ancho}%` }} />
        </div>
    );
}

// «N de M», the bar and what is missing. medida: {registrados, meta, metaCalculada, pct, falta}.
export function Avance({ titulo, medida, unidad, grande = false }) {
    const { registrados, conMeta, meta, metaCalculada, pct, falta } = medida;
    const contados = meta === null ? registrados : (conMeta ?? registrados);
    return (
        <div>
            <p className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span className="text-sm font-semibold text-ink-soft">{titulo}</span>
                {meta !== null && <span className="text-sm font-semibold text-ink tabular-nums">{porcentaje(pct)}</span>}
            </p>
            <p className={`mt-0.5 tabular-nums text-ink ${grande ? 'font-heading text-2xl font-bold' : 'text-[15px] font-semibold'}`}>
                {numero(contados)}
                {meta !== null
                    ? <span className={`${grande ? 'text-base' : 'text-sm'} font-normal text-ink-soft`}> de {numero(meta)}</span>
                    : <span className="text-sm font-normal text-ink-soft"> registrados</span>}
            </p>
            <div className="mt-2"><BarraAvance pct={meta === null ? null : pct} etiqueta={`${titulo}: avance`} grande={grande} /></div>
            <p className="mt-1.5 text-[13px] text-ink-soft tabular-nums">
                {meta === null && 'Sin meta'}
                {meta !== null && falta > 0 && <>{textoFalta(falta)} <strong className="font-semibold text-ink">{cuantos(falta, unidad)}</strong></>}
                {meta !== null && falta === 0 && <strong className="font-semibold text-ink">Meta cumplida</strong>}
                {metaCalculada && <span className="block">Meta calculada con los votos meta</span>}
                {meta !== null && registrados > contados && <span className="block">En total hay {numero(registrados)}, contando los territorios sin meta.</span>}
            </p>
        </div>
    );
}

export function TarjetaAvance({ titulo, medida, unidad }) {
    return (
        <section aria-label={titulo} className="h-full bg-panel border border-line rounded-lg p-4">
            <Avance titulo={titulo} medida={medida} unidad={unidad} grande />
        </section>
    );
}

function fraseIndice(indice, totales) {
    if (indice === null) return 'Sin metas para calcular el índice.';
    const v = totales.votantes.meta !== null;
    const l = totales.lideres.meta !== null;
    if (v && l) return 'Junta el avance en votantes (60 %) y en líderes (40 %).';
    return v ? 'Solo cuenta votantes: no hay meta de líderes.' : 'Solo cuenta líderes: no hay meta de votantes.';
}

export function TarjetaIndice({ indice, totales }) {
    return (
        <section aria-label="Índice de avance" className="h-full bg-primary text-white rounded-lg p-4 border border-primary">
            <p className="text-sm font-semibold text-white/85">Índice de avance</p>
            <p className="mt-0.5 font-heading text-2xl font-bold tabular-nums">
                {indice === null ? '—' : numero(indice)}<span className="text-base font-normal text-white/85"> de 100</span>
            </p>
            <div className="mt-2 h-2.5 w-full rounded-full bg-white/20 overflow-hidden" aria-hidden="true">
                <div className="h-full rounded-full bg-white" style={{ width: `${Math.min(indice ?? 0, 100)}%` }} />
            </div>
            <p className="mt-1.5 text-[13px] text-white/90 text-pretty">{fraseIndice(indice, totales)}</p>
        </section>
    );
}
