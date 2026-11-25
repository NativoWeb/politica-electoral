import { apiFetch, getCsrfToken } from '@/lib/api';

// Campañas electorales: the one place that knows the backend contract (docs/campanas-electorales.md). Pages and
// components only see the normalized shapes built here; a change of field names on the server is fixed here.

export const BASE = '/campanas-electorales';
export const META_MAXIMA = 10000000;
export const LOTE_MAXIMO = 300;
export const EXCEL_MAX_BYTES = 2 * 1024 * 1024;
export const ANIO_POR_DEFECTO = 2027;
export const ANIO_MIN = 2024;
export const ANIO_MAX = 2040;

export const TEXTO_SIN_RESULTADOS = 'Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano.';
export const TEXTO_SALIR = 'Tienes cambios sin guardar. ¿Salir sin guardar?';
export const TEXTO_NUMERO = 'Escribe un número entero, 0 o más';
export const TEXTO_SESION = 'Tu sesión venció. Recarga la página; tus cambios siguen en pantalla.';
export const TEXTO_PESO = 'El archivo pesa más de 2 MB.';

// territorio: what the form asks for each cargo.
//  municipio = exactly one municipio · comuna = one comuna or corregimiento · departamento = all Santander ·
//  departamento_o_municipios = all Santander or a list of municipios.
export const CARGOS = [
    { clave: 'gobernacion', etiqueta: 'Gobernación', territorio: 'departamento' },
    { clave: 'asamblea', etiqueta: 'Asamblea', territorio: 'departamento' },
    { clave: 'alcaldia', etiqueta: 'Alcaldía', territorio: 'municipio' },
    { clave: 'concejo', etiqueta: 'Concejo', territorio: 'municipio' },
    { clave: 'senado', etiqueta: 'Senado', territorio: 'departamento_o_municipios' },
    { clave: 'camara', etiqueta: 'Cámara', territorio: 'departamento_o_municipios' },
    { clave: 'jal', etiqueta: 'JAL (Junta Administradora Local)', territorio: 'comuna' },
];

export const FACTORES = {
    factor_votos: { etiqueta: 'Votos que queremos sacar', ayuda: 'Por cuánto multiplicamos los votos de la última elección. 1,10 es un 10 % más.', min: 0.5, max: 3, paso: 0.05, defecto: 1.1, decimal: true },
    factor_registro: { etiqueta: 'Votantes que hay que registrar', ayuda: 'Por cuánto multiplicamos la meta de votos. 1,00 es una ficha por cada voto.', min: 0.1, max: 3, paso: 0.05, defecto: 1, decimal: true },
    votos_por_lider: { etiqueta: 'Votos que consigue cada líder', ayuda: 'Con esto sugerimos cuántos líderes hacen falta.', min: 5, max: 1000, paso: 1, defecto: 50, decimal: false },
};

export const CAMPOS_META = [
    { clave: 'votos', etiqueta: 'Votos meta', corta: 'Votos' },
    { clave: 'lideres', etiqueta: 'Líderes meta', corta: 'Líderes' },
    { clave: 'votantes', etiqueta: 'Votantes meta', corta: 'Votantes' },
];

export const cargoDe = (clave) => CARGOS.find(c => c.clave === clave) ?? null;

/* ── Formatting ── */

export const numero = (n) => (n === null || n === undefined || n === '' ? '' : Number(n).toLocaleString('es-CO'));
export const decimal = (n) => Number(n).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const plural = (n, uno, varios) => `${numero(n)} ${Number(n) === 1 ? uno : varios}`;

