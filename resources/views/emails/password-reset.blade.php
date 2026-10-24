<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 480px; margin: 0 auto; border: 1px solid #e2e8f0; }
        .logo { color: #2B3F73; font-size: 20px; font-weight: bold; letter-spacing: 3px; margin-bottom: 4px; }
        .brand { color: #2a2520; font-size: 11px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 24px; }
        .tag { background: #d1ede9; color: #1f6b66; font-size: 11px; font-weight: bold; padding: 3px 10px; border-radius: 4px; display: inline-block; margin-bottom: 20px; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .btn { display: block; background: #2B3F73; color: #fff; text-decoration: none; text-align: center; padding: 14px 24px; border-radius: 8px; font-weight: 700; font-size: 15px; margin: 24px 0; }
        .url-wrap { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 11px; color: #64748b; word-break: break-all; margin-bottom: 16px; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; color: #92400e; font-size: 12px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <div class="brand">ATLANTICERP</div>
        <span class="tag">{{ $tag }}</span>
        <h2>{{ $greeting }}</h2>
        <p>{{ $body }}</p>
        <a href="{{ $resetUrl }}" class="btn">{{ $button }}</a>
        <p>{{ $fallback }}</p>
        <div class="url-wrap">{{ $resetUrl }}</div>
        <div class="warning">
            {{ $warning }}
        </div>
        <div class="footer">{{ $footer }}</div>
    </div>
</body>
</html>
