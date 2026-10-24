<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Límites de intentos de autenticación y de reset de contraseñas (H3/H4)
|--------------------------------------------------------------------------
|
| Todos los valores son configurables por entorno (no son reglas de negocio). `*_decay` está en SEGUNDOS.
| Revisión de seguridad del reset administrativo de contraseñas (docs/security/reset-password-revision-seguridad.md).
|
| - `login`: se cuentan SOLO los intentos fallidos (un login correcto no consume cupo, así 30 personas detrás de la misma IP
|   de la oficina no se bloquean al abrir sesión a la vez). Un tope por IP y otro por correo normalizado.
| - `refresh`: solo fallos por IP.
| - `mfa_verify`: fallos por IP; `mfa_verify.user_*` es el tope de códigos erróneos por USUARIO en la ventana (reenviar el código NO
|   lo reinicia).
| - `mfa_resend`: reenvíos por usuario.
| - `forgot_password`: se cuentan TODAS las solicitudes (existan o no los correos, para no revelar si el correo existe).
| - `register` (SCRUM-1034, A-05): se cuentan TODAS las solicitudes de registro por IP (existan o no los correos).
| - `mfa_send`: códigos MFA enviados por logins correctos, por usuario.
- `password_reset_admin`: peticiones por minuto POR USUARIO operador a las rutas de reset administrativo; `reauth_*` es el tope de
|   contraseñas de operador incorrectas en la ventana.
- `impersonation` (SCRUM-1158): igual que el anterior para «Ver como usuario». El cupo de contraseñas incorrectas es PROPIO (clave
|   `impersonation-reauth`), distinto del reset de contraseñas, para que un flujo no gaste el cupo del otro (condición C3).
*/
return [
    'login' => [
        'ip_max'      => (int) env('THROTTLE_LOGIN_IP_MAX', 10),
        'ip_decay'    => (int) env('THROTTLE_LOGIN_IP_DECAY', 60),
        'email_max'   => (int) env('THROTTLE_LOGIN_EMAIL_MAX', 5),
        'email_decay' => (int) env('THROTTLE_LOGIN_EMAIL_DECAY', 60),
    ],

    'refresh' => [
        'ip_max'   => (int) env('THROTTLE_REFRESH_IP_MAX', 30),
        'ip_decay' => (int) env('THROTTLE_REFRESH_IP_DECAY', 60),
    ],

    'mfa_verify' => [
        'ip_max'     => (int) env('THROTTLE_MFA_VERIFY_IP_MAX', 10),
        'ip_decay'   => (int) env('THROTTLE_MFA_VERIFY_IP_DECAY', 60),
        'user_max'   => (int) env('THROTTLE_MFA_VERIFY_USER_MAX', 5),
        'user_decay' => (int) env('THROTTLE_MFA_VERIFY_USER_DECAY', 600),
    ],

    'mfa_resend' => [
        'max'   => (int) env('THROTTLE_MFA_RESEND_MAX', 3),
        'decay' => (int) env('THROTTLE_MFA_RESEND_DECAY', 600),
    ],

    // R3-4: códigos MFA enviados por logins correctos con MFA activo, por usuario (cada login correcto manda un correo).
    'mfa_send' => [
        'max'   => (int) env('THROTTLE_MFA_SEND_MAX', 5),
        'decay' => (int) env('THROTTLE_MFA_SEND_DECAY', 600),
    ],

    'forgot_password' => [
        'ip_max'      => (int) env('THROTTLE_FORGOT_IP_MAX', 5),
        'ip_decay'    => (int) env('THROTTLE_FORGOT_IP_DECAY', 900),
        'email_max'   => (int) env('THROTTLE_FORGOT_EMAIL_MAX', 3),
        'email_decay' => (int) env('THROTTLE_FORGOT_EMAIL_DECAY', 3600),
    ],

    'password_reset_admin' => [
        'per_minute'   => (int) env('THROTTLE_PASSWORD_RESET_ADMIN_PER_MINUTE', 20),
        'reauth_max'   => (int) env('THROTTLE_PASSWORD_RESET_REAUTH_MAX', 5),
        'reauth_decay' => (int) env('THROTTLE_PASSWORD_RESET_REAUTH_DECAY', 900),
    ],

    'impersonation' => [
        'per_minute'   => (int) env('THROTTLE_IMPERSONATION_PER_MINUTE', 10),
        'reauth_max'   => (int) env('THROTTLE_IMPERSONATION_REAUTH_MAX', 5),
        'reauth_decay' => (int) env('THROTTLE_IMPERSONATION_REAUTH_DECAY', 900),
    ],
];