export function textoFecha(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('es-CO', { day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' });
}

/* ── The «Es líder» box (module on) ── */

// The server sends es_lider in a ficha only with the module on: the box shows then, and only then.
export const muestraEsLider = (ficha) => !!ficha && Object.prototype.hasOwnProperty.call(ficha, 'es_lider');

// A new ficha has no es_lider to look at: the shared props say whether the module is on (features.esLider when the
// server shares it; until then features.campanas, which also needs a campaigns permission).
export function moduloLideresActivo(props) {
    const f = props?.features ?? {};
    return !!(f.esLider ?? f.campanas);
}
/* ── Normalizing what the server sends ── */

const num = (v) => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? null : Number(v));
const texto = (v) => (v === null || v === undefined ? '' : String(v));
// «Solo lectura: …» from the server, without the prefix the band already shows.
const sinPrefijo = (t) => {
    const resto = texto(t).replace(/^solo lectura[:.]?\s*/i, '');
    return resto.charAt(0).toUpperCase() + resto.slice(1);
};

// The campana object (Lista card, Detalle header) and the edit form's campana.
export function normalizarCampana(raw = {}) {
    const r = raw ?? {};
    const cargo = r.cargo ?? '';
    const territorioIds = r.territorio_ids ?? (r.territorio?.unidades ?? []).filter(u => u.tipo !== 'department').map(u => u.id);
    const total = num(r.metas?.total) ?? 0;
    return {
        id: r.id,
        nombre: texto(r.name),
        cargo,
        cargoEtiqueta: texto(r.cargo_label) || cargoDe(cargo)?.etiqueta || cargo,
        anio: num(r.election_year),
        fecha: r.election_date ?? null,
        candidatoId: r.candidate_person_id ?? r.candidato?.person_id ?? null,
        candidatoNombre: texto(r.candidato_nombre ?? r.candidato?.nombre ?? r.candidate_name),
        candidatoTexto: texto(r.candidate_name),
        partidoId: r.political_organization_id ?? r.partido?.id ?? null,
        partidoNombre: texto(r.partido?.nombre),
        alcance: r.scope_level ?? null,
        territorioIds,
        territorioResumen: texto(r.territorio?.resumen),
        territorios: total,
        metasCargadas: num(r.metas?.cargadas) ?? 0,
        archivada: r.status === 'archived',
        grupoNombre: texto(r.grupo?.nombre),
        grupoPropio: r.grupo?.propio !== false,
        soloLectura: !!r.solo_lectura,
        factores: {
            factor_votos: num(r.factores?.factor_votos ?? r.factor_votos) ?? FACTORES.factor_votos.defecto,
            factor_registro: num(r.factores?.factor_registro ?? r.factor_registro) ?? FACTORES.factor_registro.defecto,
            votos_por_lider: num(r.factores?.votos_por_lider ?? r.votos_por_lider) ?? FACTORES.votos_por_lider.defecto,
        },
    };
}

const meta = (m) => ({
    votos: num(m?.votos_meta),
    lideres: num(m?.lideres_meta),
    votantes: num(m?.votantes_meta),
});

function normalizarSugerido(s) {
    if (!s || typeof s !== 'object') return null;
    const votos = num(s.votos);
    if (votos === null) return null;
    return { votos, texto: texto(s.texto) };
}

function filaPlana(r) {
    return {
        id: r.id,
        padreId: r.padre_id ?? null,
        nombre: texto(r.nombre),
        tipo: r.tipo ?? 'municipality',
        codigo: texto(r.codigo),
        meta: meta(r.meta),
        editable: r.editable !== false,
        sugerido: normalizarSugerido(r.sugerencia),
        sinSugerencia: texto(r.sin_sugerencia),
        avance: { votantes: num(r.avance?.votantes) ?? 0, lideres: num(r.avance?.lideres) ?? 0 },
        sinComuna: r.sin_comuna ? { votantes: num(r.sin_comuna.votantes) ?? 0, lideres: num(r.sin_comuna.lideres) ?? 0 } : null,
        // Votantes whose comuna came from their polling station (docs/geografia.md): "incluye N por puesto de votación".
        // null = 1 to 4, hidden by the server (k = 5): «incluye menos de 5 por puesto de votación».
        porPuesto: r.avance_por_origen && Object.prototype.hasOwnProperty.call(r.avance_por_origen, 'puesto') ? num(r.avance_por_origen.puesto) : 0,
        hijos: [],
    };
}

// The server sends one flat list (comunas carry padre_id); the grid nests them under their municipio. A comuna whose
// municipio is not in the list (a JAL campaign) stays at the top.
export function normalizarFilas(lista = []) {
    const filas = (Array.isArray(lista) ? lista : []).map(filaPlana);
    const porId = Object.fromEntries(filas.map(f => [f.id, f]));
    const top = [];
    filas.forEach(f => {
        const padre = f.padreId && porId[f.padreId];
        if (padre) padre.hijos.push(f);
        else top.push(f);
    });
    return top;
}

export function normalizarDetalle(raw = {}) {
    const d = raw ?? {};
    const campana = normalizarCampana(d.campana);
    const can = d.can ?? {};
    const disponible = d.sugerencias?.disponible !== false;
    return {
        campana,
        filas: normalizarFilas(d.filas),
        can: { editar: !!can.edit, archivar: !!can.archive, reactivar: !!can.reactivate, plantilla: !!can.plantilla },
        soloLectura: !can.edit,
        motivoSoloLectura: sinPrefijo(d.soloLectura?.texto),
        veResultados: disponible,
        avisoSugerencias: disponible ? '' : texto(d.sugerencias?.aviso) || TEXTO_SIN_RESULTADOS,
        totalAvance: { votantes: num(d.totales?.avance?.votantes), lideres: num(d.totales?.avance?.lideres) },
        totalSugerido: normalizarSugerido(d.totales?.sugerencia),
    };
}

// The Lista props (also the data of its JSON).
export function normalizarLista(raw = {}) {
    const d = raw ?? {};
    return {
        campanas: (Array.isArray(d.campanas) ? d.campanas : []).map(normalizarCampana),
        puedeGestionar: !!d.can?.manage,
        crearBloqueado: d.createBlocked ?? null,
        truncada: !!d.truncated,
    };
}

export function normalizarOpciones(raw = {}) {
    const o = raw ?? {};
    const factores = Object.fromEntries(Object.entries(FACTORES).map(([k, def]) => [k, {
        ...def,
        min: num(o.factores?.[k]?.min) ?? def.min,
        max: num(o.factores?.[k]?.max) ?? def.max,
        defecto: num(o.factores?.[k]?.defecto) ?? def.defecto,
    }]));
    return {
        cargos: Array.isArray(o.cargos) && o.cargos.length
            ? o.cargos.map(c => ({ ...(cargoDe(c.value) ?? { territorio: 'departamento' }), clave: c.value, etiqueta: cargoDe(c.value)?.etiqueta ?? texto(c.label) }))
            : CARGOS,
        anio: { min: num(o.anio?.min) ?? ANIO_MIN, max: num(o.anio?.max) ?? ANIO_MAX, defecto: num(o.anio?.defecto) ?? ANIO_POR_DEFECTO },
        partidos: (o.partidos ?? []).map(p => ({ id: p.id, nombre: texto(p.nombre) })),
        municipios: (o.municipios ?? []).map(m => ({ id: m.id, nombre: texto(m.nombre), provincia: texto(m.provincia) })),
        comunas: (o.comunas ?? []).map(c => ({ id: c.id, nombre: texto(c.nombre), tipo: c.tipo ?? 'comuna', municipioId: c.municipio_id ?? null, municipioNombre: texto(c.municipio) })),
        departamento: o.departamento ? { id: o.departamento.id, nombre: texto(o.departamento.nombre) } : null,
        factores,
    };
}

/* ── Goals: the editable grid ── */

// The draft of every goal cell: {[unitId]: {votos: '12', lideres: '', votantes: ''}} as typed.
export function borradorDe(filas) {
    const out = {};
    const poner = (f) => {
        out[f.id] = Object.fromEntries(CAMPOS_META.map(({ clave }) => [clave, f.meta[clave] === null ? '' : numero(f.meta[clave])]));
        f.hijos.forEach(poner);
    };
    filas.forEach(poner);
    return out;
}

// '' is "no goal"; otherwise a whole number from 0 to 10.000.000. Dots as thousands separators are accepted.
export function validarMeta(valor) {
    const t = String(valor ?? '').trim();
    if (t === '') return { ok: true, valor: null };
    // Dots only as thousands separators («1.200»): «1.5» is not 15.
    if (!/^\d+$/.test(t) && !/^\d{1,3}(\.\d{3})+$/.test(t)) return { ok: false, error: TEXTO_NUMERO };
    const limpio = t.replace(/\./g, '');
    const n = Number(limpio);
    if (n > META_MAXIMA) return { ok: false, error: `Escribe un número hasta ${numero(META_MAXIMA)}` };
    return { ok: true, valor: n };
}

const tieneAlguna = (celdas) => !!celdas && CAMPOS_META.some(({ clave }) => String(celdas[clave] ?? '').trim() !== '');

// A municipio carries its own goal OR goals in its comunas, never both.
export function modoMunicipio(fila, borrador) {
    if (!fila.hijos.length) return 'propia';
    if (fila.hijos.some(h => tieneAlguna(borrador[h.id]))) return 'comunas';
    if (tieneAlguna(borrador[fila.id])) return 'propia';
    return 'libre';
}

// The suggestion of a row (only when the server sends one). Votes come from the last comparable election; leaders and
// voters follow from the typed votes if any, else from the suggested votes (the server's rule, live while typing).
// votos is null when the person already typed votes: «Usar sugerido» then fills only leaders and voters.
export function sugerenciaDe(fila, celdas, factores) {
    if (!fila.sugerido) return null;
    const tecleados = validarMeta(celdas?.votos);
    const propios = tecleados.ok && tecleados.valor !== null ? tecleados.valor : null;
    const base = propios ?? fila.sugerido.votos;
    return {
        votos: propios === null ? fila.sugerido.votos : null,
        base,
        conVotosPropios: propios !== null,
        lideres: Math.ceil(base / (Number(factores?.votos_por_lider) || FACTORES.votos_por_lider.defecto)),
        votantes: Math.round(base * (Number(factores?.factor_registro) || FACTORES.factor_registro.defecto)),
        texto: fila.sugerido.texto,
    };
}

// «Usar sugerido» fills exactly what the suggestion line shows.
export function usarSugerido(celdas, sugerencia) {
    if (!sugerencia) return celdas;
    return {
        votos: sugerencia.votos === null ? celdas?.votos ?? '' : numero(sugerencia.votos),
        lideres: numero(sugerencia.lideres),
        votantes: numero(sugerencia.votantes),
    };
}

// The value of one cell of a municipio with comunas: the sum of its comunas (read-only) or its own.
export function valorMunicipio(fila, borrador, clave) {
    if (modoMunicipio(fila, borrador) !== 'comunas') return validarMeta(borrador[fila.id]?.[clave]).valor;
    return fila.hijos.reduce((s, h) => s + (validarMeta(borrador[h.id]?.[clave]).valor ?? 0), 0);
}

export function totales(filas, borrador) {
    const t = { votos: 0, lideres: 0, votantes: 0, avanceVotantes: 0, avanceLideres: 0 };
    filas.forEach(f => {
        CAMPOS_META.forEach(({ clave }) => { t[clave] += valorMunicipio(f, borrador, clave) ?? 0; });
        t.avanceVotantes += f.avance.votantes;
        t.avanceLideres += f.avance.lideres;
    });
    return t;
}

// Every cell with a problem: {[unitId]: {votos: 'Escribe…'}}.
export function erroresDe(borrador) {
    const out = {};
    Object.entries(borrador).forEach(([id, celdas]) => {
        CAMPOS_META.forEach(({ clave }) => {
            const v = validarMeta(celdas[clave]);
            if (!v.ok) (out[id] ??= {})[clave] = v.error;
        });
    });
    return out;
}

// What changed against what the server has, ready for PUT /{id}/metas.
export function cambiosDe(filas, borrador) {
    const original = borradorDe(filas);
    return Object.entries(borrador)
        .filter(([id, celdas]) => CAMPOS_META.some(({ clave }) => validarMeta(celdas[clave]).valor !== validarMeta(original[id]?.[clave]).valor))
        .map(([id, celdas]) => ({
            geographic_unit_id: id,
            votos_meta: validarMeta(celdas.votos).valor,
            lideres_meta: validarMeta(celdas.lideres).valor,
            votantes_meta: validarMeta(celdas.votantes).valor,
        }));
}

export function contarComunas(fila) {
    const comunas = fila.hijos.filter(h => h.tipo !== 'corregimiento').length;
    const corregimientos = fila.hijos.length - comunas;
    return corregimientos ? `${comunas} + ${plural(corregimientos, 'corregimiento', 'corregimientos')}` : `${comunas}`;
}

/* ── The form ── */

export function formularioInicial(campana = null, opciones = null) {
    const c = campana;
    const f = opciones?.factores ?? FACTORES;
    const cargo = cargoDe(c?.cargo);
    return {
        nombre: c?.nombre ?? '',
        cargo: c?.cargo ?? '',
        anio: String(c?.anio ?? opciones?.anio?.defecto ?? ANIO_POR_DEFECTO),
        fecha: c?.fecha ? String(c.fecha).slice(0, 10) : '',
        candidatoId: c?.candidatoId ?? null,
        candidatoNombre: c?.candidatoNombre ?? '',
        partidoId: c?.partidoId ?? '',
        alcance: c?.alcance === 'municipality' && cargo?.territorio === 'departamento_o_municipios' ? 'municipios' : 'departamento',
        territorioIds: cargo?.territorio === 'departamento' ? [] : c?.territorioIds ?? [],
        factor_votos: decimal(c?.factores?.factor_votos ?? f.factor_votos.defecto),
        factor_registro: decimal(c?.factores?.factor_registro ?? f.factor_registro.defecto),
        votos_por_lider: String(c?.factores?.votos_por_lider ?? f.votos_por_lider.defecto),
    };
}

const aNumero = (t) => {
    const s = String(t ?? '').trim().replace(',', '.');
    return s === '' ? NaN : Number(s);
};

export function validarFormulario(f, opciones = null) {
    const e = {};
    const lim = opciones?.anio ?? { min: ANIO_MIN, max: ANIO_MAX };
    if (!f.nombre.trim()) e.nombre = 'Escribe el nombre';
    else if (f.nombre.trim().length > 120) e.nombre = 'El nombre es muy largo (máximo 120 letras)';
    const cargo = cargoDe(f.cargo);
    if (!cargo) e.cargo = 'Elige el cargo';
    const anio = Number(f.anio);
    if (!Number.isInteger(anio) || anio < lim.min || anio > lim.max) e.anio = `Escribe un año entre ${lim.min} y ${lim.max}`;
    else if (f.fecha && Number(f.fecha.slice(0, 4)) !== anio) e.fecha = 'La fecha debe ser del año de la elección';
    if (f.candidatoNombre.trim().length > 160) e.candidato = 'El nombre es muy largo (máximo 160 letras)';
    if (cargo?.territorio === 'municipio' && f.territorioIds.length !== 1) e.territorio = 'Elige un municipio';
    if (cargo?.territorio === 'comuna' && f.territorioIds.length !== 1) e.territorio = 'Elige una comuna o corregimiento';
    if (cargo?.territorio === 'departamento_o_municipios' && f.alcance === 'municipios' && f.territorioIds.length === 0) e.territorio = 'Elige al menos un municipio';
    Object.entries(opciones?.factores ?? FACTORES).forEach(([clave, def]) => {
        const n = aNumero(f[clave]);
        if (!Number.isFinite(n) || n < def.min || n > def.max || (!def.decimal && !Number.isInteger(n))) {
            e[clave] = `Escribe un número entre ${def.decimal ? decimal(def.min) : numero(def.min)} y ${def.decimal ? decimal(def.max) : numero(def.max)}`;
        }
    });
    return e;
}

// The body of POST and PUT /campanas-electorales/{id}. Department: no ids (the server sets Santander).
export function cuerpoFormulario(f) {
    const cargo = cargoDe(f.cargo);
    let scope = 'department';
    let ids = [];
    if (cargo?.territorio === 'municipio') { scope = 'municipality'; ids = f.territorioIds.slice(0, 1); }
    if (cargo?.territorio === 'comuna') { scope = 'comuna'; ids = f.territorioIds.slice(0, 1); }
    if (cargo?.territorio === 'departamento_o_municipios' && f.alcance === 'municipios') { scope = 'municipality'; ids = f.territorioIds; }
    return {
        name: f.nombre.trim(),
        cargo: f.cargo,
        election_year: Number(f.anio),
        election_date: f.fecha || null,
        candidate_person_id: f.candidatoId || null,
        candidate_name: f.candidatoId ? null : (f.candidatoNombre.trim() || null),
        political_organization_id: f.partidoId || null,
        scope_level: scope,
        territorio_ids: ids,
        factor_votos: aNumero(f.factor_votos),
        factor_registro: aNumero(f.factor_registro),
        votos_por_lider: aNumero(f.votos_por_lider),
    };
}

// The server's 422 errors (Laravel field names) on the form's fields.
const CAMPO_FORM = {
    name: 'nombre', cargo: 'cargo', election_year: 'anio', election_date: 'fecha', candidate_person_id: 'candidato',
    candidate_name: 'candidato', political_organization_id: 'partido', scope_level: 'territorio', territorio_ids: 'territorio',
    territorio: 'territorio', factor_votos: 'factor_votos', factor_registro: 'factor_registro', votos_por_lider: 'votos_por_lider',
    grupo: 'general',
};

export function erroresServidor(body) {
    const out = {};
    Object.entries(body?.errors ?? {}).forEach(([k, v]) => {
        const campo = CAMPO_FORM[k.split('.')[0]] ?? 'general';
        out[campo] ??= Array.isArray(v) ? v[0] : String(v);
    });
    if (!Object.keys(out).length && body?.message) out.general = body.message;
    return out;
}

/* ── Requests ── */

// {ok, status, body}: never throws, a dropped connection is status 0.
async function pedir(url, opciones = {}) {
    try {
        const res = await apiFetch(url, opciones);
        const body = await res.json().catch(() => null);
        return { ok: res.ok && body?.success !== false, status: res.status, body: body ?? {} };
    } catch (e) {
        if (e?.name === 'AbortError') throw e;
        return { ok: false, status: 0, body: {} };
    }
}

export function mensajeDe(res, porDefecto = 'No pudimos completar esto. Intenta de nuevo.') {
    if (res.status === 0) return 'Sin conexión. Revisa tu internet e intenta de nuevo.';
    if (res.status === 401 || res.status === 419) return TEXTO_SESION;
    if (res.status === 413) return TEXTO_PESO;
    if (res.status === 404) return 'Esta campaña no existe o no la puedes ver.';
    if (res.status === 403) return res.body?.message || 'No tienes permiso para hacer esto.';
    if (res.status === 429) return 'Vas muy rápido. Espera un momento y vuelve a intentarlo.';
    if (res.status === 422) return res.body?.message || porDefecto;
    return porDefecto;
}

const datos = (res) => res.body?.data ?? {};
const ruta = (id, resto = '') => `${BASE}/${encodeURIComponent(id)}${resto}`;

export async function listarCampanas({ archivadas = false, signal } = {}) {
    const res = await pedir(`${BASE}${archivadas ? '?archivadas=1' : ''}`, { signal });
    return res.ok ? { ...res, lista: normalizarLista(datos(res)) } : res;
}

export async function verCampana(id, { signal } = {}) {
    const res = await pedir(ruta(id), { signal });
    return res.ok ? { ...res, detalle: normalizarDetalle(datos(res)) } : res;
}

export const crearCampana = (cuerpo) => pedir(BASE, { method: 'POST', body: JSON.stringify(cuerpo) })
    .then(res => (res.ok ? { ...res, id: datos(res).campana?.id } : res));

export const editarCampana = (id, cuerpo) => pedir(ruta(id), { method: 'PUT', body: JSON.stringify(cuerpo) })
    .then(res => (res.ok ? { ...res, id: datos(res).campana?.id ?? id } : res));

export const archivarCampana = (id) => pedir(ruta(id, '/archivar'), { method: 'POST' });
export const reactivarCampana = (id) => pedir(ruta(id, '/reactivar'), { method: 'POST' });

// 200 → data {filas, totales, campana}: the grid is rebuilt from it (mezclarDetalle), no second request.
export const guardarMetas = (id, metas) => pedir(ruta(id, '/metas'), { method: 'PUT', body: JSON.stringify({ metas }) });

// The answer of PUT /metas or of the Excel upload replaces the rows, the totals and the header; the rest stays.
export function mezclarDetalle(detalle, data) {
    if (!data || !Array.isArray(data.filas)) return detalle;
    const nuevo = normalizarDetalle({
        campana: data.campana,
        filas: data.filas,
        totales: data.totales,
        can: { edit: detalle.can.editar, archive: detalle.can.archivar, reactivate: detalle.can.reactivar, plantilla: detalle.can.plantilla },
        sugerencias: { disponible: detalle.veResultados, aviso: detalle.avisoSugerencias },
    });
    return { ...detalle, ...nuevo, campana: data.campana ? nuevo.campana : detalle.campana, motivoSoloLectura: detalle.motivoSoloLectura };
}

// The 422 of PUT /metas: errors["metas.N.votos_meta"] on that cell of the N-th row sent; errors.metas for the batch.
export function erroresDeMetas(body, enviados) {
    const celdas = {};
    let general = null;
    Object.entries(body?.errors ?? {}).forEach(([k, v]) => {
        const msg = Array.isArray(v) ? v[0] : String(v);
        const m = /^metas\.(\d+)\.(votos|lideres|votantes)_meta$/.exec(k);
        const id = m && enviados[Number(m[1])]?.geographic_unit_id;
        if (id) (celdas[id] ??= {})[m[2]] = msg;
        else general ??= msg;
    });
    return { celdas, general };
}

export const urlPlantilla = (id) => ruta(id, '/metas/plantilla');

const valorHistorial = (v) => (v === null || v === undefined || v === '' ? 'vacío' : (typeof v === 'number' ? numero(v) : String(v)));

export async function verHistorial(id) {
    const res = await pedir(ruta(id, '/historial'));
    if (!res.ok) return res;
    const d = res.body?.data;
    return {
        ...res,
        entradas: (Array.isArray(d) ? d : []).map(e => ({
            id: e.id,
            fecha: e.fecha,
            usuario: texto(e.usuario),
            texto: texto(e.accion_label ?? e.accion),
            detalles: (e.cambios ?? []).map(c => [c.territorio, `${texto(c.campo_label ?? c.campo)}: ${valorHistorial(c.antes)} → ${valorHistorial(c.despues)}`].filter(Boolean).join(' · ')),
        })),
    };
}

export const CANDIDATO_MIN_LETRAS = 2;

export async function buscarCandidatos(q, signal) {
    const res = await pedir(`${BASE}/opciones/candidatos?q=${encodeURIComponent(q)}`, { signal });
    if (!res.ok) return res;
    const d = res.body?.data;
    return {
        ...res,
        candidatos: (Array.isArray(d) ? d : []).map(c => ({
            id: c.id,
            nombre: texto(c.nombre),
            detalle: (c.candidaturas ?? []).map(x => [x.cargo, x.anio, x.territorio, x.partido].filter(Boolean).join(' ')).join(' · '),
        })),
    };
}

// The file is checked here first: only .xlsx, at most 2 MB.
export function validarExcel(archivo) {
    if (!archivo) return 'Elige un archivo.';
    if (!/\.xlsx$/i.test(archivo.name ?? '')) return 'El archivo debe ser un Excel (.xlsx). Usa la plantilla.';
    if (archivo.size > EXCEL_MAX_BYTES) return TEXTO_PESO;
    return null;
}

// {ok, cargadas, data} or {ok: false, mensaje, filas: ['Fila 4: "abc" no es un número']}. All or nothing on the server.
export async function importarMetas(id, archivo) {
    const fd = new FormData();
    fd.append('archivo', archivo);
    let res;
    try {
        res = await fetch(ruta(id, '/metas/importar'), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });
    } catch {
        return { ok: false, status: 0, filas: [], mensaje: mensajeDe({ status: 0 }) };
    }
    const body = (await res.json().catch(() => null)) ?? {};
    if (res.ok && body.success !== false) {
        return { ok: true, status: res.status, cargadas: num(body.data?.cargadas) ?? 0, mensaje: texto(body.message), data: body.data ?? null };
    }
    const filas = Object.values(body.errors ?? {}).flat().map(String);
    // Too big for the server (413 from nginx, or PHP dropped the file and Laravel says it "could not be uploaded").
    if (res.status === 413 || (res.status === 422 && filas.some(f => /no se pudo subir|failed to upload/i.test(f)))) {
        return { ok: false, status: res.status, mensaje: TEXTO_PESO, filas: [] };
    }
    const mensaje = res.status === 422 ? texto(body.message) || 'No se cargó nada.' : mensajeDe({ status: res.status, body }, 'No se cargó nada. Intenta de nuevo.');
    return { ok: false, status: res.status, mensaje, filas: filas.filter(f => f !== mensaje) };
}

