@php($eventLink = request()->is('e/*'))
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#003B71">
    <title>{{ $eventLink ? 'Enlace no disponible' : 'Página no encontrada' }} — Inteligencia Electoral</title>
    <style>
        :root { --primary: #003B71; --ink: #1a2433; --soft: #4a5568; --line: #e2e8f0; --bg: #f5f5f5; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--ink);
            font-family: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        header { background: var(--primary); color: #fff; padding: 14px 16px; font-weight: 800; letter-spacing: .02em; font-size: 16px; }
        main { display: flex; justify-content: center; padding: 40px 16px; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; text-align: center; }
        h1 { font-size: 24px; line-height: 1.3; margin: 0 0 12px; color: var(--primary); }
        .message { font-size: 18px; line-height: 1.5; color: var(--soft); margin: 0 0 24px; }
        a { display: block; padding: 14px 16px; border-radius: 12px; background: var(--primary); color: #fff; font-weight: 700; font-size: 17px; text-decoration: none; }
        a:focus-visible { outline: 3px solid #f2c94c; outline-offset: 2px; }
    </style>
</head>
<body>
    <header>{{ $eventLink ? 'Registro de asistencia' : 'ELECTORAL' }}</header>
    <main>
        <div class="card">
            @if ($eventLink)
                <h1>Este enlace ya no funciona</h1>
                <p class="message">Puede que el código QR haya sido cambiado o que el evento ya no exista. Pida a los organizadores del evento el código QR actualizado.</p>
            @else
                <h1>No encontramos esta página</h1>
                <p class="message">La dirección no existe o ya no está disponible.</p>
                <a href="/">Volver al inicio</a>
            @endif
        </div>
    </main>
</body>
</html>
