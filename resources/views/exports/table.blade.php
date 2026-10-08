<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a2e; margin: 0; padding: 0; }
        .header { background: #003B71; color: white; padding: 15px 20px; }
        .header h1 { font-size: 16px; font-weight: bold; margin: 0; }
        .header p { font-size: 9px; opacity: 0.6; margin: 3px 0 0; }
        .date { float: right; font-size: 9px; opacity: 0.5; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #003B71; color: white; padding: 5px 6px; text-align: left; font-size: 8px; text-transform: uppercase; font-weight: bold; }
        td { padding: 4px 6px; border-bottom: 1px solid #ddd; font-size: 9px; }
        .alt { background: #f5f5f5; }
        .b { font-weight: bold; }
        .r { text-align: right; font-weight: bold; font-family: monospace; }
        .e { background: #d4edda; }
        .footer { text-align: center; padding: 10px; font-size: 8px; color: #999; border-top: 1px solid #003B71; margin-top: 5px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <span class="date">{{ $date }}</span>
        <h1>{{ $title }}</h1>
        <p>Inteligencia Electoral Santander &middot; {{ count($data) }} registros</p>
    </div>

    @php $chunks = array_chunk($data, 40); @endphp

    @foreach($chunks as $chunkIndex => $chunk)
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
            @foreach($chunk as $i => $row)
                @php
                    $rowNum = $chunkIndex * 40 + $i;
                    $isElecto = str_contains(strtolower($row['cargo'] ?? ''), 'electo');
                    $rowClass = $isElecto ? 'e' : ($rowNum % 2 === 1 ? 'alt' : '');
                @endphp
                <tr class="{{ $rowClass }}">
                    <td>{{ $rowNum + 1 }}</td>
                    @foreach($columns as $col)
                        @if($col === 'nombre')
                            <td class="b">{{ $row[$col] ?? '—' }}@if($isElecto) ✓@endif</td>
                        @elseif($col === 'votos')
                            <td class="r">{{ ($row[$col] ?? 0) > 0 ? number_format($row[$col], 0, ',', '.') : '—' }}</td>
                        @else
                            <td>{{ $row[$col] ?? '—' }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($chunkIndex < count($chunks) - 1)
        <div class="page-break"></div>
    @endif
    @endforeach

    <div class="footer">
        Inteligencia Electoral Santander &middot; {{ $date }} &middot; {{ count($data) }} registros
    </div>
</body>
</html>
