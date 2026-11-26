<!DOCTYPE html>
{{-- The UI is Spanish. Browser auto-translation rewrites React's text nodes and blanks the page on the next update. --}}
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#003B71">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Electoral">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/img/icon-192.png">
    <title inertia>Inteligencia Electoral Santander</title>

    {{-- Accessibility preferences before the first paint: inline copy of aplicarPreferencias (resources/js/lib/accesibilidad.js). --}}
    <script id="a11y-inicial">
        (function () {
            try {
                var p = JSON.parse(localStorage.getItem('a11y-preferencias') || '{}') || {}, h = document.documentElement;
                if ([90, 110, 125, 140, 150].indexOf(p.texto) !== -1) h.setAttribute('data-a11y-texto', String(p.texto));
                if (p.contraste === true) h.setAttribute('data-a11y-contraste', '');
                if (typeof p.reducirAnimaciones === 'boolean') h.setAttribute('data-a11y-animaciones', p.reducirAnimaciones ? 'reducidas' : 'normales');
                if (p.enlaces === true) h.setAttribute('data-a11y-enlaces', '');
            } catch (e) { /* storage blocked or corrupt: the default look */ }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="antialiased" style="background:#f5f5f5;font-family:system-ui,sans-serif">
    <noscript>
        <div style="padding:40px;text-align:center">
            <h1 style="color:#003B71">Inteligencia Electoral</h1>
            <p>Necesitas JavaScript habilitado para usar esta aplicación.</p>
        </div>
    </noscript>
    @inertia
</body>
</html>
