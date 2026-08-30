<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 480px; margin: 0 auto; border: 1px solid #e2e8f0; }
        .logo { color: #2c8c84; font-size: 20px; font-weight: bold; letter-spacing: 3px; margin-bottom: 4px; }
        .brand { color: #2a2520; font-size: 11px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 24px; }
        .tag { background: #edf7e9; color: #2d6a4f; font-size: 11px; font-weight: bold; padding: 3px 10px; border-radius: 4px; display: inline-block; margin-bottom: 20px; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .credentials { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .credentials p { margin: 0 0 6px; font-size: 13px; color: #475569; }
        .credentials p:last-child { margin: 0; }
        .credentials strong { color: #1e293b; font-family: monospace; font-size: 14px; }
        .btn { display: block; background: #2c8c84; color: #fff; text-decoration: none; text-align: center; padding: 14px 24px; border-radius: 8px; font-weight: 700; font-size: 15px; margin: 24px 0; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; color: #92400e; font-size: 12px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <div class="brand">ATLANTICERP</div>
        <span class="tag">Bienvenido</span>
        <h2>Hola, {{ $firstName }}</h2>
        <p>Tu cuenta en AtlanticERP CRM ha sido creada. Estas son tus credenciales de acceso temporales:</p>
        <div class="credentials">
            <p>Contraseña temporal: <strong>{{ $temporaryPassword }}</strong></p>
        </div>
        <p>Por seguridad, te recomendamos cambiar tu contraseña en el primer ingreso.</p>
        <a href="{{ $loginUrl }}" class="btn">Iniciar sesión</a>
        <div class="warning">
            🔒 Tu contraseña temporal es tu dirección de correo electrónico. Cámbiala en cuanto ingreses desde tu perfil.
        </div>
        <div class="footer">AtlanticERP CRM · Illuminations · No respondas a este correo</div>
    </div>
</body>
</html>
