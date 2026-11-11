<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Compromisos pendientes</title></head>
<body style="margin:0;padding:24px;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#111;">
    @php($tipos = \App\Services\Eventos\Actas\CompromisoAlertas::TIPOS)
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : null)
    <div style="max-width:620px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;">
        <div style="background:#003B71;color:#fff;padding:18px 24px;">
            <p style="margin:0;font-size:12px;letter-spacing:1px;">INTELIGENCIA ELECTORAL SANTANDER</p>
            <h1 style="margin:6px 0 0;font-size:20px;">Compromisos que necesitan atención</h1>
        </div>
        <div style="padding:20px 24px;font-size:15px;line-height:1.5;">
            <p>Hola {{ $userName }}:</p>
            <p>
                Hoy tiene <b>{{ $resumen['vencidos'] }}</b> {{ $resumen['vencidos'] === 1 ? 'compromiso vencido' : 'compromisos vencidos' }},
                <b>{{ $resumen['por_vencer'] }}</b> por vencer en los próximos {{ \App\Services\Eventos\Actas\CompromisoAlertas::DIAS }} días
                y <b>{{ $resumen['recordatorios'] }}</b> con recordatorio.
            </p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                @foreach ($resumen['items'] as $item)
                    <tr>
                        <td style="padding:10px 0;border-top:1px solid #e3e7ee;vertical-align:top;width:110px;">
                            <b style="color:{{ $item['tipo'] === 'vencido' ? '#b91c1c' : ($item['tipo'] === 'por_vencer' ? '#b45309' : '#003B71') }};">{{ $tipos[$item['tipo']] }}</b>
                        </td>
                        <td style="padding:10px 0;border-top:1px solid #e3e7ee;">
                            {{ \Illuminate\Support\Str::limit($item['descripcion'], 220) }}<br>
                            <span style="color:#555;font-size:13px;">
                                {{ collect([$item['evento'], $item['fecha_limite'] ? 'Fecha límite: '.$fecha($item['fecha_limite']) : null, $item['tipo'] === 'recordatorio' && $item['recordar_el'] ? 'Recordar el: '.$fecha($item['recordar_el']) : null])->filter()->implode(' · ') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </table>
            @if ($resumen['total'] > count($resumen['items']))
                <p style="color:#555;font-size:13px;">Y {{ $resumen['total'] - count($resumen['items']) }} más.</p>
            @endif
            <p style="margin:24px 0 8px;">
                <a href="{{ $url }}" style="display:inline-block;background:#003B71;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">Ver los compromisos</a>
            </p>
        </div>
    </div>
</body>
</html>
