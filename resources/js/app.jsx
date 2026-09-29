import '../css/app.css';
import 'leaflet/dist/leaflet.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

// Register Service Worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            setInterval(() => registration.update(), 60 * 60 * 1000);

            // Pre-cache key pages so they work on offline reload
            if (registration.active) {
                registration.active.postMessage({
                    type: 'PRECACHE_PAGES',
                    urls: ['/mapa-politico', '/gobernador'],
                });
            }
            navigator.serviceWorker.ready.then(reg => {
                reg.active.postMessage({
                    type: 'PRECACHE_PAGES',
                    urls: ['/mapa-politico', '/gobernador'],
                });
            });
        }).catch(() => {});
    });

    window.addEventListener('online', () => {
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'SYNC_REQUESTED' });
        }
    });
}

// When Inertia XHR fails (offline), force hard navigation so SW can serve cached page
router.on('exception', (event) => {
    const visitUrl = event?.detail?.visit?.url;
    if (visitUrl) {
        event.preventDefault();
        window.location.href = typeof visitUrl === 'string' ? visitUrl : visitUrl.href;
        return false;
    }
});

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
