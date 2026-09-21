/** Utilidades compartidas para datos electorales */

/** Mapeo de nombres de partido → archivo de logo en /img/partidos/ */
const PARTY_LOGOS = {
    'Partido Liberal': 'liberal.png',
    'Liberal': 'liberal.png',
    'Partido Conservador': 'conservador.png',
    'Conservador': 'conservador.png',
    'Cambio Radical': 'cambio-radical.png',
    'CR': 'cambio-radical.png',
    'Alianza Verde': 'alianza-verde.png',
    'VERDE': 'alianza-verde.png',
    'Verde / En Marcha': 'alianza-verde.png',
    'ASI': 'asi.png',
    'Partido De La U': 'la-u.png',
    'La U': 'la-u.png',
    'LA U': 'la-u.png',
    'Centro Democrático': 'centro-democratico.png',
    'CD': 'centro-democratico.png',
    'Pacto Historico': 'pacto-historico.png',
    'Nuevo Liberalismo': 'nuevo-liberalismo.png',
    'MAIS': 'mais.png',
    'Mira': 'mira.png',
    'Mira / Nuevo Liberal': 'mira.png',
    'Colombia Renaciente': 'colombia-renaciente.png',
    'Avanza': 'avanza.png',
    'En Marcha': 'en-marcha.png',
    'Creemos': 'creemos.png',
    'Liga': 'liga.png',
    'LIGA': 'liga.png',
    'Aico': 'aico.png',
    'AICO': 'aico.png',
    'Salvacion Nacional': 'salvacion-nacional.png',
    'Fuerza De La Paz': 'fuerza-paz.png',
    'La Fuerza De La Paz': 'fuerza-paz.png',
    'Colombia Justa - Salvacion Nal': 'colombia-justa.png',
    'Alianza Por Colombia': 'alianza-verde.png',
    'Polo': 'polo.png',
};

export function partyLogo(name, acronym) {
    if (acronym && PARTY_LOGOS[acronym]) return `/img/partidos/${PARTY_LOGOS[acronym]}`;
    if (name && PARTY_LOGOS[name]) return `/img/partidos/${PARTY_LOGOS[name]}`;
    return null;
}

export const PARTY_COLORS = {
    'Centro Democrático': '#1B3A63',
    'CD': '#1B3A63',
    'Pacto Historico': '#C8016E',
    'Partido Liberal': '#E31937',
    'Partido Conservador': '#1560A4',
    'Cambio Radical': '#F5821F',
    'CR': '#F5821F',
    'Alianza Por Colombia': '#1BA44A',
    'Partido De La U': '#6B3FA0',
    'Verde / En Marcha': '#00A651',
    'Mira / Nuevo Liberal': '#009DDC',
    'Avanza': '#FF6B35',
    'Colombia Humana': '#FFD700',
    'Fuerza Ciudadana': '#E74C3C',
    'ASI': '#8E44AD',
    'MAIS': '#27AE60',
};

const FALLBACK = [
    '#2563EB', '#7C3AED', '#0891B2', '#059669', '#D97706',
    '#DC2626', '#4F46E5', '#0D9488', '#B45309', '#9333EA',
    '#6366F1', '#14B8A6', '#F59E0B', '#EF4444', '#8B5CF6',
];

export function partyColor(name, index = 0) {
    if (name && PARTY_COLORS[name]) return PARTY_COLORS[name];
    return FALLBACK[index % FALLBACK.length];
}

export function fmt(n) {
    if (n == null) return '—';
    return new Intl.NumberFormat('es-CO').format(n);
}

export function pct(value, total, decimals = 1) {
    if (!total) return '0,0';
    return ((value / total) * 100).toFixed(decimals).replace('.', ',');
}

/** Porcentaje numérico */
export function pctNum(value, total) {
    if (!total) return 0;
    return (value / total) * 100;
}
