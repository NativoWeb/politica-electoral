<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 14mm 12mm 18mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1a1a2e; margin: 0; }
        .banda { background: #003B71; color: #fff; padding: 11pt 16pt; }
        .banda table { width: 100%; border-collapse: collapse; }
        .banda td { vertical-align: middle; padding: 0; }
        .banda .logo { width: 70pt; text-align: right; }
        .banda .logo img { height: 44pt; }
        .banda h1 { font-size: 17pt; margin: 0; line-height: 1.2; }
        .banda .grupo { font-size: 11pt; margin-top: 3pt; color: #dbe6f2; }
        .datos { margin: 9pt 2pt 0; }
        .generado { font-size: 10pt; color: #4b5563; }
        .total { font-size: 22pt; font-weight: bold; color: #003B71; margin: 6pt 0 0; }
        .total span { font-size: 12pt; font-weight: normal; color: #1a1a2e; }
        h2 { font-size: 11pt; color: #003B71; margin: 9pt 0 3pt; }
        .filtros { font-size: 10pt; }
        .aviso { background: #fff7d6; border: 1pt solid #e0b400; color: #5c4a00; padding: 6pt 9pt; margin-top: 7pt; font-size: 10pt; }
        .desglose { border-collapse: collapse; width: 100%; font-size: 9.5pt; }
        .desglose td { padding: 2pt 6pt; border-bottom: 0.5pt solid #d6dbe3; }
        .desglose td.hueco { border: none; width: 24pt; }
        .desglose td.n { text-align: right; white-space: nowrap; }
        .desglose td.otros { color: #4b5563; font-style: italic; }
        .salto { page-break-before: always; }
        .lista { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .lista thead { display: table-header-group; }
        .lista th { background: #003B71; color: #fff; text-align: left; font-size: 9pt; padding: 5pt 5pt; }
        .lista td { padding: 4pt 5pt; border-bottom: 0.5pt solid #d6dbe3; vertical-align: top; }
        .lista tr { page-break-inside: avoid; }
        .lista tr.par td { background: #f1f4f8; }
        .lista th.num, .lista td.num { width: 26pt; text-align: right; }
        .lista td.num { color: #4b5563; }
        .lista td.nombre { font-weight: bold; }
        .vacio { font-size: 12pt; margin-top: 20pt; }
    </style>
</head>
<body>
    <div class="banda">
        <table>
            <tr>
                <td>
                    <h1>{{ $titulo }}</h1>
                    @if($grupo)
                        <div class="grupo">{{ $grupo }}</div>
                    @endif
                </td>
                @if($logo)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                @endif
            </tr>
        </table>
    </div>

    <div class="datos">
        <div class="generado">{{ $generado }}</div>
        @foreach($atribuciones ?? [] as $atribucion)
            <div class="generado">{{ $atribucion }}</div>
        @endforeach

        <div class="total">{{ number_format($total, 0, ',', '.') }} <span>{{ $total === 1 ? ($unidad['singular'] ?? 'persona') : ($unidad['plural'] ?? 'personas') }}</span></div>

        @if($aviso)
            <div class="aviso">{{ $aviso }}</div>
        @endif

        <h2>Filtros</h2>
        <div class="filtros">{{ $filtros ? implode(' · ', $filtros) : 'Ninguno: '.($unidad['todos'] ?? 'todas las personas activas').'.' }}</div>

        @if($desglose['municipios'])
            {{-- Two columns, «Otros» last: the cover stays on one page with ten municipios. --}}
            @php
                $celdas = $desglose['municipios'];
                if ($desglose['otros'] > 0) {
                    $celdas[] = ['nombre' => 'Otros', 'total' => $desglose['otros'], 'otros' => true];
                }
                $mitad = (int) ceil(count($celdas) / 2);
            @endphp
            <h2>Por municipio</h2>
            <table class="desglose">
                @for($i = 0; $i < $mitad; $i++)
                    <tr>
                        @foreach([$celdas[$i] ?? null, $celdas[$i + $mitad] ?? null] as $k => $m)
                            @if($k === 1)
                                <td class="hueco"></td>
                            @endif
                            @if($m)
                                <td @class(['otros' => $m['otros'] ?? false])>{{ $m['nombre'] }}</td>
                                <td @class(['n', 'otros' => $m['otros'] ?? false])>{{ number_format($m['total'], 0, ',', '.') }}</td>
                            @else
                                <td></td><td></td>
                            @endif
                        @endforeach
                    </tr>
                @endfor
            </table>
        @endif
    </div>

    {{-- Tables of 50 rows: one long table, split page by page, takes DomPDF about 1.6 times the memory. --}}
    @foreach(array_chunk($filas, 50, true) as $bloque)
        <div class="salto"></div>
        <table class="lista">
            <thead>
                <tr>
                    <th class="num">#</th>
                    @foreach($columnas as $etiqueta)
                        <th>{{ $etiqueta }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($bloque as $i => $fila)
                    <tr @class(['par' => $i % 2 === 1])>
                        <td class="num">{{ $i + 1 }}</td>
                        @foreach($columnas as $clave => $etiqueta)
                            @php $valor = $fila[$clave] ?? null; @endphp
                            <td @class(['nombre' => $clave === 'nombre'])>{{ is_int($valor) ? number_format($valor, 0, ',', '.') : $valor }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    @if(! $filas)
        <div class="vacio">Nadie cumple todos estos filtros.</div>
    @endif
</body>
</html>
