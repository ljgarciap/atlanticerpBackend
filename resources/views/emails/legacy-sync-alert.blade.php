<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 520px; margin: 0 auto; border: 1px solid #e2e8f0; }
        .logo { color: #2c8c84; font-size: 20px; font-weight: bold; letter-spacing: 3px; margin-bottom: 24px; }
        .tag { font-size: 11px; font-weight: bold; padding: 3px 10px; border-radius: 4px; display: inline-block; margin-bottom: 20px; background: #fef3c7; color: #92400e; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .detalle { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 13px; color: #334155; margin-bottom: 16px; }
        .detalle div { margin-bottom: 4px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <span class="tag">{{ $entorno }} · {{ $entidad }}</span>
        <h2>{{ $titulo }}</h2>
        <div class="detalle">
            @foreach($lineas as $linea)
                <div>{{ $linea }}</div>
            @endforeach
        </div>
        <p>Revise la pantalla de Migración. Ninguna corrida retenida se aplica sola: debe revisarse y, si corresponde, forzarse a mano.</p>
        <div class="footer">AtlanticERP CRM · Atlantic · No responda a este correo</div>
    </div>
</body>
</html>
