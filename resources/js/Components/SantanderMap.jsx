import { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, CircleMarker, Tooltip } from 'react-leaflet';
import { router } from '@inertiajs/react';

// Leaflet CSS is imported in app.jsx

// Approximate coordinates for main Santander municipalities
const COORDS = {
    'Aguada':                  [6.170, -73.530],
    'Albania':                 [5.760, -73.914],
    'Aratoca':                 [6.692, -73.015],
    'Barbosa':                 [5.932, -73.616],
    'Barichara':               [6.636, -73.224],
    'Barrancabermeja':         [7.065, -73.855],
    'Betulia':                 [7.079, -73.416],
    'Bolívar':                 [5.990, -73.770],
    'Bucaramanga':             [7.119, -73.122],
    'Cabrera':                 [6.637, -73.270],
    'California':              [7.350, -72.959],
    'Capitanejo':              [6.531, -72.697],
    'Carcasí':                 [6.630, -72.624],
    'Cepitá':                  [6.750, -73.060],
    'Cerrito':                 [6.578, -72.686],
    'Charalá':                 [6.266, -73.148],
    'Charta':                  [7.283, -72.972],
    'Chima':                   [6.365, -73.403],
    'Chipatá':                 [6.078, -73.639],
    'Cimitarra':               [6.317, -73.950],
    'Concepción':              [6.760, -72.696],
    'Confines':                [6.350, -73.243],
    'Contratación':            [6.280, -73.489],
    'Coromoro':                [6.557, -73.064],
    'Curití':                  [6.609, -73.068],
    'El Carmen de Chucurí':    [6.698, -73.510],
    'El Guacamayo':            [6.368, -73.519],
    'El Peñón':                [6.080, -73.934],
    'El Playón':               [7.483, -73.202],
    'Encino':                  [6.143, -73.098],
    'Enciso':                  [6.618, -72.710],
    'Florián':                 [5.848, -73.934],
    'Floridablanca':           [7.064, -73.089],
    'Galán':                   [6.353, -73.379],
    'Gámbita':                 [6.063, -73.351],
    'Girón':                   [7.068, -73.170],
    'Guaca':                   [6.877, -72.845],
    'Guadalupe':               [6.248, -73.426],
    'Guapotá':                 [6.322, -73.338],
    'Guavatá':                 [5.960, -73.698],
    'Güepsa':                  [5.980, -73.568],
    'Hato':                    [6.559, -73.360],
    'Jesús María':             [5.870, -73.779],
    'Jordán':                  [6.705, -73.133],
    'La Belleza':              [5.863, -73.963],
    'La Paz':                  [5.999, -73.574],
    'Landázuri':               [6.218, -73.813],
    'Lebrija':                 [7.114, -73.222],
    'Los Santos':              [6.867, -73.097],
    'Macaravita':              [6.505, -72.594],
    'Málaga':                  [6.706, -72.733],
    'Matanza':                 [7.271, -73.059],
    'Mogotes':                 [6.476, -72.969],
    'Molagavita':              [6.644, -72.802],
    'Ocamonte':                [6.337, -73.118],
    'Oiba':                    [6.265, -73.299],
    'Onzaga':                  [6.339, -72.815],
    'Palmar':                  [6.485, -73.291],
    'Palmas del Socorro':      [6.539, -73.267],
    'Páramo':                  [6.415, -73.169],
    'Piedecuesta':             [6.987, -73.052],
    'Pinchote':                [6.530, -73.180],
    'Puente Nacional':         [5.879, -73.683],
    'Puerto Parra':            [6.651, -73.953],
    'Puerto Wilches':          [7.349, -73.898],
    'Rionegro':                [7.384, -73.153],
    'Sabana de Torres':        [7.394, -73.497],
    'San Andrés':              [6.814, -72.836],
    'San Benito':              [6.080, -73.180],
    'San Gil':                 [6.559, -73.136],
    'San Joaquín':             [6.374, -72.864],
    'San José de Miranda':     [6.680, -72.732],
    'San Miguel':              [6.590, -72.668],
    'San Vicente de Chucurí':  [6.881, -73.411],
    'Santa Bárbara':           [6.614, -73.303],
    'Santa Helena del Opón':   [6.348, -73.650],
    'Simacota':                [6.446, -73.349],
    'Socorro':                 [6.470, -73.261],
    'Suaita':                  [6.101, -73.438],
    'Sucre':                   [6.127, -73.583],
    'Suratá':                  [7.364, -72.979],
    'Tona':                    [7.216, -72.975],
    'Valle de San José':       [6.436, -73.122],
    'Vélez':                   [6.013, -73.673],
    'Vetas':                   [7.361, -72.884],
    'Villanueva':              [6.658, -73.168],
    'Zapatoca':                [6.817, -73.270],
};

