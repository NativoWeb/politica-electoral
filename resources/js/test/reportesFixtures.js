// A "personas" source in the shape the backend sends (app/Services/Reportes: Fuente::filtros() sections,
// Filtro::paraFrontend(), Columna::paraFrontend()).
export const FUENTE_PERSONAS = {
    clave: 'personas',
    etiqueta: 'Personas',
    descripcion: 'Votantes, líderes, directorio y todos los que tienen ficha',
    total: 12084,
    filtros: [
        {
            clave: 'donde', etiqueta: '¿Dónde viven?', abierto: true, filtros: [
                { clave: 'municipio', etiqueta: 'Municipio', tipo: 'chips', multiple: true, opciones: [
                    { valor: 'm-bga', etiqueta: 'Bucaramanga', provincia: 'metro' }, { valor: 'm-giron', etiqueta: 'Girón', provincia: 'metro' },
                ] },
                { clave: 'comuna', etiqueta: 'Comuna', tipo: 'chips', multiple: true, opciones: [
                    { valor: 'c1', etiqueta: 'Comuna 1 Norte', municipio: 'm-bga' },
                ] },
                { clave: 'barrio', etiqueta: 'Barrio', tipo: 'buscar', multiple: true, opciones: [], opciones_de: 'barrios', placeholder: 'Buscar barrio…' },
            ],
        },
        {
            clave: 'que', etiqueta: '¿Qué son?', abierto: false, filtros: [
                { clave: 'marcas', etiqueta: 'Marcas', tipo: 'chips', multiple: true, opciones: [
                    { valor: 'gran_elector', etiqueta: 'Gran elector' }, { valor: 'militante', etiqueta: 'Militante' },
                ] },
            ],
        },
        {
            clave: 'edad', etiqueta: 'Edad y datos personales', abierto: false, filtros: [
                { clave: 'edad', etiqueta: 'Edad', tipo: 'rango', multiple: true, opciones: [
                    { etiqueta: '18 a 25', valor: { desde: 18, hasta: 25 } }, { etiqueta: 'Más de 60', valor: { desde: 61, hasta: null } },
                ], ayuda: 'Solo cuenta a quienes tienen fecha de nacimiento.' },
            ],
        },
        {
            clave: 'estado', etiqueta: 'Autorización y estado', abierto: false, filtros: [
                { clave: 'estado', etiqueta: 'Estado', tipo: 'chips', multiple: false, por_defecto: 'activas', opciones: [
                    { valor: 'activas', etiqueta: 'Activas' }, { valor: 'inactivas', etiqueta: 'Inactivas' }, { valor: 'todas', etiqueta: 'Todas' },
                ] },
                { clave: 'incluir_fallecidos', etiqueta: 'Incluir fallecidos', tipo: 'casilla', multiple: false, opciones: [] },
                { clave: 'eventos_min', etiqueta: '¿A cuántos eventos?', tipo: 'chips', multiple: false, opciones: [
                    { valor: 1, etiqueta: 'A cualquier evento' }, { valor: 2, etiqueta: 'A 2 o más' },
                ] },
            ],
        },
    ],
    columnas: [
        { clave: 'nombre', etiqueta: 'Nombre', grupo: 'Identificación', sensible: false, siempre: true },
        { clave: 'cedula', etiqueta: 'Cédula', grupo: 'Identificación', sensible: true, siempre: false },
        { clave: 'telefono', etiqueta: 'Teléfono', grupo: 'Contacto', sensible: true, siempre: false },
        { clave: 'municipio', etiqueta: 'Municipio', grupo: 'Ubicación', sensible: false, siempre: false },
        { clave: 'barrio', etiqueta: 'Barrio', grupo: 'Ubicación', sensible: false, siempre: false },
        { clave: 'tipo', etiqueta: 'Tipo', grupo: 'En la campaña', sensible: false, siempre: false },
    ],
    presets: [
        { clave: 'contacto', etiqueta: 'Contacto básico', descripcion: '', columnas: ['nombre', 'cedula', 'telefono', 'municipio', 'barrio'] },
        { clave: 'lugar', etiqueta: 'Solo nombre y lugar', descripcion: '', columnas: ['nombre', 'municipio', 'barrio', 'tipo'] },
    ],
    ordenes: [
        { clave: 'nombre', dir: 'asc', etiqueta: 'Nombre A–Z' }, { clave: 'municipio', dir: 'asc', etiqueta: 'Municipio' },
    ],
    atajos: [
        { clave: 'gran_elector', etiqueta: 'Grandes electores', descripcion: '', spec: { v: 1, fuente: 'personas', filtros: { marcas: ['gran_elector'] } } },
        { clave: 'asistentes', etiqueta: 'Asistentes a eventos', spec: { v: 1, fuente: 'personas', filtros: { eventos_min: 1 } } },
    ],
};

