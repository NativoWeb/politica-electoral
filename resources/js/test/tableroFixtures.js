// The tablero contract of docs/campanas-electorales.md (Fase 5), for the Tablero tests and the local browser check.

const medida = (registrados, meta, extra = {}) => ({
    registrados,
    meta,
    meta_calculada: false,
    pct: meta === null ? null : Math.round((registrados / meta) * 10000) / 10000,
    falta: meta === null ? null : Math.max(meta - registrados, 0),
    ...extra,
});

export const IDS = {
    giron: '11111111-1111-4111-8111-111111111111',
    lebrija: '22222222-2222-4222-8222-222222222222',
    bga: '33333333-3333-4333-8333-333333333333',
    piede: '44444444-4444-4444-8444-444444444444',
    bogota: '55555555-5555-4555-8555-555555555555',
    c01: '66666666-6666-4666-8666-666666666666',
    c02: '77777777-7777-4777-8777-777777777777',
    flori: '88888888-8888-4888-8888-888888888888',
    campana: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
};

export function campanaRaw(over = {}) {
    return {
        id: IDS.campana, name: 'Gobernación 2027', cargo: 'gobernacion', cargo_label: 'Gobernación', election_year: 2027,
        election_date: '2027-10-31', status: 'active', candidato: { person_id: null, nombre: 'Ana Ruiz' },
        partido: { id: 'cd', nombre: 'Centro Democrático' }, scope_level: 'department',
        territorio: { resumen: 'Todo Santander', unidades: [] }, metas: { cargadas: 2, total: 87 },
        factores: { factor_votos: 1.1, factor_registro: 1, votos_por_lider: 50 }, grupo: { id: 'g', nombre: 'Centro Democrático', propio: true },
        solo_lectura: false, ...over,
    };
}

export const GIRON = {
    id: IDS.giron, nombre: 'Girón', tipo: 'municipality', padre_id: null, codigo: '68307',
    votantes: medida(50, 100), lideres: medida(5, 10), indice: 50, tramo: { indice: 2, votantes: 2, lideres: 2 }, estado: 'ok', motivo: null,
};
export const LEBRIJA = {
    id: IDS.lebrija, nombre: 'Lebrija', tipo: 'municipality', padre_id: null, codigo: '68406',
    votantes: medida(220, 200, { meta_calculada: true }), lideres: medida(0, 4, { meta_calculada: true }),
    indice: 60, tramo: { indice: 2, votantes: 4, lideres: 0 }, estado: 'ok', motivo: null,
};
export const BGA = {
    id: IDS.bga, nombre: 'Bucaramanga', tipo: 'municipality', padre_id: null, codigo: '68001',
    votantes: medida(100, null), lideres: medida(0, null), indice: null, tramo: { indice: null, votantes: null, lideres: null },
    estado: 'sin_meta', motivo: null, cobertura_comuna: { con: 50, total: 100 },
};
export const C01 = {
    id: IDS.c01, nombre: 'Comuna 1 - Norte', tipo: 'comuna', padre_id: IDS.bga, codigo: '68001-C01',
    votantes: medida(40, 100), lideres: medida(0, null), indice: 40, tramo: { indice: 1, votantes: 1, lideres: null },
    estado: 'insuficiente', motivo: 'Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna',
    por_origen: { auto: 30, texto: null, direccion: 0, puesto: null, manual: null },
};
export const C02 = {
    ...C01, id: IDS.c02, nombre: 'Comuna 2 - Nororiental', codigo: '68001-C02', votantes: medida(10, null), indice: null,
    tramo: { indice: null, votantes: null, lideres: null }, estado: 'insuficiente', por_origen: { auto: 10, texto: null, direccion: 0, puesto: 0, manual: null },
};
export const PIEDE = {
    id: IDS.piede, nombre: 'Piedecuesta', tipo: 'municipality', padre_id: null, codigo: '68547',
    votantes: medida(3, null), lideres: medida(0, null), indice: null, tramo: { indice: null, votantes: null, lideres: null }, estado: 'sin_meta', motivo: null,
};
export const BOGOTA = {
    id: IDS.bogota, nombre: 'Bogota', tipo: 'municipality', padre_id: null, codigo: '11001',
    votantes: medida(2, null), lideres: medida(0, null), indice: null, tramo: { indice: null, votantes: null, lideres: null }, estado: 'sin_meta', motivo: null,
};

