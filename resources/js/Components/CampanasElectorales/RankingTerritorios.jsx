import { focoCls } from '@/Components/Reportes/ui';
import { Muestra } from '@/Components/CampanasElectorales/ui';
import { TEXTO_RANKING, colorDe, cuantos, falta, numero, pinturaDe } from '@/lib/campanas';

const MAXIMO_UNA_LISTA = 10;

function Fila({ t, onElegir }) {
    const pintura = pinturaDe(t, 'indice');
    return (
        <li>
            <button
                type="button"
                onClick={() => onElegir(t.id)}
                className={`w-full min-h-14 flex items-center gap-3 px-3 py-3 rounded-md text-left hover:bg-bg transition-colors duration-150 ${focoCls}`}
            >
                <Muestra clase={pintura.clase} color={colorDe(pintura)} className="w-4 h-10" />
                <span className="flex-1 min-w-0">
                    <span className="block text-lg font-semibold text-ink break-words">{t.nombre}</span>
                    <span className="block text-base text-ink-soft tabular-nums">
                        {t.votantes.falta > 0 ? `${falta(t.votantes.falta).toLowerCase()} ${cuantos(t.votantes.falta, 'votantes')}` : (t.votantes.meta !== null ? 'meta de votantes cumplida' : 'sin meta de votantes')}
                    </span>
                </span>
                <span className="text-right">
                    <span className="block font-heading text-2xl font-bold text-ink tabular-nums">{numero(t.indice)}</span>
                    <span className="block text-base text-ink-soft">de 100</span>
                </span>
            </button>
        </li>
    );
}

function Lista({ titulo, ids, porId, onElegir }) {
    const filas = ids.map(id => porId[id]).filter(Boolean);
    const idTitulo = `ranking-${titulo.replace(/\W+/g, '-')}`;
    return (
        <section aria-labelledby={idTitulo} className="bg-panel border-2 border-line rounded-lg p-4 sm:p-5">
            <h2 id={idTitulo} className="font-heading text-xl font-bold text-ink">{titulo} ({filas.length})</h2>
            {filas.length === 0
                ? <p className="mt-3 text-lg text-ink-soft">Aún no hay territorios para comparar.</p>
                : <ul className="mt-2 divide-y-2 divide-line">{filas.map(t => <Fila key={t.id} t={t} onElegir={onElegir} />)}</ul>}
        </section>
    );
}

// «Van mejor» / «Necesitan apoyo» as the server ranked them; with 10 or fewer territories, one list.
export default function RankingTerritorios({ tablero, onElegir, apilado = false }) {
    const { ranking, porId } = tablero;
    const todos = [...new Set([...ranking.mejores, ...ranking.porApoyar])];
    const unaLista = ranking.unaLista ?? todos.length <= MAXIMO_UNA_LISTA;
    const ordenados = [...todos].sort((a, b) => ((porId[b].indice ?? -1) - (porId[a].indice ?? -1)) || ((porId[b].votantes.falta ?? -1) - (porId[a].votantes.falta ?? -1)));
    return (
        <div>
            {unaLista ? (
                <Lista titulo="Cómo va cada territorio" ids={ordenados} porId={porId} onElegir={onElegir} />
            ) : (
                <div className={`grid grid-cols-1 gap-4 ${apilado ? '' : 'lg:grid-cols-2'}`}>
                    <Lista titulo="Van mejor" ids={ranking.mejores} porId={porId} onElegir={onElegir} />
                    <Lista titulo="Necesitan apoyo" ids={ranking.porApoyar} porId={porId} onElegir={onElegir} />
                </div>
            )}
            <p className="mt-3 text-base text-ink-soft">{ranking.nota ? `${ranking.nota.replace(/\.$/, '')}.` : TEXTO_RANKING}</p>
        </div>
    );
}
