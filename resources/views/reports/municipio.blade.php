<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Perfil Municipal — {{ $municipio->name }}</title>
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

        /* ── SUMMARY CARD ── */
        .summary-card {
            background: #F5F7FA;
            border-left: 4px solid #003B71;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 0 4px 4px 0;
        }
        .summary-grid {
            width: 100%;
        }
        .summary-grid td {
            padding: 3px 12px 3px 0;
            font-size: 9pt;
        }
        .summary-label {
            font-weight: bold;
            color: #003B71;
            width: 160px;
        }
        .summary-value {
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

        /* ── RANK COLUMN ── */
        .rank {
            text-align: center;
            font-weight: bold;
            color: #003B71;
            width: 30px;
        }
        .rank-1 { color: #B8860B; }
        .rank-2 { color: #6C757D; }
        .rank-3 { color: #8B4513; }

        /* ── VOTE BAR ── */
        .vote-bar-wrap {
            background: #E0E6F0;
            border-radius: 3px;
            height: 8px;
            margin-top: 3px;
        }
        .vote-bar-fill {
            background: #003B71;
            border-radius: 3px;
            height: 8px;
        }

        .num { text-align: right; }

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
        }
        .footer table { width: 100%; }
        .footer table td { padding: 0; font-size: 7pt; color: rgba(255,255,255,0.7); }
    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">
        <div class="header-top">Plataforma de Inteligencia Político-Electoral de Santander</div>
        <div class="header-title">Perfil Municipal — Análisis Electoral</div>
        <div class="header-name">{{ $municipio->name }}</div>
        @if(!empty($municipio->provincia))
        <div class="header-sub">Provincia de {{ $municipio->provincia }} &nbsp;·&nbsp; Departamento de Santander</div>
        @else
        <div class="header-sub">Departamento de Santander</div>
        @endif
    </div>

    <!-- FOOTER (fixed) -->
    <div class="footer">
        <table>
            <tr>
                <td style="text-align:left;">Generado el {{ date('d/m/Y \a \l\a\s H:i') }}</td>
                <td style="text-align:right;">Datos de carácter informativo — uso interno</td>
            </tr>
        </table>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- Summary card -->
        @php
            $totalCandidatos = $candidatos->count();
            $electoAlcalde   = $candidatos->firstWhere('outcome', 'elected');
            $maxVotos        = $candidatos->max('votos') ?: 1;
            $totalConcejales = $concejales->count();
        @endphp
        <div class="summary-card">
            <table class="summary-grid">
                <tr>
                    <td class="summary-label">Candidatos a alcaldía</td>
                    <td class="summary-value">{{ $totalCandidatos }}</td>
                    <td class="summary-label">Concejales electos</td>
                    <td class="summary-value">{{ $totalConcejales }}</td>
                </tr>
                @if($electoAlcalde)
                <tr>
                    <td class="summary-label">Alcalde electo</td>
                    <td class="summary-value" colspan="3">
                        <strong>{{ $electoAlcalde->name }}</strong>
                        @if(!empty($electoAlcalde->partido))
                            &nbsp;·&nbsp; {{ $electoAlcalde->partido }}
                        @endif
                        &nbsp;·&nbsp; {{ number_format($electoAlcalde->votos, 0, ',', '.') }} votos
                    </td>
                </tr>
                @endif
            </table>
        </div>

        <!-- RESULTADOS ALCALDÍA -->
        <div class="section-title">Resultados Alcaldía</div>

        @if($candidatos->isEmpty())
            <p class="empty">No se encontraron candidatos a alcaldía registrados para este municipio.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:28px;">#</th>
                        <th>Candidato</th>
                        <th>Partido / Movimiento</th>
                        <th class="num" style="width:70px;">Votos</th>
                        <th style="width:80px;">% del total</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                    @php $totalVotoAlcaldia = $candidatos->sum('votos') ?: 1; @endphp
                    @foreach($candidatos as $i => $cand)
                    @php
                        $pct     = $totalVotoAlcaldia > 0 ? round(($cand->votos / $totalVotoAlcaldia) * 100, 1) : 0;
                        $outcome = strtolower($cand->outcome ?? '');
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
                            default     => ucfirst($cand->outcome ?? 'N/D'),
                        };
                        $rankClass = match($i) { 0 => 'rank rank-1', 1 => 'rank rank-2', 2 => 'rank rank-3', default => 'rank' };
                        $barWidth  = $maxVotos > 0 ? round(($cand->votos / $maxVotos) * 100) : 0;
                    @endphp
                    <tr>
                        <td class="{{ $rankClass }}">{{ $i + 1 }}</td>
                        <td>{{ $cand->name }}</td>
                        <td>{{ $cand->partido ?? '—' }}</td>
                        <td class="num">{{ $cand->votos > 0 ? number_format($cand->votos, 0, ',', '.') : '—' }}</td>
                        <td>
                            {{ $pct }}%
                            <div class="vote-bar-wrap">
                                <div class="vote-bar-fill" style="width: {{ $barWidth }}%;"></div>
                            </div>
                        </td>
                        <td><span class="badge {{ $badgeClass }}">{{ $outcomeLabel }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <!-- CONCEJO MUNICIPAL -->
        <div class="section-title">Concejo Municipal — Miembros Electos</div>

        @if($concejales->isEmpty())
            <p class="empty">No se encontraron concejales electos registrados para este municipio.</p>
        @else
            @php $maxVotosConcejo = $concejales->max('votos') ?: 1; @endphp
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:28px;">#</th>
                        <th>Concejal</th>
                        <th>Partido / Movimiento</th>
                        <th class="num" style="width:70px;">Votos</th>
                        <th style="width:80px;">% del total</th>
                    </tr>
                </thead>
                <tbody>
                    @php $totalVotosConcejo = $concejales->sum('votos') ?: 1; @endphp
                    @foreach($concejales as $i => $concejal)
                    @php
                        $pct      = $totalVotosConcejo > 0 ? round(($concejal->votos / $totalVotosConcejo) * 100, 1) : 0;
                        $barWidth = $maxVotosConcejo > 0 ? round(($concejal->votos / $maxVotosConcejo) * 100) : 0;
                        $rankClass = match($i) { 0 => 'rank rank-1', 1 => 'rank rank-2', 2 => 'rank rank-3', default => 'rank' };
                    @endphp
                    <tr>
                        <td class="{{ $rankClass }}">{{ $i + 1 }}</td>
                        <td>{{ $concejal->name }}</td>
                        <td>{{ $concejal->partido ?? '—' }}</td>
                        <td class="num">{{ $concejal->votos > 0 ? number_format($concejal->votos, 0, ',', '.') : '—' }}</td>
                        <td>
                            {{ $pct }}%
                            <div class="vote-bar-wrap">
                                <div class="vote-bar-fill" style="width: {{ $barWidth }}%;"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

    </div><!-- /content -->

</body>
</html>
