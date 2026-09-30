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

function showOfflineToast() {
    if (document.getElementById('offline-toast')) return;
    const toast = document.createElement('div');
    toast.id = 'offline-toast';
    toast.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;padding:14px 28px;background:#ef4444;color:white;border-radius:14px;font-size:15px;font-weight:700;box-shadow:0 4px 24px rgba(0,0,0,0.3);text-align:center;max-width:90vw;';
    toast.textContent = 'Sin conexión — los filtros no están disponibles';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Inertia v3: "networkError" fires when XHR fails (e.g. offline)
// - Same page (filtering) → show toast, keep current data
// - Different page (tab switch) → hard navigation so SW serves cached page
router.on('networkError', (event) => {
    // Get the URL Inertia was trying to visit
    const error = event?.detail?.error;
    const targetUrl = error?.url || window.location.href;

    const target = new URL(targetUrl, window.location.origin);

    if (target.pathname === window.location.pathname) {
        showOfflineToast();
        return false;
    }

    window.location.href = target.href;
    return false;
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