/* ── Tablero (fase 5): GET /{id}/tablero, /tablero/territorio/{unitId}, /tablero/exportar.{xlsx,pdf} ── */

export const MEDIDAS = [
    { clave: 'indice', etiqueta: 'Índice' },
    { clave: 'votantes', etiqueta: 'Votantes' },
    { clave: 'lideres', etiqueta: 'Líderes' },
];

// Light to dark, one hue: tramo 0 = 0–24 … tramo 4 = 100 or more («Meta cumplida»).
export const COLORES_TRAMO = ['#8fb3df', '#6596d0', '#3d74bb', '#1d5394', '#002f5c'];
export const COLOR_SIN_META = '#e5e7eb';
export const COLOR_RAYAS = '#9ca3af';
export const PATRON_RAYAS = 'tablero-rayas-insuficiente';
export const MINIMO_EVOLUCION = 50;
export const SEMANAS_POR_DEFECTO = 26;

export const TEXTO_FORMULA = 'El índice va de 0 a 100: el 60 % sale del avance en votantes registrados y el 40 % del avance en líderes. Pasar la meta no suma más.';
export const TEXTO_RANKING = 'Las metas muy pequeñas no entran al ranking.';
export const TEXTO_UN_TERRITORIO = 'Esta campaña es de un solo territorio: mira el avance arriba.';
export const TEXTO_ERROR_MAPA = 'No pudimos cargar el mapa. La tabla de abajo tiene los mismos datos.';
export const TEXTO_SIN_METAS = 'Esta campaña aún no tiene metas. Escríbelas en la campaña para ver el avance.';
export const TEXTO_POCOS = 'Muy pocos registros para ver la evolución';
export const TEXTO_MASIVA = 'Incluye un día con más de 300 registros (posible carga de archivo)';
export const TEXTO_MENOS_DE_5 = 'menos de 5';
// The tablero is read-only: nothing is left on screen to keep (TEXTO_SESION is for the goals grid).
export const TEXTO_SESION_TABLERO = 'Tu sesión venció. Recarga la página para volver a entrar.';

