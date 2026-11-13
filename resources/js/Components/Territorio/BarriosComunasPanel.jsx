import { useCallback, useEffect, useMemo, useState } from 'react';
import { apiFetch, showGlobalToast } from '@/lib/api';

const ESTADOS = {
    ambiguo: { label: 'Ambiguo', cls: 'bg-amber-100 text-amber-800' },
    sin_match: { label: 'Sin coincidencia', cls: 'bg-gray-100 text-gray-700' },
    otro_municipio: { label: 'Menciona otro municipio', cls: 'bg-red-100 text-red-700' },
    asignado: { label: 'Sugerido', cls: 'bg-emerald-100 text-emerald-800' },
};

function countLabel(item) {
    return [
        [item.lideres, 'ficha', 'fichas'],
        [item.event_attendees, 'asistente', 'asistentes'],
        [item.organizaciones, 'organización', 'organizaciones'],
    ].filter(([n]) => n > 0).map(([n, one, many]) => `${n} ${n === 1 ? one : many}`).join(' · ');
}

// value "b:<barrio id>" or "c:<comuna id>"
function PendienteRow({ item, comunas, barriosPorComuna, onAssign, busy }) {
    const initial = item.sugerencia?.barrio_id ? `b:${item.sugerencia.barrio_id}` : (item.candidatos?.[0] ? `b:${item.candidatos[0].barrio_id}` : '');
    const [choice, setChoice] = useState(initial);
    const estado = ESTADOS[item.estado] || ESTADOS.sin_match;

    return (
        <li className="bg-white border border-[var(--color-line)] rounded-xl p-4 space-y-3" data-testid="pendiente">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[15px] font-bold text-[var(--color-ink)] break-words">{item.texto}</p>
                    {item.ejemplos?.length > 0 && (
                        <p className="text-[12px] text-[var(--color-ink-faint)] break-words">Escrito como: {item.ejemplos.join(' · ')}</p>
                    )}
                    <p className="text-[12px] text-[var(--color-ink-soft)] mt-0.5">{countLabel(item)}</p>
                </div>
                <span className={`px-2 py-0.5 rounded-full text-[11px] font-bold ${estado.cls}`}>{estado.label}</span>
            </div>

            {item.candidatos?.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {item.candidatos.map(c => (
                        <button
                            key={c.barrio_id}
                            type="button"
                            onClick={() => setChoice(`b:${c.barrio_id}`)}
                            className={`px-2 py-1 rounded-lg text-[12px] border ${choice === `b:${c.barrio_id}` ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)]' : 'border-[var(--color-line)] text-[var(--color-ink-soft)]'}`}
                        >
                            {c.barrio} <span className="text-[var(--color-ink-faint)]">({c.comuna})</span>
                        </button>
                    ))}
                </div>
            )}

            <div className="flex flex-col sm:flex-row gap-2">
                <label className="sr-only" htmlFor={`asignar-${item.texto}`}>Barrio o comuna para «{item.texto}»</label>
                <select
                    id={`asignar-${item.texto}`}
                    value={choice}
                    onChange={e => setChoice(e.target.value)}
                    className="flex-1 min-w-0 px-3 py-2 border border-[var(--color-line)] rounded-lg text-[14px] bg-white focus:outline-none focus:border-[var(--color-primary)]"
                >
                    <option value="">Elegir barrio o comuna…</option>
                    <optgroup label="Solo la comuna">
                        {comunas.map(c => <option key={c.id} value={`c:${c.id}`}>{c.nombre}</option>)}
                    </optgroup>
                    {comunas.map(c => (barriosPorComuna[c.id] || []).length > 0 && (
                        <optgroup key={c.id} label={`Barrios · ${c.nombre}`}>
                            {barriosPorComuna[c.id].map(b => <option key={b.id} value={`b:${b.id}`}>{b.nombre}</option>)}
                        </optgroup>
                    ))}
                </select>
                <button
                    type="button"
                    disabled={!choice || busy}
                    onClick={() => onAssign(item, choice)}
                    className="px-4 py-2 bg-[var(--color-primary)] text-white text-[13px] font-bold rounded-lg disabled:opacity-50"
                >
                    {busy ? 'Asignando…' : 'Asignar'}
                </button>
            </div>
        </li>
    );
}

