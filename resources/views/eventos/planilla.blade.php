<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Planilla de asistencia — {{ $evento->nombre }}</title>
    <style>
        @page { margin: 9mm 8mm 8mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #111; margin: 0; }

        .mark { position: fixed; width: 7mm; height: 7mm; border: 0 solid #000; }
        .mark.tl { top: -6.5mm; left: -6mm; border-top-width: 1.6mm; border-left-width: 1.6mm; }
        .mark.tr { top: -6.5mm; right: -6mm; border-top-width: 1.6mm; border-right-width: 1.6mm; }
        .mark.bl { bottom: -5.5mm; left: -6mm; border-bottom-width: 1.6mm; border-left-width: 1.6mm; }
        .mark.br { bottom: -5.5mm; right: -6mm; border-bottom-width: 1.6mm; border-right-width: 1.6mm; }

        .page { page-break-after: always; }
        .page.last { page-break-after: auto; }

        .head { width: 100%; border-collapse: collapse; border-bottom: 0.6mm solid #003B71; }
        .head td { vertical-align: top; padding: 0 0 1mm 0; }
        .kicker { font-size: 7pt; font-weight: bold; color: #003B71; letter-spacing: 0.4pt; }
        .title { font-size: 12.5pt; font-weight: bold; margin: 0.4mm 0 0.5mm; }
        .meta { font-size: 7.8pt; line-height: 1.25; }
        .meta b { color: #003B71; }
        .head td.pageno { width: 42mm; text-align: right; vertical-align: middle; padding-right: 2mm; }
        .head td.qr { width: 21mm; text-align: right; }
        .qr img { width: 21mm; height: 21mm; }
        .pageno .n { font-size: 14pt; font-weight: bold; }
        .pageno .code { font-size: 6.5pt; color: #444; font-family: 'DejaVu Sans Mono', monospace; }

        .instr { font-size: 7.5pt; margin: 1.2mm 0; }
        .instr b { color: #003B71; }

        .grid { width: 263.4mm; border-collapse: collapse; }
        .grid tr.size td { height: 0; padding: 0; border: 0; }
        .grid th { background: #003B71; color: #fff; font-size: 6.5pt; font-weight: bold; padding: 0.6mm 0.4mm; border: 0.2mm solid #003B71; text-align: center; }
        .grid td { height: 8.6mm; border: 0.25mm solid #555; padding: 0; }
        .grid tr.alt td.f { background: #e9eef5; }
        .grid td.num { text-align: center; font-weight: bold; font-size: 9pt; background: #003B71 !important; color: #fff; }
        .grid td.d { background: #fff; text-align: center; vertical-align: middle; line-height: 0; }
        .grid td.d img { height: 7mm; }
        .grid td.ok { text-align: center; background: #fff; }
        .grid td.ok span { display: inline-block; width: 5mm; height: 5mm; border: 0.4mm solid #111; }
        .grid tr.end td { border-bottom: 0.5mm solid #111; }
        .grid td.t { font-size: 8pt; padding: 0 0.8mm; vertical-align: middle; }
        .grid td.t.n { font-family: 'DejaVu Sans Mono', monospace; letter-spacing: 1.1mm; text-align: center; }
        .grid td.t.s { font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 9pt; }
        .grid td.ok span b { display: block; font-size: 10pt; line-height: 4.6mm; }
        .grid td.g, .grid th.g { border-left: 0.6mm solid #111; }

        .foot { margin-top: 1.5mm; border: 0.3mm solid #003B71; padding: 1.4mm 2mm; font-size: 6.3pt; line-height: 1.3; }
        .foot .h { font-weight: bold; color: #003B71; font-size: 6.8pt; }
        .prov { margin-top: 1mm; background: #b00020; color: #fff; font-weight: bold; font-size: 9pt; text-align: center; padding: 1mm; }
        .trace { margin-top: 0.8mm; font-size: 5.8pt; color: #555; }
    </style>
</head>
<body>
    <div class="mark tl"></div><div class="mark tr"></div><div class="mark bl"></div><div class="mark br"></div>

    @php
        $codigo = strtoupper(substr($evento->id, 0, 8));
        $barrios = $evento->barrios->pluck('nombre')->implode(', ');
        $municipio = trim(($evento->municipio?->canonical_name ?? '').($evento->provincia ? ' ('.\App\Support\Provincias::display($evento->provincia->canonical_name).')' : ''));
        $provisional = ! $consent || ! $consent->approved;
        // One vector strip per group of digit boxes (3.6 mm each; '/' is a 1.8 mm separator), drawn once per cell
        // instead of one table cell per digit: keeps dompdf's memory flat up to 20 pages.
        $strip = function (array $pattern): array {
            $x = 0;
            $shapes = '';
            foreach ($pattern as $item) {
                if ($item === '/') {
                    $shapes .= '<line x1="'.($x + 4).'" y1="58" x2="'.($x + 14).'" y2="12" stroke="#555" stroke-width="3"/>';
                    $x += 18;

                    continue;
                }
                for ($i = 0; $i < $item; $i++, $x += 36) {
                    $shapes .= '<rect x="'.($x + 1.25).'" y="1.25" width="33.5" height="67.5" fill="#fff" stroke="#666" stroke-width="2.5"/>';
                }
            }
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$x.'" height="70" viewBox="0 0 '.$x.' 70">'.$shapes.'</svg>';

            return ['src' => 'data:image/svg+xml;base64,'.base64_encode($svg), 'mm' => $x / 10];
        };
        $grupos = ['cedula' => $strip([10]), 'celular' => $strip([10]), 'fijo' => $strip([7]), 'fecha' => $strip([2, '/', 2, '/', 4])];
        // Only for the synthetic sheets of `eventos:ocr-piloto-ejemplo` (typed fake data): [page][row] => values.
        $relleno = $relleno ?? [];
    @endphp

    @foreach ($pages as $page)
        <div class="page {{ $loop->last ? 'last' : '' }}">
            <table class="head">
                <tr>
                    <td>
                        <div class="kicker">PLANILLA DE ASISTENCIA · {{ mb_strtoupper($evento->type?->nombre ?? 'Evento') }}</div>
                        <div class="title">{{ $evento->nombre }}</div>
                        <div class="meta">
                            @if ($cuando)<b>Fecha y hora:</b> {{ $cuando }}<br>@endif
                            <b>Lugar:</b> {{ $evento->sitio_encuentro ?: '—' }} &nbsp;·&nbsp; <b>Municipio:</b> {{ $municipio ?: '—' }}<br>
                            <b>Barrios:</b> {{ \Illuminate\Support\Str::limit($barrios ?: '—', 160) }}
                        </div>
                    </td>
                    <td class="pageno">
                        <div class="n">Página {{ $page['numero'] }} de {{ $total }}</div>
                        <div class="code">Evento {{ $codigo }}</div>
                    </td>
                    <td class="qr"><img src="{{ $page['qr'] }}" alt="QR"></td>
                </tr>
            </table>

            <div class="instr">
                Escriba con letra clara y en <b>MAYÚSCULAS</b>. <b>Un número por casilla.</b>
                Marque <b>«Autorizo»</b> con una X y firme. Sin autorización y firma la persona no se registra.
            </div>

            <table class="grid">
                <thead>
                    <tr class="size">
                        @foreach ([6, 26, 26, $grupos['cedula']['mm'], $grupos['celular']['mm'], $grupos['fijo']['mm'], $grupos['fecha']['mm'], 23, 18, 9, 26] as $w)<td style="width: {{ $w }}mm;"></td>@endforeach
                    </tr>
                    <tr>
                        <th>N.º</th>
                        <th>NOMBRES<br>(MAYÚSCULAS)</th>
                        <th>APELLIDOS<br>(MAYÚSCULAS)</th>
                        <th class="g">CÉDULA<br>(un número por casilla)</th>
                        <th class="g">CELULAR</th>
                        <th class="g">TEL. FIJO</th>
                        <th class="g">FECHA DE NACIMIENTO<br>DD / MM / AAAA</th>
                        <th class="g">DIRECCIÓN</th>
                        <th>BARRIO</th>
                        <th>AUTO-<br>RIZO</th>
                        <th>FIRMA</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($row = 1; $row <= $filas; $row++)
                        @php $dato = $relleno[$page['numero']][$row] ?? null; @endphp
                        <tr class="{{ $row % 2 === 0 ? 'alt' : '' }} {{ $row === $filas ? 'end' : '' }}">
                            <td class="num">{{ $row }}</td>
                            @if ($dato)
                                <td class="f t">{{ $dato['nombres'] }}</td>
                                <td class="f t">{{ $dato['apellidos'] }}</td>
                                @foreach (['cedula', 'celular', 'fijo', 'fecha_nacimiento'] as $campo)
                                    <td class="d g t n">{{ $dato[$campo] }}</td>
                                @endforeach
                                <td class="f g t">{{ $dato['direccion'] }}</td>
                                <td class="f t">{{ $dato['barrio'] }}</td>
                                <td class="ok"><span>@if ($dato['autorizo'])<b>X</b>@endif</span></td>
                                <td class="f t s">{{ $dato['firma'] ? $dato['nombres'].' '.mb_substr($dato['apellidos'], 0, 1).'.' : '' }}</td>
                            @else
                                <td class="f"></td>
                                <td class="f"></td>
                                @foreach ($grupos as $grupo)
                                    <td class="d g"><img src="{{ $grupo['src'] }}" style="width: {{ $grupo['mm'] }}mm;" alt=""></td>
                                @endforeach
                                <td class="f g"></td>
                                <td class="f"></td>
                                <td class="ok"><span></span></td>
                                <td class="f"></td>
                            @endif
                        </tr>
                    @endfor
                </tbody>
            </table>

            <div class="foot">
                <div class="h">AUTORIZACIÓN DE TRATAMIENTO DE DATOS PERSONALES (Ley 1581 de 2012) — RESUMEN · Versión {{ $consent?->version ?? 'sin texto' }}</div>
                @if ($consentResumen)
                    <div>{{ $consentResumen }}</div>
                @endif
                <div>
                    Al marcar «Autorizo» y firmar, la persona acepta el texto completo de la versión {{ $consent?->version ?? '—' }}, que el organizador
                    le lee o le muestra antes de firmar. Texto completo y política de tratamiento de datos: solicítelos al organizador del evento
                    o en el formulario de registro del evento.
                </div>
                @if ($provisional)
                    <div class="prov">TEXTO PROVISIONAL — no válido como autorización</div>
                @endif
                <div class="trace">Evento {{ $evento->id }} · Página {{ $page['numero'] }} de {{ $total }} · Generada {{ now(\App\Http\Controllers\EventoController::TZ)->format('d/m/Y H:i') }}</div>
            </div>
        </div>
    @endforeach
</body>
</html>
