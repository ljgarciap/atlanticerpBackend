<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Shared\Auth\JwtClaimsBuilder;
use App\Shared\Auth\JwtService;
use App\Shared\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SCRUM-1158 — «Ver como usuario» (ADR-010, threat model `docs/security/THREAT-MODEL-impersonacion.md`). Un superadmin recibe un JWT de SOLO
 * LECTURA con la identidad de otra persona (`sub` = objetivo, `act` = actor real + sesión). Toda la lógica de las reglas RN1–RN10 vive acá; los
 * controladores solo traducen HTTP.
 *
 * Qué NO hace este servicio, por diseño: no emite refresh token (la sesión no se renueva), no deja iniciar una sesión desde otra sesión de
 * este tipo, y nunca registra ni devuelve la contraseña del actor.
 */
class ImpersonationService
{
    /** Motivos por los que el objetivo no es válido; viajan a la auditoría (no al cliente, que solo recibe el `code` genérico). */
    private const TARGET_SELF           = 'self';
    private const TARGET_INACTIVE       = 'inactive';
    private const TARGET_SECURITY_BLOCK = 'security_blocked';
    private const TARGET_GUEST          = 'guest';
    private const TARGET_TOP_PRIVILEGE  = 'top_privilege';

    public function __construct(
        private readonly AuditService $audit,
        private readonly JwtService $jwt,
        private readonly JwtClaimsBuilder $claims,
        private readonly AdminPasswordResetService $accounts,
        private readonly ImpersonationSettings $settings,
        private readonly PreferencesService $preferences,
    ) {}

    // ── Inicio ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Pasos 1–3 del orden fijo del diseño (§3.4), ANTES de pedir la contraseña: sin encadenar, actor revalidado en BD, motivo suficiente.
     * Devuelve el actor ya cargado.
     *
     * @throws ImpersonationException|ValidationException
     */
    public function authorizeStart(Request $request, int $targetId, string $reason): User
    {
        $payload = $this->jwtPayload($request);
        $actorId = isset($payload['sub']) ? (int) $payload['sub'] : null;

        // 1. Sin encadenar (RN7). Por HTTP casi nunca llega acá (el middleware de solo lectura corta antes un POST con `act`); es defensa en profundidad.
        if (isset($payload['act'])) {
            throw $this->deny($actorId, $targetId, 'nested', 403, 'impersonation_nested', trans('auth.impersonation_nested'));
        }

        // 2. El rol de superadmin se revalida en BD, no se confía en el token (mismo criterio que el reset administrativo, H3).
        $actor = $actorId !== null ? User::find($actorId) : null;
        if ($actor === null || ! $this->accounts->operatorIsStillSuperadmin($actor) || $actor->isSecurityBlocked()) {
            throw $this->deny($actorId, $targetId, 'operator_role_revoked', 403, 'operator_role_revoked', trans('auth.operator_role_revoked'));
        }

        // 3. Motivo obligatorio, con largo mínimo configurable.
        $min = $this->settings->reasonMinLength();
        if (mb_strlen(trim($reason)) < $min) {
            $this->audit->log(
                action:       AuditService::AUTH_IMPERSONATION_DENIED,
                entityType:   'user',
                entityId:     $targetId,
                metadata:     ['denied_reason' => 'reason_too_short', 'target_user_id' => $targetId],
                actingUserId: $actor->id,
            );

            throw ValidationException::withMessages(['reason' => [trans('auth.impersonation_reason_short', ['min' => $min])]]);
        }

        return $actor;
    }

    /** Deja constancia de una re-autenticación fallida (CA6): cuenta para el límite de intentos y queda en la auditoría. */
    public function auditReauthFailed(Request $request, User $actor, int $targetId): void
    {
        $this->audit->log(
            action:       AuditService::AUTH_IMPERSONATION_DENIED,
            entityType:   'user',
            entityId:     $targetId,
            metadata:     ['denied_reason' => 'operator_reauth_failed', 'target_user_id' => $targetId],
            actingUserId: $actor->id,
        );
    }

