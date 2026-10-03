<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg, #003B71, #00264d); padding:32px 24px; text-align:center;">
                            <h1 style="color:#ffffff; font-size:18px; margin:0; letter-spacing:1px;">
                                INTELIGENCIA ELECTORAL
                            </h1>
                            <p style="color:rgba(255,255,255,0.5); font-size:11px; margin:4px 0 0; letter-spacing:2px;">
                                SANTANDER &middot; COLOMBIA
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="font-size:16px; color:#1f2937; margin:0 0 16px;">
                                Hola <strong>{{ $userName }}</strong>,
                            </p>
                            <p style="font-size:14px; color:#4b5563; line-height:1.6; margin:0 0 24px;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta.
                                Haz clic en el botón de abajo para crear una nueva contraseña.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $resetUrl }}"
                                           style="display:inline-block; background:#003B71; color:#ffffff; text-decoration:none; padding:14px 40px; border-radius:8px; font-size:14px; font-weight:bold; letter-spacing:0.5px;">
                                            Restablecer Contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:13px; color:#6b7280; line-height:1.6; margin:0 0 16px;">
                                Este enlace expira en <strong>60 minutos</strong>. Si no solicitaste el cambio de contraseña, puedes ignorar este correo.
                            </p>

                            <p style="font-size:11px; color:#9ca3af; line-height:1.5; margin:0; padding-top:16px; border-top:1px solid #e5e7eb;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                                <span style="color:#6b7280; word-break:break-all;">{{ $resetUrl }}</span>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 24px; border-top:1px solid #e5e7eb; text-align:center;">
                            <p style="font-size:11px; color:#9ca3af; margin:0;">
                                Este es un correo automático. No respondas a este mensaje.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
