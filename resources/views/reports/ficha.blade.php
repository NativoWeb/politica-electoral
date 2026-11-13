<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha - {{ $f['nombre'] }}</title>
    <style>
        @page { margin: 0 0 14mm 0; }
        * { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1a2e; }
        .header { background: #003B71; color: #fff; padding: 22px 32px; }
        .header .kicker { font-size: 8pt; letter-spacing: 2px; opacity: .75; }
        .header h1 { font-size: 18pt; margin: 4px 0; }
        .header .sub { font-size: 9pt; opacity: .8; }
        .section { padding: 12px 32px; border-bottom: 1px solid #e8e8ee; }
        .section h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: 2px; color: #8e8ea0; margin-bottom: 8px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { padding: 4px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        table.rows td.label { width: 32%; color: #8e8ea0; text-transform: uppercase; font-size: 8pt; }
        .item { background: #f5f5f5; padding: 7px 10px; margin-bottom: 6px; }
        .item .name { font-weight: bold; font-size: 9.5pt; }
        .item .detail { font-size: 8pt; color: #555; margin-top: 2px; }
        .footer { padding: 14px 32px; font-size: 7.5pt; color: #8e8ea0; text-align: center; }
    </style>
</head>
<body>
    @php($fecha = fn ($v, $format = 'd/m/Y') => filled($v) ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format($format) : null)
    @php($soloFecha = fn ($v) => filled($v) ? \Illuminate\Support\Carbon::parse(substr((string) $v, 0, 10))->format('d/m/Y') : null)
    @php($join = fn (array $parts) => implode(' · ', array_filter($parts, 'filled')))
    @php($cargo = ! empty($f['cargos']) ? implode(', ', $f['cargos']) : ($f['cargo'] ?? null))

    <div class="header">
        <p class="kicker">FICHA PERSONAL</p>
        <h1>{{ $f['nombre'] }}</h1>
        <p class="sub">{{ $join([$f['municipio'] ?? null, $cargo]) }}</p>
    </div>

    @php($datos = [
        'Cédula' => $f['cedula'] ?? null,
        'Teléfono' => $f['telefono'] ?? null,
        'Email' => $f['email'] ?? null,
        'Partido' => $f['partido'] ?? null,
        'Tipo' => $f['tipo_registro'] ?? null,
        'Cargo' => $cargo,
        'Profesión' => $f['profesion'] ?? null,
        'Votos' => ($f['votos'] ?? 0) > 0 ? number_format((int) $f['votos'], 0, ',', '.') : null,
        'Dirección' => $f['direccion'] ?? null,
        'Barrio/Vereda' => $f['barrio'] ?? null,
        'Observaciones' => $f['observacion'] ?? null,
        'Fecha de nacimiento' => $soloFecha($f['fecha_nacimiento'] ?? null),
    ])
    <div class="section">
        <h2>Datos personales</h2>
        <table class="rows">
            @foreach (array_filter($datos, 'filled') as $label => $value)
                <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    </div>

    @php($votacion = array_filter([
        'Puesto' => $f['puesto_votacion'] ?? null,
        'Dirección del puesto' => $f['direccion_puesto'] ?? null,
        'Mesa' => $f['mesa_votacion'] ?? null,
    ], 'filled'))
    @if ($votacion)
        <div class="section">
            <h2>Votación</h2>
            <table class="rows">
                @foreach ($votacion as $label => $value)
                    <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if (! empty($f['contactos']))
        <div class="section">
            <h2>Otros contactos ({{ count($f['contactos']) }})</h2>
            <table class="rows">
                @foreach ($f['contactos'] as $c)
                    <tr><td class="label">{{ $c['tipo'] ?? '' }}</td><td>{{ $join([$c['valor'] ?? null, ($c['estado'] ?? null) !== 'activo' ? ($c['estado'] ?? null) : null]) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if (! empty($f['info_politica']))
        <div class="section">
            <h2>Información política ({{ count($f['info_politica']) }})</h2>
            @foreach ($f['info_politica'] as $i)
                <div class="item">
                    <div class="name">{{ $i['cargo_politico'] ?? 'Sin cargo' }}</div>
                    <div class="detail">{{ $join([$i['partido'] ?? null, $i['municipio'] ?? null, $i['departamento'] ?? null, isset($i['votos']) && $i['votos'] ? $i['votos'].' votos' : null, filled($i['aliado'] ?? null) ? 'Aliado: '.$i['aliado'] : null, $i['estado'] ?? null, $i['observacion'] ?? null]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($f['nexos']))
        <div class="section">
            <h2>Familia / Nexos ({{ count($f['nexos']) }})</h2>
            @foreach ($f['nexos'] as $n)
                <div class="item">
                    <div class="name">{{ $n['nombre'] }}</div>
                    <div class="detail">{{ $join([$n['parentesco'] ?? null, $n['cargo'] ?? null, ! empty($n['edad']) ? $n['edad'].' años' : null, filled($n['cedula'] ?? null) ? 'CC: '.$n['cedula'] : null, $n['telefono'] ?? null, filled($n['gustos'] ?? null) ? 'Gustos: '.$n['gustos'] : null, $n['observaciones'] ?? null]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($f['seguimientos']['items']))
        <div class="section">
            <h2>Seguimientos ({{ $f['seguimientos']['total'] }})</h2>
            @foreach ($f['seguimientos']['items'] as $s)
                <div class="item">
                    <div class="name">{{ $join([ucfirst((string) $s['tipo']), $fecha($s['fecha'] ?? null)]) }}</div>
                    <div class="detail">{{ $join([$s['nota'] ?? null, filled($s['user'] ?? null) ? 'Por: '.$s['user'] : null]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($f['eventos']['eventos']))
        <div class="section">
            <h2>Eventos ({{ $f['eventos']['total'] }})</h2>
            @foreach ($f['eventos']['eventos'] as $e)
                <div class="item">
                    <div class="name">{{ $e['nombre'] }}</div>
                    <div class="detail">{{ $join([$e['rol'] ?? null, $e['tipo'] ?? null, $e['municipio'] ?? null, $fecha($e['starts_at'] ?? null)]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($f['organizaciones']))
        <div class="section">
            <h2>Empresas y gremios ({{ count($f['organizaciones']) }})</h2>
            @foreach ($f['organizaciones'] as $o)
                <div class="item">
                    <div class="name">{{ $o['nombre'] }}</div>
                    <div class="detail">{{ $join([$o['tipo_label'] ?? null, $o['rol_label'] ?? null, $o['municipio'] ?? null, $o['nota'] ?? null]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($f['compromisos']))
        <div class="section">
            <h2>Compromisos ({{ count($f['compromisos']) }})</h2>
            @foreach ($f['compromisos'] as $c)
                <div class="item">
                    <div class="name">{{ $c['descripcion'] }}</div>
                    <div class="detail">{{ $join([\App\Models\EventCompromiso::LABELS[$c['estado']] ?? $c['estado'], $c['vencido'] ? 'Vencido' : null, filled($c['fecha_limite'] ?? null) ? 'Límite: '.$soloFecha($c['fecha_limite']) : null, $c['evento']['nombre'] ?? $c['organizacion']['nombre'] ?? null, $c['acta']['titulo'] ?? null]) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="footer">Inteligencia Electoral Santander · Generado el {{ $date }}</div>
</body>
</html>
