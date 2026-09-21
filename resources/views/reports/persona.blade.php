<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Perfil Político-Electoral — {{ $persona->full_name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #1A1A2E;
            background: #FFFFFF;
        }

        /* ── HEADER ── */
        .header {
            background-color: #003B71;
            color: #FFFFFF;
            padding: 18px 24px 14px;
        }
        .header-top {
            font-size: 7.5pt;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            opacity: 0.75;
            margin-bottom: 6px;
        }
        .header-title {
            font-size: 9pt;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }
        .header-name {
            font-size: 18pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .header-sub {
            font-size: 8pt;
            opacity: 0.8;
            margin-top: 4px;
        }

        /* ── CONTENT ── */
        .content {
            padding: 20px 24px;
        }

        /* ── IDENTITY CARD ── */
        .identity-card {
            background: #F5F7FA;
            border-left: 4px solid #003B71;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 0 4px 4px 0;
        }
        .identity-grid {
            width: 100%;
        }
        .identity-grid td {
            padding: 3px 12px 3px 0;
            font-size: 9pt;
        }
        .identity-label {
            font-weight: bold;
            color: #003B71;
            width: 120px;
        }
        .identity-value {
            color: #333;
        }

        /* ── SECTION ── */
        .section-title {
            font-size: 9pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #003B71;
            border-bottom: 2px solid #003B71;
            padding-bottom: 5px;
            margin-bottom: 10px;
            margin-top: 20px;
        }

        /* ── TABLES ── */
        table.data {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-bottom: 8px;
        }
        table.data thead tr {
            background-color: #003B71;
            color: #FFFFFF;
        }
        table.data thead th {
            padding: 7px 8px;
            text-align: left;
            font-weight: bold;
            letter-spacing: 0.5px;
            font-size: 7.5pt;
            text-transform: uppercase;
        }
        table.data tbody tr {
            border-bottom: 1px solid #E0E6F0;
        }
        table.data tbody tr:nth-child(even) {
            background-color: #F5F7FA;
        }
        table.data tbody td {
            padding: 6px 8px;
            vertical-align: top;
        }

        /* ── OUTCOME BADGES ── */
        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-elected   { background: #D4EDDA; color: #155724; }
        .badge-defeated  { background: #F8D7DA; color: #721C24; }
        .badge-withdrawn { background: #FFF3CD; color: #856404; }
        .badge-other     { background: #E2E8F0; color: #4A5568; }

        .status-active   { color: #155724; font-weight: bold; }
        .status-ended    { color: #6C757D; }
        .status-other    { color: #856404; }

        /* ── EMPTY STATE ── */
        .empty {
            font-size: 8.5pt;
            color: #888;
            font-style: italic;
            padding: 10px 0;
        }

        /* ── FOOTER ── */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #003B71;
            color: rgba(255,255,255,0.7);
            font-size: 7pt;
            padding: 7px 24px;
            display: flex;
        }
        .footer-left  { text-align: left; }
        .footer-right { text-align: right; }
        .footer table { width: 100%; }
        .footer table td { padding: 0; font-size: 7pt; color: rgba(255,255,255,0.7); }

        .page-break { page-break-after: always; }

        /* Number formatting */
        .num { text-align: right; }
    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">
        <div class="header-top">Plataforma de Inteligencia Político-Electoral de Santander</div>
        <div class="header-title">Perfil Político-Electoral</div>
        <div class="header-name">{{ $persona->full_name }}</div>
        @if(!empty($persona->birth_date))
        <div class="header-sub">
            Fecha de nacimiento: {{ \Carbon\Carbon::parse($persona->birth_date)->format('d/m/Y') }}
            @if(!empty($persona->gender))
                &nbsp;·&nbsp; {{ ucfirst($persona->gender) }}
            @endif
        </div>
        @endif
    </div>

    <!-- FOOTER (fixed) -->
    <div class="footer">
        <table>
            <tr>
                <td class="footer-left">Generado el {{ date('d/m/Y \a \l\a\s H:i') }}</td>
                <td class="footer-right">Datos de carácter informativo — uso interno</td>
            </tr>
        </table>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- Identity card -->
        <div class="identity-card">
            <table class="identity-grid">
                <tr>
                    @if(!empty($persona->document_number))
                    <td class="identity-label">Documento</td>
                    <td class="identity-value">{{ $persona->document_type ?? '' }} {{ $persona->document_number }}</td>
                    @endif
                    @if(!empty($persona->birth_municipality))
                    <td class="identity-label">Municipio de origen</td>
                    <td class="identity-value">{{ $persona->birth_municipality }}</td>
                    @endif
                </tr>
                <tr>
                    <td class="identity-label">Total candidaturas</td>
                    <td class="identity-value">{{ $candidaturas->count() }}</td>
                    <td class="identity-label">Cargos ejercidos</td>
                    <td class="identity-value">{{ $cargos->count() }}</td>
                </tr>
                @php
                    $electos = $candidaturas->where('outcome', 'elected')->count();
                    $totalVotos = $candidaturas->sum('votos');
                @endphp
                <tr>
                    <td class="identity-label">Victorias electorales</td>
                    <td class="identity-value">{{ $electos }} de {{ $candidaturas->count() }}</td>
                    <td class="identity-label">Total votos obtenidos</td>
                    <td class="identity-value">{{ number_format($totalVotos, 0, ',', '.') }}</td>
                </tr>
            </table>
        </div>

        <!-- TRAYECTORIA ELECTORAL -->
        <div class="section-title">Trayectoria Electoral</div>

        @if($candidaturas->isEmpty())
            <p class="empty">No se encontraron candidaturas registradas para esta persona.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Evento electoral</th>
                        <th>Cargo</th>
                        <th>Municipio / Circunscripción</th>
                        <th>Partido / Movimiento</th>
                        <th class="num">Votos</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($candidaturas as $c)
                    <tr>
                        <td>{{ $c->evento ?? '—' }}</td>
                        <td>{{ $c->cargo ?? '—' }}</td>
                        <td>{{ $c->municipio ?? '—' }}</td>
                        <td>{{ $c->partido ?? '—' }}</td>
                        <td class="num">{{ $c->votos > 0 ? number_format($c->votos, 0, ',', '.') : '—' }}</td>
                        <td>
                            @php
                                $outcome = strtolower($c->outcome ?? '');
                                $badgeClass = match($outcome) {
                                    'elected'   => 'badge-elected',
                                    'defeated'  => 'badge-defeated',
                                    'withdrawn' => 'badge-withdrawn',
                                    default     => 'badge-other',
                                };
                                $outcomeLabel = match($outcome) {
                                    'elected'   => 'Electo',
                                    'defeated'  => 'Derrotado',
                                    'withdrawn' => 'Retirado',
                                    default     => ucfirst($c->outcome ?? 'N/D'),
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $outcomeLabel }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <!-- CARGOS DESEMPEÑADOS -->
        <div class="section-title">Cargos Desempeñados</div>

        @if($cargos->isEmpty())
            <p class="empty">No se encontraron cargos registrados para esta persona.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Cargo / Corporación</th>
                        <th>Municipio / Jurisdicción</th>
                        <th>Periodo</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cargos as $cargo)
                    <tr>
                        <td>{{ $cargo->cargo ?? '—' }}</td>
                        <td>{{ $cargo->municipio ?? '—' }}</td>
                        <td>
                            @php
                                $inicio = !empty($cargo->start_date) ? \Carbon\Carbon::parse($cargo->start_date)->format('d/m/Y') : '?';
                                $fin    = !empty($cargo->end_date)   ? \Carbon\Carbon::parse($cargo->end_date)->format('d/m/Y')   : 'Presente';
                            @endphp
                            {{ $inicio }} — {{ $fin }}
                        </td>
                        <td>
                            @php
                                $st = strtolower($cargo->status ?? '');
                                $stClass = match($st) {
                                    'active'   => 'status-active',
                                    'ended', 'completed' => 'status-ended',
                                    default    => 'status-other',
                                };
                                $stLabel = match($st) {
                                    'active'   => 'Activo',
                                    'ended'    => 'Finalizado',
                                    'completed'=> 'Completado',
                                    default    => ucfirst($cargo->status ?? 'N/D'),
                                };
                            @endphp
                            <span class="{{ $stClass }}">{{ $stLabel }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

    </div><!-- /content -->

</body>
</html>