// «1 votante» / «2 votantes»; «Falta 1» / «Faltan 2».
const UNIDADES = { votantes: ['votante', 'votantes'], lideres: ['líder', 'líderes'], fichas: ['ficha', 'fichas'], semanas: ['semana', 'semanas'], territorios: ['territorio', 'territorios'] };
export const unidad = (n, clave) => (Number(n) === 1 ? UNIDADES[clave][0] : UNIDADES[clave][1]);
export const cuantos = (n, clave) => `${numero(n)} ${unidad(n, clave)}`;
export const falta = (n) => (Number(n) === 1 ? 'Falta' : 'Faltan');

export function mensajeTablero(res, porDefecto) {
    if (res.status === 401 || res.status === 419) return TEXTO_SESION_TABLERO;
    return (res.status === 429 && res.body?.message) || mensajeDe(res, porDefecto);
}

// «Además hay N fichas sin comuna y M en comunas sin meta: no cuentan para la meta.» Counts 1–4 come as null
// («menos de 5»); a 0 part is left out; nothing when both are 0 or the municipio has no goals in its comunas.
export function textoFueraDeMeta(t) {
    const partes = [];
    const parte = (n, resto) => {
        if (n === undefined || n === 0) return;
        partes.push({ n, resto });
    };
    parte(t.sinComuna, 'sin comuna');
    parte(t.otrasComunas, 'en comunas sin meta');
    if (!partes.length) return '';
    const cifra = (n) => (n === null ? TEXTO_MENOS_DE_5 : numero(n));
    const [a, b] = partes;
    const primera = `${cifra(a.n)} ${a.n === 1 ? 'ficha' : 'fichas'} ${a.resto}`;
    return b
        ? `Además hay ${primera} y ${cifra(b.n)} ${b.resto}: no cuentan para la meta.`
        : `Además hay ${primera}: no cuentan para la meta.`;
}
// The same words as the Excel and the PDF (CampanaTableroController::ESTADOS).
export const TEXTO_INSUFICIENTE = 'Pocas fichas con comuna';

