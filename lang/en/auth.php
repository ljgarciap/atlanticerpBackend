<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines (en — fallback)
    |--------------------------------------------------------------------------
    |
    | These lines are used during authentication for messages displayed to
    | the user. AtlanticERP custom keys are also included for consistency with es/.
    |
    */

    'failed'   => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    /*
    |--------------------------------------------------------------------------
    | AtlanticERP custom keys (mirror of es/auth.php)
    |--------------------------------------------------------------------------
    */

    'unauthenticated' => 'Unauthenticated. Please log in.',
    'unauthorized'    => 'You do not have permission to perform this action.',
    'token_expired'   => 'Your session has expired. Please log in again.',
    'token_invalid'   => 'Invalid access token.',
    'user_inactive'   => 'Your account is deactivated. Contact the administrator.',
    'mfa_delivery_failed' => 'We could not send the verification code. Please try again in a few minutes.',
    'password_incorrect' => 'The current password is incorrect.',
    'mfa_challenge_mismatch' => 'This verification does not match your session.',

];