    /**
     * Pasos 5–6: valida el objetivo, crea la sesión y emite el token de solo lectura. La contraseña del actor ya se verificó antes.
     *
     * @return array<string, mixed> cuerpo de la respuesta 201
     * @throws ImpersonationException
     */
    public function start(Request $request, User $actor, int $targetId, string $reason): array
    {
        $target = User::find($targetId);
        if ($target === null) {
            throw $this->deny($actor->id, $targetId, 'target_not_found', 404, 'not_found', trans('auth.impersonation_target_invalid'));
        }

        $problem = $this->targetProblem($actor, $target);
        if ($problem !== null) {
            throw $this->deny($actor->id, $targetId, $problem, 422, 'impersonation_target_invalid', trans('auth.impersonation_target_invalid'));
        }

        $minutes = $this->settings->maxMinutes();

        $session = ImpersonationSession::create([
            'actor_id'   => $actor->id,
            'target_id'  => $target->id,
            'reason'     => trim($reason),
            'started_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $this->requestId(),
        ]);

        $this->audit->log(
            action:       AuditService::AUTH_IMPERSONATION_STARTED,
            entityType:   'impersonation_session',
            entityId:     $session->id,
            metadata:     [
                'target_user_id' => $target->id,
                'reason'         => $session->reason,
                'expires_at'     => $session->expires_at->toIso8601String(),
            ],
            actingUserId: $actor->id,
        );

        $tenantSlug = (string) ($this->jwtPayload($request)['tenant'] ?? '');
        $token      = $this->jwt->encode($this->claims->impersonationClaims($target, $tenantSlug, $actor, $session->id), $minutes * 60_000);

        $prefs    = is_array($target->preferences) ? $target->preferences : [];
        $language = $prefs['language'] ?? $this->preferences->getSystemSettings()['default_language'];

        return [
            'token'         => $token,
            'token_type'    => 'Bearer',
            'expires_in_ms' => $minutes * 60_000,
            // Sin `refresh_token` a propósito (RN4 / condición C8): la sesión no se renueva.
            'user'          => $this->claims->userPayload($target, (string) $language, $this->block($session, $actor, $target)),
        ];
    }

    // ── Cierre ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * El propio actor termina su sesión (`POST /auth/impersonation/stop`, con el token de impersonación).
     *
     * @throws ImpersonationException
     */
    public function stop(Request $request): void
    {
        $payload   = $this->jwtPayload($request);
        $sessionId = $this->sessionIdFrom($payload);
        $session   = $sessionId !== null ? ImpersonationSession::find($sessionId) : null;

        if ($session === null) {
            throw new ImpersonationException(409, 'not_impersonating', trans('auth.impersonation_not_active'));
        }

        // Si ya estaba cerrada (carrera con una revocación), terminar es idempotente: el resultado que importa ya se dio.
        $this->close($session, ImpersonationSession::ENDED_MANUAL, $session->actor_id);
    }

    /**
     * Otro superadmin (o el mismo) termina una sesión desde la pantalla de sesiones de soporte. Devuelve false si ya estaba cerrada.
     */
    public function revoke(ImpersonationSession $session, User $revoker): bool
    {
        if (! $this->markEnded($session, ImpersonationSession::ENDED_REVOKED, $revoker->id)) {
            return false;
        }

        $this->audit->log(
            action:       AuditService::AUTH_IMPERSONATION_REVOKED,
            entityType:   'impersonation_session',
            entityId:     $session->id,
            metadata:     ['actor_user_id' => $session->actor_id, 'target_user_id' => $session->target_id, 'revoked_by' => $revoker->id],
            actingUserId: $revoker->id,
        );

        return true;
    }

    // ── Validación por request ────────────────────────────────────────────────────────────────────────────

    /**
     * Lo que `AuthenticateJwt` exige a todo token con claim `act` (RN9, RN10, condición C5). Devuelve null si la sesión sigue vigente, o el
     * motivo (para el log) si debe responderse 401 `impersonation_ended`. Se llama SOLO cuando hay `act`: las requests normales no pagan nada.
     *
     * Revalida en BD, en cada request: sesión existente, que pertenezca a este actor y a este objetivo, sin cerrar y sin vencer; actor activo,
     * sin bloqueo de seguridad y todavía superadmin; objetivo sin bloqueo de seguridad (`is_active` ya lo comprueba el middleware).
     *
     * @param array<string, mixed> $payload
     */
    public function guard(array $payload): ?string
    {
        $act = $payload['act'] ?? null;
        if (! is_array($act) || ! isset($act['sub']) || ! is_numeric($act['sub'])) {
            return 'malformed_act';
        }

        $sessionId = $this->sessionIdFrom($payload);
        if ($sessionId === null) {
            return 'malformed_act';
        }

        $session = ImpersonationSession::find($sessionId);
        if ($session === null) {
            return 'unknown_session';
        }

        // La sesión debe corresponder EXACTAMENTE a la identidad del token: impide combinar un `act` ajeno con otro `sub`.
        if ((int) $session->actor_id !== (int) $act['sub'] || (int) $session->target_id !== (int) ($payload['sub'] ?? 0)) {
            return 'session_mismatch';
        }

        if ($session->ended_at !== null) {
            return 'ended';
        }

        if ($session->expires_at->isPast()) {
            $this->close($session, ImpersonationSession::ENDED_EXPIRED, null);

            return 'expired';
        }

        $actor = User::find($session->actor_id);
        if ($actor === null || ! $this->accounts->operatorIsStillSuperadmin($actor) || $actor->isSecurityBlocked()) {
            $this->close($session, ImpersonationSession::ENDED_REVOKED, null);

            return 'actor_invalid';
        }

        // El objetivo debe seguir existiendo, activo y sin bloqueo de seguridad. Desactivarlo (o bloquearlo) cierra la sesión con el motivo
        // `target_blocked` (se reutiliza: «el objetivo ya no está disponible»; el frontend ya lo etiqueta) y reactivarlo después no la revive.
        $target = User::query()->whereKey($session->target_id)->first(['is_active', 'security_blocked_at']);
        if ($target === null || $target->is_active !== true || $target->security_blocked_at !== null) {
            $this->close($session, ImpersonationSession::ENDED_TARGET_BLOCKED, null);

            return 'target_blocked';
        }

        return null;
    }