const AVISOS_POR_DEFECTO = {
    sin_lideres: 'Aún no hay fichas marcadas como líder. Márcalas con la casilla "Es líder" en la ficha. Mientras tanto, el índice llega como máximo a 60.',
    archivada: 'Campaña archivada: el tablero muestra los datos de hoy.',
};

const ETIQUETAS_TRAMO = ['0 a 24 %', '25 a 49 %', '50 a 74 %', '75 a 99 %', 'Meta cumplida'];
const ETIQUETAS_TRAMO_INDICE = ['0 a 24', '25 a 49', '50 a 74', '75 a 99', 'Meta cumplida'];
export const etiquetaTramo = (tramo, medida) => (medida === 'indice' ? ETIQUETAS_TRAMO_INDICE : ETIQUETAS_TRAMO)[tramo] ?? '';

// 0–100 (or more) → 0..4; null stays null.
export function tramoDe(valor) {
    if (valor === null || valor === undefined || Number.isNaN(Number(valor))) return null;
    const v = Number(valor);
    if (v >= 100) return 4;
    if (v >= 75) return 3;
    if (v >= 50) return 2;
    if (v >= 25) return 1;
    return 0;
}

// pct comes as a ratio (0.5 = 50 %), with no cap.
export const porcentaje = (pct) => (pct === null || pct === undefined ? '' : `${numero(Math.round(Number(pct) * 100))} %`);

