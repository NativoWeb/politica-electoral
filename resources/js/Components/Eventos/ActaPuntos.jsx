import { newKey } from '@/lib/actas';
import { inputCls } from '@/Components/Eventos/ActaParticipantes';

function move(items, index, delta) {
    const next = [...items];
    const target = index + delta;
    if (target < 0 || target >= next.length) return items;
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

function RowButtons({ items, index, onChange, label }) {
    const btn = 'min-w-[44px] min-h-[44px] px-2 rounded-lg border border-[var(--color-line)] text-[14px] font-bold disabled:opacity-40';
    return (
        <div className="flex gap-1 flex-shrink-0">
            <button type="button" className={btn} disabled={index === 0} onClick={() => onChange(move(items, index, -1))} aria-label={`Subir ${label}`}>↑</button>
            <button type="button" className={btn} disabled={index === items.length - 1} onClick={() => onChange(move(items, index, 1))} aria-label={`Bajar ${label}`}>↓</button>
            <button type="button" className={`${btn} text-red-700`} onClick={() => onChange(items.filter((_, i) => i !== index))} aria-label={`Quitar ${label}`}>✕</button>
        </div>
    );
}

// PUNTOS DEL ORDEN DEL DÍA: an editable, ordered list.
export function OrdenDia({ items, onChange, disabled }) {
    return (
        <div className="space-y-2">
            <ol className="space-y-2" aria-label="Puntos del orden del día">
                {items.map((o, i) => (
                    <li key={o.key} className="flex items-start gap-2">
                        <span className="w-7 pt-3 text-[15px] font-extrabold text-[var(--color-ink-soft)] text-right">{i + 1}.</span>
                        <label className="flex-1 min-w-0">
                            <span className="sr-only">Punto {i + 1}</span>
                            <input className={inputCls} value={o.texto} disabled={disabled} maxLength={1000} onChange={e => onChange(items.map(x => (x.key === o.key ? { ...x, texto: e.target.value } : x)))} />
                        </label>
                        {!disabled && <RowButtons items={items} index={i} onChange={onChange} label={`el punto ${i + 1}`} />}
                    </li>
                ))}
            </ol>
            {!disabled && (
                <button type="button" onClick={() => onChange([...items, { key: newKey(), texto: '' }])} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[15px] font-bold text-[var(--color-primary)]">
                    Agregar punto
                </button>
            )}
        </div>
    );
}

// DESARROLLO DE LA REUNIÓN: a text per point (a point without title is an introduction).
export function Desarrollo({ items, onChange, orden = [], disabled }) {
    const fromOrden = () => onChange(orden.filter(o => o.texto.trim()).map(o => ({ key: newKey(), titulo: o.texto.trim(), texto: '' })));
    return (
        <div className="space-y-3">
            {!disabled && items.length === 0 && orden.some(o => o.texto.trim()) && (
                <button type="button" onClick={fromOrden} className="min-h-[44px] px-4 py-2 rounded-xl bg-blue-50 border border-blue-200 text-[15px] font-bold text-[var(--color-primary)]">
                    Crear un texto por cada punto del orden del día
                </button>
            )}
            {items.map((d, i) => (
                <div key={d.key} className="border border-[var(--color-line)] rounded-xl p-3 space-y-2">
                    <div className="flex items-start gap-2">
                        <label className="flex-1 min-w-0 block text-[14px] font-semibold text-[var(--color-ink)]">Punto {i + 1}
                            <input className={inputCls} value={d.titulo} disabled={disabled} maxLength={1000} placeholder="Título (vacío = introducción)" onChange={e => onChange(items.map(x => (x.key === d.key ? { ...x, titulo: e.target.value } : x)))} />
                        </label>
                        {!disabled && <div className="pt-6"><RowButtons items={items} index={i} onChange={onChange} label={`el punto ${i + 1} del desarrollo`} /></div>}
                    </div>
                    <label className="block text-[14px] font-semibold text-[var(--color-ink)]">Lo que se habló
                        <textarea className={`${inputCls} min-h-[110px]`} value={d.texto} disabled={disabled} maxLength={20000} onChange={e => onChange(items.map(x => (x.key === d.key ? { ...x, texto: e.target.value } : x)))} />
                    </label>
                </div>
            ))}
            {!disabled && (
                <button type="button" onClick={() => onChange([...items, { key: newKey(), titulo: '', texto: '' }])} className="min-h-[44px] px-4 py-2 rounded-xl border-2 border-[var(--color-primary)] text-[15px] font-bold text-[var(--color-primary)]">
                    Agregar texto
                </button>
            )}
        </div>
    );
}
