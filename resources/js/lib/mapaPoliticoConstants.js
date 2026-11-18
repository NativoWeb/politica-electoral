// Fichas created from event attendees: their own tipo, off by default ("Todos" does not include it).
export const ASISTENTES_EVENTO = 'asistentes_evento';
export const ASISTENTES_EVENTO_LABEL = 'Asistentes a eventos';
// Voters (lideres.tipo = votante): counted by the server, their rows load by pages ("Ver más").
export const VOTANTES = 'votantes';
export const VOTANTES_LABEL = 'Votantes';

export const TIPO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'alcaldia', label: 'Alcaldía' },
    { value: 'concejo', label: 'Concejo' },
    { value: 'lideres', label: 'Líderes' },
    { value: 'nuevos_lideres', label: 'Nuevos Líderes' },
    { value: VOTANTES, label: VOTANTES_LABEL },

    { value: 'senado', label: 'Senado' },
    { value: 'camara', label: 'Cámara' },
    { value: 'asamblea', label: 'Asamblea' },
    { value: ASISTENTES_EVENTO, label: ASISTENTES_EVENTO_LABEL },
];

// The attendee tipo exists only for who may see event attendees; anyone else sees those fichas as Líderes.
export function tipoOptionsFor(canSeeEventos) {
    return canSeeEventos ? TIPO_OPTIONS : TIPO_OPTIONS.filter(o => o.value !== ASISTENTES_EVENTO);
}

// Every tipo checked is the same as "Todos" (plus the event attendees): the page then groups rows in sections.
export function coversAllTipos(tipoFilter, options = TIPO_OPTIONS) {
    if (!tipoFilter || tipoFilter === 'todos') return true;
    const selected = new Set(tipoFilter.split(','));

    return options.filter(o => o.value !== 'todos').every(o => selected.has(o.value));
}

export const CARGOS_DISPONIBLES = [
    'Líder',
    'Concejal',
    'Directorio Municipal',
    'Frentes de Seguridad',
    'Reservistas',
    'JAC',
    'Ediles',
    'Comunidades Religiosas',
    'Candidato Concejo',
    'Candidato Asamblea',
    'Candidato Alcaldía',
    'Empresarios',
    'Juventudes',
    'Mujeres',
    'Coordinador Municipal',
    'Coordinador Provincia',
    'Funcionario',
];

export const PARENTESCOS = ['Esposa', 'Esposo', 'Hijo/a', 'Hermano/a', 'Padre', 'Madre', 'Sobrino/a', 'Tío/a', 'Primo/a', 'Cuñado/a', 'Suegro/a', 'Otro'];

export const AREA_METROPOLITANA = ['Bucaramanga', 'Floridablanca', 'Piedecuesta', 'Girón', 'Rionegro', 'Lebrija'];

// The national catalog repeats names (Rionegro, Barbosa, La Paz...): a municipio of another departamento says which.
export function isSantanderMunicipio(m) {
    return m?.santander !== false;
}

export function municipioLabel(m) {
    if (!m) return '';
    const dep = m.departamento || (m.provincia !== 'Fuera de Santander' ? m.provincia : null);
    if (isSantanderMunicipio(m) || !dep || m.name.endsWith(`(${dep})`)) return m.name;
    return `${m.name} (${dep})`;
}

// Santander's municipio of the metropolitan area, never a namesake elsewhere.
export function inAreaMetropolitana(m, names = AREA_METROPOLITANA) {
    return isSantanderMunicipio(m) && names.includes(m.name);
}

// The municipio of a ficha whose form only has the name: Santander's first.
export function municipioByName(municipios, name) {
    const matches = (municipios || []).filter(m => m.name === name);
    return matches.find(isSantanderMunicipio) || matches[0] || null;
}

export const NIVEL_CONFIANZA_OPTIONS = [
    { value: '', label: 'Sin asignar' },
    { value: 'sin_llamar', label: 'Sin llamar' },
    { value: 'contactado', label: 'Contactado' },
    { value: 'confirmado', label: 'Confirmado' },
    { value: 'comprometido', label: 'Comprometido' },
    { value: 'no_responde', label: 'No responde' },
];

export const NIVEL_CONFIANZA_COLORS = {
    sin_llamar: 'bg-gray-100 text-gray-600',
    contactado: 'bg-blue-100 text-blue-700',
    confirmado: 'bg-emerald-100 text-emerald-700',
    comprometido: 'bg-green-100 text-green-800',
    no_responde: 'bg-red-100 text-red-600',
};

export const GENERO_OPTIONS = ['Masculino', 'Femenino', 'Otro', 'No binario'];
export const TIPO_DOCUMENTO_OPTIONS = ['CC', 'CE', 'TI', 'PA', 'RC', 'NIP', 'NUIP'];
export const ESTADO_CIVIL_OPTIONS = ['Soltero/a', 'Casado/a', 'Unión libre', 'Divorciado/a', 'Viudo/a'];
export const ESCOLARIDAD_OPTIONS = ['Primaria', 'Secundaria', 'Técnico', 'Tecnólogo', 'Universitario', 'Posgrado', 'Ninguno'];

export const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
