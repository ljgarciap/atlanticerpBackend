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
    'password_change_required' => 'You must change your password before continuing.',
    'password_same_as_email'   => 'The new password cannot be the same as your email.',
    'password_same_as_current' => 'The new password cannot be the same as the current one.',
    'password_incorrect' => 'The current password is incorrect.',
    'mfa_challenge_mismatch' => 'This verification does not match your session.',

    // H2/H3/H4 closure of the administrative password reset
    'too_many_attempts'          => 'Too many attempts. Try again in :seconds seconds.',
    'service_unavailable'        => 'The service is temporarily unavailable. Please try again in a few minutes.',
    'password_reset_expired'     => 'Your temporary password has expired. Ask an administrator to reset it again or use "Forgot my password".',
    'password_reset_disabled'    => 'The administrative password reset is disabled in this environment.',
    'operator_password_required' => 'Confirm your password to perform this action.',
    'operator_reauth_failed'     => 'The password you entered is not correct.',
    'operator_role_revoked'      => 'You no longer have the superadmin role.',
    // SCRUM-1158 — "View as user"
    'impersonation_ended'      => 'The "View as user" session has ended. Go back to your session.',
    'impersonation_read_only'  => 'Read-only mode: changes are not saved while you view the application as another user.',
    'impersonation_nested'     => 'You cannot start "View as user" from a "View as user" session.',
    'impersonation_target_invalid' => 'You cannot view as this person: they must be active, not security-blocked, not a guest, not a superadmin, and not yourself.',
    'impersonation_reason_short'   => 'The reason must be at least :min characters long.',
    'impersonation_not_active'     => 'There is no active "View as user" session.',

    'mail' => [
        'footer' => 'AtlanticERP CRM · Atlantic · Do not reply to this email',
        'greeting' => 'Hello, :name',
        'admin_reset' => [
            'subject' => 'Your password was reset — AtlanticERP CRM',
            'tag'     => 'Password reset',
            'body'    => 'An administrator reset your account password. Your temporary password is your email address and you must change it when you sign in.',
            'expires' => 'You must do it before: :date. After that the temporary password stops working and you will need to ask for another reset.',
            'notme'   => 'If you were not expecting this change, tell the administrator right away.',
        ],
        'activation' => [
            'subject' => 'Activate your account — AtlanticERP',
            'tag'     => 'Activate account',
            'body'    => 'Your account was created (or reset). Choose your password with the button to sign in for the first time:',
            'button'  => 'Choose my password',
            'fallback' => 'If the button does not work, copy and paste this link into your browser:',
            'warning' => 'This link works once and expires in :hours hours. If it expires, ask an administrator to send you another or use “Forgot my password”.',
        ],
        'reset' => [
            'subject' => 'Reset your password — AtlanticERP CRM',
            'tag'     => 'Reset password',
            'body'    => 'We received a request to reset your account password. Click the button to continue:',
            'button'  => 'Reset password',
            'fallback' => 'If the button does not work, copy and paste this link into your browser:',
            'warning' => 'This link expires in 1 hour. If you did not request a password reset, ignore this email.',
        ],
        'changed' => [
            'subject' => 'Your password was changed — AtlanticERP CRM',
            'tag'     => 'Password changed',
            'body'    => 'Your account password was changed on :date.',
            'notme'   => 'If it was not you, tell the administrator right away.',
        ],
    ],

];