// Admin → Territorio → "Barrios y comunas": barrio texts of the grupo político's records that got no comuna. Assigning
// one saves an alias for that text and moves every record with it to the comuna.
export default function BarriosComunasPanel() {
    const [municipioId, setMunicipioId] = useState('');
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [busyTexto, setBusyTexto] = useState(null);

    const load = useCallback(async (id) => {
        setLoading(true);
        setError('');
        try {
            const r = await apiFetch(`/admin/territorio/barrios-sin-comuna${id ? `?municipio_id=${encodeURIComponent(id)}` : ''}`, { method: 'GET' });
            const res = await r.json().catch(() => null);
            if (!r.ok || !res?.success) {
                setError(res?.message || 'No se pudo cargar la lista.');
                return;
            }
            setData(res);
            if (!id && res.municipio) setMunicipioId(res.municipio.id);
        } catch {
            setError('Sin conexión: no se pudo cargar la lista.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(''); }, [load]);

    const barriosPorComuna = useMemo(() => (data?.barrios || []).reduce((acc, b) => {
        (acc[b.comuna_id] ||= []).push(b);
        return acc;
    }, {}), [data]);

    async function assign(item, choice) {
        const [kind, id] = choice.split(':');
        setBusyTexto(item.texto);
        try {
            const r = await apiFetch('/admin/territorio/barrios/alias', {
                method: 'POST',
                body: JSON.stringify({
                    municipio_id: data.municipio.id,
                    texto: item.ejemplos?.[0] || item.texto,
                    [kind === 'b' ? 'barrio_id' : 'comuna_id']: id,
                }),
            });
            const res = await r.json().catch(() => null);
            if (!r.ok || !res?.success) {
                showGlobalToast((res?.errors && Object.values(res.errors)[0]?.[0]) || res?.message || 'No se pudo asignar.', 'error');
                return;
            }
            showGlobalToast(res.message);
            await load(data.municipio.id);
        } catch {
            showGlobalToast('Sin conexión: no se asignó.', 'error');
        } finally {
            setBusyTexto(null);
        }
    }

    if (loading && !data) {
        return <p className="py-8 text-center text-[14px] text-[var(--color-ink-faint)]">Cargando barrios…</p>;
    }
    if (error && !data) {
        return (
            <div role="alert" className="py-8 text-center space-y-3">
                <p className="text-[14px] text-red-600">{error}</p>
                <button type="button" onClick={() => load(municipioId)} className="px-4 py-2 border border-[var(--color-line)] rounded-lg text-[13px] font-bold">Reintentar</button>
            </div>
        );
    }
    if (!data?.municipios?.length) {
        return <p className="py-8 text-center text-[14px] text-[var(--color-ink-faint)]">Ningún municipio de tu territorio tiene comunas cargadas.</p>;
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                <div className="flex-1">
                    <p className="text-[14px] text-[var(--color-ink-soft)]">
                        Barrios escritos en las fichas, asistentes y organizaciones de tu grupo político que el sistema no pudo ubicar en una comuna.
                        Al asignar uno, todos los registros con ese mismo texto pasan a esa comuna.
                    </p>
                </div>
                {data.municipios.length > 1 && (
                    <div>
                        <label htmlFor="barrios-municipio" className="block text-[12px] font-bold text-[var(--color-ink-faint)] mb-1">Municipio</label>
                        <select
                            id="barrios-municipio"
                            value={municipioId}
                            onChange={e => { setMunicipioId(e.target.value); load(e.target.value); }}
                            className="px-3 py-2 border border-[var(--color-line)] rounded-lg text-[14px] bg-white"
                        >
                            {data.municipios.map(m => <option key={m.id} value={m.id}>{m.nombre}</option>)}
                        </select>
                    </div>
                )}
            </div>

            {error && <p role="alert" className="text-[13px] text-red-600">{error}</p>}

            <p className="text-[13px] font-bold text-[var(--color-ink)]">
                {data.municipio?.nombre}: {data.pendientes.length === 0 ? 'no hay barrios por asignar.' : `${data.total_textos} ${data.total_textos === 1 ? 'texto' : 'textos'} sin comuna`}
            </p>

            {data.pendientes.length > 0 && (
                <ul className="space-y-3">
                    {data.pendientes.map(item => (
                        <PendienteRow
                            key={item.texto}
                            item={item}
                            comunas={data.comunas}
                            barriosPorComuna={barriosPorComuna}
                            onAssign={assign}
                            busy={busyTexto === item.texto}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}
