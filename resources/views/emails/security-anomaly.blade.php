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
        .tag { font-size: 11px; font-weight: bold; padding: 3px 10px; border-radius: 4px; display: inline-block; margin-bottom: 20px; }
        .tag-high { background: #fee2e2; color: #991b1b; }
        .tag-medium { background: #fef3c7; color: #92400e; }
        .tag-low { background: #e0f2fe; color: #075985; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .reasoning { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 13px; color: #334155; margin-bottom: 16px; }
        .btn { display: block; background: #2c8c84; color: #fff; text-decoration: none; text-align: center; padding: 14px 24px; border-radius: 8px; font-weight: 700; font-size: 15px; margin: 24px 0; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; color: #92400e; font-size: 12px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <div class="brand">ATLANTICERP</div>
        @php
            $tagClass = ['high' => 'tag-high', 'medium' => 'tag-medium', 'low' => 'tag-low'][$severity] ?? 'tag-low';
        @endphp
        <span class="tag {{ $tagClass }}">Severidad {{ ucfirst($severity) }}</span>
        <h2>Anomalía de seguridad detectada</h2>
        <p>
            @if($affectedUserName)
                Usuario afectado: <strong>{{ $affectedUserName }}</strong>
            @else
                Usuario afectado: no identificado
            @endif
        </p>
        <div class="reasoning">{{ $reasoning }}</div>
        <p>Revisá el panel de alertas de seguridad para confirmar o descartar esta sugerencia. Ninguna acción se ejecuta automáticamente — vos decidís.</p>
        <a href="{{ $panelUrl }}" class="btn">Ir al panel de alertas</a>
        <div class="warning">
            ⚠️ Este es un análisis automático. Verificá el contexto antes de confirmar cualquier bloqueo.
        </div>
        <div class="footer">AtlanticERP CRM · Illuminations · No respondas a este correo</div>
    </div>
</body>
</html>