/**
 * Interpolates a color on the scale:
 *   0% → light blue (#BFD7ED)   50% → mid navy (#0056A6)   100% → dark navy (#003B71)
 *
 * @param {number} ratio  0–1
 * @returns {string}  hex color
 */
function voteColor(ratio) {
    // Low:  rgb(191, 215, 237)  #BFD7ED
    // Mid:  rgb(0,   86,  166)  #0056A6
    // High: rgb(0,   59,  113)  #003B71
    const stops = [
        { r: 191, g: 215, b: 237 },
        { r:   0, g:  86, b: 166 },
        { r:   0, g:  59, b: 113 },
    ];

    let from, to, t;
    if (ratio <= 0.5) {
        from = stops[0];
        to   = stops[1];
        t    = ratio / 0.5;
    } else {
        from = stops[1];
        to   = stops[2];
        t    = (ratio - 0.5) / 0.5;
    }

    const r = Math.round(from.r + (to.r - from.r) * t);
    const g = Math.round(from.g + (to.g - from.g) * t);
    const b = Math.round(from.b + (to.b - from.b) * t);

    return `rgb(${r},${g},${b})`;
}

function fmt(n) {
    if (n == null) return '—';
    return new Intl.NumberFormat('es-CO').format(n);
}

/**
 * SantanderMap — circle-marker map of Santander municipalities.
 *
 * Props:
 *   municipios: Array<{ id, name, provincia, totalVotos, alcalde, lat?, lng? }>
 */
export default function SantanderMap({ municipios = [] }) {
    // Resolve coordinates: prefer data lat/lng, fall back to COORDS lookup
    const points = municipios
        .map((m) => {
            const coords =
                m.lat != null && m.lng != null
                    ? [m.lat, m.lng]
                    : COORDS[m.name] ?? null;
            return coords ? { ...m, coords } : null;
        })
        .filter(Boolean);

    const maxVotos = Math.max(...points.map((p) => p.totalVotos || 0), 1);

    return (
        <div
            className="w-full rounded overflow-hidden"
            style={{ height: 'clamp(300px, 40vw, 400px)' }}
        >
            <MapContainer
                center={[7.1, -73.1]}
                zoom={8}
                scrollWheelZoom={false}
                style={{ height: '100%', width: '100%' }}
                attributionControl={false}
            >
                {/* CartoDB Positron — clean, institutional, low visual noise */}
                <TileLayer
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                    subdomains="abc"
                    maxZoom={19}
                />

                {points.map((m) => {
                    const ratio     = maxVotos > 0 ? (m.totalVotos || 0) / maxVotos : 0;
                    const color     = voteColor(ratio);
                    // Radius: 6px minimum, up to 20px for the top municipality
                    const radius    = 6 + ratio * 14;

                    return (
                        <CircleMarker
                            key={m.id}
                            center={m.coords}
                            radius={radius}
                            pathOptions={{
                                color:       '#fff',
                                weight:      1.5,
                                fillColor:   color,
                                fillOpacity: 0.88,
                            }}
                            eventHandlers={{
                                click: () => router.visit(`/municipio/${m.id}`),
                            }}
                        >
                            <Tooltip direction="top" offset={[0, -radius]} opacity={0.97}>
                                <div style={{ fontFamily: 'IBM Plex Sans, sans-serif', minWidth: 140 }}>
                                    <p style={{ fontWeight: 700, fontSize: 13, marginBottom: 2, color: '#1A1A2E' }}>
                                        {m.name}
                                    </p>
                                    {m.provincia && (
                                        <p style={{ fontSize: 11, color: '#6B7280', marginBottom: 2 }}>
                                            {m.provincia}
                                        </p>
                                    )}
                                    {m.alcalde && (
                                        <p style={{ fontSize: 11, color: '#059669', fontWeight: 600, marginBottom: 2 }}>
                                            {m.alcalde}
                                        </p>
                                    )}
                                    <p style={{ fontSize: 12, fontWeight: 700, color: '#003B71', fontVariantNumeric: 'tabular-nums' }}>
                                        {fmt(m.totalVotos)} votos
                                    </p>
                                </div>
                            </Tooltip>
                        </CircleMarker>
                    );
                })}
            </MapContainer>
        </div>
    );
}
