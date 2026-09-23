import '../css/app.css';
import 'leaflet/dist/leaflet.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

// Register Service Worker for PWA (including iOS Safari)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            // Check for updates periodically (iOS doesn't auto-update SW reliably)
            setInterval(() => registration.update(), 60 * 60 * 1000); // every hour
        }).catch(() => {});
    });

    // iOS fallback: Safari doesn't support Background Sync
    // Trigger sync when coming back online
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
