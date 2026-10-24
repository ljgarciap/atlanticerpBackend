<?php

declare(strict_types=1);

return [
    /*
    | SCRUM-1114 (revisión de seguridad S-03) — dominios de correo permitidos para `correos_alerta` del
    | sync de stock (separados por coma, p. ej. "atlantic.com.pa"). Vacío = sin restricción. Existe
    | porque un superadmin comprometido podría agregar un correo externo para recibir los avisos del sync
    | (volumen, productos y errores). Los superadmins activos siempre reciben el aviso por su correo de cuenta.
    */
    'alert_allowed_domains' => array_values(array_filter(array_map(
        static fn (string $d): string => strtolower(trim($d)),
        explode(',', (string) env('LEGACY_ALERT_ALLOWED_DOMAINS', '')),
    ))),

    /*
    | SCRUM-1136 F6 — carga única del stock de ICG a Bodega (`legacy:cargar-stock-bodegas`). Artículos de ICG que NUNCA se cargan como stock
    | (separados por coma): decisión de Luis 2026-10-15 — 2999 «REO OK 3033 S1 W» (sin referencia) y 68602 (línea de servicio, no es un bien físico).
    | Se listan en la salida del comando para revisión manual.
    */
    'carga_stock_excluir_codarticulos' => array_values(array_filter(array_map(
        static fn (string $c): int => (int) trim($c),
        explode(',', (string) env('LEGACY_CARGA_STOCK_EXCLUIR', '2999,68602')),
    ))),
];
