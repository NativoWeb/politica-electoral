import { useEffect, useState } from 'react';
import { apiFetch } from '@/lib/api';

// Every municipio of Colombia for the ficha forms (a ficha may live anywhere), fetched once per page load. The page's
// own list (Santander + municipios with fichas) stays in use until it arrives, and offline.
let cached = null;
let pending = null;

function load() {
    pending ??= apiFetch('/api/geo/municipios-todos')
        .then(res => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
        .then(json => { cached = Array.isArray(json.data) ? json.data : null; return cached; })
        .catch(() => { pending = null; return null; });

    return pending;
}

export function useAllMunicipios(fallback = [], enabled = true) {
    const [list, setList] = useState(cached);

    useEffect(() => {
        if (!enabled || cached) return undefined;
        let alive = true;
        load().then(data => { if (alive && data) setList(data); });
        return () => { alive = false; };
    }, [enabled]);

    return list || fallback;
}

// Tests only.
export function resetAllMunicipiosCache() {
    cached = null;
    pending = null;
}
