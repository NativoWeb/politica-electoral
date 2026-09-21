<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #1a1a2e; }
        .header { background: #003B71; color: white; padding: 20px 30px; }
        .header h1 { font-size: 18px; font-weight: 800; }
        .header p { font-size: 10px; opacity: 0.6; margin-top: 4px; }
        .header .date { float: right; font-size: 10px; opacity: 0.5; }
        .flag { display: inline-block; width: 24px; height: 16px; margin-right: 8px; vertical-align: middle; }
        .flag-y { background: #FCD116; height: 8px; }
        .flag-b { background: #003893; height: 4px; }
        .flag-r { background: #CE1126; height: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 0; }
        thead th {
            background: #003B71; color: white; padding: 8px 10px;
            text-align: left; font-size: 9px; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 700;
        }
        tbody td {
            padding: 6px 10px; border-bottom: 1px solid #e8e8ee;
            font-size: 10px; vertical-align: top;
        }
        tbody tr:nth-child(even) { background: #f8f9fa; }
        tbody tr.electo { background: #d4edda; }
        .nombre { font-weight: 700; text-transform: uppercase; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 8px; font-weight: 700; }
        .badge-electo { background: #d4edda; color: #28a745; }
        .badge-tipo { background: #e8e8ee; color: #4a4a68; }
        .votos { text-align: right; font-weight: 700; font-family: monospace; }
        .footer { text-align: center; padding: 15px; font-size: 9px; color: #8e8ea0; border-top: 2px solid #003B71; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <span class="date">{{ $date }}</span>
        <div class="flag"><div class="flag-y"></div><div class="flag-b"></div><div class="flag-r"></div></div>
        <h1>{{ $title }}</h1>
        <p>Inteligencia Electoral Santander · {{ count($data) }} registros</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                @foreach($columns as $col)
                    <th>{{ strtoupper(str_replace('_', ' ', $col)) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($data as $i => $row)
                @php
                    $isElecto = str_contains(strtolower($row['cargo'] ?? ''), 'electo');
                @endphp
                <tr class="{{ $isElecto ? 'electo' : '' }}">
                    <td>{{ $i + 1 }}</td>
                    @foreach($columns as $col)
                        <td class="{{ $col === 'nombre' ? 'nombre' : ($col === 'votos' ? 'votos' : '') }}">
                            @if($col === 'nombre')
                                {{ $row[$col] ?? '—' }}
                                @if($isElecto)
                                    <span class="badge badge-electo">ELECTO</span>
                                @endif
                            @elseif($col === 'tipo')
                                <span class="badge badge-tipo">{{ $row[$col] ?? '—' }}</span>
                            @elseif($col === 'votos')
                                {{ ($row[$col] ?? 0) > 0 ? number_format($row[$col], 0, ',', '.') : '—' }}
                            @else
                                {{ $row[$col] ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Inteligencia Electoral Santander · Generado el {{ $date }} · {{ count($data) }} registros
    </div>
</body>
</html>
