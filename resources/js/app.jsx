import '../css/app.css';
import 'leaflet/dist/leaflet.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

// Register Service Worker for PWA (including iOS Safari)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            setInterval(() => registration.update(), 60 * 60 * 1000);
        }).catch(() => {});

        // Pre-cache: fetch current page assets + key pages for offline
        if (navigator.onLine) {
            setTimeout(() => {
                // Cache all CSS/JS from current page so they work offline
                const assets = [...document.querySelectorAll('link[rel="stylesheet"][href*="/build/"], script[src*="/build/"]')]
                    .map(el => el.href || el.src).filter(Boolean);
                assets.forEach(url => fetch(url).catch(() => {}));

                // Cache key pages HTML
                ['/mapa-politico', '/gobernador'].forEach(url => {
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } }).catch(() => {});
                });
            }, 3000);
        }
    });

    window.addEventListener('online', () => {
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'SYNC_REQUESTED' });
        }
    });
}

createInertiaApp({
    title: (title) => title ? `${title} — Inteligencia Electoral` : 'Inteligencia Electoral Santander',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: false,
});
