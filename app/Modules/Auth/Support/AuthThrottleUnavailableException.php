<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1031 (A-01): el almacén de los límites de intentos (Redis) no responde y el flujo está marcado FAIL-CLOSED
 * (`AuthThrottle::CERRAR`: verificación de MFA y re-autenticación del operador, cuyo único freno contra adivinar un código es ese
 * contador). Se responde 503 explícito en vez de un 500 crudo; los tokens ya emitidos siguen valiendo.
 */
final class AuthThrottleUnavailableException extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 30;

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Auth throttle store unavailable.', 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => trans('auth.service_unavailable'),
            'code'    => 'service_unavailable',
        ], 503)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }
}
