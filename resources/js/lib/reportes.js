import { useEffect, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';

// The only place that knows the shape the backend sends (Inertia props of "Reportes/Index") and the
// requests of the contract (docs/reportes.md). Components work with the normalized objects below.

export const SPEC_VERSION = 1;
export const DEBOUNCE_MS = 400;
export const PASOS = [
    { numero: 1, titulo: 'Qué quieres ver' },
    { numero: 2, titulo: 'A quiénes' },
    { numero: 3, titulo: 'Qué datos' },
    { numero: 4, titulo: 'Tu lista' },
];

export const ORDENES_POR_DEFECTO = [
    { valor: 'nombre:asc', etiqueta: 'Nombre A–Z' },
    { valor: 'nombre:desc', etiqueta: 'Nombre Z–A' },
    { valor: 'municipio:asc', etiqueta: 'Municipio' },
    { valor: 'recientes:desc', etiqueta: 'Más recientes' },
    { valor: 'referidos:desc', etiqueta: 'Más personas referidas' },
];

// Internal kinds: chips (several, "O"), unico (one or none), buscar, rango, fechas, interruptor (on/off).
// The backend sends chips+multiple:false for "unico" and "casilla" for "interruptor" (Catalogo/Filtro.php).
function tipoDe(f) {
    if (f.tipo === 'casilla' || f.tipo === 'interruptor') return 'interruptor';
    if (f.tipo === 'chips' && f.multiple === false) return 'unico';
    if (['chips', 'unico', 'buscar', 'rango', 'fechas'].includes(f.tipo)) return f.tipo;
    return Array.isArray(f.opciones) && f.opciones.length ? 'chips' : 'buscar';
}

const texto = (v) => (v === null || v === undefined ? '' : String(v));

// The value keeps its type: the server checks some with is_int (eventos_min: 1), so "1" would be dropped.
const valorDe = (v) => (typeof v === 'number' || typeof v === 'string' ? v : texto(v));
export const mismoValor = (a, b) => String(a) === String(b);

function normalizarOpcion(o) {
    if (o === null || typeof o !== 'object') return { valor: valorDe(o), etiqueta: texto(o) };
    return {
        ...o,
        valor: valorDe(o.valor ?? o.value ?? o.id ?? o.clave),
        etiqueta: texto(o.etiqueta ?? o.label ?? o.nombre ?? o.valor ?? o.value),
    };
}

function normalizarFiltro(f, grupo) {
    const tipo = tipoDe(f);
    const crudas = Array.isArray(f.opciones) ? f.opciones : [];
    const opcionesDe = f.opciones_de ?? f.opcionesDe ?? null;
    // Ready-made ranges: {etiqueta, valor: {desde, hasta}} (backend) or {etiqueta, desde, hasta}.
    const rangos = tipo === 'rango'
        ? [...crudas, ...(Array.isArray(f.rangos) ? f.rangos : [])].map(r => ({
            etiqueta: texto(r.etiqueta ?? r.label),
            desde: r.valor?.desde ?? r.desde ?? null,
            hasta: r.valor?.hasta ?? r.hasta ?? null,
        }))
        : [];
    const periodos = tipo === 'fechas'
        ? crudas.map(o => ({ etiqueta: texto(o?.etiqueta ?? o?.label), relativo: texto(o?.valor?.relativo ?? o?.relativo) })).filter(o => o.relativo && o.etiqueta)
        : [];
    const remoto = tipo === 'buscar' && (!!opcionesDe || crudas.length === 0);
    return {
        clave: texto(f.clave ?? f.key),
        etiqueta: texto(f.etiqueta ?? f.label),
        grupo: texto(grupo ?? f.grupo ?? 'Otros filtros'),
        tipo,
        opciones: tipo === 'rango' || tipo === 'fechas' || tipo === 'interruptor' || remoto ? null : crudas.map(normalizarOpcion),
        // Remote search: GET /reportes/opciones/{opciones_de ?? clave}.
        remoto,
        opcionesDe: texto(opcionesDe ?? f.clave ?? f.key),
        placeholder: texto(f.placeholder) || `Buscar ${texto(f.etiqueta ?? f.label).toLowerCase()}…`,
        ayuda: texto(f.ayuda),
        // Comuna arrives as chips whose options carry the municipio they belong to.
        dependeDe: f.depende_de ?? f.dependeDe
            ?? ((f.clave ?? f.key) !== 'municipio' && crudas.length > 0 && crudas.every(o => o && typeof o === 'object' && 'municipio' in o) ? 'municipio' : null),
        rangos,
        periodos,
        unidad: texto(f.unidad),
        // "unico": the option that applies when nothing is chosen (e.g. Estado → Activas). Choosing it clears the filter.
        // A mandatory one (Empresarios → vista) falls back to its first option.
        porDefecto: porDefectoDe(f, tipo, crudas),
        obligatorio: !!(f.obligatorio ?? f.requerido),
    };
}

function porDefectoDe(f, tipo, crudas) {
    if (f.por_defecto !== undefined && f.por_defecto !== null && typeof f.por_defecto !== 'object') return texto(f.por_defecto);
    if ((f.obligatorio ?? f.requerido) && tipo === 'unico' && crudas.length) return texto(normalizarOpcion(crudas[0]).valor);
    return null;
}

function normalizarColumna(c) {
    return {
        clave: texto(c.clave ?? c.key),
        etiqueta: texto(c.etiqueta ?? c.label),
        grupo: texto(c.grupo ?? 'Otros datos'),
        sensible: !!c.sensible,
        siempre: !!(c.siempre ?? c.fija),
        ordenable: !!c.ordenable,
        // Empresarios: the vistas the column belongs to; null = every one.
        vistas: Array.isArray(c.vistas) && c.vistas.length ? c.vistas.map(texto) : null,
    };
}

// filtros arrive as sections [{clave, etiqueta, abierto, filtros: [...]}] (Fuentes/Fuente.php) or flat with `grupo`.
function seccionesDe(raw) {
    const lista = Array.isArray(raw.filtros) ? raw.filtros : [];
    if (lista.some(x => Array.isArray(x?.filtros))) {
        return lista.filter(x => Array.isArray(x?.filtros)).map(sec => ({
            titulo: texto(sec.etiqueta ?? sec.label ?? sec.clave),
            abierto: !!sec.abierto,
            filtros: sec.filtros.map(f => normalizarFiltro(f, sec.etiqueta ?? sec.label ?? sec.clave)),
        }));
    }
    return gruposDe(lista.map(f => normalizarFiltro(f))).map((g, i) => ({ titulo: g.titulo, abierto: i === 0, filtros: g.items }));
}

export function normalizarFuente(raw) {
    const clave = texto(raw.clave ?? raw.key);
    // Each filter knows its source: a remote search asks /reportes/opciones/{x}?fuente=<clave>.
    const secciones = seccionesDe(raw)
        .map(s => ({ ...s, filtros: s.filtros.filter(f => f.clave).map(f => ({ ...f, fuente: clave })) }))
        .filter(s => s.filtros.length);
    if (secciones.length && !secciones.some(s => s.abierto)) secciones[0].abierto = true;
    const columnas = (Array.isArray(raw.columnas) ? raw.columnas : Object.values(raw.columnas ?? {})).map(normalizarColumna).filter(c => c.clave);
    const u = raw.unidad;
    const unidad = u && typeof u === 'object' && u.singular && u.plural
        ? { singular: texto(u.singular), plural: texto(u.plural), todos: texto(u.todos), femenino: typeof u.femenino === 'boolean' ? u.femenino : null }
        : null;
    return {
        clave,
        etiqueta: texto(raw.etiqueta ?? raw.label),
        descripcion: texto(raw.descripcion),
        unidad,
        total: raw.total === null || raw.total === undefined || !Number.isFinite(Number(raw.total)) ? null : Number(raw.total),
        secciones,
        filtros: secciones.flatMap(s => s.filtros),
        columnas,
        presets: (raw.presets ?? []).map(p => ({
            clave: texto(p.clave ?? p.key),
            etiqueta: texto(p.etiqueta ?? p.label),
            descripcion: texto(p.descripcion),
            columnas: p.columnas === '*' ? '*' : [].concat(p.columnas ?? []).map(texto),
            porDefecto: !!(p.por_defecto ?? p.default),
            vista: p.vista ? texto(p.vista) : null,
        })),
        // Backend: {clave, etiqueta, descripcion, spec: {filtros, columnas?}}.
        atajos: (raw.atajos ?? []).map(a => {
            const filtros = a.spec?.filtros ?? a.filtros;
            const columnas = a.spec?.columnas ?? a.columnas;
            return {
                clave: texto(a.clave ?? a.key),
                etiqueta: texto(a.etiqueta ?? a.label),
                descripcion: texto(a.descripcion),
                filtros: filtros && typeof filtros === 'object' && !Array.isArray(filtros) ? filtros : {},
                columnas: Array.isArray(columnas) && columnas.length ? columnas.map(texto) : null,
            };
        }),
        ordenes: Array.isArray(raw.ordenes) && raw.ordenes.length
            ? raw.ordenes.map(o => ({ valor: texto(o.valor ?? `${o.campo ?? o.clave}:${o.dir ?? 'asc'}`), etiqueta: texto(o.etiqueta ?? o.label) }))
            : ORDENES_POR_DEFECTO,
    };
}

export function gruposDe(items) {
    const grupos = [];
    for (const item of items) {
        let g = grupos.find(x => x.titulo === item.grupo);
        if (!g) grupos.push(g = { titulo: item.grupo, items: [] });
        g.items.push(item);
    }
    return grupos;
}

/* ── Filter values ── */

export function filtroVacio(valor) {
    if (valor === null || valor === undefined || valor === '' || valor === false) return true;
    if (Array.isArray(valor)) return valor.length === 0;
    if (typeof valor === 'object' && 'relativo' in valor) return !valor.relativo;
    if (typeof valor === 'object') return (valor.desde ?? null) === null && (valor.hasta ?? null) === null;
    return false;
}

// Keeps insertion order meaningful: the key just changed goes last, so "the last filter" is the newest.
export function conFiltro(filtros, clave, valor) {
    const next = { ...filtros };
    delete next[clave];
    if (!filtroVacio(valor)) next[clave] = valor;
    return next;
}

export const EDAD_MAXIMA = 120;

function entero(v) {
    if (v === null || v === undefined || v === '') return null;
    const t = String(v).trim();
    if (!/^\d{1,3}$/.test(t)) return null;
    const n = Number(t);
    return n <= EDAD_MAXIMA ? n : null;
}

export function normalizarRango(r) {
    const desde = entero(r?.desde);
    const hasta = entero(r?.hasta);
    if (desde !== null && hasta !== null && desde > hasta) return { desde: null, hasta: null };
    return { desde, hasta };
}

// What the user typed in "Otra: desde [__] hasta [__]": the range to apply, or the reason it cannot be applied.
// An invalid box never applies the other one alone.
export function validarRango(desdeTexto, hastaTexto) {
    const campos = [String(desdeTexto ?? '').trim(), String(hastaTexto ?? '').trim()];
    for (const t of campos) {
        if (t === '') continue;
        if (!/^\d+$/.test(t)) return { error: 'Escribe años enteros, por ejemplo 30.' };
        if (Number(t) > EDAD_MAXIMA) return { error: `Escribe una edad entre 0 y ${EDAD_MAXIMA}.` };
    }
    const [desde, hasta] = campos.map(t => (t === '' ? null : Number(t)));
    if (desde !== null && hasta !== null && desde > hasta) return { error: 'El primer número debe ser menor que el segundo.' };
    return { rango: { desde, hasta } };
}

/* ── Dates (filter "fechas": {desde, hasta} as YYYY-MM-DD) ── */

const ZONA = 'America/Bogota';

export function fechaValida(t) {
    if (typeof t !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(t)) return false;
    const [a, m, d] = t.split('-').map(Number);
    const f = new Date(Date.UTC(a, m - 1, d));
    return a >= 1900 && f.getUTCFullYear() === a && f.getUTCMonth() === m - 1 && f.getUTCDate() === d;
}

export function normalizarFechas(r) {
    const desde = fechaValida(r?.desde) ? r.desde : null;
    const hasta = fechaValida(r?.hasta) ? r.hasta : null;
    if (desde !== null && hasta !== null && desde > hasta) return { desde: null, hasta: null };
    return { desde, hasta };
}

// Same rule as the age: an invalid pair applies nothing, never half of it.
// `incompleta`: the browser's date box has something typed that is not a whole date yet (its value is '').
export function validarFechas(desdeTexto, hastaTexto, { incompleta = false } = {}) {
    const campos = [String(desdeTexto ?? '').trim(), String(hastaTexto ?? '').trim()];
    if (incompleta || campos.some(t => t !== '' && !fechaValida(t))) return { error: 'Escribe la fecha completa: día, mes y año.' };
    const [desde, hasta] = campos.map(t => (t === '' ? null : t));
    if (desde !== null && hasta !== null && desde > hasta) return { error: 'La fecha «desde» debe ser igual o anterior a la fecha «hasta».' };
    return { rango: { desde, hasta } };
}

const iso = (f) => f.toISOString().slice(0, 10);

export function hoyEnBogota(ahora = new Date()) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: ZONA }).format(ahora);
}

