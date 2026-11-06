<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\MfaThrottledException;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\PreferencesService;
use App\Modules\Auth\Support\AuthThrottle;
use App\Shared\Auth\JwtClaimsBuilder;
use App\Shared\Auth\JwtService;
use App\Shared\Auth\TenantResolver;
use App\Shared\Auth\Totp;
use App\Shared\Services\AuditService;
use App\Shared\Support\QrPng;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService         $mfaService,
        private readonly JwtService         $jwt,
        private readonly TenantResolver     $tenantResolver,
        private readonly PreferencesService $preferences,
        private readonly JwtClaimsBuilder   $claims,
    ) {}

    /** POST /api/auth/mfa/verify — public */
    public function verify(Request $request): JsonResponse
    {
        // `code` admite 6 dígitos (correo o aplicación) o un código de respaldo (Fase 27c); el formato exacto se exige abajo según el método del reto.
        $data   = $request->validate([
            'mfa_challenge_id' => ['required', 'string', 'uuid'],
            'code'             => ['required', 'string', 'max:20'],
        ]);
        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        // H4: tope de códigos erróneos por IP (solo fallos); el tope por usuario vive en MfaService::verify().
        $ipKey = 'mfa:verify:ip:' . $request->ip();
        $cfg   = (array) config('throttle.mfa_verify');
        if (($retry = AuthThrottle::reservar([$ipKey => [(int) $cfg['ip_max'], (int) $cfg['ip_decay']]], AuthThrottle::CERRAR)) !== null) {
            return AuthThrottle::respuesta($retry);
        }

        $tenant->makeCurrent();

        try {
            if ($this->mfaService->methodForChallenge($data['mfa_challenge_id']) !== MfaService::METHOD_TOTP && preg_match('/^\d{6}$/', $data['code']) !== 1) {
                throw ValidationException::withMessages(['code' => [trans('validation.digits', ['attribute' => 'code', 'digits' => 6])]]);
            }

            try {
                $userId = $this->mfaService->verify($data['mfa_challenge_id'], $data['code']);
            } catch (MfaThrottledException $e) {
                // R3-4: el tope por usuario responde 429 con retry_after, igual que los demás límites.
                return AuthThrottle::respuesta($e->retryAfter);
            }

            // Código correcto: se devuelve el intento reservado de la IP (solo cuentan los fallos).
            AuthThrottle::devolver($ipKey, (int) $cfg['ip_decay']);

            /** @var User|null $user */
            $user = User::find($userId);

            if ($user === null || ! $user->is_active) {
                return response()->json(['message' => trans('auth.failed')], 401);
            }

            return $this->buildTokenResponse($user, $tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /** POST /api/auth/mfa/resend — public */
    public function resend(Request $request): JsonResponse
    {
        $data   = $request->validate(['mfa_challenge_id' => ['required', 'string', 'uuid']]);
        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            // H4: tope de reenvíos por usuario (por IP si el reto no existe, sin revelar nada del reto).
            $cfg       = (array) config('throttle.mfa_resend');
            $ownerId   = $this->mfaService->userIdForChallenge($data['mfa_challenge_id']);
            $resendKey = $ownerId !== null ? 'mfa:resend:user:' . $ownerId : 'mfa:resend:ip:' . $request->ip();
            if (($retry = AuthThrottle::reservar([$resendKey => [(int) $cfg['max'], (int) $cfg['decay']]], AuthThrottle::CERRAR)) !== null) {
                return AuthThrottle::respuesta($retry);
            }

            $newId = $this->mfaService->resend($data['mfa_challenge_id']);

            return response()->json(['mfa_challenge_id' => $newId]);
        } catch (MfaDeliveryException) {
            return response()->json(['message' => trans('auth.mfa_delivery_failed')], 503);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /** GET /api/auth/mfa/status — protected (JWT required) */
    public function status(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json([
            'mfa_enabled' => $user->mfa_enabled,
            'mfa_mode'    => $this->mfaService->getMfaMode(),
            // Fase 27c: método vigente y respaldos que le quedan (nunca el secreto).
            'mfa_method'            => $user->mfa_enabled ? $user->mfa_method : null,
            'backup_codes_remaining' => $user->mfa_enabled && $this->mfaService->usesTotp($user) ? $this->mfaService->backupCodesRemaining($user) : null,
        ]);
    }

    /**
     * POST /api/auth/mfa/enable — protected (JWT + current password)
     *
     * No activa MFA de inmediato — envía un código de verificación al correo del usuario
     * y devuelve un challenge que debe confirmarse en /mfa/enable/confirm. Esto asegura que
     * el usuario efectivamente puede recibir códigos antes de quedar dependiendo de ellos
     * para futuros logins (ver SCRUM-2, reporte de QA 2026-07-05).
     */
    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            return response()->json([
                'message' => trans('auth.password_incorrect'),
                'errors'  => ['password' => [trans('auth.password_incorrect')]],
            ], 422);
        }

        if ($this->mfaService->usesTotp($user)) {
            return response()->json(['message' => trans('auth.mfa_totp_already_active')], 422);
        }

        try {
            $challengeId = $this->mfaService->generateChallenge($user);
        } catch (MfaDeliveryException) {
            return response()->json(['message' => trans('auth.mfa_delivery_failed')], 503);
        }

        return response()->json(['mfa_challenge_id' => $challengeId]);
    }

    /** POST /api/auth/mfa/enable/confirm — protected (JWT + challenge code) */
    public function confirmEnable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_challenge_id' => ['required', 'string', 'uuid'],
            'code'             => ['required', 'string', 'digits:6'],
        ]);

        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (($mismatch = $this->challengeMismatch($data['mfa_challenge_id'], $user)) !== null) {
            return $mismatch;
        }

        if (($failure = $this->verifyOwnChallenge($data['mfa_challenge_id'], $data['code'], 'enable')) !== null) {
            return $failure;
        }

        $this->mfaService->enableForUser($user);

        return response()->json(['mfa_enabled' => true]);
    }

    /**
     * POST /api/auth/mfa/disable — protected (JWT + clave vigente)
     *
     * Fase 27a (a2): ya no basta con la sesión. Igual que `enable`, pide la clave y envía un código al correo;
     * el MFA solo se apaga en `/mfa/disable/confirm` con ese código.
     */
    public function disable(Request $request): JsonResponse
    {
        return $this->issueProof($request);
    }

    /**
     * POST /api/auth/mfa/proof — protected (JWT + clave vigente). Fase 27c: primer paso de cualquier acción sensible sobre un MFA ya activo
     * (cambiar de método, regenerar respaldos). Es el mismo paso que pide `disable`: con correo envía un código y devuelve el reto;
     * con aplicación autenticadora no hay nada que enviar y solo responde `method: totp` (el código sale de la aplicación).
     */
    public function proof(Request $request): JsonResponse
    {
        return $this->issueProof($request);
    }

    private function issueProof(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            return response()->json([
                'message' => trans('auth.password_incorrect'),
                'errors'  => ['password' => [trans('auth.password_incorrect')]],
            ], 422);
        }

        if (! $user->mfa_enabled) {
            return response()->json(['message' => 'No tienes MFA activo.'], 422);
        }

        if ($this->mfaService->usesTotp($user)) {
            return response()->json(['method' => MfaService::METHOD_TOTP]);
        }

        try {
            $challengeId = $this->mfaService->generateChallenge($user);
        } catch (MfaDeliveryException) {
            return response()->json(['message' => trans('auth.mfa_delivery_failed')], 503);
        }

        return response()->json(['method' => MfaService::METHOD_EMAIL, 'mfa_challenge_id' => $challengeId]);
    }

    /** POST /api/auth/mfa/disable/confirm — protected (JWT + código del reto, o de la aplicación / un respaldo con TOTP) */
    public function confirmDisable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_challenge_id' => ['nullable', 'string', 'uuid'],
            'code'             => ['required', 'string', 'max:20'],
        ]);

        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (($failure = $this->stepUpFailure($user, $data, 'disable')) !== null) {
            return $failure;
        }

        $wasTotp = $this->mfaService->usesTotp($user);
        $this->mfaService->disableForUser($user);
        if ($wasTotp) {
            $this->audit(AuditService::SECURITY_MFA_TOTP_DISABLED, $user);
        }

        return response()->json(['mfa_enabled' => false]);
    }

    /**
     * POST /api/auth/mfa/totp/setup — protected (JWT + clave; con MFA ya activo, además la prueba del método vigente).
     * Devuelve el secreto UNA vez, para dibujar el QR o teclearlo a mano; no activa nada hasta `totp/confirm`.
     */
    public function totpSetup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password'         => ['required', 'string'],
            'mfa_challenge_id' => ['nullable', 'string', 'uuid'],
            'code'             => ['nullable', 'string', 'max:20'],
        ]);
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            return response()->json([
                'message' => trans('auth.password_incorrect'),
                'errors'  => ['password' => [trans('auth.password_incorrect')]],
            ], 422);
        }

        if ($this->mfaService->usesTotp($user)) {
            return response()->json(['message' => trans('auth.mfa_totp_already_active')], 422);
        }

        if ($user->mfa_enabled) {
            // Cambiar de método con el correo activo exige probar el método vigente (el reto del correo, de `mfa/proof`).
            if (! isset($data['code'])) {
                return response()->json(['message' => trans('auth.mfa_proof_required'), 'errors' => ['code' => [trans('auth.mfa_proof_required')]]], 422);
            }
            if (($failure = $this->stepUpFailure($user, $data, 'enable')) !== null) {
                return $failure;
            }
        }

        $secret = $this->mfaService->startTotpSetup($user);
        $uri    = Totp::uri($secret, $user->email, (string) config('app.name', 'AtlanticERP'));

        return response()->json(['secret' => $secret, 'otpauth_uri' => $uri, 'qr' => QrPng::dataUri($uri)]);
    }

    /** POST /api/auth/mfa/totp/confirm — protected (JWT + primer código de la aplicación). Activa el método y devuelve los 10 respaldos, una sola vez. */
    public function totpConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $codes = $this->mfaService->confirmTotpSetup($user, $data['code']);
        } catch (MfaThrottledException $e) {
            return AuthThrottle::respuesta($e->retryAfter);
        }

        if ($codes === null) {
            return response()->json(['message' => trans('auth.mfa_totp_code_invalid'), 'errors' => ['code' => [trans('auth.mfa_totp_code_invalid')]]], 422);
        }

        $this->audit(AuditService::SECURITY_MFA_TOTP_ENABLED, $user);

        return response()->json(['mfa_enabled' => true, 'mfa_method' => MfaService::METHOD_TOTP, 'backup_codes' => $codes]);
    }

    /** POST /api/auth/mfa/backup-codes/regenerate — protected (JWT + clave + código de la aplicación o un respaldo). Invalida los anteriores. */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code'     => ['required', 'string', 'max:20'],
        ]);
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            return response()->json([
                'message' => trans('auth.password_incorrect'),
                'errors'  => ['password' => [trans('auth.password_incorrect')]],
            ], 422);
        }

        if (! $this->mfaService->usesTotp($user)) {
            return response()->json(['message' => trans('auth.mfa_totp_not_active')], 422);
        }

        if (($failure = $this->stepUpFailure($user, $data, 'regenerate')) !== null) {
            return $failure;
        }

        $codes = $this->mfaService->regenerateBackupCodes($user);
        $this->audit(AuditService::SECURITY_MFA_BACKUP_REGENERATED, $user);

        return response()->json(['backup_codes' => $codes]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * El reto debe pertenecer a la sesión ANTES de validar el código (Fase 27a, a1): si no, un reto ajeno gastaba el código
     * de su dueño. Un reto inexistente no se distingue aquí: lo rechaza la validación del código (422).
     */
    private function challengeMismatch(string $challengeId, User $user): ?JsonResponse
    {
        $owner = $this->mfaService->userIdForChallenge($challengeId);

        return ($owner !== null && $owner !== $user->id)
            ? response()->json(['message' => trans('auth.mfa_challenge_mismatch')], 403)
            : null;
    }

    /**
     * Prueba del método vigente para una acción sensible (Fase 27c). Con correo exige el formato de 6 dígitos y que el reto sea de la sesión;
     * con aplicación autenticadora acepta el código de la aplicación o un respaldo.
     *
     * @param array<string, mixed> $data
     */
    private function stepUpFailure(User $user, array $data, string $scope): ?JsonResponse
    {
        $challengeId = isset($data['mfa_challenge_id']) ? (string) $data['mfa_challenge_id'] : null;
        $code        = (string) $data['code'];

        if (! $this->mfaService->usesTotp($user)) {
            if (preg_match('/^\d{6}$/', $code) !== 1) {
                return response()->json(['message' => trans('validation.digits', ['attribute' => 'code', 'digits' => 6]), 'errors' => ['code' => [trans('validation.digits', ['attribute' => 'code', 'digits' => 6])]]], 422);
            }
            if ($challengeId !== null && ($mismatch = $this->challengeMismatch($challengeId, $user)) !== null) {
                return $mismatch;
            }
        }

        try {
            $this->mfaService->verifyStepUp($user, $challengeId, $code, $scope);
        } catch (MfaThrottledException $e) {
            return AuthThrottle::respuesta($e->retryAfter);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return null;
    }

    private function audit(string $action, User $user): void
    {
        app(AuditService::class)->log(action: $action, entityType: 'user', entityId: $user->id, actingUserId: $user->id);
    }

    private function verifyOwnChallenge(string $challengeId, string $code, string $scope): ?JsonResponse
    {
        try {
            $this->mfaService->verify($challengeId, $code, $scope);
        } catch (MfaThrottledException $e) {
            return AuthThrottle::respuesta($e->retryAfter);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return null;
    }

    private function buildTokenResponse(User $user, string $tenantSlug): JsonResponse
    {
        // ADR-006, Fase C — mismo cutover que AuthController::login(): sin pantalla de
        // selección, v2 ya une role_id + user_additional_roles automáticamente.
        $expirationMs = $this->preferences->getEffectiveSessionTimeoutMs($user);
        $claims       = $this->claims->claims($user, $tenantSlug);
        $accessToken  = $this->jwt->encode($claims, $expirationMs);
        $refreshToken = $this->issueRefreshToken($user);
        $prefs        = is_array($user->preferences) ? $user->preferences : [];
        $language     = $prefs['language'] ?? $this->preferences->getSystemSettings()['default_language'];

        return response()->json([
            'token'          => $accessToken,
            'token_type'     => 'Bearer',
            'expires_in_ms'  => $expirationMs,
            'refresh_token'  => $refreshToken,
            'user'           => $this->claims->userPayload($user, $language),
        ]);
    }

    private function issueRefreshToken(User $user): string
    {
        // MFA verify() is always a fresh login flow (never a refresh-token rotation), so
        // login_at is always "now" here — see AuthController::issueRefreshToken() docblock for
        // why the distinction matters (SCRUM-746 absolute 30-day ceiling surviving rotation).
        $plain = Str::random(64);
        $days  = (int) config('jwt.refresh_token_expiration_days', 30);
        $now   = now();

        RefreshToken::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $now->copy()->addDays($days),
            'revoked'    => false,
            'login_at'   => $now,
            'last_activity_at' => $now,
        ]);

        return $plain;
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
