export const TIPO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'alcaldia', label: 'Alcaldía' },
    { value: 'concejo', label: 'Concejo' },
    { value: 'lideres', label: 'Líderes' },
    { value: 'directorio', label: 'Directorio Municipal' },
    { value: 'senado', label: 'Senado' },
    { value: 'camara', label: 'Cámara' },
    { value: 'asamblea', label: 'Asamblea' },
];

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
