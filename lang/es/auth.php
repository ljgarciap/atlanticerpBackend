<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de idioma para autenticación (es)
    |--------------------------------------------------------------------------
    |
    | Las siguientes líneas se usan durante la autenticación para los mensajes
    | que se muestran al usuario.
    |
    */

    'failed'   => 'Credenciales inválidas.',
    'password' => 'La contraseña proporcionada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Por favor, inténtalo de nuevo en :seconds segundos.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes personalizados de AtlanticERP
    |--------------------------------------------------------------------------
    */

    'unauthenticated' => 'No autenticado. Por favor, inicia sesión.',
    'unauthorized'    => 'No tienes permiso para realizar esta acción.',
    'token_expired'   => 'Tu sesión ha expirado. Por favor, inicia sesión de nuevo.',
    'token_invalid'   => 'Token de acceso inválido.',
    'user_inactive'   => 'Tu cuenta está desactivada. Contacta al administrador.',
    'mfa_delivery_failed' => 'No pudimos enviar el código de verificación. Intenta de nuevo en unos minutos.',
    'password_change_required' => 'Debes cambiar tu contraseña antes de continuar.',
    'password_same_as_email'   => 'La nueva contraseña no puede ser igual a tu correo.',
    'password_same_as_current' => 'La nueva contraseña no puede ser igual a la actual.',
    'password_incorrect' => 'La contraseña actual es incorrecta.',
    'mfa_challenge_mismatch' => 'La verificación no corresponde a tu sesión.',

    // Cierre H2/H3/H4 del reset administrativo de contraseñas
    'too_many_attempts'          => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',
    'service_unavailable'        => 'El servicio no está disponible temporalmente. Vuelve a intentarlo en unos minutos.',
    'password_reset_expired'     => 'Tu contraseña temporal venció. Pide a un administrador que la restablezca de nuevo o usa «Olvidé mi contraseña».',
    'password_reset_disabled'    => 'El reset administrativo de contraseñas está deshabilitado en este entorno.',
    'operator_password_required' => 'Confirma tu contraseña para realizar esta acción.',
    'operator_reauth_failed'     => 'La contraseña que ingresaste no es correcta.',
    'operator_role_revoked'      => 'Ya no tienes el rol de superadministrador.',
    // SCRUM-1158 — «Ver como usuario»
    'impersonation_ended'      => 'La sesión de «Ver como usuario» terminó. Vuelve a tu sesión.',
    'impersonation_read_only'  => 'Modo solo lectura: no se guardan cambios mientras ves la aplicación como otro usuario.',
    'impersonation_nested'     => 'No puedes iniciar «Ver como usuario» desde una sesión de «Ver como usuario».',
    'impersonation_target_invalid' => 'No se puede ver como esta persona: debe estar activa, sin bloqueo de seguridad, no ser invitada, ni superadministradora, ni tú mismo.',
    'impersonation_reason_short'   => 'El motivo debe tener al menos :min caracteres.',
    'impersonation_not_active'     => 'No hay una sesión de «Ver como usuario» activa.',

    'mail' => [
        'footer' => 'AtlanticERP CRM · Atlantic · No respondas a este correo',
        'greeting' => 'Hola, :name',
        'admin_reset' => [
            'subject' => 'Tu contraseña fue restablecida — AtlanticERP CRM',
            'tag'     => 'Contraseña restablecida',
            'body'    => 'Un administrador restableció la contraseña de tu cuenta. Tu contraseña temporal es tu correo electrónico y debes cambiarla al iniciar sesión.',
            'expires' => 'Debes hacerlo antes de: :date. Pasado ese plazo la contraseña temporal deja de funcionar y tendrás que pedir otro restablecimiento.',
            'notme'   => 'Si no esperabas este cambio, avisa de inmediato al administrador.',
        ],
        'reset' => [
            'subject' => 'Restablece tu contraseña — AtlanticERP CRM',
            'tag'     => 'Restablecer contraseña',
            'body'    => 'Recibimos una solicitud para restablecer la contraseña de tu cuenta. Haz clic en el botón para continuar:',
            'button'  => 'Restablecer contraseña',
            'fallback' => 'Si el botón no funciona, copia y pega este enlace en tu navegador:',
            'warning' => 'Este enlace expira en 1 hora. Si no solicitaste restablecer tu contraseña, ignora este correo.',
        ],
        'changed' => [
            'subject' => 'Tu contraseña fue cambiada — AtlanticERP CRM',
            'tag'     => 'Contraseña cambiada',
            'body'    => 'La contraseña de tu cuenta fue cambiada el :date.',
            'notme'   => 'Si no fuiste tú, avisa de inmediato al administrador.',
        ],
    ],

];