function medidaDe(m) {
    const registrados = num(m?.registrados) ?? 0;
    const meta = num(m?.meta);
    return {
        registrados,
        // Totals: pct and falta only count the territories with that goal (registrados_con_meta).
        conMeta: num(m?.registrados_con_meta) ?? registrados,
        meta,
        metaCalculada: meta !== null && !!m?.meta_calculada,
        pct: meta === null ? null : num(m?.pct),
        // Always the server's: for the totals and for a municipio with goals in its comunas it is a sum by territory
        // (F5-9), not meta − registrados.
        falta: meta === null ? null : num(m?.falta),
    };
}

const ESTADOS = ['sin_meta', 'insuficiente', 'ok'];
const cuentaFuera = (v) => {
    if (v === undefined) return undefined;
    if (v && typeof v === 'object') return num(v.votantes);
    return num(v);
};
const tramoDePct = (pct) => tramoDe(pct === null ? null : pct * 100);

function territorioDe(r) {
    const votantes = medidaDe(r.votantes);
    const lideres = medidaDe(r.lideres);
    const indice = num(r.indice);
    const tramo = r.tramo ?? {};
    const origen = r.por_origen;
    return {
        id: r.id,
        nombre: texto(r.nombre),
        tipo: r.tipo ?? 'municipality',
        padreId: r.padre_id ?? null,
        codigo: texto(r.codigo),
        votantes,
        lideres,
        indice,
        tramo: {
            indice: num(tramo.indice) ?? tramoDe(indice),
            votantes: num(tramo.votantes) ?? tramoDePct(votantes.pct),
            lideres: num(tramo.lideres) ?? tramoDePct(lideres.pct),
        },
        estado: ESTADOS.includes(r.estado) ? r.estado : (votantes.meta === null && lideres.meta === null ? 'sin_meta' : 'ok'),
        motivo: texto(r.motivo),
        // Municipios with goals in their comunas: fichas outside those goals (undefined = not sent, null = 1 to 4).
        sinComuna: cuentaFuera(r.sin_comuna),
        otrasComunas: cuentaFuera(r.otras_comunas),
        cobertura: r.cobertura_comuna ? { con: num(r.cobertura_comuna.con) ?? 0, total: num(r.cobertura_comuna.total) ?? 0 } : null,
        // Only comunas carry it; each count may be null (1 to 4, hidden).
        porOrigen: origen && typeof origen === 'object'
            ? { auto: num(origen.auto), texto: num(origen.texto), puesto: num(origen.puesto), manual: num(origen.manual) }
            : null,
    };
}

