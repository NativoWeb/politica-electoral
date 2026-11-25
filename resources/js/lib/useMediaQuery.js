import { useEffect, useState } from 'react';

const coincide = (query) => typeof window !== 'undefined' && !!window.matchMedia?.(query)?.matches;

// true while the media query matches; follows rotation and window resizing. Without matchMedia: false.
export default function useMediaQuery(query) {
    const [valor, setValor] = useState(() => coincide(query));
    useEffect(() => {
        const mql = typeof window !== 'undefined' ? window.matchMedia?.(query) : null;
        if (!mql) return undefined;
        const alCambiar = () => setValor(mql.matches);
        alCambiar();
        mql.addEventListener?.('change', alCambiar);
        return () => mql.removeEventListener?.('change', alCambiar);
    }, [query]);
    return valor;
}

// Phones and tablets in portrait (below Tailwind's lg).
export const useEsMovil = () => useMediaQuery('(max-width: 1023px)');