// Hoy, Esta semana (lunes a domingo), Este mes (del 1 al último día) and Último mes (from the same day one
// month back until today), in Bogotá's calendar.
export function atajosFechas(ahora = new Date()) {
    const hoy = hoyEnBogota(ahora);
    const [a, m, d] = hoy.split('-').map(Number);
    const base = new Date(Date.UTC(a, m - 1, d));
    const lunes = new Date(Date.UTC(a, m - 1, d - ((base.getUTCDay() + 6) % 7)));
    const domingo = new Date(Date.UTC(lunes.getUTCFullYear(), lunes.getUTCMonth(), lunes.getUTCDate() + 6));
    const diasMesAnterior = new Date(Date.UTC(a, m - 1, 0)).getUTCDate();
    const haceUnMes = new Date(Date.UTC(a, m - 2, Math.min(d, diasMesAnterior)));
    return [
        { etiqueta: 'Hoy', desde: hoy, hasta: hoy },
        { etiqueta: 'Esta semana', desde: iso(lunes), hasta: iso(domingo) },
        { etiqueta: 'Este mes', desde: iso(new Date(Date.UTC(a, m - 1, 1))), hasta: iso(new Date(Date.UTC(a, m, 0))) },
        { etiqueta: 'Último mes', desde: iso(haceUnMes), hasta: hoy },
    ];
}

