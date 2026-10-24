<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Support\AuthThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Re-autenticación del operador (superadmin) con su contraseña actual (`operator_password`) para las acciones sensibles del reset
 * administrativo: reset masivo, reset de una cuenta de máximo privilegio, reset individual pasado el tope por hora (R3-1) y cambio
 * del interruptor/caducidad en Configuración global (R3-6) y, desde SCRUM-1158, «Ver como usuario». Los intentos fallidos se limitan POR USUARIO con el cupo RESERVADO antes
 * de comparar la contraseña (incremento atómico: una ráfaga paralela no multiplica los intentos). Nunca se guarda ni se registra la
 * contraseña.
 */
final class OperatorReauth
{
    /** Reset administrativo de contraseñas (comportamiento original; es el ámbito por defecto). */
    public const SCOPE_PASSWORD_RESET = 'password_reset_admin';

    /** «Ver como usuario» (SCRUM-1158, condición C3): cupo de contraseñas incorrectas PROPIO, no compartido con el reset. */
    public const SCOPE_IMPERSONATION = 'impersonation';

    /** @var array<string, array{prefix: string, config: string}> ámbito => prefijo de la clave del limitador y bloque de `config/throttle.php` */
    private const SCOPES = [
        self::SCOPE_PASSWORD_RESET => ['prefix' => 'pwreset-reauth', 'config' => 'throttle.password_reset_admin'],
        self::SCOPE_IMPERSONATION  => ['prefix' => 'impersonation-reauth', 'config' => 'throttle.impersonation'],
    ];

    public function __construct(private readonly PasswordResetPolicy $policy) {}

    /**
     * Devuelve la respuesta de error (401/422/429) si la re-autenticación no pasa, o null si el operador quedó verificado.
     * `$scope` separa el cupo de intentos fallidos por flujo: 5 fallos en uno no bloquean al otro.
     */
    public function verify(Request $request, ?int $actorId, string $scope = self::SCOPE_PASSWORD_RESET): ?JsonResponse
    {
        $actor = $actorId !== null ? User::find($actorId) : null;
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $def   = self::SCOPES[$scope] ?? self::SCOPES[self::SCOPE_PASSWORD_RESET];
        $clave = $def['prefix'] . ':u' . $actor->id;
        $max   = (int) config($def['config'] . '.reauth_max', 5);
        $decay = (int) config($def['config'] . '.reauth_decay', 900);

        if (($retry = AuthThrottle::reservar([$clave => [$max, $decay]], AuthThrottle::CERRAR)) !== null) {
            return AuthThrottle::respuesta($retry);
        }

        $password = $request->input('operator_password');
        if (! is_string($password) || $password === '') {
            AuthThrottle::devolver($clave, $decay);

            return response()->json(['message' => trans('auth.operator_password_required'), 'code' => 'operator_password_required'], 422);
        }

        if (! Hash::check($password, (string) $actor->password_hash)) {
            return response()->json(['message' => trans('auth.operator_reauth_failed'), 'code' => 'operator_reauth_failed'], 422);
        }

        AuthThrottle::limpiar($clave);

        return null;
    }

    /**
     * R3-1: cuenta un reset individual de una persona normal contra el tope por hora del operador (ajuste global
     * `password_reset_free_per_hour`, default 5). Devuelve true si todavía entra sin contraseña; false si ya superó el tope y debe
     * re-autenticarse. El conteo es atómico y se hace ANTES de decidir.
     */
    public function withinFreeQuota(int $actorId): bool
    {
        $n = AuthThrottle::contar('pwreset-free:u' . $actorId, 3600, AuthThrottle::CERRAR);

        return $n <= $this->policy->freeResetsPerHour();
    }
}
