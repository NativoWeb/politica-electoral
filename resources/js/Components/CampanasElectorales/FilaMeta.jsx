import { memo } from 'react';
import { Boton, IconoChevron, focoCls, tactilCls } from '@/Components/CampanasElectorales/ui';
import { FUENTE_PUESTOS } from '@/lib/comunas';
import { CAMPOS_META, TEXTO_MENOS_DE_5, contarComunas, modoMunicipio, numero, plural, sugerenciaDe, validarMeta, valorMunicipio } from '@/lib/campanas';

// One territory of the goals grid. A single DOM for every width: a card on the phone, a table row from lg up
// (the column titles live in the page header row; here each cell carries its own label, visible on the phone).

export const COLUMNAS = 'lg:grid lg:grid-cols-[minmax(12rem,1.5fr)_repeat(3,minmax(7.5rem,1fr))_minmax(11rem,1.3fr)] lg:gap-3 lg:items-start';

function Celda({ fila, clave, etiqueta, valor, error, soloLectura, suma, onCambio }) {
    const id = `meta-${fila.id}-${clave}`;
    if (suma !== undefined) {
        return (
            <div className="flex items-center justify-between gap-3 lg:block">
                <span className="text-sm text-ink-soft lg:sr-only">{etiqueta}</span>
                <p className="text-[15px] font-semibold tabular-nums lg:min-h-10 lg:flex lg:items-center">
                    {numero(suma)}<span className="sr-only"> (suma de sus comunas)</span>
                </p>
            </div>
        );
    }
    if (soloLectura) {
        return (
            <div className="flex items-center justify-between gap-3 lg:block">
                <span className="text-sm text-ink-soft lg:sr-only">{etiqueta}</span>
                <p className="text-[15px] font-semibold tabular-nums lg:min-h-10 lg:flex lg:items-center">{valor === '' ? <span className="text-ink-soft font-normal">Sin meta</span> : numero(validarMeta(valor).valor)}</p>
            </div>
        );
    }
    return (
        <div>
            <div className="flex items-center justify-between gap-3 lg:block">
                <label htmlFor={id} className="text-sm text-ink-soft lg:sr-only">{etiqueta}<span className="sr-only"> de {fila.nombre}</span></label>
                <input
                    id={id}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    value={valor}
                    onChange={e => onCambio(fila.id, clave, e.target.value)}
                    onBlur={e => { const v = validarMeta(e.target.value); if (v.ok && v.valor !== null) onCambio(fila.id, clave, numero(v.valor)); }}
                    aria-invalid={!!error || undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className={`w-36 lg:w-full ${tactilCls} px-2.5 text-[15px] text-right tabular-nums text-ink rounded-md border ${error ? 'border-danger bg-danger-light' : 'bg-white border-line-strong focus:border-primary'} ${focoCls}`}
                />
            </div>
            {error && <p id={`${id}-error`} className="mt-1 text-[13px] font-semibold text-danger text-right lg:text-left">{error}</p>}
        </div>
    );
}

function LineaSugerido({ fila, sugerencia, soloLectura, onUsar }) {
    if (!sugerencia) {
        return fila.sinSugerencia ? <p className="text-[13px] text-ink-soft">{fila.sinSugerencia}</p> : null;
    }
    const derivados = `${plural(sugerencia.lideres, 'líder', 'líderes')} · ${plural(sugerencia.votantes, 'votante', 'votantes')}`;
    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-2 px-3 py-2 rounded-md bg-bg">
            <div className="flex-1 text-[13px] text-ink">
                <p>{sugerencia.texto || `Sugerido: ${plural(fila.sugerido.votos, 'voto', 'votos')}`}</p>
                <p className="font-semibold">
                    {sugerencia.conVotosPropios ? `Con tus ${plural(sugerencia.base, 'voto', 'votos')}: ${derivados}` : `Y para lograrlos: ${derivados}`}
                </p>
            </div>
            {!soloLectura && (
                <Boton variante="secundario" className="sm:min-h-8 px-3 text-[13px]" onClick={() => onUsar(fila.id)} aria-label={`Usar sugerido en ${fila.nombre}`}>
                    Usar sugerido
                </Boton>
            )}
        </div>
    );
}

function Avance({ fila }) {
    return (
        <div className="flex items-center justify-between gap-3 lg:block">
            <span className="text-sm text-ink-soft lg:sr-only">Avance</span>
            <div className="text-right lg:text-left lg:min-h-10 lg:flex lg:flex-col lg:justify-center">
                <p className="text-[13px] text-ink tabular-nums">
                    Votantes {numero(fila.avance.votantes)} · Líderes {numero(fila.avance.lideres)}
                </p>
                {(fila.porPuesto === null || fila.porPuesto > 0) && (
                    <p className="text-xs text-ink-soft tabular-nums">incluye {fila.porPuesto === null ? TEXTO_MENOS_DE_5 : numero(fila.porPuesto)} por puesto de votación</p>
                )}
            </div>
        </div>
    );
}