export function filasDe(n, desde = 0) {
    return Array.from({ length: n }, (_, i) => ({
        id: `p${desde + i}`,
        editable: true,
        celdas: { nombre: `Persona ${desde + i}`, municipio: 'Bucaramanga', barrio: 'Centro', tipo: 'Votante' },
    }));
}

// B-3 sources, in the shape of docs/reportes.md (synthetic data).
export const FUENTE_ASISTENCIAS = {
    clave: 'asistencias',
    etiqueta: 'Eventos y asistencia',
    descripcion: 'Quiénes fueron a cada evento',
    total: 320,
    unidad: { singular: 'asistente', plural: 'asistentes', todos: 'todos los asistentes', femenino: false },
    filtros: [
        {
            clave: 'evento', etiqueta: '¿Qué evento?', abierto: true, filtros: [
                { clave: 'evento', etiqueta: 'Evento', tipo: 'buscar', multiple: true, opciones: [], opciones_de: 'eventos' },
                { clave: 'fecha', etiqueta: 'Fecha del evento', tipo: 'fechas', multiple: false, opciones: [
                    { valor: { relativo: 'ultimos_7' }, etiqueta: 'Última semana' }, { valor: { relativo: 'ultimos_30' }, etiqueta: 'Último mes' },
                ] },
            ],
        },
        {
            clave: 'ficha', etiqueta: 'Ficha', abierto: false, filtros: [
                { clave: 'sin_ficha', etiqueta: 'Sin ficha', tipo: 'casilla', multiple: false, opciones: [] },
            ],
        },
    ],
    columnas: [
        { clave: 'nombre', etiqueta: 'Persona', grupo: 'Asistente', sensible: false, siempre: true },
        { clave: 'cedula', etiqueta: 'Cédula', grupo: 'Asistente', sensible: true, siempre: false },
        { clave: 'evento', etiqueta: 'Evento', grupo: 'Evento', sensible: false, siempre: false },
        { clave: 'fecha', etiqueta: 'Fecha', grupo: 'Evento', sensible: false, siempre: false },
    ],
    presets: [{ clave: 'basico', etiqueta: 'Básico', columnas: ['nombre', 'evento', 'fecha'], por_defecto: true }],
    ordenes: [{ clave: 'fecha', dir: 'desc', etiqueta: 'Más recientes' }, { clave: 'nombre', dir: 'asc', etiqueta: 'Nombre A–Z' }],
    atajos: [
        { clave: 'ultimo_mes', etiqueta: 'Asistentes del último mes', spec: { v: 1, fuente: 'asistencias', filtros: { fecha: { relativo: 'ultimos_30' } } } },
        { clave: 'sin_ficha', etiqueta: 'Asistentes sin ficha', spec: { v: 1, fuente: 'asistencias', filtros: { sin_ficha: true } } },
    ],
};

