import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    AvisoError, Boton, Chip, ErrorCampo, Girando, IconoBuscar, IconoChevron, LinkBoton, ayudaCls, campoCls, etiquetaCls, focoCls, tactilCls,
    tarjetaCls, tituloPaginaCls,
} from '@/Components/CampanasElectorales/ui';
import { showGlobalToast } from '@/lib/api';
import {
    BASE, CANDIDATO_MIN_LETRAS, buscarCandidatos, cargoDe, crearCampana, cuerpoFormulario, editarCampana,
    erroresServidor, formularioInicial, mensajeDe, normalizarCampana, normalizarOpciones, validarFormulario,
} from '@/lib/campanas';

const ORDEN_CAMPOS = ['nombre', 'cargo', 'anio', 'fecha', 'candidato', 'partido', 'territorio', 'factor_votos', 'factor_registro', 'votos_por_lider'];

function Paso({ numero, titulo, htmlFor, children, ayuda, id }) {
    const Titulo = htmlFor ? 'label' : 'h2';
    return (
        <section aria-labelledby={id ? `${id}-titulo` : undefined} className="pt-4 first:pt-0">
            <div className="flex items-center gap-2">
                <span aria-hidden="true" className="w-6 h-6 flex-shrink-0 rounded-full bg-primary text-white flex items-center justify-center font-heading text-xs font-bold">{numero}</span>
                <Titulo id={id ? `${id}-titulo` : undefined} htmlFor={htmlFor} className="font-heading text-base font-bold text-ink text-balance">{titulo}</Titulo>
            </div>
            {ayuda && <p className="mt-1 text-[13px] text-ink-soft">{ayuda}</p>}
            <div className="mt-2">{children}</div>
        </section>
    );
}