const semanaDe = (s) => ({ inicio: texto(s?.inicio).slice(0, 10), nuevos: num(s?.nuevos), masiva: !!s?.masiva });

const idsDe = (lista) => (Array.isArray(lista) ? lista.map(x => (x && typeof x === 'object' ? x.id : x)).filter(Boolean) : []);

// «Bogota (2 fichas)»: the name from mapa.sin_poligono, the count from its row in territorios.
// Every ficha of a territory: in a municipio with goals in its comunas, registrados only counts those comunas (F5-9)
// and the rest comes in sin_comuna / otras_comunas. null when one of them is hidden (1 to 4).
export function fichasDe(t) {
    const extra = [t.sinComuna, t.otrasComunas].filter(v => v !== undefined);
    if (extra.some(v => v === null)) return null;
    return t.votantes.registrados + extra.reduce((s, v) => s + v, 0);
}

function sinPoligonoDe(s, territorios) {
    const nombre = texto(s && typeof s === 'object' ? s.nombre : s);
    const fila = territorios.find(t => t.nombre === nombre);
    const fichas = num(s?.fichas) ?? (fila ? fichasDe(fila) : null);
    return fichas === null ? nombre : `${nombre} (${plural(fichas, 'ficha', 'fichas')})`;
}

// The tablero's data (Inertia props or the JSON data). can.edit, when sent, decides «Ir a las metas».
export function normalizarTablero(raw = {}) {
    const d = raw ?? {};
    const territorios = (Array.isArray(d.territorios) ? d.territorios : []).map(territorioDe);
    const porId = Object.fromEntries(territorios.map(t => [t.id, t]));
    const t = d.totales ?? {};
    const ev = d.evolucion ?? {};
    const ritmo = ev.ritmo_necesario;
    const mapa = d.mapa ?? {};
    const cuenta = (estado) => territorios.filter(x => x.estado === estado).length;
    return {
        campana: normalizarCampana(d.campana),
        soloLectura: !!d.soloLectura,
        puedeEditar: !!(d.can?.edit ?? d.can?.editar),
        generadoAt: d.generado_at ?? null,
        formula: texto(d.formula?.texto) || TEXTO_FORMULA,
        k: num(d.umbrales?.k) ?? 5,
        pocosRegistros: num(d.umbrales?.pocos_registros) ?? MINIMO_EVOLUCION,
        totales: {
            votantes: medidaDe(t.votantes),
            lideres: medidaDe(t.lideres),
            indice: num(t.indice),
            territorios: {
                total: num(t.territorios?.total) ?? territorios.length,
                conMeta: num(t.territorios?.con_meta) ?? territorios.length - cuenta('sin_meta'),
                sinMeta: num(t.territorios?.sin_meta) ?? cuenta('sin_meta'),
                insuficientes: num(t.territorios?.insuficientes) ?? cuenta('insuficiente'),
            },
        },
        territorios,
        porId,
        ranking: {
            mejores: idsDe(d.ranking?.mejores).filter(id => porId[id]),
            porApoyar: idsDe(d.ranking?.por_apoyar).filter(id => porId[id]),
            unaLista: typeof d.ranking?.una_lista === 'boolean' ? d.ranking.una_lista : null,
            nota: texto(d.ranking?.nota),
        },
        evolucion: {
            desde: ev.desde ?? null,
            semanasDefecto: num(ev.semanas_defecto) ?? SEMANAS_POR_DEFECTO,
            antes: num(ev.antes),
            antesTexto: texto(ev.antes_texto),
            masivaTexto: texto(ev.masiva_texto) || TEXTO_MASIVA,
            semanas: (Array.isArray(ev.semanas) ? ev.semanas : []).map(semanaDe),
            promedio4: num(ev.promedio_4),
            ritmo: ritmo && typeof ritmo === 'object' && num(ritmo.semanas) !== null
                ? { semanas: num(ritmo.semanas), porSemana: num(ritmo.por_semana), texto: texto(ritmo.texto) }
                : null,
        },
        avisos: (Array.isArray(d.avisos) ? d.avisos : [])
            .map(a => ({ tipo: texto(a?.tipo), texto: texto(a?.texto) || AVISOS_POR_DEFECTO[a?.tipo] || '' }))
            .filter(a => a.texto),
        mapa: {
            capa: ['municipios', 'comunas'].includes(mapa.capa) ? mapa.capa : null,
            texto: texto(mapa.texto) || TEXTO_UN_TERRITORIO,
            conComunas: (Array.isArray(mapa.con_comunas) ? mapa.con_comunas : []).map(c => ({ id: c.id, codigo: texto(c.codigo), nombre: texto(c.nombre) })),
            sinPoligono: (Array.isArray(mapa.sin_poligono) ? mapa.sin_poligono : []).map(s => sinPoligonoDe(s, territorios)).filter(Boolean),
        },
    };
}