    /**
     * Bloque `impersonation` para `GET /auth/me` (recarga de la página con la sesión viva). Null si el token no es de impersonación.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function describe(array $payload): ?array
    {
        $sessionId = isset($payload['act']) ? $this->sessionIdFrom($payload) : null;
        $session   = $sessionId !== null ? ImpersonationSession::with(['actor', 'target'])->find($sessionId) : null;

        if ($session === null || $session->actor === null || $session->target === null) {
            return null;
        }

        return $this->block($session, $session->actor, $session->target);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function block(ImpersonationSession $session, User $actor, User $target): array
    {
        return [
            'id'          => $session->id,
            'actor_id'    => $actor->id,
            'actor_name'  => trim($actor->first_name . ' ' . $actor->last_name),
            'target_name' => trim($target->first_name . ' ' . $target->last_name),
            'expires_at'  => $session->expires_at->toIso8601String(),
        ];
    }

    /** RN3: activo, sin bloqueo de seguridad, no invitado, distinto del actor y no de máximo privilegio. Null si es válido. */
    private function targetProblem(User $actor, User $target): ?string
    {
        if ($target->id === $actor->id) {
            return self::TARGET_SELF;
        }
        if ($target->is_active !== true) {
            return self::TARGET_INACTIVE;
        }
        if ($target->isSecurityBlocked()) {
            return self::TARGET_SECURITY_BLOCK;
        }
        if ($target->isGuest()) {
            return self::TARGET_GUEST;
        }
        if ($this->accounts->isTopPrivilege($target)) {
            return self::TARGET_TOP_PRIVILEGE;
        }

        return null;
    }

    /** Cierra la sesión y deja el evento `auth.impersonation.ended`. Idempotente: si ya estaba cerrada no hace nada. */
    private function close(ImpersonationSession $session, string $reason, ?int $endedBy): void
    {
        if (! $this->markEnded($session, $reason, $endedBy)) {
            return;
        }

        $this->audit->log(
            action:       AuditService::AUTH_IMPERSONATION_ENDED,
            entityType:   'impersonation_session',
            entityId:     $session->id,
            metadata:     [
                'ended_reason'     => $reason,
                'target_user_id'   => $session->target_id,
                'duration_seconds' => max(0, (int) $session->started_at->diffInSeconds(now())),
            ],
            actingUserId: $session->actor_id,
        );
    }

    /** UPDATE atómico condicionado a `ended_at IS NULL`: dos cierres simultáneos no se pisan. Solo toca las 3 columnas de cierre (condición C4). */
    private function markEnded(ImpersonationSession $session, string $reason, ?int $endedBy): bool
    {
        $affected = ImpersonationSession::query()
            ->whereKey($session->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_reason' => $reason, 'ended_by' => $endedBy]);

        return $affected === 1;
    }

    /** Registra el rechazo y devuelve la excepción lista para lanzar. */
    private function deny(?int $actorId, int $targetId, string $deniedReason, int $status, string $code, string $message): ImpersonationException
    {
        $this->audit->log(
            action:       AuditService::AUTH_IMPERSONATION_DENIED,
            entityType:   'user',
            entityId:     $targetId,
            metadata:     ['denied_reason' => $deniedReason, 'target_user_id' => $targetId],
            actingUserId: $actorId,
        );

        return new ImpersonationException($status, $code, $message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sessionIdFrom(array $payload): ?string
    {
        $id = is_array($payload['act'] ?? null) ? ($payload['act']['imp_id'] ?? null) : null;

        // Un id que no es UUID jamás llega a la consulta: Postgres lo rechazaría con un 500 (mismo patrón que Z-01).
        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    /** @return array<string, mixed> */
    private function jwtPayload(Request $request): array
    {
        $payload = $request->attributes->get('jwt');

        return is_array($payload) ? $payload : [];
    }

    private function requestId(): ?string
    {
        try {
            $id = Context::get('request_id');

            return is_string($id) && $id !== '' ? substr($id, 0, 64) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
