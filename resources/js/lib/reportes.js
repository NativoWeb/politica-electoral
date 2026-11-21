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

// Internal kinds: chips (several, "O"), unico (one or none), buscar, rango, interruptor (on/off).
// The backend sends chips+multiple:false for "unico" and "casilla" for "interruptor" (Catalogo/Filtro.php).
function tipoDe(f) {
    if (f.tipo === 'casilla' || f.tipo === 'interruptor') return 'interruptor';
    if (f.tipo === 'chips' && f.multiple === false) return 'unico';
    if (['chips', 'unico', 'buscar', 'rango'].includes(f.tipo)) return f.tipo;
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
    const remoto = tipo === 'buscar' && (!!opcionesDe || crudas.length === 0);
    return {
        clave: texto(f.clave ?? f.key),
        etiqueta: texto(f.etiqueta ?? f.label),
        grupo: texto(grupo ?? f.grupo ?? 'Otros filtros'),
        tipo,
        opciones: tipo === 'rango' || tipo === 'interruptor' || remoto ? null : crudas.map(normalizarOpcion),
        // Remote search: GET /reportes/opciones/{opciones_de ?? clave}.
        remoto,
        opcionesDe: texto(opcionesDe ?? f.clave ?? f.key),
        placeholder: texto(f.placeholder) || `Buscar ${texto(f.etiqueta ?? f.label).toLowerCase()}…`,
        ayuda: texto(f.ayuda),
        // Comuna arrives as chips whose options carry the municipio they belong to.
        dependeDe: f.depende_de ?? f.dependeDe
            ?? ((f.clave ?? f.key) !== 'municipio' && crudas.length > 0 && crudas.every(o => o && typeof o === 'object' && 'municipio' in o) ? 'municipio' : null),
        rangos,
        unidad: texto(f.unidad),
        // "unico": the option that applies when nothing is chosen (e.g. Estado → Activas). Choosing it clears the filter.
        porDefecto: f.por_defecto === undefined || f.por_defecto === null || typeof f.por_defecto === 'object' ? null : texto(f.por_defecto),
    };
}

function normalizarColumna(c) {
    return {
        clave: texto(c.clave ?? c.key),
        etiqueta: texto(c.etiqueta ?? c.label),
        grupo: texto(c.grupo ?? 'Otros datos'),
        sensible: !!c.sensible,
        siempre: !!(c.siempre ?? c.fija),
        ordenable: !!c.ordenable,
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
    const secciones = seccionesDe(raw).map(s => ({ ...s, filtros: s.filtros.filter(f => f.clave) })).filter(s => s.filtros.length);
    if (secciones.length && !secciones.some(s => s.abierto)) secciones[0].abierto = true;
    const columnas = (Array.isArray(raw.columnas) ? raw.columnas : Object.values(raw.columnas ?? {})).map(normalizarColumna).filter(c => c.clave);
    return {
        clave: texto(raw.clave ?? raw.key),
        etiqueta: texto(raw.etiqueta ?? raw.label),
        descripcion: texto(raw.descripcion),
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
        if (!filtroVacio(v)) out[clave] = v;
    }
    return out;
}

/* ── Columns ── */

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
    return {
        fuente,
        paso,
        filtros: limpiarFiltros(parseJson(query.get('f'), {}), fuente),
        columnas: fuente
            ? (columnasUrl === null ? columnasPorDefecto(fuente, puedeDatosPersonales) : limpiarColumnas(columnasUrl.split(','), fuente, puedeDatosPersonales))
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
export function textoExcluidos(filtros, fuente) {
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

export function etiquetaValor(filtro, valor, etiquetas = {}) {
    const op = filtro.opciones?.find(o => mismoValor(o.valor, valor));
    return op?.etiqueta ?? etiquetas[String(valor)] ?? String(valor);
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
            for (const v of valor) chips.push({ id: `${clave}:${v}`, clave, valor: v, texto: etiquetaValor(f, v, etiquetas) });
        } else if (f.tipo === 'rango') {
            chips.push({ id: clave, clave, texto: `${f.etiqueta}: ${textoRango(valor, f.unidad || 'años')}` });
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
    return conFiltro(filtros, chip.clave, Array.isArray(valor) ? valor.filter(v => v !== chip.valor) : null);
}

/* ── Numbers and messages ── */

export const numero = (n) => (Number(n) || 0).toLocaleString('es-CO');
export const personas = (n) => `${numero(n)} ${Number(n) === 1 ? 'persona' : 'personas'}`;

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

export async function pedirOpciones(filtro, q, municipios, signal) {
    const params = new URLSearchParams();
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

export function nombreSugerido(chips, fecha = new Date(), extension = 'xlsx') {
    const partes = chips.slice(0, 3).map(c => c.texto.replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_|_$/g, '')).filter(Boolean);
    const dia = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(fecha);
    return `Reporte_${partes.length ? partes.join('_') : 'Personas'}_${dia}.${extension}`;
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

export function guardarBlob(blob, nombre) {
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    a.remove();
    // iOS (PWA) lee el blob tarde: se libera al minuto, no al segundo.
    setTimeout(() => URL.revokeObjectURL(href), 60000);
}

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
