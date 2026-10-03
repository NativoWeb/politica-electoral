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
                    {{-- Header --}}
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

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="font-size:16px; color:#1f2937; margin:0 0 16px;">
                                Hola <strong>{{ $userName }}</strong>,
                            </p>
                            <p style="font-size:14px; color:#4b5563; line-height:1.6; margin:0 0 24px;">
                                Se ha creado tu cuenta en la plataforma de Inteligencia Electoral de Santander.
                                A continuación tus credenciales de acceso:
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:24px;">
                                <tr>
                                    <td style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                                        <span style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:1px;">Correo</span><br>
                                        <strong style="font-size:14px; color:#1f2937;">{{ $userEmail }}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <span style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:1px;">Contraseña temporal</span><br>
                                        <strong style="font-size:14px; color:#1f2937; font-family:monospace;">{{ $plainPassword }}</strong>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:14px; color:#4b5563; line-height:1.6; margin:0 0 24px;">
                                Al iniciar sesión por primera vez se te pedirá cambiar tu contraseña.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $loginUrl }}"
                                           style="display:inline-block; background:#003B71; color:#ffffff; text-decoration:none; padding:12px 32px; border-radius:8px; font-size:14px; font-weight:bold; letter-spacing:0.5px;">
                                            Iniciar Sesión
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
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
