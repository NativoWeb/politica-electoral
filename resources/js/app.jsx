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

        // Pre-cache key pages for offline (tell SW to fetch and store)
        if (navigator.onLine && navigator.serviceWorker.controller) {
            setTimeout(() => {
                navigator.serviceWorker.controller.postMessage({
                    type: 'PRECACHE_PAGES',
                    urls: ['/mapa-politico', '/gobernador'],
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
    progress: {
        color: '#1E4E79',
        showSpinner: true,
    },
});
