<?php

declare(strict_types=1);

/**
 * SCRUM-1127 — parámetros TÉCNICOS de la traducción con IA del PDF de la orden de compra (no son umbrales de negocio:
 * no tienen CRUD de superadmin; cambiarlos es un ajuste de infraestructura, no una decisión comercial).
 */
return [
    // Modelo de Claude para traducir (mismo que usa DraftMessageJob). Su costo sale de `ai_model_pricing`.
    'translation_model' => env('COMPRAS_TRANSLATION_MODEL', 'claude-haiku-4-5-20251001'),

    // Descripciones por llamada. Un pedido con más líneas se parte en varias llamadas; la traducción solo se guarda si TODAS tienen éxito.
    'translation_lines_per_call' => 40,

    // Segundos de espera por llamada a Claude. Debe ser MENOR que `fastcgi_read_timeout` de nginx (60 s): si no, nginx corta la petición antes
    // de que el servidor pueda responder con un error claro. Una orden de varias llamadas usa `translation_total_timeout`.
    'translation_timeout'       => 25,
    'translation_total_timeout' => 50,

    // Tope de tokens de salida por llamada (~40 descripciones traducidas con holgura).
    'translation_max_tokens' => 4096,

    // Una traducción más larga que (largo del original * factor + margen) se considera sospechosa y se rechaza.
    'translation_max_length_factor' => 3,
    'translation_max_length_margin' => 120,

    // Generaciones de traducción por minuto y por usuario (cada una es una llamada de pago a la IA).
    'translation_per_minute' => 6,
];
