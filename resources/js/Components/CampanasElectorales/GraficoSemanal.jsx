import { useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Chip } from '@/Components/Reportes/ui';
import { SEMANAS_POR_DEFECTO, TEXTO_MASIVA, TEXTO_MENOS_DE_5, cuantos, falta, fechaCorta, numero, textoSemana } from '@/lib/campanas';

const COLOR_BARRA = '#3d74bb';
const COLOR_EJE = '#4A4A68';
const COLOR_REJILLA = '#E8E8EE';

export function IconoMasiva({ className = 'w-5 h-5' }) {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" className={className}>
            <path d="M10 2 19 18H1z" fill="#1A1A2E" />
            <path d="M10 8v4.5M10 15h.01" stroke="#FFD100" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}

function MarcaMasiva({ x, y, width, value }) {
    if (!value) return null;
    const cx = x + width / 2;
    return (
        <g transform={`translate(${cx - 8}, ${y - 20})`} aria-hidden="true">
            <path d="M8 1 15.5 15H.5z" fill="#1A1A2E" />
            <path d="M8 6v4M8 12.5h.01" stroke="#FFD100" strokeWidth="1.8" strokeLinecap="round" />
        </g>
    );
}

function Globo({ active, payload }) {
    const s = active && payload?.[0]?.payload;
    if (!s) return null;
    return (
        <div className="bg-panel border-2 border-line-strong rounded-md px-3 py-2 shadow-lg text-base text-ink max-w-xs">
            <p className="font-semibold">{textoSemana(s)}</p>
            {s.masiva && <p className="mt-1 flex items-start gap-2 text-ink-soft"><IconoMasiva className="w-5 h-5 flex-shrink-0" />{TEXTO_MASIVA}</p>}
        </div>
    );
}