// The buttons of a "fechas" filter: the source's relative periods (they keep meaning "the last month" in a saved
// report) and Hoy / Esta semana / Este mes / Último mes for whatever the source does not already offer.
export function periodosDe(filtro, ahora = new Date()) {
    const remotos = (filtro.periodos ?? []).map(p => ({ etiqueta: p.etiqueta, valor: { relativo: p.relativo } }));
    const nombres = new Set(remotos.map(p => p.etiqueta.toLowerCase()));
    const locales = atajosFechas(ahora).filter(p => !nombres.has(p.etiqueta.toLowerCase())).map(p => ({ etiqueta: p.etiqueta, valor: { desde: p.desde, hasta: p.hasta } }));
    return [...locales, ...remotos];
}

export const mismoPeriodo = (a, b) => !!a && !!b && (a.relativo
    ? a.relativo === b.relativo
    : !b.relativo && (a.desde ?? null) === (b.desde ?? null) && (a.hasta ?? null) === (b.hasta ?? null));

export function textoFecha(t) {
    const [a, m, d] = t.split('-');
    return `${Number(d)}/${Number(m)}/${a}`;
}

export function textoFechas(r) {
    if (r.desde !== null && r.hasta !== null) {
        return r.desde === r.hasta ? `el ${textoFecha(r.desde)}` : `del ${textoFecha(r.desde)} al ${textoFecha(r.hasta)}`;
    }
    if (r.desde !== null) return `desde el ${textoFecha(r.desde)}`;
    return `hasta el ${textoFecha(r.hasta)}`;
}

