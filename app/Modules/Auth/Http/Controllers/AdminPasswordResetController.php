<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\ResetPasswordsRequest;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Modules\Auth\Services\OperatorReauth;
use App\Modules\Auth\Services\PasswordResetPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Reset administrativo de contraseñas — SOLO superadmin (`role:superadmin` + límite por usuario en routes/users.php).
 * Contraseña = correo del usuario y cambio obligatorio al ingresar (`must_change_password`).
 *
 * Cierre H2/H3 de la revisión de seguridad: (1) el rol del operador se REVALIDA en BD en cada llamada (un token emitido antes de que
 * le quitaran el rol ya no sirve); (2) el interruptor `password_reset_enabled` bloquea la aplicación del reset (apagado por defecto en
 * producción); (3) el masivo y el reset de una cuenta de máximo privilegio exigen la contraseña del operador (`operator_password`).
 * Ver docs/runbooks/reset-password.md.
 */
class AdminPasswordResetController extends Controller
{
    public function __construct(
        private readonly AdminPasswordResetService $service,
        private readonly PasswordResetPolicy $policy,
        private readonly OperatorReauth $reauth,
    ) {}

    public function reset(Request $request, int $id): JsonResponse
    {
        if (($denied = $this->guardOperator($request)) !== null) {
            return $denied;
        }

        if (! $this->policy->enabled()) {
            return $this->disabled();
        }

        $user = User::find($id);
        if ($user === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($user->is_active !== true) {
            return response()->json(['message' => 'El usuario está inactivo: un reset de contraseña no reactiva cuentas.'], 422);
        }

        // Exige re-autenticar al operador (a) siempre con una cuenta de máximo privilegio (rol superadmin, nivel 10 o superadmin.all) y
        // (b) con una persona normal una vez superado el tope de resets por hora sin contraseña (R3-1: un token robado no puede
        // encadenar resets individuales sin límite). El conteo es atómico y se hace antes de decidir.
        $actorId = $this->actorId($request);
        $reauth  = $this->service->isTopPrivilege($user);
        if (! $reauth && $actorId !== null && ! $this->reauth->withinFreeQuota($actorId)) {
            $reauth = true;
        }
        if ($reauth && ($denied = $this->reauth->verify($request, $actorId)) !== null) {
            return $denied;
        }

        $this->service->resetUser($user, $this->operador($request), 'api', 'individual', $reauth);

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email, 'must_change_password' => true]]);
    }

    public function resetMany(ResetPasswordsRequest $request): JsonResponse
    {
        /** @var array{dry_run?: bool, except_ids?: list<int>, include_self?: bool, include_superadmins?: bool, confirm?: string|null} $data */
        $data = $request->validated();

        if (($denied = $this->guardOperator($request)) !== null) {
            return $denied;
        }

        $actorId     = $this->actorId($request);
        $includeSelf = (bool) ($data['include_self'] ?? false);
        $includeSuperadmins = (bool) ($data['include_superadmins'] ?? false);
        $exceptIds   = array_values(array_unique(array_map('intval', $data['except_ids'] ?? [])));

        $targets = $this->service->resolveTargets($exceptIds, [], $includeSelf ? null : $actorId, $includeSuperadmins);
        $count   = $targets->count();

        if ((bool) ($data['dry_run'] ?? false)) {
            return response()->json(['data' => [
                'count' => $count,
                'users' => $targets->map(static fn (User $u): array => [
                    'id'    => $u->id,
                    'email' => $u->email,
                    'name'  => trim($u->first_name . ' ' . $u->last_name),
                ])->values()->all(),
            ]]);
        }

        // La simulación (dry_run) sigue permitida con el interruptor apagado; aplicar no.
        if (! $this->policy->enabled()) {
            return $this->disabled();
        }

        if (trim((string) ($data['confirm'] ?? '')) !== 'RESETEAR ' . $count) {
            return response()->json([
                'message' => 'La confirmación no coincide: escribe exactamente RESETEAR ' . $count . '.',
                'code'    => 'confirmation_mismatch',
            ], 422);
        }

        // El masivo SIEMPRE exige la contraseña del operador (H3).
        if (($denied = $this->reauth->verify($request, $actorId)) !== null) {
            return $denied;
        }

        $resetCount = $this->service->resetMany($targets, $this->operador($request), 'api', [
            'except_ids' => $exceptIds, 'include_self' => $includeSelf, 'include_superadmins' => $includeSuperadmins,
        ], true);

        return response()->json(['data' => ['reset_count' => $resetCount]]);
    }

    /** Revalida en BD que el operador sigue siendo superadmin activo; 403 si no (el JWT por sí solo no basta). */
    private function guardOperator(Request $request): ?JsonResponse
    {
        $actor = $this->actorId($request) !== null ? User::find($this->actorId($request)) : null;

        if (! $this->service->operatorIsStillSuperadmin($actor)) {
            return response()->json(['message' => trans('auth.operator_role_revoked'), 'code' => 'operator_role_revoked'], 403);
        }

        return null;
    }

    private function disabled(): JsonResponse
    {
        return response()->json(['message' => trans('auth.password_reset_disabled'), 'code' => 'password_reset_disabled'], 403);
    }

    private function actorId(Request $request): ?int
    {
        $payload = $request->attributes->get('jwt');

        return is_array($payload) && isset($payload['sub']) ? (int) $payload['sub'] : null;
    }

    private function operador(Request $request): string
    {
        $payload = $request->attributes->get('jwt');

        return is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : 'desconocido';
    }
}
