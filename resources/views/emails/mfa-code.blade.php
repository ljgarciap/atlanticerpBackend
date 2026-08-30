<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 480px; margin: 0 auto; border: 1px solid #e2e8f0; }
        .logo { color: #2B3F73; font-size: 20px; font-weight: bold; letter-spacing: 3px; margin-bottom: 24px; }
        .tag { background: #d1ede9; color: #1f6b66; font-size: 11px; font-weight: bold; padding: 3px 10px; border-radius: 4px; display: inline-block; margin-bottom: 20px; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .code-box { background: #f8fafc; border: 2px dashed #2B3F73; border-radius: 10px; text-align: center; padding: 32px 16px; margin: 24px 0; }
        .code { font-size: 52px; font-weight: 900; letter-spacing: 12px; color: #2B3F73; font-family: 'Courier New', monospace; display: block; margin: 16px 0; }
        .expire { font-size: 12px; color: #94a3b8; margin-top: 8px; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; color: #92400e; font-size: 12px; margin-top: 16px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <span class="tag">Verificación en dos pasos</span>
        <h2>Hola, {{ $firstName }}</h2>
        <p>Usa este código para iniciar sesión en AtlanticERP CRM. Ingrésalo en la pantalla de verificación:</p>
        <div class="code-box">
            <br>
            <br>
            <div class="code">{{ $code }}</div>
            <br>
            <br>
            <div class="expire">Expira en 10 minutos</div>
        </div>
        <div class="warning">
            ⚠️ Si no solicitaste este código, alguien intentó acceder a tu cuenta. Cambia tu contraseña inmediatamente.
        </div>
        <div class="footer">AtlanticERP CRM · Atlantic · No compartas este código con nadie</div>
    </div>
</body>
</html>