// Values for an unknown filter or of the wrong shape are dropped; the server re-validates anyway.
export function limpiarFiltros(filtros, fuente) {
    const out = {};
    if (!filtros || typeof filtros !== 'object' || !fuente) return out;
    for (const [clave, valor] of Object.entries(filtros)) {
        const f = fuente.filtros.find(x => x.clave === clave);
        if (!f) continue;
        let v = valor;
        if (f.tipo === 'chips' || f.tipo === 'buscar') {
            const pedidos = [].concat(valor ?? []).filter(x => typeof x === 'string' || typeof x === 'number').filter(x => texto(x) !== '');
            v = f.opciones
                ? f.opciones.filter(o => pedidos.some(x => mismoValor(x, o.valor))).map(o => o.valor)
                : [...new Set(pedidos.map(texto))];
        }
        else if (f.tipo === 'unico') {
            const op = (typeof valor === 'string' || typeof valor === 'number') ? f.opciones?.find(o => mismoValor(o.valor, valor)) : null;
            v = op && !mismoValor(op.valor, f.porDefecto) ? op.valor : '';
        }
        else if (f.tipo === 'interruptor') v = valor === true || valor === 'true' || valor === 1;
        else if (f.tipo === 'rango') v = normalizarRango(valor);
        else if (f.tipo === 'fechas') {
            v = valor && typeof valor === 'object' && 'relativo' in valor
                ? (f.periodos.some(x => x.relativo === valor.relativo) ? { relativo: valor.relativo } : null)
                : normalizarFechas(valor);
        }
        if (!filtroVacio(v)) out[clave] = v;
    }
    return out;
}

/* ── Columns ── */

// The chosen vista of a source that has one (Empresarios: organizaciones | miembros), or null.
export function vistaDe(fuente, filtros = {}) {
    const f = fuente?.filtros.find(x => x.clave === 'vista');
    if (!f) return null;
    return String(filtros.vista ?? f.porDefecto ?? f.opciones?.[0]?.valor ?? '');
}

// The source as seen in that vista: only its columns and presets (another vista's column is a 422).
export function fuenteEnVista(fuente, filtros = {}) {
    const vista = vistaDe(fuente, filtros);
    if (vista === null) return fuente;
    return {
        ...fuente,
        columnas: fuente.columnas.filter(c => !c.vistas || c.vistas.includes(vista)),
        presets: fuente.presets.filter(p => !p.vista || p.vista === vista),
    };
}

export function columnasPermitidas(fuente, puedeDatosPersonales) {
    return fuente.columnas.filter(c => puedeDatosPersonales || !c.sensible);
}

export function columnasDePreset(preset, fuente, puedeDatosPersonales) {
    const permitidas = columnasPermitidas(fuente, puedeDatosPersonales);
    const claves = preset.columnas === '*' ? permitidas.map(c => c.clave) : preset.columnas;
    return limpiarColumnas(claves, fuente, puedeDatosPersonales);
}

// The marked one; otherwise the first preset, or without the permission the first one with no locked column
// ("Solo nombre y lugar" in the plan).
export function presetPorDefecto(fuente, puedeDatosPersonales) {
    const p = fuente.presets;
    const marcado = p.find(x => x.porDefecto);
    if (puedeDatosPersonales) return marcado ?? p[0] ?? null;
    const sensibles = new Set(fuente.columnas.filter(c => c.sensible).map(c => c.clave));
    const limpio = (x) => x.columnas !== '*' && x.columnas.every(c => !sensibles.has(c));
    return (marcado && limpio(marcado) ? marcado : null) ?? p.find(limpio) ?? marcado ?? p[0] ?? null;
}

export function columnasPorDefecto(fuente, puedeDatosPersonales) {
    const preset = presetPorDefecto(fuente, puedeDatosPersonales);
    if (preset) return columnasDePreset(preset, fuente, puedeDatosPersonales);
    return limpiarColumnas(fuente.columnas.slice(0, 5).map(c => c.clave), fuente, puedeDatosPersonales);
}

// Catalog order, fixed columns always in, sensitive ones out without the permission (the API answers 403).
export function limpiarColumnas(claves, fuente, puedeDatosPersonales) {
    const pedidas = new Set([].concat(claves ?? []).map(texto));
    return columnasPermitidas(fuente, puedeDatosPersonales)
        .filter(c => c.siempre || pedidas.has(c.clave))
        .map(c => c.clave);
}

export function mismasColumnas(a, b) {
    return a.length === b.length && a.every((x, i) => x === b[i]);
}

