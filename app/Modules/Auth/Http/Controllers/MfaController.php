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
        $data   = $request->validate([
            'mfa_challenge_id' => ['required', 'string', 'uuid'],
            'code'             => ['required', 'string', 'digits:6'],
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

        try {
            $challengeUserId = $this->mfaService->verify($data['mfa_challenge_id'], $data['code']);
        } catch (MfaThrottledException $e) {
            return AuthThrottle::respuesta($e->retryAfter);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        // El challenge debe pertenecer al mismo usuario autenticado que lo generó — evita que
        // un challenge_id ajeno (adivinado o filtrado) active MFA a nombre de otra cuenta.
        if ($challengeUserId !== $user->id) {
            return response()->json(['message' => trans('auth.mfa_challenge_mismatch')], 403);
        }

        $this->mfaService->enableForUser($user);

        return response()->json(['mfa_enabled' => true]);
    }

    /** POST /api/auth/mfa/disable — protected (JWT required) */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $this->mfaService->disableForUser($user);

        return response()->json(['mfa_enabled' => false]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

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
