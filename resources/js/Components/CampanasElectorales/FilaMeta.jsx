import { memo } from 'react';
import { Boton, IconoChevron, focoCls } from '@/Components/Reportes/ui';
import { FUENTE_PUESTOS } from '@/lib/comunas';
import { CAMPOS_META, contarComunas, modoMunicipio, numero, plural, sugerenciaDe, validarMeta, valorMunicipio } from '@/lib/campanas';

// One territory of the goals grid. A single DOM for every width: a card on the phone, a table row from lg up
// (the column titles live in the page header row; here each cell carries its own label, visible on the phone).

export const COLUMNAS = 'lg:grid lg:grid-cols-[minmax(13rem,1.5fr)_repeat(3,minmax(8.5rem,1fr))_minmax(12rem,1.3fr)] lg:gap-4 lg:items-start';

function Celda({ fila, clave, etiqueta, valor, error, soloLectura, suma, onCambio }) {
    const id = `meta-${fila.id}-${clave}`;
    if (suma !== undefined) {
        return (
            <div className="flex items-center justify-between gap-3 lg:block">
                <span className="text-lg text-ink-soft lg:sr-only">{etiqueta}</span>
                <p className="text-lg font-semibold tabular-nums lg:min-h-14 lg:flex lg:items-center">
                    {numero(suma)}<span className="sr-only"> (suma de sus comunas)</span>
                </p>
            </div>
        );
    }
    if (soloLectura) {
        return (
            <div className="flex items-center justify-between gap-3 lg:block">
                <span className="text-lg text-ink-soft lg:sr-only">{etiqueta}</span>
                <p className="text-lg font-semibold tabular-nums lg:min-h-14 lg:flex lg:items-center">{valor === '' ? <span className="text-ink-soft font-normal">Sin meta</span> : numero(validarMeta(valor).valor)}</p>
            </div>
        );
    }
    return (
        <div>
            <div className="flex items-center justify-between gap-3 lg:block">
                <label htmlFor={id} className="text-lg text-ink-soft lg:sr-only">{etiqueta}<span className="sr-only"> de {fila.nombre}</span></label>
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
                    className={`w-40 lg:w-full min-h-14 px-3 text-lg text-right tabular-nums text-ink rounded-md border-2 bg-bg ${error ? 'border-danger' : 'border-line-strong focus:border-primary'} ${focoCls}`}
                />
            </div>
            {error && <p id={`${id}-error`} className="mt-1 text-base font-semibold text-danger text-right lg:text-left">{error}</p>}
        </div>
    );
}

function LineaSugerido({ fila, sugerencia, soloLectura, onUsar }) {
    if (!sugerencia) {
        return fila.sinSugerencia ? <p className="text-base text-ink-soft">{fila.sinSugerencia}</p> : null;
    }
    const derivados = `${plural(sugerencia.lideres, 'líder', 'líderes')} · ${plural(sugerencia.votantes, 'votante', 'votantes')}`;
    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 p-3 rounded-md bg-bg">
            <div className="flex-1 text-base text-ink">
                <p>{sugerencia.texto || `Sugerido: ${plural(fila.sugerido.votos, 'voto', 'votos')}`}</p>
                <p className="font-semibold">
                    {sugerencia.conVotosPropios ? `Con tus ${plural(sugerencia.base, 'voto', 'votos')}: ${derivados}` : `Y para lograrlos: ${derivados}`}
                </p>
            </div>
            {!soloLectura && (
                <Boton variante="secundario" className="min-h-12 text-base px-4" onClick={() => onUsar(fila.id)} aria-label={`Usar sugerido en ${fila.nombre}`}>
                    Usar sugerido
                </Boton>
            )}
        </div>
    );
}

function Avance({ fila }) {
    return (
        <div className="flex items-center justify-between gap-3 lg:block">
            <span className="text-lg text-ink-soft lg:sr-only">Avance</span>
            <div className="text-right lg:text-left lg:min-h-14 lg:flex lg:flex-col lg:justify-center">
                <p className="text-base text-ink tabular-nums">
                    Votantes {numero(fila.avance.votantes)} · Líderes {numero(fila.avance.lideres)}
                </p>
                {fila.porPuesto > 0 && (
                    <p className="text-sm text-ink-soft tabular-nums">incluye {numero(fila.porPuesto)} por puesto de votación</p>
                )}
            </div>
        </div>
    );
}

// Below the goals table, once, when some count includes fichas placed by their puesto de votación.
export function FuentePuestos() {
    return (
        <p data-testid="fuente-puestos" className="mt-3 text-sm text-ink-soft">
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
        <li className={`${hijo ? 'border-t-2 border-line pt-4 mt-4 lg:pl-6' : 'bg-panel border-2 border-line rounded-lg p-4 sm:p-5 lg:rounded-none lg:border-x-0 lg:border-t-0 lg:px-4 lg:py-4'}`}>
            <div className={`space-y-3 lg:space-y-0 ${COLUMNAS}`}>
                <div>
                    <p className={`${hijo ? 'text-lg' : 'font-heading text-xl'} font-bold text-ink break-words`}>{fila.nombre}</p>
                    {fila.codigo && <p className="text-base text-ink-soft tabular-nums">Código {fila.codigo}</p>}
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

            <div className="mt-3 space-y-3">
                {bloqueadaPorComunas && <p className="text-base text-ink-soft">Las metas de {fila.nombre} son la suma de sus comunas.</p>}
                <LineaSugerido fila={fila} sugerencia={sugerencia} soloLectura={fijo} onUsar={onUsar} />

                {fila.hijos.length > 0 && (
                    <button
                        type="button"
                        aria-expanded={abierto}
                        aria-controls={`comunas-${fila.id}`}
                        onClick={() => onAbrir(fila.id)}
                        className={`inline-flex items-center gap-2 min-h-12 px-1 text-lg font-semibold text-primary rounded-md hover:underline underline-offset-4 ${focoCls}`}
                    >
                        <IconoChevron abierto={abierto} className="w-5 h-5" />
                        {abierto ? 'Ocultar comunas' : `Ver por comunas (${contarComunas(fila)})`}
                    </button>
                )}
            </div>

            {fila.hijos.length > 0 && abierto && (
                <div id={`comunas-${fila.id}`}>
                    {padreConMetaPropia && !soloLectura && (
                        <p role="note" className="mt-3 p-3 rounded-md border-2 border-line-strong bg-bg text-base text-ink">
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
                            <li className="border-t-2 border-line pt-4 mt-4 lg:pl-6 text-lg text-ink">
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