// Below the goals table, once, when some count includes fichas placed by their puesto de votación.
export function FuentePuestos() {
    return (
        <p data-testid="fuente-puestos" className="mt-2 text-xs text-ink-soft">
            Comunas según el puesto de votación. {FUENTE_PUESTOS}
        </p>
    );
}

function FilaMeta({ fila, borrador, errores, factores, soloLectura, abierto, onAbrir, onCambio, onUsar, nivel = 0 }) {
    const celdas = borrador[fila.id] ?? {};
    const modo = modoMunicipio(fila, borrador);
    const bloqueadaPorComunas = modo === 'comunas';
    const hijo = nivel > 0;
    const padreConMetaPropia = !hijo && fila.hijos.length > 0 && modo === 'propia';
    // editable false from the server (and not because of its comunas): shown, not changed.
    const fijo = soloLectura || (!fila.editable && !bloqueadaPorComunas);
    const sugerencia = bloqueadaPorComunas ? null : sugerenciaDe(fila, celdas, factores);

    return (
        <li className={`${hijo ? 'border-t border-line pt-3 mt-3 lg:pl-6' : 'bg-panel border border-line rounded-lg p-3 sm:p-4 lg:rounded-none lg:border-x-0 lg:border-t-0 lg:px-4 lg:py-3'}`}>
            <div className={`space-y-2 lg:space-y-0 ${COLUMNAS}`}>
                <div>
                    <p className={`${hijo ? 'text-sm' : 'font-heading text-[15px]'} font-bold text-ink break-words lg:pt-2`}>{fila.nombre}</p>
                    {fila.codigo && <p className="text-xs text-ink-soft tabular-nums">Código {fila.codigo}</p>}
                </div>
                {CAMPOS_META.map(({ clave, etiqueta }) => (
                    <Celda
                        key={clave}
                        fila={fila}
                        clave={clave}
                        etiqueta={etiqueta}
                        valor={celdas[clave] ?? ''}
                        error={errores?.[fila.id]?.[clave]}
                        soloLectura={fijo}
                        suma={bloqueadaPorComunas ? valorMunicipio(fila, borrador, clave) : undefined}
                        onCambio={onCambio}
                    />
                ))}
                <Avance fila={fila} />
            </div>

            <div className="mt-2 space-y-2">
                {bloqueadaPorComunas && <p className="text-[13px] text-ink-soft">Las metas de {fila.nombre} son la suma de sus comunas.</p>}
                <LineaSugerido fila={fila} sugerencia={sugerencia} soloLectura={fijo} onUsar={onUsar} />

                {fila.hijos.length > 0 && (
                    <button
                        type="button"
                        aria-expanded={abierto}
                        aria-controls={`comunas-${fila.id}`}
                        onClick={() => onAbrir(fila.id)}
                        className={`inline-flex items-center gap-1.5 min-h-11 sm:min-h-8 px-1 text-sm font-semibold text-primary rounded-md hover:underline underline-offset-4 ${focoCls}`}
                    >
                        <IconoChevron abierto={abierto} className="w-4 h-4" />
                        {abierto ? 'Ocultar comunas' : `Ver por comunas (${contarComunas(fila)})`}
                    </button>
                )}
            </div>

            {fila.hijos.length > 0 && abierto && (
                <div id={`comunas-${fila.id}`}>
                    {padreConMetaPropia && !soloLectura && (
                        <p role="note" className="mt-2 p-2.5 rounded-md border border-line-strong bg-bg text-[13px] text-ink">
                            {fila.nombre} ya tiene meta propia. Para repartirla por comunas, primero borra la meta de {fila.nombre}.
                        </p>
                    )}
                    <ul aria-label={`Comunas y corregimientos de ${fila.nombre}`}>
                        {fila.hijos.map(h => (
                            <FilaMetaMemo
                                key={h.id}
                                fila={h}
                                borrador={borrador}
                                errores={errores}
                                factores={factores}
                                soloLectura={soloLectura || padreConMetaPropia}
                                onCambio={onCambio}
                                onUsar={onUsar}
                                nivel={1}
                            />
                        ))}
                        {fila.sinComuna && (
                            <li className="border-t border-line pt-3 mt-3 lg:pl-6 text-sm text-ink">
                                <strong className="font-semibold">Sin comuna asignada:</strong> {plural(fila.sinComuna.votantes, 'ficha', 'fichas')}
                                {fila.sinComuna.lideres > 0 && <span className="text-ink-soft"> ({plural(fila.sinComuna.lideres, 'líder', 'líderes')})</span>}
                            </li>
                        )}
                    </ul>
                </div>
            )}
        </li>
    );
}

const FilaMetaMemo = memo(FilaMeta);
export default FilaMetaMemo;