/* ── URL state ── */

function parseJson(raw, fallback) {
    if (!raw) return fallback;
    try {
        const v = JSON.parse(raw);
        return v && typeof v === 'object' && !Array.isArray(v) ? v : fallback;
    } catch {
        return fallback;
    }
}

export function leerEstado(url, fuentes, puedeDatosPersonales) {
    const query = new URLSearchParams(texto(url).split('?')[1] ?? '');
    const fuente = fuentes.find(f => f.clave === query.get('fuente')) ?? null;
    let paso = Number.parseInt(query.get('paso') ?? '1', 10);
    if (!Number.isFinite(paso) || paso < 1 || paso > 4 || !fuente) paso = 1;
    const columnasUrl = query.get('c');
    const ordenes = fuente?.ordenes ?? ORDENES_POR_DEFECTO;
    const orden = ordenes.some(o => o.valor === query.get('o')) ? query.get('o') : ordenes[0].valor;
    const pagina = Math.max(1, Number.parseInt(query.get('p') ?? '1', 10) || 1);
    const etiquetas = parseJson(query.get('et'), {});
    const filtros = limpiarFiltros(parseJson(query.get('f'), {}), fuente);
    const enVista = fuente ? fuenteEnVista(fuente, filtros) : null;
    return {
        fuente,
        paso,
        filtros,
        columnas: enVista
            ? (columnasUrl === null ? columnasPorDefecto(enVista, puedeDatosPersonales) : limpiarColumnas(columnasUrl.split(','), enVista, puedeDatosPersonales))
            : [],
        orden,
        busqueda: (query.get('q') ?? '').slice(0, 100),
        pagina,
        etiquetas: Object.fromEntries(Object.entries(etiquetas).filter(([, v]) => typeof v === 'string')),
    };
}

export function urlDeEstado(estado, base = '/reportes') {
    const q = new URLSearchParams();
    if (estado.fuente) {
        q.set('fuente', estado.fuente.clave);
        q.set('paso', String(estado.paso));
        if (Object.keys(estado.filtros).length) q.set('f', JSON.stringify(estado.filtros));
        q.set('c', estado.columnas.join(','));
        q.set('o', estado.orden);
        // A cédula or a phone typed in the search never goes to the URL (history, logs, a shared link).
        if (estado.busqueda && !pareceDocumento(estado.busqueda)) q.set('q', estado.busqueda);
        if (estado.pagina > 1) q.set('p', String(estado.pagina));
        // Only the labels of chosen values that came from a remote search (needed to show the chips again).
        const usados = new Set(Object.values(estado.filtros).flatMap(v => (Array.isArray(v) ? v.map(String) : [])));
        const et = Object.fromEntries(Object.entries(estado.etiquetas ?? {}).filter(([k]) => usados.has(k)));
        if (Object.keys(et).length) q.set('et', JSON.stringify(et));
    }
    const s = q.toString();
    return s ? `${base}?${s}` : base;
}

export function pareceDocumento(texto) {
    return /^\d{5,}$/.test(String(texto ?? '').replace(/[\s.\-]/g, ''));
}

// The people left out by default, in words (Estado = Activas and no "Incluir fallecidos"), or '' when none.
// Only Personas: another source's "estado" (a compromiso's) says nothing about fallecidos or inactive people.
export function textoExcluidos(filtros, fuente) {
    if (fuente?.clave !== 'personas') return '';
    const hay = (clave) => fuente?.filtros.some(f => f.clave === clave);
    const fallecidos = hay('incluir_fallecidos') && !filtros.incluir_fallecidos;
    const inactivas = hay('estado') && filtros.estado === undefined;
    if (fallecidos && inactivas) return 'No incluye fallecidos ni personas inactivas.';
    if (fallecidos) return 'No incluye fallecidos.';
    if (inactivas) return 'No incluye personas inactivas.';
    return '';
}

export function specDe(estado) {
    const [campo, dir] = estado.orden.split(':');
    return {
        v: SPEC_VERSION,
        fuente: estado.fuente.clave,
        filtros: estado.filtros,
        columnas: estado.columnas,
        orden: { campo, dir: dir === 'desc' ? 'desc' : 'asc' },
        busqueda: estado.busqueda.trim(),
    };
}

/* ── Words for the chosen filters ── */

