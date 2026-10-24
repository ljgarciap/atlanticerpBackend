<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

/** Tope de códigos MFA erróneos por usuario agotado: el controlador responde 429 con `retry_after` (no 422). */
final class MfaThrottledException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Demasiados intentos de verificación MFA.');
    }
}
