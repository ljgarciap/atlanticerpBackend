<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT — clave de firma y expiración
    |--------------------------------------------------------------------------
    |
    | Auth del proyecto: JWT (HS256, vía firebase/php-jwt) + bcrypt para el
    | hash de contraseñas (ver columna `password_hash` en `{tenant}_auth.users`).
    | Ver sección "Auth" en atlanticerp/CLAUDE.md.
    |
    */

    'secret' => env('JWT_SECRET'),

    'expiration_ms' => (int) env('JWT_EXPIRATION_MS', 3_600_000),

    'refresh_token_expiration_days' => (int) env('REFRESH_TOKEN_EXPIRATION_DAYS', 30),

];