export const sinMetas = (tablero) => tablero.totales.territorios.conMeta === 0
    && tablero.totales.votantes.meta === null && tablero.totales.lideres.meta === null;

// How a territory is painted for one measure. A territory outside the campaign (or the user's territory) is 'fuera'.
export function pinturaDe(territorio, medida) {
    if (!territorio) return { clase: 'fuera', tramo: null };
    if (territorio.estado === 'insuficiente') return { clase: 'insuficiente', tramo: null };
    const tramo = territorio.tramo[medida];
    if (territorio.estado === 'sin_meta' || tramo === null || tramo === undefined) return { clase: 'sin_meta', tramo: null };
    return { clase: 'tramo', tramo };
}

export function colorDe(pintura) {
    if (pintura.clase === 'tramo') return COLORES_TRAMO[pintura.tramo];
    if (pintura.clase === 'insuficiente') return `url(#${PATRON_RAYAS})`;
    if (pintura.clase === 'sin_meta') return COLOR_SIN_META;
    return '#ffffff';
}

// The legend counts only what the map draws, in a fixed order.
export function leyendaDe(dibujados, medida) {
    const tramos = [0, 0, 0, 0, 0];
    let sinMeta = 0;
    let insuficiente = 0;
    dibujados.forEach(t => {
        const p = pinturaDe(t, medida);
        if (p.clase === 'tramo') tramos[p.tramo] += 1;
        else if (p.clase === 'sin_meta') sinMeta += 1;
        else if (p.clase === 'insuficiente') insuficiente += 1;
    });
    return [
        ...tramos.map((cuantos, tramo) => ({ clave: `t${tramo}`, etiqueta: etiquetaTramo(tramo, medida), clase: 'tramo', tramo, cuantos })),
        { clave: 'sin_meta', etiqueta: 'Sin meta', clase: 'sin_meta', tramo: null, cuantos: sinMeta },
        { clave: 'insuficiente', etiqueta: TEXTO_INSUFICIENTE, clase: 'insuficiente', tramo: null, cuantos: insuficiente },
    ];
}

export function textoEstado(t) {
    if (t.estado === 'sin_meta') return 'Sin meta';
    if (t.estado === 'insuficiente') return TEXTO_INSUFICIENTE;
    return 'Con meta';
}

export const ORDENES = [
    { clave: 'falta', etiqueta: 'Lo que falta' },
    { clave: 'indice', etiqueta: 'Índice' },
    { clave: 'nombre', etiqueta: 'Nombre' },
];

const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');

// Lo que falta: most missing voters first, then leaders (no goal at the end); Índice: highest first.
export function ordenarTerritorios(lista, orden) {
    const copia = [...lista];
    if (orden === 'nombre') return copia.sort(porNombre);
    if (orden === 'indice') return copia.sort((a, b) => ((b.indice ?? -1) - (a.indice ?? -1)) || porNombre(a, b));
    return copia.sort((a, b) => ((b.votantes.falta ?? -1) - (a.votantes.falta ?? -1))
        || ((b.lideres.falta ?? -1) - (a.lideres.falta ?? -1)) || porNombre(a, b));
}

const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

// '2026-11-16' → '16 nov' (as written: no time zone shift); conAnio → '16 nov 2026'.
export function fechaCorta(iso, conAnio = false) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
    if (!m) return '';
    return `${Number(m[3])} ${MESES_CORTOS[Number(m[2]) - 1]}${conAnio ? ` ${m[1]}` : ''}`;
}

export const textoSemana = (s) => `Semana del ${fechaCorta(s.inicio)}: ${s.nuevos === null ? TEXTO_MENOS_DE_5 : numero(s.nuevos)} ${s.nuevos === 1 ? 'ficha nueva' : 'fichas nuevas'}`;

export function horaDe(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'America/Bogota' });
}

export const urlTablero = (id) => ruta(id, '/tablero');
export const urlExportarTablero = (id, formato) => ruta(id, `/tablero/exportar.${formato === 'pdf' ? 'pdf' : 'xlsx'}`);

export async function verTablero(id, { actualizar = false, signal } = {}) {
    const res = await pedir(`${urlTablero(id)}${actualizar ? '?actualizar=1' : ''}`, { signal });
    return res.ok ? { ...res, tablero: normalizarTablero(datos(res)) } : res;
}

// One territory: its row again plus its weeks (counts 1–4 come as null).
export async function verTerritorioTablero(id, unitId, { signal } = {}) {
    const res = await pedir(ruta(id, `/tablero/territorio/${encodeURIComponent(unitId)}`), { signal });
    if (!res.ok) return res;
    const d = datos(res);
    const fila = d.territorio && typeof d.territorio === 'object' ? d.territorio : (d.id ? d : null);
    const semanas = d.semanas ?? d.evolucion?.semanas ?? fila?.semanas;
    return {
        ...res,
        territorio: fila ? territorioDe(fila) : null,
        semanas: (Array.isArray(semanas) ? semanas : []).map(semanaDe),
        antes: num(d.antes),
        pocosRegistros: typeof d.pocos_registros === 'boolean' ? d.pocos_registros : null,
        pocosTexto: texto(d.pocos_registros_texto) || TEXTO_POCOS,
        porPuestoTexto: texto(d.por_puesto_texto),
        comunas: idsDe(d.comunas),
    };
}
