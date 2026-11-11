<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $acta['titulo'] }}</title>
    <style>
        @page { margin: 18mm 16mm 16mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }
        h1 { font-size: 12.5pt; text-align: center; margin: 0 0 1mm; }
        .date { text-align: center; font-weight: bold; margin: 0 0 5mm; }
        .meta p { margin: 0 0 1mm; }
        h2 { font-size: 10.5pt; margin: 6mm 0 2mm; }
        h3 { font-size: 9.8pt; margin: 3mm 0 1mm; }
        p { margin: 0 0 1.5mm; }
        ol { margin: 0 0 2mm 5mm; padding-left: 4mm; }
        .item { margin-left: 5mm; }
        table { width: 100%; border-collapse: collapse; margin-top: 1mm; }
        th, td { border: 0.3mm solid #000; padding: 1.2mm 1.5mm; vertical-align: top; text-align: left; }
        th { background: #d9e2f3; font-size: 8.8pt; }
        td { font-size: 8.8pt; }
        td p { margin: 0; }
        .cierre { margin-top: 6mm; }
    </style>
</head>
<body>
    @php($fmt = \App\Services\Eventos\Actas\ActaFormat::class)
    @php($lines = fn ($text) => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen')))
    <h1>{{ $acta['titulo'] }}</h1>
    @if ($acta['fecha'])
        <p class="date">{{ $fmt::longDate($acta['fecha']) }}</p>
    @endif

    <div class="meta">
        @if ($acta['municipio'])<p><b>Municipio:</b> {{ $acta['municipio'] }}</p>@endif
        @if ($acta['sitio'])<p><b>Sitio:</b> {{ $acta['sitio'] }}</p>@endif
        @if ($acta['hora_inicio'] || $acta['hora_fin'])<p><b>Hora:</b> {{ $fmt::hourRange($acta['hora_inicio'], $acta['hora_fin']) }}</p>@endif
    </div>

    <h2>1. PARTICIPANTES</h2>
    <table>
        <thead><tr><th style="width:32%">NOMBRE</th><th style="width:30%">PERFIL</th><th style="width:20%">MUNICIPIO</th><th style="width:18%">TELEFONO</th></tr></thead>
        <tbody>
            @forelse ($acta['participantes'] as $p)
                <tr><td>{{ $p['nombre'] }}</td><td>{{ $p['perfil'] }}</td><td>{{ $p['municipio'] }}</td><td>{{ $p['telefono'] }}</td></tr>
            @empty
                <tr><td colspan="4">&nbsp;</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>2. PUNTOS DEL ORDEN DEL DÍA</h2>
    @foreach ($lines($acta['introduccion']) as $line)
        <p>{{ $line }}</p>
    @endforeach
    @if ($acta['orden_dia'])
        <ol>
            @foreach ($acta['orden_dia'] as $punto)
                <li>{{ $punto }}</li>
            @endforeach
        </ol>
    @endif

    <h2>3. DESARROLLO DE LA REUNIÓN</h2>
    @php($n = 0)
    @foreach ($acta['desarrollo'] as $item)
        @if (trim((string) ($item['titulo'] ?? '')) !== '')
            <h3>{{ ++$n }}. {{ $item['titulo'] }}</h3>
        @endif
        @foreach ($lines($item['texto'] ?? '') as $line)
            <p class="{{ str_starts_with($line, '•') ? 'item' : '' }}">{{ $line }}</p>
        @endforeach
    @endforeach

    <h2>4. COMPROMISOS</h2>
    <table>
        <thead><tr><th style="width:52%">NOMBRE</th><th style="width:26%">RESPONSABLE</th><th style="width:22%">TELEFONO</th></tr></thead>
        <tbody>
            @forelse ($acta['compromisos'] as $c)
                <tr>
                    <td>
                        @foreach ($lines($c['descripcion']) as $line)<p>{{ $line }}</p>@endforeach
                        @if ($c['fecha_limite'])<p><b>Fecha límite:</b> {{ $fmt::shortDate($c['fecha_limite']) }}</p>@endif
                    </td>
                    <td>@foreach ($c['responsables'] as $r)<p>{{ $r['nombre'] }}</p>@endforeach</td>
                    <td>@foreach ($c['responsables'] as $r)<p>{{ $r['telefono'] ?: ' ' }}</p>@endforeach</td>
                </tr>
            @empty
                <tr><td colspan="3">&nbsp;</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($acta['cierre'])
        <div class="cierre">
            @foreach ($lines($acta['cierre']) as $line)
                <p>{{ $line }}</p>
            @endforeach
        </div>
    @endif
</body>
</html>
