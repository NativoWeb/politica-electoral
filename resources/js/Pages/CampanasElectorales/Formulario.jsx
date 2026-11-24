import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { AvisoError, Boton, Chip, Girando, IconoBuscar, IconoChevron, focoCls } from '@/Components/Reportes/ui';
import { ErrorCampo, LinkBoton, ayudaCls, campoCls, etiquetaCls } from '@/Components/CampanasElectorales/ui';
import { showGlobalToast } from '@/lib/api';
import {
    BASE, CANDIDATO_MIN_LETRAS, buscarCandidatos, cargoDe, crearCampana, cuerpoFormulario, editarCampana,
    erroresServidor, formularioInicial, mensajeDe, normalizarCampana, normalizarOpciones, validarFormulario,
} from '@/lib/campanas';

const ORDEN_CAMPOS = ['nombre', 'cargo', 'anio', 'fecha', 'candidato', 'partido', 'territorio', 'factor_votos', 'factor_registro', 'votos_por_lider'];

function Paso({ numero, titulo, htmlFor, children, ayuda, id }) {
    const Titulo = htmlFor ? 'label' : 'h2';
    return (
        <section aria-labelledby={id ? `${id}-titulo` : undefined} className="pt-7 first:pt-0">
            <div className="flex items-center gap-3">
                <span aria-hidden="true" className="w-9 h-9 flex-shrink-0 rounded-full bg-primary text-white flex items-center justify-center font-heading text-lg font-bold">{numero}</span>
                <Titulo id={id ? `${id}-titulo` : undefined} htmlFor={htmlFor} className="font-heading text-2xl font-bold text-ink text-balance">{titulo}</Titulo>
            </div>
            {ayuda && <p className="mt-2 text-lg text-ink-soft">{ayuda}</p>}
            <div className="mt-4">{children}</div>
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
            <div className="flex flex-col sm:flex-row sm:items-center gap-3 p-4 rounded-md border-2 border-primary bg-panel">
                <p className="flex-1 text-lg"><span className="text-ink-soft">Candidato elegido:</span> <strong className="font-semibold">{form.candidatoNombre}</strong></p>
                <Boton variante="secundario" onClick={() => set({ candidatoId: null, candidatoNombre: '' })}>Cambiar</Boton>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {puedeBuscar && (
                <div>
                    <label htmlFor={`${id}-q`} className={etiquetaCls}>Busca al candidato en la plataforma</label>
                    <div className="relative mt-2">
                        <IconoBuscar className="w-6 h-6 absolute left-4 top-1/2 -translate-y-1/2 text-ink-soft pointer-events-none" />
                        <input id={`${id}-q`} type="search" value={q} onChange={e => setQ(e.target.value)} autoComplete="off" placeholder="Escribe al menos 2 letras" className={`${campoCls} pl-13`} />
                    </div>
                    <div aria-live="polite" className="mt-2">
                        {estado.cargando && <p className="flex items-center gap-2 text-lg text-ink-soft"><Girando className="w-5 h-5" />Buscando…</p>}
                        {estado.error && <p className="text-lg text-danger font-semibold">{estado.error}</p>}
                        {estado.items?.length === 0 && <p className="text-lg text-ink-soft">No encontramos a nadie con ese nombre. Puedes escribirlo abajo.</p>}
                    </div>
                    {estado.items?.length > 0 && (
                        <ul className="mt-2 rounded-md border-2 border-line bg-panel divide-y-2 divide-line" aria-label="Candidatos encontrados">
                            {estado.items.map(c => (
                                <li key={c.id}>
                                    <button type="button" onClick={() => { set({ candidatoId: c.id, candidatoNombre: c.nombre }); setQ(''); }}
                                        className={`w-full min-h-14 px-4 py-2 text-left text-lg hover:bg-bg ${focoCls}`}>
                                        <span className="font-semibold text-ink">{c.nombre}</span>
                                        {c.detalle && <span className="block text-base text-ink-soft">{c.detalle}</span>}
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
                    aria-invalid={!!error || undefined} aria-describedby={error ? 'error-candidato' : undefined} className={`${campoCls} mt-2`} />
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

    if (!cargo) return <p className="text-lg text-ink-soft">Primero elige el cargo.</p>;
    const depto = opciones.departamento?.nombre ?? 'Santander';

    if (cargo.territorio === 'departamento') {
        return <p className="p-4 rounded-md bg-panel border-2 border-line text-lg">Todo el departamento de {depto}.</p>;
    }

    if (cargo.territorio === 'municipio') {
        return (
            <div>
                <label htmlFor="campo-territorio" className={etiquetaCls}>Municipio</label>
                <select id="campo-territorio" value={form.territorioIds[0] ?? ''} onChange={e => set({ territorioIds: e.target.value ? [e.target.value] : [] })}
                    aria-invalid={!!error || undefined} aria-describedby={describe} className={`${campoCls} mt-2`}>
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
                    aria-invalid={!!error || undefined} aria-describedby={describe} className={`${campoCls} mt-2`}>
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
            <div role="group" aria-label="Dónde" className="flex flex-wrap gap-3">
                <Chip activo={form.alcance === 'departamento'} onClick={() => set({ alcance: 'departamento', territorioIds: [] })}>Todo {depto}</Chip>
                <Chip activo={form.alcance === 'municipios'} onClick={() => set({ alcance: 'municipios' })}>Algunos municipios</Chip>
            </div>
            {form.alcance === 'municipios' && (
                <div className="mt-4">
                    <label htmlFor="campo-territorio" className={etiquetaCls}>Busca y marca los municipios</label>
                    <input id="campo-territorio" type="search" value={filtro} onChange={e => setFiltro(e.target.value)} autoComplete="off"
                        aria-describedby={describe} className={`${campoCls} mt-2`} placeholder="Nombre del municipio" />
                    <p className="mt-2 text-lg text-ink-soft tabular-nums" aria-live="polite">{form.territorioIds.length === 1 ? '1 municipio elegido' : `${form.territorioIds.length} municipios elegidos`}</p>
                    <ul className="mt-2 max-h-80 overflow-y-auto rounded-md border-2 border-line bg-panel divide-y-2 divide-line">
                        {visibles.map(m => (
                            <li key={m.id}>
                                <label className="flex items-center gap-4 min-h-14 px-4 text-lg cursor-pointer hover:bg-bg">
                                    <input type="checkbox" checked={elegidos.has(m.id)} onChange={() => alternar(m.id)} className="w-6 h-6 accent-primary" />
                                    {m.nombre}
                                </label>
                            </li>
                        ))}
                        {visibles.length === 0 && <li className="px-4 py-4 text-lg text-ink-soft">Ningún municipio se llama así.</li>}
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
            <div className="max-w-2xl mx-auto px-4 lg:px-8 pt-6 lg:pt-8 pb-16 text-lg text-ink">
                <h1 className="font-heading text-4xl font-bold text-primary">{titulo}</h1>
                {crearBloqueado && (
                    <div className="mt-5"><AvisoError>{crearBloqueado}</AvisoError></div>
                )}

                <form onSubmit={guardar} noValidate className="mt-8 bg-panel border-2 border-line rounded-lg p-5 sm:p-8 divide-y-2 divide-line [&>section]:pb-7 [&>section:last-of-type]:pb-0">
                    <Paso numero={1} titulo="Nombre de la campaña" htmlFor="campo-nombre">
                        <input id="campo-nombre" type="text" value={form.nombre} maxLength={120} onChange={e => set({ nombre: e.target.value })}
                            aria-invalid={!!errores.nombre || undefined} aria-describedby={describir('nombre')} className={campoCls} placeholder="Por ejemplo: Alcaldía Girón 2027" />
                        <ErrorCampo id="error-nombre">{errores.nombre}</ErrorCampo>
                    </Paso>

                    <Paso numero={2} titulo="¿A qué cargo?" id="paso-cargo">
                        <div role="group" aria-labelledby="paso-cargo-titulo" aria-describedby={describir('cargo')} className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {opciones.cargos.map((c, i) => (
                                <button key={c.clave} id={i === 0 ? 'campo-cargo' : undefined} type="button" aria-pressed={form.cargo === c.clave} onClick={() => elegirCargo(c.clave)}
                                    className={`min-h-16 px-5 py-3 rounded-md border-2 text-left text-lg transition-colors duration-150 ${focoCls} ${form.cargo === c.clave ? 'bg-primary border-primary text-white font-semibold' : 'bg-panel border-line-strong text-ink hover:border-primary'}`}>
                                    {c.etiqueta}
                                </button>
                            ))}
                        </div>
                        <ErrorCampo id="error-cargo">{errores.cargo}</ErrorCampo>
                    </Paso>

                    <Paso numero={3} titulo="¿Cuándo es la elección?" id="paso-fecha">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label htmlFor="campo-anio" className={etiquetaCls}>Año</label>
                                <input id="campo-anio" type="number" inputMode="numeric" min={opciones.anio.min} max={opciones.anio.max} value={form.anio} onChange={e => set({ anio: e.target.value })}
                                    aria-invalid={!!errores.anio || undefined} aria-describedby={describir('anio')} className={`${campoCls} mt-2 tabular-nums`} />
                                <ErrorCampo id="error-anio">{errores.anio}</ErrorCampo>
                            </div>
                            <div>
                                <label htmlFor="campo-fecha" className={etiquetaCls}>Fecha (si ya la sabes)</label>
                                <input id="campo-fecha" type="date" value={form.fecha} onChange={e => set({ fecha: e.target.value })}
                                    aria-invalid={!!errores.fecha || undefined} aria-describedby={describir('fecha')} className={`${campoCls} mt-2`} />
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

                    <section className="pt-7">
                        <button type="button" aria-expanded={factoresAbiertos} aria-controls="factores" onClick={() => setFactoresAbiertos(a => !a)}
                            className={`w-full min-h-14 flex items-center justify-between gap-3 text-left font-heading text-xl font-bold text-ink rounded-md ${focoCls}`}>
                            Cómo sugerimos las metas
                            <IconoChevron abierto={factoresAbiertos} />
                        </button>
                        <div id="factores" hidden={!factoresAbiertos} className="mt-4 space-y-5">
                            <p className="text-lg text-ink-soft">Puedes dejarlo así. Solo cambia la sugerencia; las metas las decides tú.</p>
                            {Object.entries(opciones.factores).map(([clave, def]) => (
                                <div key={clave}>
                                    <label htmlFor={`campo-${clave}`} className={etiquetaCls}>{def.etiqueta}</label>
                                    <p className={ayudaCls}>{def.ayuda}</p>
                                    <input id={`campo-${clave}`} type="text" inputMode={def.decimal ? 'decimal' : 'numeric'} value={form[clave]} onChange={e => set({ [clave]: e.target.value })}
                                        aria-invalid={!!errores[clave] || undefined} aria-describedby={describir(clave)} className={`${campoCls} mt-2 sm:max-w-48 tabular-nums`} />
                                    <ErrorCampo id={`error-${clave}`}>{errores[clave]}</ErrorCampo>
                                </div>
                            ))}
                        </div>
                    </section>

                    <div className="pt-7 space-y-4">
                        {errores.general && <AvisoError>{errores.general}</AvisoError>}
                        <div className="flex flex-col sm:flex-row gap-3">
                            <Boton type="submit" disabled={guardando || !!crearBloqueado} aria-busy={guardando || undefined}>
                                {guardando ? <><Girando className="w-5 h-5" />Guardando…</> : 'Guardar campaña'}
                            </Boton>
                            <LinkBoton variante="secundario" href={editando ? `${BASE}/${campana.id}` : BASE}>Cancelar</LinkBoton>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