// The name of a chosen value, or null when only its id is known (a remote search whose label was not kept: a link
// without "et", a shortcut that names an evento by id).
export function nombreDeValor(filtro, valor, etiquetas = {}) {
    const op = filtro.opciones?.find(o => mismoValor(o.valor, valor));
    const nombre = op?.etiqueta ?? etiquetas[String(valor)];
    if (nombre) return nombre;
    return pareceId(valor) ? null : String(valor);
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const pareceId = (v) => UUID.test(String(v));

// "1 evento elegido", "2 referentes elegidos": a value is never shown as its id.
export function textoElegidos(filtro, n) {
    const singular = filtro.etiqueta.toLowerCase();
    const plural = /[aeiouáéó]$/.test(singular) ? `${singular}s` : `${singular}es`;
    return `${n} ${n === 1 ? singular : plural} ${n === 1 ? 'elegido' : 'elegidos'}`;
}

export function etiquetaValor(filtro, valor, etiquetas = {}) {
    return nombreDeValor(filtro, valor, etiquetas) ?? textoElegidos(filtro, 1);
}

export function textoRango(r, unidad = 'años') {
    const u = unidad ? ` ${unidad}` : '';
    if (r.desde !== null && r.hasta !== null) return `${r.desde} a ${r.hasta}${u}`;
    if (r.desde !== null) return `${r.desde}${u} o más`;
    return `Hasta ${r.hasta}${u}`;
}

// One removable chip per chosen value, in the order the filters were chosen.
export function resumenFiltros(filtros, fuente, etiquetas = {}) {
    const chips = [];
    for (const [clave, valor] of Object.entries(filtros)) {
        const f = fuente.filtros.find(x => x.clave === clave);
        if (!f) continue;
        if (Array.isArray(valor)) {
            // Values without a name go together in one chip ("2 eventos elegidos") that removes them all.
            const sinNombre = [];
            for (const v of valor) {
                const nombre = nombreDeValor(f, v, etiquetas);
                if (nombre === null) sinNombre.push(v);
                else chips.push({ id: `${clave}:${v}`, clave, valor: v, texto: nombre });
            }
            if (sinNombre.length) chips.push({ id: `${clave}:sin-nombre`, clave, valores: sinNombre, texto: textoElegidos(f, sinNombre.length) });
        } else if (f.tipo === 'rango') {
            chips.push({ id: clave, clave, texto: `${f.etiqueta}: ${textoRango(valor, f.unidad || 'años')}` });
        } else if (f.tipo === 'fechas') {
            const periodo = valor.relativo ? f.periodos.find(x => x.relativo === valor.relativo)?.etiqueta : null;
            chips.push({ id: clave, clave, texto: `${f.etiqueta}: ${periodo ?? textoFechas(valor)}` });
        } else if (f.tipo === 'interruptor') {
            chips.push({ id: clave, clave, texto: f.etiqueta });
        } else {
            chips.push({ id: clave, clave, texto: `${f.etiqueta}: ${etiquetaValor(f, valor, etiquetas)}` });
        }
    }
    return chips;
}

export function sinChip(filtros, chip) {
    const valor = filtros[chip.clave];
    const quitar = chip.valores ?? [chip.valor];
    return conFiltro(filtros, chip.clave, Array.isArray(valor) ? valor.filter(v => !quitar.includes(v)) : null);
}

/* ── Numbers and messages ── */

export const numero = (n) => (Number(n) || 0).toLocaleString('es-CO');
export const personas = (n) => `${numero(n)} ${Number(n) === 1 ? 'persona' : 'personas'}`;

/* ── Words of each source ── */

// gente: rows that are people ("¿A quiénes…?", "ver a todos los asistentes"); otherwise "¿Qué compromisos…?".
const UNIDADES = {
    personas: { singular: 'persona', plural: 'personas', femenino: true, gente: true },
    asistencias: { singular: 'asistente', plural: 'asistentes', femenino: false, gente: true },
    compromisos: { singular: 'compromiso', plural: 'compromisos', femenino: false, gente: false },
    organizaciones: { singular: 'organización', plural: 'organizaciones', femenino: true, gente: false },
    miembros: { singular: 'miembro', plural: 'miembros', femenino: false, gente: true },
};
const GENERICA = { singular: 'resultado', plural: 'resultados', femenino: false, gente: false };

// The words of the rows of a source. Empresarios lists organizations or their members depending on its vista;
// the backend's `unidad` is the one of the default filters, so it only applies to the default vista.
export function unidadDe(fuente, filtros = {}) {
    let clave = fuente?.clave;
    if (clave === 'empresarios') clave = vistaDe(fuente, filtros) === 'miembros' ? 'miembros' : 'organizaciones';
    const local = UNIDADES[clave] ?? GENERICA;
    const b = fuente?.unidad && (fuente.clave !== 'empresarios' || clave === 'organizaciones') ? fuente.unidad : null;
    const singular = b?.singular || local.singular;
    const plural = b?.plural || local.plural;
    const femenino = typeof b?.femenino === 'boolean' ? b.femenino : local.femenino;
    return {
        singular,
        plural,
        femenino,
        todas: b?.todos || `${femenino ? 'todas las' : 'todos los'} ${plural}`,
        ninguna: clave === 'personas' ? 'Nadie' : `${femenino ? 'Ninguna' : 'Ningún'} ${singular}`,
        a: local.gente ? 'a ' : '',
        quienes: local.gente ? 'A quiénes' : 'Cuáles',
    };
}

export const cantidad = (n, unidad) => `${numero(n)} ${Number(n) === 1 ? unidad.singular : unidad.plural}`;
export const mayuscula = (t) => (t ? t[0].toUpperCase() + t.slice(1) : t);

/* ── The phone card of a row ── */

// subtitulo: the cells under the name, joined by " · "; destacadas: data shown first (when chosen);
// rotulos: a clearer label than the column's.
const TARJETAS = {
    personas: { subtitulo: ['municipio', 'tipo'] },
    asistencias: { subtitulo: ['evento', 'fecha'], destacadas: ['municipio'], rotulos: { municipio: 'Municipio del evento' } },
    compromisos: { subtitulo: ['estado', 'fecha_limite'], destacadas: ['origen_nombre', 'responsables'], rotulos: { responsables: 'Responsable' } },
    empresarios: { subtitulo: ['tipo', 'municipio'] },
};
const MAX_DATOS = 3;

const conValor = (v) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0);