function Barras({ semanas, alto, mini }) {
    const datos = semanas.map(s => ({ ...s, valor: s.nuevos ?? 0 }));
    return (
        <div style={{ height: alto }} className="w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={datos} margin={{ top: mini ? 8 : 24, right: 8, bottom: 0, left: mini ? 8 : 0 }} barCategoryGap={2}>
                    {!mini && <CartesianGrid vertical={false} stroke={COLOR_REJILLA} />}
                    <XAxis dataKey="inicio" tickFormatter={v => fechaCorta(v)} tick={{ fill: COLOR_EJE, fontSize: 14 }} tickLine={false} axisLine={{ stroke: COLOR_REJILLA }} minTickGap={24} hide={mini} />
                    {!mini && <YAxis allowDecimals={false} tickFormatter={v => numero(v)} tick={{ fill: COLOR_EJE, fontSize: 14 }} tickLine={false} axisLine={false} width={56} />}
                    <Tooltip content={<Globo />} cursor={{ fill: 'rgba(0,59,113,0.08)' }} />
                    <Bar dataKey="valor" fill={COLOR_BARRA} radius={[4, 4, 0, 0]} maxBarSize={24} isAnimationActive={false}>
                        {!mini && <LabelList dataKey="masiva" content={<MarcaMasiva />} />}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

function TablaSemanas({ semanas }) {
    return (
        <details className="mt-3 group">
            <summary className="min-h-12 inline-flex items-center cursor-pointer text-lg font-semibold text-primary underline underline-offset-4">Ver los números de cada semana</summary>
            <div className="mt-2 max-h-80 overflow-y-auto">
                <table className="w-full text-lg tabular-nums">
                    <caption className="sr-only">Fichas nuevas por semana</caption>
                    <thead><tr className="border-b-2 border-line text-left text-base text-ink-soft"><th scope="col" className="py-2">Semana del</th><th scope="col" className="py-2 text-right">Fichas nuevas</th></tr></thead>
                    <tbody>
                        {semanas.map(s => (
                            <tr key={s.inicio} className="border-b border-line">
                                <th scope="row" className="py-2 text-left font-normal">{fechaCorta(s.inicio, true)}{s.masiva && <span className="ml-2 inline-flex align-middle"><IconoMasiva /><span className="sr-only">{TEXTO_MASIVA}</span></span>}</th>
                                <td className="py-2 text-right">{s.nuevos === null ? TEXTO_MENOS_DE_5 : numero(s.nuevos)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

// Mini: the bars of one territory inside its detail (no axes, no range switch).
export function MiniSemanas({ semanas, cuantas = SEMANAS_POR_DEFECTO }) {
    const ultimas = semanas.slice(-cuantas);
    return (
        <div>
            <p className="text-base text-ink-soft">Últimas {ultimas.length} semanas</p>
            <Barras semanas={ultimas} alto={110} mini />
            <TablaSemanas semanas={ultimas} />
        </div>
    );
}

// Weekly new fichas (voters only). Default: the last 26 weeks; «Desde el inicio» shows them all.
export default function GraficoSemanal({ evolucion, fechaEleccion = null }) {
    const [todas, setTodas] = useState(false);
    const { semanas, antes, antesTexto, desde, promedio4, ritmo, masivaTexto } = evolucion;
    const porDefecto = evolucion.semanasDefecto ?? SEMANAS_POR_DEFECTO;
    const visibles = useMemo(() => (todas ? semanas : semanas.slice(-porDefecto)), [semanas, todas, porDefecto]);
    const hayMasiva = visibles.some(s => s.masiva);

    return (
        <section aria-labelledby="titulo-semanas" className="bg-panel border-2 border-line rounded-lg p-4 sm:p-6">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h2 id="titulo-semanas" className="font-heading text-2xl font-bold text-ink">Fichas nuevas por semana</h2>
                    <p className="mt-1 text-base text-ink-soft">Votantes registrados cada semana (lunes a domingo, hora de Colombia).</p>
                </div>
                {semanas.length > porDefecto && (
                    <div role="group" aria-label="Qué semanas ver" className="flex flex-wrap gap-2">
                        <Chip activo={!todas} onClick={() => setTodas(false)}>Últimas {porDefecto} semanas</Chip>
                        <Chip activo={todas} onClick={() => setTodas(true)}>Desde el inicio</Chip>
                    </div>
                )}
            </div>

            {ritmo && (ritmo.texto || ritmo.semanas > 0) && (
                <p className="mt-4 p-4 rounded-md bg-bg border-2 border-line text-lg text-ink text-pretty">
                    {ritmo.texto || <>{falta(ritmo.semanas)} <strong className="font-semibold">{cuantos(ritmo.semanas, 'semanas')}</strong>{fechaEleccion ? ` para el ${fechaCorta(fechaEleccion, true)}` : ''}: hay que registrar <strong className="font-semibold">{numero(ritmo.porSemana)} por semana</strong>.
                    {promedio4 !== null && <> Las últimas 4 semanas: {numero(promedio4)} por semana.</>}</>}
                </p>
            )}

            {visibles.length === 0
                ? <p className="mt-4 text-lg text-ink-soft">Aún no hay fichas nuevas desde el {fechaCorta(desde, true) || 'inicio de la campaña'}.</p>
                : <div className="mt-4"><Barras semanas={visibles} alto={260} /></div>}

            {hayMasiva && <p className="mt-3 flex items-start gap-2 text-base text-ink-soft"><IconoMasiva className="w-5 h-5 flex-shrink-0 mt-0.5" />{masivaTexto || TEXTO_MASIVA}</p>}
            {(antesTexto || (antes !== null && antes > 0)) && (
                <p className="mt-2 text-base text-ink-soft">{antesTexto || `${numero(antes)} ya estaban registrados antes del ${fechaCorta(desde, true) || '22 sep 2025'} (carga inicial del sistema anterior).`}</p>
            )}
            {visibles.length > 0 && <TablaSemanas semanas={visibles} />}
        </section>
    );
}
