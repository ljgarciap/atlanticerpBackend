<?php

namespace App\Shared\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Services\ImpersonationService;
use App\Modules\Auth\Services\PasswordResetPolicy;
use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida el JWT y, a partir del claim `tenant`, inicializa el search_path
 * de Postgres antes de pasar al handler. El `forgetCurrent()` se ejecuta
 * siempre en el finally, incluso si el handler lanza una excepción.
 *
 * SCRUM-823 — revocación inmediata de sesión: `is_active` se re-valida en CADA request, no solo
 * en login()/refresh(). Antes de este cambio, un usuario desactivado con un access
 * token todavía vigente seguía funcionando hasta que el token expiraba (session_timeout_min,
 * default 600 min) o intentaba refrescar — decisión explícita de Luis/CTO (2026-08-31) de que ese
 * delay no era aceptable. Costo: 1 query extra por request (por PK, indexada) — se aceptó el
 * costo antes que la complejidad de un blacklist en Redis, dado el volumen actual del sistema.
 */
class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $token = substr($header, 7);
        $payload = $this->jwt->decode($token);

        // SCRUM-1035 (A-06): un token de acceso nunca lleva el claim `type`; cualquiera que lo tenga (p. ej. el antiguo token de
        // selección de rol, sin rol ni permisos) no es un token de acceso y se rechaza.
        if ($payload === null || isset($payload['type'])) {
            return response()->json(['message' => trans('auth.token_invalid')], 401);
        }

        $tenantSlug = isset($payload['tenant']) && is_string($payload['tenant'])
            ? $payload['tenant']
            : null;

        if ($tenantSlug === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $request->attributes->set('jwt', $payload);
        $tenant->makeCurrent();

        try {
            $userId = $payload['sub'] ?? null;

            // SCRUM-1158 — un token con claim `act` es una sesión «Ver como usuario»: sin un `sub` válido no hay a quién atribuirla y se rechaza.
            // Es defensa en profundidad: `ImpersonationService::guard()` valida el resto, pero solo se llega a él con un `sub` entero.
            if (array_key_exists('act', $payload) && ! (is_int($userId) || (is_string($userId) && ctype_digit($userId)))) {
                return $this->impersonationEnded();
            }

            if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
                // UNA sola query por PK para is_active y must_change_password (reset administrativo de contraseña):
                // no se agrega ninguna query por request respecto a SCRUM-823.
                $row = User::where('id', (int) $userId)->first(['is_active', 'must_change_password', 'password_reset_at', 'password_changed_at']);

                // SCRUM-1158 (RN9, RN10, condición C5): una sesión «Ver como usuario» se revalida en BD en CADA request — sesión abierta y vigente,
                // actor activo y todavía superadmin, objetivo activo y sin bloqueo de seguridad. Solo corre con el claim `act`: las requests normales
                // no pagan ninguna query nueva. Va PRIMERO (antes incluso del `is_active` del objetivo): así, si el objetivo se desactiva a mitad de
                // sesión, la sesión se cierra y la respuesta es el 401 `impersonation_ended` (no un 401 genérico con la fila aún «activa»), y
                // reactivar a la persona después no revive el mismo token.
                if (array_key_exists('act', $payload) && app(ImpersonationService::class)->guard($payload) !== null) {
                    return $this->impersonationEnded();
                }

                if ($row === null || $row->is_active !== true) {
                    return response()->json(['message' => trans('auth.unauthenticated')], 401);
                }

                // H1 (revisión de seguridad del reset administrativo): un access token emitido ANTES del último reset
                // administrativo ya no vale. `iat` es de segundos y `password_reset_at` de microsegundos: se compara en
                // segundos fraccionarios, así un token emitido en el mismo segundo pero antes del reset también se rechaza
                // (un login legítimo en la fracción restante de ese segundo recibe 401 y basta reintentar).
                // SCRUM-1033 (A-02): igual para el cambio de contraseña propio (`password_changed_at`): gana el más reciente.
                $iat = isset($payload['iat']) && is_numeric($payload['iat']) ? (float) $payload['iat'] : 0.0;
                foreach ([$row->password_reset_at, $row->password_changed_at] as $changedAt) {
                    if ($changedAt !== null && $iat < (float) $changedAt->getTimestamp() + ((int) $changedAt->micro) / 1_000_000) {
                        return response()->json(['message' => trans('auth.unauthenticated')], 401);
                    }
                }

                // SCRUM-1158 (Senior Review R1) — en una sesión «Ver como usuario» las dos comprobaciones de contraseña del OBJETIVO se omiten: la sesión
                // es de solo lectura (el cambio de contraseña ya está bloqueado) y el caso típico de soporte es justo «no puedo entrar» por un
                // cambio pendiente o una temporal caducada; con ellas el superadmin vería 403/401 en todo. Sin `act` queda idéntico a antes.
                $impersonating = array_key_exists('act', $payload);

                // H2: contraseña temporal (= correo) caducada. Solo se consulta el ajuste cuando hay un reset pendiente.
                if (! $impersonating && $row->must_change_password === true && app(PasswordResetPolicy::class)->isExpiredRow(true, $row->password_reset_at)) {
                    return response()->json([
                        'message' => trans('auth.password_reset_expired'),
                        'code'    => 'password_reset_expired',
                    ], 401);
                }

                // Con un reset administrativo pendiente solo se permite ver el propio perfil y cambiar la contraseña.
                if (! $impersonating && $row->must_change_password === true && ! $this->isAllowedWhilePasswordChangeRequired($request)) {
                    return response()->json([
                        'message' => trans('auth.password_change_required'),
                        'code'    => 'password_change_required',
                    ], 403);
                }
            }

            return $next($request);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /** 401 `impersonation_ended`: la sesión «Ver como usuario» ya no es válida; el frontend vuelve a la sesión original del superadmin. */
    private function impersonationEnded(): Response
    {
        return response()->json(['message' => trans('auth.impersonation_ended'), 'code' => 'impersonation_ended'], 401);
    }

    /** GET /api/auth/me y PUT /api/auth/password (logout y refresh son públicos y no pasan por este middleware). */
    private function isAllowedWhilePasswordChangeRequired(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return ($request->isMethod('GET') && $path === 'api/auth/me')
            || ($request->isMethod('PUT') && $path === 'api/auth/password');
    }
}