// {titulo, subtitulo: [valores], datos: [{clave, etiqueta, valor}]} for the card of `fila` with the chosen `columnas`.
export function tarjetaDe(fuenteClave, fila, columnas) {
    const c = fila?.celdas ?? {};
    const conf = TARJETAS[fuenteClave] ?? { subtitulo: ['municipio', 'tipo'] };
    const titulo = 'nombre' in c ? 'nombre' : (columnas[0]?.clave ?? 'nombre');
    const subtitulo = conf.subtitulo.filter(k => k !== titulo && conValor(c[k]));
    const usadas = new Set([titulo, ...conf.subtitulo]);
    // A compromiso's origen goes as the label of its name: "Evento: Foro" / "Organización: Café del Sur".
    if (fuenteClave === 'compromisos' && conValor(c.origen) && columnas.some(col => col.clave === 'origen_nombre')) usadas.add('origen');
    const orden = [
        ...(conf.destacadas ?? []).map(k => columnas.find(col => col.clave === k)).filter(Boolean),
        ...columnas.filter(col => !(conf.destacadas ?? []).includes(col.clave)),
    ];
    const datos = orden
        .filter(col => !usadas.has(col.clave))
        .slice(0, MAX_DATOS)
        .map(col => {
            let etiqueta = conf.rotulos?.[col.clave] ?? col.etiqueta;
            if (fuenteClave === 'compromisos' && col.clave === 'origen_nombre' && conValor(c.origen)) etiqueta = String(c.origen);
            return { clave: col.clave, etiqueta, valor: c[col.clave] };
        });
    return { titulo, subtitulo: subtitulo.map(k => c[k]), datos };
}

/* ── What tapping a row does ── */