export function tableroData(over = {}) {
    return {
        campana: campanaRaw(),
        soloLectura: null,
        can: { edit: true },
        generado_at: '2026-11-25T10:05:00-05:00',
        formula: { texto: 'El índice va de 0 a 100: el 60 % sale del avance en votantes registrados y el 40 % del avance en líderes. Pasar la meta no suma más.', peso_votantes: 0.6, peso_lideres: 0.4 },
        umbrales: { k: 5, cobertura_comuna_min: 0.3, ranking_min_votantes: 20, ranking_min_lideres: 3, pocos_registros: 50 },
        totales: {
            votantes: { registrados: 375, registrados_con_meta: 270, meta: 300, meta_calculada: true, pct: 0.9, falta: 30 },
            lideres: { registrados: 5, registrados_con_meta: 5, meta: 14, meta_calculada: true, pct: 0.3571, falta: 9 },
            indice: 68,
            territorios: { total: 5, con_meta: 2, sin_meta: 3, insuficientes: 2 },
        },
        territorios: [GIRON, LEBRIJA, BGA, C01, C02, PIEDE, BOGOTA],
        ranking: { mejores: [IDS.lebrija, IDS.giron], por_apoyar: [], una_lista: true, nota: 'Las metas muy pequeñas no entran al ranking' },
        evolucion: {
            desde: '2025-09-22', semanas_defecto: 26, antes: 90394,
            antes_texto: '90.394 ya estaban registrados antes del 22 sep 2025 (carga inicial del sistema anterior)',
            masiva_texto: 'Incluye un día con más de 300 registros (posible carga de archivo)',
            semanas: [
                { inicio: '2026-11-02', nuevos: 812, masiva: true },
                { inicio: '2026-11-09', nuevos: 120, masiva: false },
                { inicio: '2026-11-16', nuevos: 1, masiva: false },
            ],
            promedio_4: 150,
            ritmo_necesario: { semanas: 49, por_semana: 1, texto: 'Faltan 49 semanas para el 31 oct 2027: hay que registrar 1 por semana. Las últimas 4 semanas: 150 por semana.' },
        },
        avisos: [
            { tipo: 'sin_metas', texto: '3 de 5 territorios no tienen meta: aparecen en gris.' },
            { tipo: 'sin_lideres', texto: 'Aún no hay fichas marcadas como líder. Márcalas con la casilla "Es líder" en la ficha. Mientras tanto, el índice llega como máximo a 60.' },
            { tipo: 'cobertura_comuna', texto: 'Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna: las comunas se ven rayadas hasta que haya más datos.' },
        ],
        mapa: { capa: 'municipios', con_comunas: [{ id: IDS.bga, codigo: '68001', nombre: 'Bucaramanga' }], sin_poligono: ['Bogota'], texto: null },
        ...over,
    };
}

const cuadro = (x, y) => ({ type: 'Polygon', coordinates: [[[x, y], [x + 0.1, y], [x + 0.1, y + 0.1], [x, y + 0.1], [x, y]]] });
const feature = (id, nombre, codigo, x, y) => ({ type: 'Feature', id, properties: { id, nombre, official_code: codigo }, geometry: cuadro(x, y) });

// Floridablanca is not in the campaign rows: it is drawn white with a dotted border. Bogota has no polygon.
export const GEO_MUNICIPIOS = {
    type: 'FeatureCollection',
    features: [
        feature(IDS.bga, 'Bucaramanga', '68001', -73.1, 7.1),
        feature(IDS.giron, 'Girón', '68307', -73.2, 7.0),
        feature(IDS.lebrija, 'Lebrija', '68406', -73.3, 7.1),
        feature(IDS.piede, 'Piedecuesta', '68547', -73.0, 6.9),
        feature(IDS.flori, 'Floridablanca', '68276', -73.0, 7.0),
    ],
};

export const GEO_COMUNAS_BGA = {
    type: 'FeatureCollection',
    features: [
        { ...feature(IDS.c01, 'Comuna 1 - Norte', '68001-C01', -73.12, 7.15), properties: { id: IDS.c01, nombre: 'Comuna 1 - Norte', codigo: '68001-C01', tipo: 'comuna', municipio_id: IDS.bga } },
        { ...feature(IDS.c02, 'Comuna 2 - Nororiental', '68001-C02', -73.1, 7.14), properties: { id: IDS.c02, nombre: 'Comuna 2 - Nororiental', codigo: '68001-C02', tipo: 'comuna', municipio_id: IDS.bga } },
    ],
};

export function detalleTerritorio(fila, over = {}) {
    return {
        territorio: fila,
        semanas: [{ inicio: '2026-11-09', nuevos: 12, masiva: false }, { inicio: '2026-11-16', nuevos: null, masiva: false }],
        antes: null,
        pocos_registros: fila.votantes.registrados < 50,
        pocos_registros_texto: 'Muy pocos registros para ver la evolución',
        por_puesto_texto: null,
        comunas: [],
        ...over,
    };
}
