<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Límites de intentos de autenticación (H4 de la revisión de seguridad del reset administrativo de contraseñas). Envoltorio fino de
 * `RateLimiter` con las claves y los valores de `config/throttle.php`: los controladores consultan `bloqueado()` ANTES de verificar
 * credenciales y registran con `golpear()` solo lo que corresponde (fallos, o todas las solicitudes en olvidé mi contraseña).
 *
 * No hay bloqueo permanente de cuentas: el cupo se libera solo al vencer la ventana. Las claves usan el correo normalizado y
 * hasheado (nunca el correo en claro dentro de la caché).
 */
final class AuthThrottle
{
    /** Política ante una caída del almacén (Redis), SCRUM-1031: el flujo sigue sin límite y se avisa (fail-open). */
    public const ABRIR = 'abrir';

    /** Política ante una caída del almacén: 503 explícito `service_unavailable` (fail-closed). */
    public const CERRAR = 'cerrar';

    private const DEGRADED_LOG_EVERY = 60;   // segundos entre avisos por proceso

    private static int $lastDegradedAt = 0;

    /** Correo normalizado (minúsculas, sin espacios) y hasheado para usarlo como parte de una clave. */
    public static function emailKey(string $email): string
    {
        // nosemgrep: atlanticerp-weak-hash — huella NO criptográfica: solo arma una clave de caché sin guardar el correo en claro
        return sha1(mb_strtolower(trim($email)));
    }

    /**
     * Segundos que faltan para que se libere el cupo si alguna de las claves ya agotó su máximo; null si ninguna está agotada.
     *
     * @param array<string, int> $limites clave => máximo de intentos en la ventana
     */
    public static function bloqueado(array $limites): ?int
    {
        $retry = null;
        foreach ($limites as $clave => $max) {
            if ($max > 0 && RateLimiter::tooManyAttempts($clave, $max)) {
                $retry = max($retry ?? 0, RateLimiter::availableIn($clave));
            }
        }

        return $retry;
    }

    /**
     * Reserva atómicamente un intento en cada clave ANTES de evaluar credenciales (R3-5 de la ronda 3): una ráfaga paralela ya no
     * pasa toda junta el chequeo previo, porque cada petición incrementa el contador antes de decidir. Devuelve los segundos de
     * espera si alguna clave superó su máximo (esa petición no debe evaluarse), o null si puede seguir. Quien tenga éxito debe
     * devolver el cupo con `devolver()`/`limpiar()` para que solo cuenten los fallos.
     *
     * @param array<string, array{0: int, 1: int}> $limites clave => [máximo de intentos, ventana en segundos]
     */
    public static function reservar(array $limites, string $enCaida): ?int
    {
        try {
            $retry = null;
            foreach ($limites as $clave => [$max, $decay]) {
                $n = RateLimiter::hit($clave, $decay);
                if ($max > 0 && $n > $max) {
                    $retry = max($retry ?? 0, RateLimiter::availableIn($clave));
                }
            }

            return $retry;
        } catch (\Throwable $e) {
            self::alFallar($e, $enCaida, (string) array_key_first($limites));

            return null;
        }
    }

    /** Cuenta un intento y devuelve cuántos lleva la clave en la ventana (incremento atómico del almacén). */
    public static function contar(string $clave, int $decaySeconds, string $enCaida): int
    {
        try {
            return RateLimiter::hit($clave, $decaySeconds);
        } catch (\Throwable $e) {
            self::alFallar($e, $enCaida, $clave);

            return 0;
        }
    }

    /** Devuelve un intento reservado con `reservar()` (la operación terminó bien y no debe consumir cupo). */
    public static function devolver(string $clave, int $decaySeconds): void
    {
        // Limpieza posterior a una operación ya resuelta: un fallo del almacén nunca debe convertir un éxito en un 500.
        try {
            if (RateLimiter::attempts($clave) > 0) {
                RateLimiter::decrement($clave, $decaySeconds);
            }
        } catch (\Throwable $e) {
            self::avisarDegradado($e, $clave);
        }
    }

    /** Registra un intento en la clave; vence tras `$decaySeconds`. */
    public static function golpear(string $clave, int $decaySeconds): void
    {
        try {
            RateLimiter::hit($clave, $decaySeconds);
        } catch (\Throwable $e) {
            self::avisarDegradado($e, $clave);
        }
    }

    public static function limpiar(string $clave): void
    {
        try {
            RateLimiter::clear($clave);
        } catch (\Throwable $e) {
            self::avisarDegradado($e, $clave);
        }
    }

    /**
     * Aplica la política del flujo cuando el almacén no responde: CERRAR lanza `AuthThrottleUnavailableException` (503); ABRIR avisa y
     * deja seguir. `$clave` solo sirve para saber de qué flujo viene (prefijo antes de los dos puntos), nunca se registra completa.
     *
     * @throws AuthThrottleUnavailableException
     */
    private static function alFallar(\Throwable $e, string $enCaida, string $clave): void
    {
        if ($enCaida === self::CERRAR) {
            Log::error('AuthThrottle: el almacén de límites no responde; flujo fail-closed (503)', ['flujo' => self::flujo($clave), 'exception' => $e::class]);

            throw new AuthThrottleUnavailableException($e);
        }

        self::avisarDegradado($e, $clave);
    }

    private static function avisarDegradado(\Throwable $e, string $clave): void
    {
        $now = time();
        if ($now - self::$lastDegradedAt < self::DEGRADED_LOG_EVERY) {
            return;
        }
        self::$lastDegradedAt = $now;

        $flujo = self::flujo($clave);
        Log::warning('AuthThrottle degradado: el almacén de límites no responde; el flujo sigue sin límite (fail-open)', ['flujo' => $flujo, 'exception' => $e::class]);

        // Evento de auditoría para SecurityAnalysisJob; puede no registrarse si aún no hay tenant activo (el log de arriba sí queda).
        try {
            app(\App\Shared\Services\AuditService::class)->log(
                action:   \App\Shared\Services\AuditService::AUTH_THROTTLE_DEGRADED,
                metadata: ['flujo' => $flujo],
            );
        } catch (\Throwable) {
            // sin tenant o sin base: nada más que hacer
        }
    }

    private static function flujo(string $clave): string
    {
        return explode(':', $clave)[0];
    }

    /** 429 con `code`, `retry_after` y la cabecera Retry-After. No revela nada sobre la cuenta. */
    public static function respuesta(int $retryAfter): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return response()->json([
            'message'     => trans('auth.too_many_attempts', ['seconds' => $retryAfter]),
            'code'        => 'too_many_attempts',
            'retry_after' => $retryAfter,
        ], 429)->header('Retry-After', (string) $retryAfter);
    }
}
