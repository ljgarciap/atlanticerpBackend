<?php

/*
 * SCRUM-1032 (A-07) — CORS restringido. Antes no existía este archivo y aplicaba el valor por defecto del framework
 * (`allowed_origins => ['*']`). La SPA se sirve desde el mismo origen que la API (nginx), así que no necesita CORS;
 * se permiten solo el dominio propio del entorno y, opcionalmente, orígenes extra por `CORS_ALLOWED_ORIGINS`
 * (lista separada por comas). La API usa Bearer, no cookies: `supports_credentials` queda en false.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        static fn ($o) => rtrim(trim((string) $o), '/'),
        array_merge(
            [env('APP_URL'), env('FRONTEND_URL')],
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        ),
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