// A same-site path only: never another host ("//x", "/\x", "https://x") or a script ("javascript:").
const rutaInterna = (u) => typeof u === 'string' && /^\/(?![/\\])/.test(u) && !/[\s<>"\\]/.test(u);

// {tipo:'ficha', id, editable} | {tipo:'compromiso', url} | {tipo:'organizacion', id} | null. A row without the key
// (Personas before B-3) opens its own ficha.
export function accionDe(fila) {
    if (!fila || typeof fila !== 'object') return null;
    if (!('accion' in fila)) {
        return fila.id !== undefined && fila.id !== null && fila.id !== '' ? { tipo: 'ficha', id: fila.id, editable: fila.editable !== false } : null;
    }
    const a = fila.accion;
    if (!a || typeof a !== 'object') return null;
    const id = a.id === undefined || a.id === null || a.id === '' ? null : a.id;
    if (a.tipo === 'ficha' && id !== null) return { tipo: 'ficha', id, editable: (a.editable ?? fila.editable) !== false };
    if (a.tipo === 'organizacion' && id !== null) return { tipo: 'organizacion', id };
    if (a.tipo === 'compromiso' && rutaInterna(a.url)) return { tipo: 'compromiso', url: a.url };
    return null;
}

export function mensajeError(error, accion = 'consulta', formato = 'excel') {
    const status = error?.status ?? 0;
    if (status === 401 || status === 419) return 'La página estuvo abierta mucho tiempo. Recárgala e intenta de nuevo.';
    if (accion === 'descarga') {
        if (status === 429) {
            const motivo = error?.data?.motivo;
            if (motivo === 'por_hora' && error?.data?.message) return error.data.message;
            const enCurso = motivo === 'en_curso' || /preparando|en curso/i.test(error?.data?.message ?? '');
            return enCurso
                ? 'Ya estamos preparando una descarga. Espera a que termine.'
                : 'Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.';
        }
        if (status === 403) return 'No tienes permiso para descargar estos datos.';
        if (status === 422) return 'No se pudo armar el archivo con estos datos. Revisa los datos elegidos e intenta de nuevo.';
        if (status >= 500) {
            return formato === 'pdf'
                ? 'No pudimos preparar el archivo. Prueba con menos filas o usa el Excel.'
                : 'No pudimos preparar el archivo. Prueba con menos filas.';
        }
        return 'No se pudo descargar. Revisa tu conexión e intenta de nuevo.';
    }
    if (status === 403) return 'No tienes permiso para ver estos datos. Pídeselo a tu administrador.';
    if (status === 422) return 'Algo en lo que elegiste no es válido. Quita los filtros y vuelve a intentarlo.';
    if (status === 429) return 'Vas muy rápido. Espera un momento y vuelve a intentarlo.';
    return accion === 'conteo' ? 'No pudimos contar. Revisa tu conexión.' : 'No pudimos cargar la lista.';
}

/* ── Requests ── */

async function postJson(url, body, signal) {
    let res;
    try {
        res = await apiFetch(url, { method: 'POST', body: JSON.stringify(body), signal });
    } catch (e) {
        if (e?.name === 'AbortError') throw e;
        throw { status: 0 };
    }
    const data = await res.json().catch(() => null);
    if (!res.ok || data?.success === false) throw { status: res.ok ? 500 : res.status, data };
    return data ?? {};
}

export const pedirConteo = (spec, signal) => postJson(`/reportes/${encodeURIComponent(spec.fuente)}/conteo`, { spec }, signal)
    .then(d => ({ total: Number(d.total) || 0 }));

export const pedirLista = (spec, pagina, signal) => postJson(`/reportes/${encodeURIComponent(spec.fuente)}/lista`, { spec, pagina }, signal)
    .then(d => ({
        total: Number(d.total) || 0,
        pagina: Number(d.pagina) || pagina,
        porPagina: Number(d.por_pagina) || 50,
        filas: Array.isArray(d.filas) ? d.filas : [],
    }));

export async function pedirOpciones(filtro, q, municipios, signal, fuente = '') {
    const params = new URLSearchParams();
    if (fuente) params.set('fuente', fuente);
    params.set('q', q);
    for (const m of municipios ?? []) params.append('municipios[]', m);
    let res;
    try {
        res = await apiFetch(`/reportes/opciones/${encodeURIComponent(filtro)}?${params}`, { signal });
    } catch (e) {
        if (e?.name === 'AbortError') throw e;
        throw { status: 0 };
    }
    const data = await res.json().catch(() => null);
    if (!res.ok) throw { status: res.status, data };
    const lista = Array.isArray(data) ? data : (data?.opciones ?? data?.data ?? []);
    return lista.map(normalizarOpcion).slice(0, 50);
}

export function nombreArchivo(contentDisposition, fallback) {
    const cd = texto(contentDisposition);
    const utf = cd.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf) {
        try { return decodeURIComponent(utf[1].replace(/"/g, '')); } catch { /* falls through to the plain filename */ }
    }
    const plain = cd.match(/filename="?([^";]+)"?/i);
    return plain ? plain[1] : fallback;
}

export const FORMATOS = {
    excel: { ruta: 'excel', extension: 'xlsx', mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
    pdf: { ruta: 'pdf', extension: 'pdf', mime: 'application/pdf' },
};

// The PDF only fits this many columns per person (contract B-2); the server keeps the first ones of the spec.
export const PDF_MAX_COLUMNAS = 8;

const paraArchivo = (t) => String(t).replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_|_$/g, '');

export function nombreSugerido(chips, fecha = new Date(), extension = 'xlsx', base = 'Personas') {
    const partes = chips.slice(0, 3).map(c => paraArchivo(c.texto)).filter(Boolean);
    const dia = new Intl.DateTimeFormat('en-CA', { timeZone: ZONA }).format(fecha);
    return `Reporte_${partes.length ? partes.join('_') : (paraArchivo(base) || 'Personas')}_${dia}.${extension}`;
}

export const descargarExcel = (spec) => descargarArchivo(spec, 'excel');
export const descargarPdf = (spec) => descargarArchivo(spec, 'pdf');

export async function descargarArchivo(spec, formato) {
    const f = FORMATOS[formato];
    let res;
    try {
        res = await apiFetch(`/reportes/${encodeURIComponent(spec.fuente)}/${f.ruta}`, {
            method: 'POST',
            body: JSON.stringify({ spec }),
            headers: { Accept: `${f.mime}, application/json` },
        });
    } catch {
        throw { status: 0 };
    }
    const tipo = res.headers.get('content-type') || '';
    if (!res.ok || tipo.includes('json') || tipo.includes('html')) {
        const data = await res.json().catch(() => null);
        throw { status: res.ok ? 500 : res.status, data };
    }
    let blob;
    try {
        blob = await res.blob();
    } catch {
        throw { status: 0 };
    }
    return { blob, disposition: res.headers.get('content-disposition') };
}

// Reportes downloads with POST (the spec is the body): there is no URL the browser could open instead, so the
// installed-app fallback of the GET downloads (lib/descargaArchivo) does not apply here.
export { guardarBlob } from '@/lib/descargaArchivo';

/* ── Hooks ── */

// Runs `fn(signal)` DEBOUNCE_MS after `key` stops changing; a newer key aborts the request in flight.
export function useDebouncedRequest(fn, key, { enabled = true, delay = DEBOUNCE_MS } = {}) {
    const [state, setState] = useState({ estado: 'idle', datos: null, error: null });
    const [intento, setIntento] = useState(0);
    const fnRef = useRef(fn);
    const delayRef = useRef(delay);
    fnRef.current = fn;
    // Read when the key changes, so a different delay alone never triggers a request.
    delayRef.current = delay;

    useEffect(() => {
        if (!enabled) return undefined;
        const control = new AbortController();
        setState(s => ({ ...s, estado: 'cargando', error: null }));
        const timer = setTimeout(() => {
            fnRef.current(control.signal).then(
                datos => { if (!control.signal.aborted) setState({ estado: 'ok', datos, error: null }); },
                error => {
                    if (control.signal.aborted || error?.name === 'AbortError') return;
                    setState(s => ({ ...s, estado: 'error', error }));
                },
            );
        }, delayRef.current);
        return () => { clearTimeout(timer); control.abort(); };
    }, [key, enabled, intento]);

    return { ...state, reintentar: () => setIntento(i => i + 1) };
}