function BuscadorCandidato({ form, set, error, puedeBuscar, onSinPermiso }) {
    const [q, setQ] = useState('');
    const [estado, setEstado] = useState({ cargando: false, error: '', items: null });
    const id = useId();

    useEffect(() => {
        const t = q.trim();
        if (!puedeBuscar || t.length < CANDIDATO_MIN_LETRAS) { setEstado({ cargando: false, error: '', items: null }); return undefined; }
        const ctrl = new AbortController();
        setEstado(e => ({ ...e, cargando: true, error: '' }));
        const timer = setTimeout(async () => {
            try {
                const res = await buscarCandidatos(t, ctrl.signal);
                if (ctrl.signal.aborted) return;
                if (res.status === 403) { onSinPermiso(); return; }
                setEstado(res.ok ? { cargando: false, error: '', items: res.candidatos } : { cargando: false, error: mensajeDe(res, 'No pudimos buscar. Intenta de nuevo.'), items: null });
            } catch {
                // Aborted by a newer search.
            }
        }, 350);
        return () => { clearTimeout(timer); ctrl.abort(); };
    }, [q, puedeBuscar, onSinPermiso]);

    if (form.candidatoId) {
        return (
            <div className="flex flex-col sm:flex-row sm:items-center gap-2 p-3 rounded-md border border-primary bg-panel">
                <p className="flex-1 text-[15px]"><span className="text-ink-soft">Candidato elegido:</span> <strong className="font-semibold">{form.candidatoNombre}</strong></p>
                <Boton variante="secundario" onClick={() => set({ candidatoId: null, candidatoNombre: '' })}>Cambiar</Boton>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {puedeBuscar && (
                <div>
                    <label htmlFor={`${id}-q`} className={etiquetaCls}>Busca al candidato en la plataforma</label>
                    <div className="relative mt-1">
                        <IconoBuscar className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-soft pointer-events-none" />
                        <input id={`${id}-q`} type="search" value={q} onChange={e => setQ(e.target.value)} autoComplete="off" placeholder="Escribe al menos 2 letras" className={`${campoCls} pl-9`} />
                    </div>
                    <div aria-live="polite" className="mt-1">
                        {estado.cargando && <p className="flex items-center gap-2 text-sm text-ink-soft"><Girando className="w-4 h-4" />Buscando…</p>}
                        {estado.error && <p className="text-sm text-danger font-semibold">{estado.error}</p>}
                        {estado.items?.length === 0 && <p className="text-sm text-ink-soft">No encontramos a nadie con ese nombre. Puedes escribirlo abajo.</p>}
                    </div>
                    {estado.items?.length > 0 && (
                        <ul className="mt-1 rounded-md border border-line bg-panel divide-y divide-line" aria-label="Candidatos encontrados">
                            {estado.items.map(c => (
                                <li key={c.id}>
                                    <button type="button" onClick={() => { set({ candidatoId: c.id, candidatoNombre: c.nombre }); setQ(''); }}
                                        className={`w-full ${tactilCls} px-3 py-1.5 text-left text-sm hover:bg-bg ${focoCls}`}>
                                        <span className="font-semibold text-ink">{c.nombre}</span>
                                        {c.detalle && <span className="block text-[13px] text-ink-soft">{c.detalle}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            <div>
                <label htmlFor="campo-candidato" className={etiquetaCls}>{puedeBuscar ? 'o escribe su nombre' : 'Escribe el nombre del candidato'}</label>
                <input id="campo-candidato" type="text" value={form.candidatoNombre} maxLength={160} onChange={e => set({ candidatoNombre: e.target.value })}
                    aria-invalid={!!error || undefined} aria-describedby={error ? 'error-candidato' : undefined} className={`${campoCls} mt-1`} />
                <p className={ayudaCls}>Puedes dejarlo vacío si aún no hay candidato.</p>
                <ErrorCampo id="error-candidato">{error}</ErrorCampo>
            </div>
        </div>
    );
}

function Territorio({ form, set, opciones, error }) {
    const cargo = cargoDe(form.cargo);
    const [filtro, setFiltro] = useState('');
    const describe = error ? 'error-territorio' : undefined;

    if (!cargo) return <p className="text-sm text-ink-soft">Primero elige el cargo.</p>;
    const depto = opciones.departamento?.nombre ?? 'Santander';

    if (cargo.territorio === 'departamento') {
        return <p className="p-3 rounded-md bg-bg border border-line text-sm">Todo el departamento de {depto}.</p>;
    }

    if (cargo.territorio === 'municipio') {
        return (
            <div>
                <label htmlFor="campo-territorio" className={etiquetaCls}>Municipio</label>
                <select id="campo-territorio" value={form.territorioIds[0] ?? ''} onChange={e => set({ territorioIds: e.target.value ? [e.target.value] : [] })}
                    aria-invalid={!!error || undefined} aria-describedby={describe} className={`${campoCls} mt-1`}>
                    <option value="">Elige un municipio</option>
                    {opciones.municipios.map(m => <option key={m.id} value={m.id}>{m.nombre}</option>)}
                </select>
                <ErrorCampo id="error-territorio">{error}</ErrorCampo>
            </div>
        );
    }

    if (cargo.territorio === 'comuna') {
        const grupos = [...new Set(opciones.comunas.map(c => c.municipioNombre))];
        return (
            <div>
                <label htmlFor="campo-territorio" className={etiquetaCls}>Comuna o corregimiento</label>
                <select id="campo-territorio" value={form.territorioIds[0] ?? ''} onChange={e => set({ territorioIds: e.target.value ? [e.target.value] : [] })}
                    aria-invalid={!!error || undefined} aria-describedby={describe} className={`${campoCls} mt-1`}>
                    <option value="">Elige una comuna o corregimiento</option>
                    {grupos.map(g => (
                        <optgroup key={g || 'sin'} label={g || 'Otros'}>
                            {opciones.comunas.filter(c => c.municipioNombre === g).map(c => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                        </optgroup>
                    ))}
                </select>
                {opciones.comunas.length === 0 && <p className={ayudaCls}>No hay comunas registradas todavía.</p>}
                <ErrorCampo id="error-territorio">{error}</ErrorCampo>
            </div>
        );
    }

    const elegidos = new Set(form.territorioIds);
    const visibles = opciones.municipios.filter(m => !filtro.trim() || m.nombre.toLowerCase().includes(filtro.trim().toLowerCase()));
    const alternar = (idM) => set({ territorioIds: elegidos.has(idM) ? form.territorioIds.filter(x => x !== idM) : [...form.territorioIds, idM] });

    return (
        <div>
            <div role="group" aria-label="Dónde" className="flex flex-wrap gap-2">
                <Chip activo={form.alcance === 'departamento'} onClick={() => set({ alcance: 'departamento', territorioIds: [] })}>Todo {depto}</Chip>
                <Chip activo={form.alcance === 'municipios'} onClick={() => set({ alcance: 'municipios' })}>Algunos municipios</Chip>
            </div>
            {form.alcance === 'municipios' && (
                <div className="mt-3">
                    <label htmlFor="campo-territorio" className={etiquetaCls}>Busca y marca los municipios</label>
                    <input id="campo-territorio" type="search" value={filtro} onChange={e => setFiltro(e.target.value)} autoComplete="off"
                        aria-describedby={describe} className={`${campoCls} mt-1`} placeholder="Nombre del municipio" />
                    <p className="mt-1 text-[13px] text-ink-soft tabular-nums" aria-live="polite">{form.territorioIds.length === 1 ? '1 municipio elegido' : `${form.territorioIds.length} municipios elegidos`}</p>
                    <ul className="mt-1 max-h-72 overflow-y-auto rounded-md border border-line bg-panel divide-y divide-line">
                        {visibles.map(m => (
                            <li key={m.id}>
                                <label className="flex items-center gap-3 min-h-11 sm:min-h-9 px-3 text-sm cursor-pointer hover:bg-bg">
                                    <input type="checkbox" checked={elegidos.has(m.id)} onChange={() => alternar(m.id)} className="w-4 h-4 accent-primary" />
                                    {m.nombre}
                                </label>
                            </li>
                        ))}
                        {visibles.length === 0 && <li className="px-3 py-3 text-sm text-ink-soft">Ningún municipio se llama así.</li>}
                    </ul>
                </div>
            )}
            <ErrorCampo id="error-territorio">{error}</ErrorCampo>
        </div>
    );
}

export default function CampanaFormulario({ campana: campanaRaw = null, opciones: opcionesRaw = {}, puedeBuscarCandidatos = true, createBlocked = null }) {
    const { props } = usePage();
    const campana = useMemo(() => (campanaRaw ? normalizarCampana(campanaRaw) : null), [campanaRaw]);
    const opciones = useMemo(() => normalizarOpciones(opcionesRaw), [opcionesRaw]);
    const editando = !!campana?.id;
    const crearBloqueado = editando ? null : createBlocked ?? props.grupoVista?.createBlocked ?? null;
    const [form, setForm] = useState(() => formularioInicial(campana, opciones));
    const [errores, setErrores] = useState({});
    const [guardando, setGuardando] = useState(false);
    const [puedeBuscar, setPuedeBuscar] = useState(!!puedeBuscarCandidatos);
    const [factoresAbiertos, setFactoresAbiertos] = useState(false);
    // A group without electoral results gets no suggestions: the party is not "used to suggest" anything.
    const sinResultados = !puedeBuscarCandidatos || props.auth?.user?.seesElectoralResults === false;
    const enviando = useRef(false);

    const set = (cambios) => setForm(f => ({ ...f, ...cambios }));
    const sinPermisoBuscar = useMemo(() => () => setPuedeBuscar(false), []);

    function elegirCargo(clave) {
        const antes = cargoDe(form.cargo)?.territorio;
        const ahora = cargoDe(clave)?.territorio;
        set({ cargo: clave, ...(antes !== ahora ? { territorioIds: [], alcance: 'departamento' } : {}) });
        setErrores(e => ({ ...e, cargo: undefined, territorio: undefined }));
    }

    function enfocarPrimerError(e) {
        const primero = ORDEN_CAMPOS.find(k => e[k]);
        if (primero === 'factor_votos' || primero === 'factor_registro' || primero === 'votos_por_lider') setFactoresAbiertos(true);
        if (primero) setTimeout(() => document.getElementById(`campo-${primero}`)?.focus(), 0);
    }

    async function guardar(ev) {
        ev.preventDefault();
        if (enviando.current) return;
        const e = validarFormulario(form, opciones);
        setErrores(e);
        if (Object.keys(e).length) { enfocarPrimerError(e); return; }
        enviando.current = true;
        setGuardando(true);
        const cuerpo = cuerpoFormulario(form);
        const res = editando ? await editarCampana(campana.id, cuerpo) : await crearCampana(cuerpo);
        if (res.ok && res.id) {
            showGlobalToast(editando ? 'Campaña guardada' : 'Campaña creada');
            router.visit(`${BASE}/${res.id}`);
            return;
        }
        enviando.current = false;
        setGuardando(false);
        const delServidor = res.status === 422 ? erroresServidor(res.body) : { general: mensajeDe(res, 'No pudimos guardar la campaña. Intenta de nuevo.') };
        setErrores(delServidor);
        enfocarPrimerError(delServidor);
    }

    const titulo = editando ? 'Editar campaña' : 'Nueva campaña';
    const describir = (k) => (errores[k] ? `error-${k}` : undefined);

    return (
        <AppLayout title={titulo}>
            <Head title={titulo} />
            <div className="max-w-3xl mx-auto px-4 lg:px-6 pt-4 lg:pt-6 pb-10 text-[15px] text-ink">
                <h1 className={tituloPaginaCls}>{titulo}</h1>
                {crearBloqueado && (
                    <div className="mt-3"><AvisoError>{crearBloqueado}</AvisoError></div>
                )}

                <form onSubmit={guardar} noValidate className={`mt-4 ${tarjetaCls} p-4 sm:p-6 divide-y divide-line [&>section]:pb-4 [&>section:last-of-type]:pb-0`}>
                    <Paso numero={1} titulo="Nombre de la campaña" htmlFor="campo-nombre">
                        <input id="campo-nombre" type="text" value={form.nombre} maxLength={120} onChange={e => set({ nombre: e.target.value })}
                            aria-invalid={!!errores.nombre || undefined} aria-describedby={describir('nombre')} className={campoCls} placeholder="Por ejemplo: Alcaldía Girón 2027" />
                        <ErrorCampo id="error-nombre">{errores.nombre}</ErrorCampo>
                    </Paso>

                    <Paso numero={2} titulo="¿A qué cargo?" id="paso-cargo">
                        <div role="group" aria-labelledby="paso-cargo-titulo" aria-describedby={describir('cargo')} className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            {opciones.cargos.map((c, i) => (
                                <button key={c.clave} id={i === 0 ? 'campo-cargo' : undefined} type="button" aria-pressed={form.cargo === c.clave} onClick={() => elegirCargo(c.clave)}
                                    className={`${tactilCls} px-3 py-1.5 rounded-md border text-center text-sm leading-tight transition-colors duration-150 ${focoCls} ${form.cargo === c.clave ? 'bg-primary border-primary text-white font-semibold' : 'bg-panel border-line-strong text-ink hover:border-primary'}`}>
                                    {c.etiqueta}
                                </button>
                            ))}
                        </div>
                        <ErrorCampo id="error-cargo">{errores.cargo}</ErrorCampo>
                    </Paso>

                    <Paso numero={3} titulo="¿Cuándo es la elección?" id="paso-fecha">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label htmlFor="campo-anio" className={etiquetaCls}>Año</label>
                                <input id="campo-anio" type="number" inputMode="numeric" min={opciones.anio.min} max={opciones.anio.max} value={form.anio} onChange={e => set({ anio: e.target.value })}
                                    aria-invalid={!!errores.anio || undefined} aria-describedby={describir('anio')} className={`${campoCls} mt-1 tabular-nums`} />
                                <ErrorCampo id="error-anio">{errores.anio}</ErrorCampo>
                            </div>
                            <div>
                                <label htmlFor="campo-fecha" className={etiquetaCls}>Fecha (si ya la sabes)</label>
                                <input id="campo-fecha" type="date" value={form.fecha} onChange={e => set({ fecha: e.target.value })}
                                    aria-invalid={!!errores.fecha || undefined} aria-describedby={describir('fecha')} className={`${campoCls} mt-1`} />
                                <ErrorCampo id="error-fecha">{errores.fecha}</ErrorCampo>
                            </div>
                        </div>
                    </Paso>

                    <Paso numero={4} titulo="Candidato" id="paso-candidato">
                        <BuscadorCandidato form={form} set={set} error={errores.candidato} puedeBuscar={puedeBuscar} onSinPermiso={sinPermisoBuscar} />
                    </Paso>

                    <Paso numero={5} titulo="Partido o movimiento" htmlFor="campo-partido" ayuda={sinResultados ? undefined : 'Se usa para sugerir metas.'}>
                        <select id="campo-partido" value={form.partidoId} onChange={e => set({ partidoId: e.target.value })}
                            aria-invalid={!!errores.partido || undefined} aria-describedby={describir('partido')} className={campoCls}>
                            <option value="">Sin partido</option>
                            {opciones.partidos.map(p => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                        </select>
                        <ErrorCampo id="error-partido">{errores.partido}</ErrorCampo>
                    </Paso>

                    <Paso numero={6} titulo="¿Dónde?" id="paso-territorio">
                        <Territorio form={form} set={set} opciones={opciones} error={errores.territorio} />
                    </Paso>

                    <section className="pt-4">
                        <button type="button" aria-expanded={factoresAbiertos} aria-controls="factores" onClick={() => setFactoresAbiertos(a => !a)}
                            className={`w-full ${tactilCls} flex items-center justify-between gap-3 text-left font-heading text-base font-bold text-ink rounded-md ${focoCls}`}>
                            Cómo sugerimos las metas
                            <IconoChevron abierto={factoresAbiertos} className="w-5 h-5" />
                        </button>
                        <div id="factores" hidden={!factoresAbiertos} className="mt-2 space-y-4">
                            <p className="text-sm text-ink-soft">Puedes dejarlo así. Solo cambia la sugerencia; las metas las decides tú.</p>
                            {Object.entries(opciones.factores).map(([clave, def]) => (
                                <div key={clave}>
                                    <label htmlFor={`campo-${clave}`} className={etiquetaCls}>{def.etiqueta}</label>
                                    <p className={ayudaCls}>{def.ayuda}</p>
                                    <input id={`campo-${clave}`} type="text" inputMode={def.decimal ? 'decimal' : 'numeric'} value={form[clave]} onChange={e => set({ [clave]: e.target.value })}
                                        aria-invalid={!!errores[clave] || undefined} aria-describedby={describir(clave)} className={`${campoCls} mt-1 sm:max-w-40 tabular-nums`} />
                                    <ErrorCampo id={`error-${clave}`}>{errores[clave]}</ErrorCampo>
                                </div>
                            ))}
                        </div>
                    </section>

                    <div className="pt-4 space-y-3">
                        {errores.general && <AvisoError>{errores.general}</AvisoError>}
                        <div className="flex flex-col sm:flex-row gap-2">
                            <Boton type="submit" disabled={guardando || !!crearBloqueado} aria-busy={guardando || undefined}>
                                {guardando ? <><Girando className="w-4 h-4" />Guardando…</> : 'Guardar campaña'}
                            </Boton>
                            <LinkBoton variante="secundario" href={editando ? `${BASE}/${campana.id}` : BASE}>Cancelar</LinkBoton>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
