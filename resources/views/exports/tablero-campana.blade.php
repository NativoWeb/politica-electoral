<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tablero · {{ $c->name }}</title>
    @php
        $n = fn ($v) => $v === null ? '—' : number_format($v, 0, ',', '.');
        $p = fn ($v) => $v === null ? '—' : number_format($v * 100, 0, ',', '.').' %';
        $t = $data['totales'];
    @endphp
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a2e; margin: 0; padding: 0; }
        .header { background: #003B71; color: white; padding: 15px 20px; }
        .header h1 { font-size: 16px; font-weight: bold; margin: 0; }
        .header p { font-size: 9px; opacity: 0.7; margin: 3px 0 0; }
        .date { float: right; font-size: 9px; opacity: 0.6; }
        .cards { width: 100%; margin: 12px 0; border-collapse: separate; border-spacing: 8px 0; }
        .card { border: 1px solid #cfd8e3; padding: 8px 10px; vertical-align: top; width: 33%; }
        .card .l { font-size: 9px; color: #555; text-transform: uppercase; }
        .card .v { font-size: 16px; font-weight: bold; color: #003B71; margin: 3px 0; }
        .card .s { font-size: 9px; color: #333; }
        h2 { font-size: 11px; color: #003B71; margin: 12px 8px 4px; }
        table.t { width: 100%; border-collapse: collapse; }
        .t th { background: #003B71; color: white; padding: 4px 6px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .t td { padding: 3px 6px; border-bottom: 1px solid #ddd; font-size: 9px; }
        .t td.r { text-align: right; font-family: monospace; }
        .t tr.comuna td.n { padding-left: 16px; color: #333; }
        .rk { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
        .rk td { vertical-align: top; width: 50%; }
        .aviso { background: #fff3cd; border: 1px solid #ffc107; padding: 6px 10px; margin: 4px 8px; font-size: 9px; color: #856404; }
        .formula { margin: 8px; font-size: 9px; color: #444; }
        .footer { text-align: center; padding: 10px; font-size: 8px; color: #777; border-top: 1px solid #003B71; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <span class="date">{{ $fecha }} (hora de Bogotá)</span>
        <h1>Tablero · {{ $c->name }}</h1>
        <p>Inteligencia Electoral Santander &middot; {{ $c->cargoLabel() }} {{ $c->election_year }} &middot; {{ $data['campana']['territorio']['resumen'] }}</p>
    </div>

    @foreach($data['avisos'] as $aviso)
        <div class="aviso">{{ $aviso['texto'] }}</div>
    @endforeach

    <table class="cards"><tr>
        <td class="card">
            <div class="l">Votantes registrados</div>
            @if($t['votantes']['meta'] !== null)
                <div class="v">{{ $n($t['votantes']['registrados_con_meta']) }} de {{ $n($t['votantes']['meta']) }}</div>
                <div class="s">{{ $p($t['votantes']['pct']) }} · Faltan {{ $n($t['votantes']['falta']) }}</div>
                @if($t['votantes']['registrados'] > $t['votantes']['registrados_con_meta'])
                    <div class="s">En total hay {{ $n($t['votantes']['registrados']) }}, contando los territorios sin meta.</div>
                @endif
            @else
                <div class="v">{{ $n($t['votantes']['registrados']) }}</div>
                <div class="s">Sin meta</div>
            @endif
        </td>
        <td class="card">
            <div class="l">Líderes</div>
            @if($t['lideres']['meta'] !== null)
                <div class="v">{{ $n($t['lideres']['registrados_con_meta']) }} de {{ $n($t['lideres']['meta']) }}</div>
                <div class="s">{{ $p($t['lideres']['pct']) }} · Faltan {{ $n($t['lideres']['falta']) }}</div>
                @if($t['lideres']['registrados'] > $t['lideres']['registrados_con_meta'])
                    <div class="s">En total hay {{ $n($t['lideres']['registrados']) }}, contando los territorios sin meta.</div>
                @endif
            @else
                <div class="v">{{ $n($t['lideres']['registrados']) }}</div>
                <div class="s">Sin meta</div>
            @endif
        </td>
        <td class="card">
            <div class="l">Índice</div>
            <div class="v">@if($t['indice'] !== null){{ $t['indice'] }} de 100 @else — @endif</div>
            <div class="s">{{ $data['formula']['texto'] }}</div>
        </td>
    </tr></table>

    @if($mejores || $porApoyar)
    <table class="rk"><tr>
        <td>
            <h2>{{ $data['ranking']['una_lista'] ? 'Avance por territorio' : 'Van mejor' }}</h2>
            <table class="t">
                <tr><th>Territorio</th><th>Índice</th><th>Faltan votantes</th></tr>
                @foreach($mejores as $r)
                    <tr><td>{{ $r['nombre'] }}</td><td class="r">{{ $r['indice'] }}</td><td class="r">{{ $n($r['votantes']['falta']) }}</td></tr>
                @endforeach
            </table>
        </td>
        <td>
            @if($porApoyar)
            <h2>Necesitan apoyo</h2>
            <table class="t">
                <tr><th>Territorio</th><th>Índice</th><th>Faltan votantes</th></tr>
                @foreach($porApoyar as $r)
                    <tr><td>{{ $r['nombre'] }}</td><td class="r">{{ $r['indice'] }}</td><td class="r">{{ $n($r['votantes']['falta']) }}</td></tr>
                @endforeach
            </table>
            @endif
        </td>
    </tr></table>
    <div class="formula">{{ $data['ranking']['nota'] }}.</div>
    @endif

    <h2>Todos los territorios</h2>
    <table class="t">
        <thead>
            <tr>
                <th>Territorio</th><th>Votantes reg.</th><th>Meta</th><th>%</th><th>Líderes reg.</th><th>Meta</th><th>%</th>
                <th>Faltan votantes</th><th>Índice</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['territorios'] as $f)
                <tr class="{{ $f['padre_id'] ? 'comuna' : '' }}">
                    <td class="n">{{ $f['nombre'] }}</td>
                    <td class="r">{{ $n($f['votantes']['registrados']) }}</td>
                    <td class="r">{{ $n($f['votantes']['meta']) }}</td>
                    <td class="r">{{ $p($f['votantes']['pct']) }}</td>
                    <td class="r">{{ $n($f['lideres']['registrados']) }}</td>
                    <td class="r">{{ $n($f['lideres']['meta']) }}</td>
                    <td class="r">{{ $p($f['lideres']['pct']) }}</td>
                    <td class="r">{{ $n($f['votantes']['falta']) }}</td>
                    <td class="r">{{ $f['indice'] ?? '—' }}</td>
                    <td>{{ $estados[$f['estado']] ?? $f['estado'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">{{ $fuente }} Generado el {{ $fecha }}.</div>
</body>
</html>
