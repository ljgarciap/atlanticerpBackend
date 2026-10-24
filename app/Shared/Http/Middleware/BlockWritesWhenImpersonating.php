<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-1158 («Ver como usuario», ADR-010, regla RN5) — mientras el JWT lleve el claim `act`, la sesión es de SOLO LECTURA: toda petición cuyo
 * método no sea GET/HEAD/OPTIONS responde 403 `impersonation_read_only`, sin importar qué ruta sea (cambio de contraseña, MFA, perfil, IA y
 * cualquier endpoint futuro incluidos). Una única regla global no puede olvidarse en un módulo nuevo.
 *
 * Única excepción exacta: `POST /api/auth/impersonation/stop`, para que el actor pueda terminar su propia sesión.
 *
 * Se evalúa también el método REAL de la petición (`getRealMethod()`), no solo el efectivo: Laravel permite que un POST traiga `_method=GET`
 * y el router lo trate como GET; bloquearlo por el método real cierra ese rodeo sin costo (un POST nunca es lectura).
 *
 * Cada intento bloqueado queda en `audit_logs` (`auth.impersonation.write_blocked`, autor = actor real vía AuditService) y alimenta la regla de
 * alerta de `SecurityAnalysisJob` (condición C7 del threat model: muchos intentos seguidos suelen ser un guion probando endpoints).
 *
 * Debe correr DESPUÉS de AuthenticateJwt (necesita el JWT ya validado en `$request->attributes`).
 */
class BlockWritesWhenImpersonating
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const STOP_PATH = 'api/auth/impersonation/stop';

    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->attributes->get('jwt');

        if (! is_array($payload) || ! array_key_exists('act', $payload)) {
            return $next($request);
        }

        $real      = strtoupper($request->getRealMethod());
        $effective = strtoupper($request->getMethod());

        if (in_array($real, self::SAFE_METHODS, true) && in_array($effective, self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($real === 'POST' && $effective === 'POST' && trim($request->path(), '/') === self::STOP_PATH) {
            return $next($request);
        }

        return self::reject($request);
    }

    /** Audita el intento y responde 403 `impersonation_read_only`. Compartido con `DenyWhenImpersonating` (GET que escriben). */
    public static function reject(Request $request): Response
    {
        app(AuditService::class)->log(
            action:     AuditService::AUTH_IMPERSONATION_WRITE_BLOCKED,
            entityType: 'route',
            entityId:   $request->path(),
            metadata:   ['method' => strtoupper($request->getRealMethod())],
        );

        return response()->json([
            'message' => trans('auth.impersonation_read_only'),
            'code'    => 'impersonation_read_only',
        ], 403);
    }
}
