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

export function jsonResponse(body, status = 200, headers = {}) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } }));
}