export const FUENTE_COMPROMISOS = {
    clave: 'compromisos',
    etiqueta: 'Compromisos y actas',
    descripcion: 'Lo que se prometió en eventos y reuniones',
    total: 41,
    unidad: { singular: 'compromiso', plural: 'compromisos', todos: 'todos los compromisos', femenino: false },
    filtros: [
        {
            clave: 'estado', etiqueta: 'Estado', abierto: true, filtros: [
                { clave: 'estado', etiqueta: 'Estado', tipo: 'chips', multiple: true, opciones: [
                    { valor: 'pendiente', etiqueta: 'Pendiente' }, { valor: 'cumplido', etiqueta: 'Cumplido' },
                ] },
                { clave: 'fecha_limite', etiqueta: 'Fecha límite', tipo: 'fechas', multiple: false, opciones: [
                    { valor: { relativo: 'proximos_7' }, etiqueta: 'Próximos 7 días' },
                ] },
            ],
        },
    ],
    columnas: [
        { clave: 'descripcion', etiqueta: 'Compromiso', grupo: 'Compromiso', sensible: false, siempre: true },
        { clave: 'estado', etiqueta: 'Estado', grupo: 'Compromiso', sensible: false, siempre: false },
        { clave: 'fecha_limite', etiqueta: 'Fecha límite', grupo: 'Compromiso', sensible: false, siempre: false },
    ],
    presets: [],
    ordenes: [{ clave: 'fecha_limite', dir: 'asc', etiqueta: 'Fecha límite' }],
    atajos: [
        { clave: 'vencidos', etiqueta: 'Compromisos vencidos', spec: { v: 1, fuente: 'compromisos', filtros: { estado: ['pendiente'] } } },
    ],
};

export const FUENTE_EMPRESARIOS = {
    clave: 'empresarios',
    etiqueta: 'Empresarios y gremios',
    descripcion: 'Empresas, gremios y sus miembros',
    total: 15,
    unidad: { singular: 'organización', plural: 'organizaciones', todos: 'todas las organizaciones', femenino: true },
    filtros: [
        {
            clave: 'vista', etiqueta: '¿Qué quieres listar?', abierto: true, filtros: [
                { clave: 'vista', etiqueta: 'Listar', tipo: 'chips', multiple: false, obligatorio: true, por_defecto: 'organizaciones', opciones: [
                    { valor: 'organizaciones', etiqueta: 'Organizaciones' }, { valor: 'miembros', etiqueta: 'Miembros' },
                ] },
                { clave: 'tipo', etiqueta: 'Tipo', tipo: 'chips', multiple: true, opciones: [
                    { valor: 'empresa', etiqueta: 'Empresa' }, { valor: 'gremio', etiqueta: 'Gremio' },
                ] },
            ],
        },
    ],
    columnas: [
        { clave: 'nombre', etiqueta: 'Nombre', grupo: 'Organización', sensible: false, siempre: true },
        { clave: 'nit', etiqueta: 'NIT', grupo: 'Organización', sensible: false, siempre: false, vistas: ['organizaciones'] },
        { clave: 'correo', etiqueta: 'Correo', grupo: 'Organización', sensible: true, siempre: false, vistas: ['organizaciones'] },
        { clave: 'organizacion', etiqueta: 'Organización', grupo: 'Miembro', sensible: false, siempre: false, vistas: ['miembros'] },
        { clave: 'rol', etiqueta: 'Rol', grupo: 'Miembro', sensible: false, siempre: false, vistas: ['miembros'] },
    ],
    presets: [
        { clave: 'org', etiqueta: 'Organizaciones', columnas: ['nombre', 'nit'], por_defecto: true, vista: 'organizaciones' },
        { clave: 'miem', etiqueta: 'Miembros', columnas: ['nombre', 'organizacion', 'rol'], por_defecto: true, vista: 'miembros' },
    ],
    ordenes: [{ clave: 'nombre', dir: 'asc', etiqueta: 'Nombre A–Z' }],
    atajos: [
        { clave: 'miembros', etiqueta: 'Miembros de organizaciones', spec: { v: 1, fuente: 'empresarios', filtros: { vista: 'miembros' } } },
    ],
};

export function jsonResponse(body, status = 200, headers = {}) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } }));
}
