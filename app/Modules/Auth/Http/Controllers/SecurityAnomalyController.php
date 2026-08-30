<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\SecurityAnomaly;
use App\Modules\Auth\Services\MfaService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Panel de superadmin del Epic 11 — Seguridad Agéntica. Ver ADR-004 punto 6-7: el job nunca
 * bloquea por sí mismo — /confirm es la única vía real de bloqueo, y siempre queda auditada
 * como acción del superadmin, no del job.
 */
final class SecurityAnomalyController
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly MfaService   $mfaService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = SecurityAnomaly::query()
            ->with(['affectedUser:id,first_name,last_name,email', 'reviewer:id,first_name,last_name'])
            ->orderByDesc('detected_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get()->map(fn (SecurityAnomaly $a) => $this->payload($a)),
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $anomaly = SecurityAnomaly::find($id);

        if ($anomaly === null) {
            return response()->json(['message' => 'Anomalía no encontrada.'], 404);
        }

        $actorId = $this->actorId($request);

        // Idempotente (ver ADR-004): si el usuario ya está bloqueado (ej. dos anomalías
        // seguidas), no falla — solo actualiza el registro de esta anomalía.
        if ($anomaly->affected_user_id !== null) {
            $user = User::find($anomaly->affected_user_id);

            if ($user !== null && ! $user->isSecurityBlocked()) {
                $user->security_blocked_at = Carbon::now();
                $user->save();

                $this->audit->log(
                    action:       AuditService::SECURITY_USER_BLOCKED,
                    entityType:   'user',
                    entityId:     $user->id,
                    newValues:    ['security_blocked_at' => $user->security_blocked_at->toIso8601String()],
                    metadata:     ['anomaly_id' => $anomaly->id],
                    actingUserId: $actorId,
                );
            }
        }

        $anomaly->status      = SecurityAnomaly::STATUS_CONFIRMED;
        $anomaly->reviewed_by = $actorId;
        $anomaly->reviewed_at = Carbon::now();
        $anomaly->save();

        $this->audit->log(
            action:       AuditService::SECURITY_ANOMALY_CONFIRMED,
            entityType:   'security_anomaly',
            entityId:     $anomaly->id,
            actingUserId: $actorId,
        );

        return response()->json(['data' => $this->payload($anomaly->load(['affectedUser', 'reviewer']))]);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $anomaly = SecurityAnomaly::find($id);

        if ($anomaly === null) {
            return response()->json(['message' => 'Anomalía no encontrada.'], 404);
        }

        $actorId = $this->actorId($request);

        $anomaly->status      = SecurityAnomaly::STATUS_DISMISSED;
        $anomaly->reviewed_by = $actorId;
        $anomaly->reviewed_at = Carbon::now();
        $anomaly->save();

        $this->audit->log(
            action:       AuditService::SECURITY_ANOMALY_DISMISSED,
            entityType:   'security_anomaly',
            entityId:     $anomaly->id,
            actingUserId: $actorId,
        );

        return response()->json(['data' => $this->payload($anomaly->load(['affectedUser', 'reviewer']))]);
    }

    public function unblock(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if ($user === null) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (! $user->isSecurityBlocked()) {
            return response()->json(['message' => 'El usuario no está bloqueado por seguridad.'], 422);
        }

        $user->security_blocked_at = null;
        $user->save();

        $this->audit->log(
            action:       AuditService::SECURITY_USER_UNBLOCKED,
            entityType:   'user',
            entityId:     $user->id,
            actingUserId: $this->actorId($request),
        );

        return response()->json(['message' => 'Usuario reactivado.']);
    }

    /**
     * Resetea el MFA de un usuario bloqueado (perdió acceso a su correo, dispositivo, etc.).
     * Único procedimiento de soporte soportado — antes había que apagar la política de MFA
     * de toda la organización para rescatar a un solo usuario (ver SCRUM-2, QA 2026-07-05).
     */
    public function resetMfa(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if ($user === null) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (! $user->mfa_enabled) {
            return response()->json(['message' => 'El usuario no tiene MFA activo.'], 422);
        }

        $this->mfaService->disableForUser($user);

        $this->audit->log(
            action:       AuditService::SECURITY_MFA_RESET,
            entityType:   'user',
            entityId:     $user->id,
            actingUserId: $this->actorId($request),
        );

        return response()->json(['message' => 'MFA reseteado.']);
    }

    private function actorId(Request $request): ?int
    {
        $payload = $request->attributes->get('jwt');

        return is_array($payload) && isset($payload['sub']) ? (int) $payload['sub'] : null;
    }

    /** @return array<string, mixed> */
    private function payload(SecurityAnomaly $anomaly): array
    {
        return [
            'id'            => $anomaly->id,
            'detected_at'   => $anomaly->detected_at->toIso8601String(),
            'severity'      => $anomaly->severity,
            'reasoning'     => $anomaly->reasoning,
            'status'        => $anomaly->status,
            'affected_user' => $anomaly->affectedUser !== null ? [
                'id'   => $anomaly->affectedUser->id,
                'name' => "{$anomaly->affectedUser->first_name} {$anomaly->affectedUser->last_name}",
            ] : null,
            'reviewer' => $anomaly->reviewer !== null ? [
                'id'   => $anomaly->reviewer->id,
                'name' => "{$anomaly->reviewer->first_name} {$anomaly->reviewer->last_name}",
            ] : null,
            'reviewed_at' => $anomaly->reviewed_at?->toIso8601String(),
        ];
    }
}
