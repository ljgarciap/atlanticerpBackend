<!DOCTYPE html>
<html lang="{{ $lang ?? 'es' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 480px; margin: 0 auto; border: 1px solid #e2e8f0; }
        .logo { color: #2B3F73; font-size: 20px; font-weight: bold; letter-spacing: 3px; margin-bottom: 4px; }
        .brand { color: #2a2520; font-size: 11px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 24px; }
        h2 { color: #2a2520; font-size: 17px; margin: 0 0 10px; }
        p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .footer { margin-top: 28px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ATLANTICERP</div>
        <div class="brand">ATLANTIC</div>
        <h2>{{ trans('purchase_order_pdf.title', ['id' => $orderId], $lang ?? 'es') }}</h2>
        <p>{{ trans('purchase_order_pdf.mail.greeting', ['provider' => $providerName], $lang ?? 'es') }}</p>
        <p>{{ trans('purchase_order_pdf.mail.body', ['id' => $orderId], $lang ?? 'es') }}</p>
        <div class="footer">{{ trans('purchase_order_pdf.mail.footer', [], $lang ?? 'es') }}</div>
    </div>
</body>
</html>
