<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Http\Requests\UpdateProfileRequest;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\PreferencesService;
use App\Shared\Auth\JwtClaimsBuilder;
use App\Shared\Auth\JwtService;
use App\Shared\Auth\TenantResolver;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Autenticación',
    description: 'Login con JWT. Endpoints públicos — no requieren Authorization header.'
)]
class AuthController
{
    public function __construct(
        private readonly JwtService          $jwt,
        private readonly MfaService          $mfaService,
        private readonly TenantResolver      $tenantResolver,
        private readonly PreferencesService  $preferences,
        private readonly AuditService        $audit,
        private readonly JwtClaimsBuilder    $claims,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Resuelve el tenant por dominio (Host header) con fallback a TENANT_DEFAULT,
     * valida las credenciales y devuelve un JWT de acceso + un refresh token rotativo.
     */
    #[OA\Post(
        path: '/auth/login',
        summary: 'Iniciar sesión y obtener JWT',
        tags: ['Autenticación'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'gerencia@atlantic.test'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password123!'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login exitoso',
                content: new OA\JsonContent(
                    required: ['token', 'token_type', 'expires_in_ms', 'refresh_token', 'user'],
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'expires_in_ms', type: 'integer', example: 3600000),
                        new OA\Property(property: 'refresh_token', type: 'string'),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'first_name', type: 'string'),
                                new OA\Property(property: 'last_name', type: 'string'),
                                new OA\Property(property: 'email', type: 'string'),
                                new OA\Property(property: 'role', type: 'string'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Credenciales inválidas'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            $user = User::query()->where('email', $data['email'])->first();

            if (
                ! $user
                || ! $user->is_active
                || $user->isSecurityBlocked()
                || $user->hasRole(User::ROLE_GUEST)
                || ! Hash::check($data['password'], $user->password_hash)
            ) {
                // $user puede ser null (email no existe) — igual se loggea el intento para que
                // SecurityAnalysisJob pueda ver enumeración de emails desde una misma IP.
                $this->audit->log(
                    action:       AuditService::AUTH_FAILED,
                    entityType:   'user',
                    entityId:     $user?->id,
                    metadata:     ['attempted_email' => $data['email']],
                    actingUserId: $user?->id,
                );

                return response()->json(['message' => trans('auth.failed')], 401);
            }

            $this->audit->log(
                action:       AuditService::AUTH_LOGIN,
                entityType:   'user',
                entityId:     $user->id,
                actingUserId: $user->id,
            );

            // MFA check — happens before role selection
            if ($this->mfaService->isRequired($user)) {
                try {
                    $challengeId = $this->mfaService->generateChallenge($user);
                } catch (MfaDeliveryException) {
                    return response()->json(['message' => trans('auth.mfa_delivery_failed')], 503);
                }

                return response()->json([
                    'requires_mfa'     => true,
                    'mfa_challenge_id' => $challengeId,
                ]);
            }

            // ADR-006, Fase C, Decisión 1 revisada — el algoritmo v2 ya une automáticamente
            // role_id + user_additional_roles (ver User::modulesPayload()/effectivePermissionsV2()),
            // así que login() emite el JWT directo en un solo paso, sin pantalla de selección.
            // select-role/switch-role quedan en el código (no se borran) pero sin llamador real:
            // login() ya no genera selection tokens.
            $expirationMs = $this->preferences->getEffectiveSessionTimeoutMs($user);
            $claims       = $this->claims->claims($user, $tenant->slug);
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
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * POST /api/auth/refresh
     *
     * Acepta un refresh token, valida que no esté revocado ni expirado,
     * rota el token (el viejo queda revocado) y devuelve un nuevo JWT + nuevo refresh token.
     */
    #[OA\Post(
        path: '/auth/refresh',
        summary: 'Renovar JWT con refresh token',
        tags: ['Autenticación'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nuevo JWT emitido'),
            new OA\Response(response: 401, description: 'Refresh token inválido, expirado o revocado'),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            $tokenHash = hash('sha256', $data['refresh_token']);
            $table     = (new RefreshToken())->getTable();

            // SCRUM-746 — el "touch" de actividad y el gate de expiración viven acá (nunca en
            // AuthenticateJwt, ver Threat Model Hallazgo 1/4). Una sola sentencia atómica
            // UPDATE...RETURNING, nunca SELECT-luego-UPDATE (Hallazgo 2, evita el TOCTOU donde
            // 2 requests concurrentes en el borde del umbral podrían ambas leer la fila como
            // "todavía vigente"). El gate combina 2 criterios independientes: inactividad
            // (configurable, se reinicia con cada refresh exitoso) O el techo absoluto de 30 días
            // desde el login original (`login_at`, fijo, nunca se resetea por actividad) — lo que
            // ocurra primero. Si `RETURNING` no devuelve fila, la sesión ya expiró por cualquiera
            // de los dos motivos — mismo 401 que un token inválido, sin distinguir la causa.
            //
            // Los umbrales se calculan en PHP (Carbon::now(), mockeable con setTestNow en tests)
            // y se pasan como bindings — nunca `now()`/`make_interval()` de Postgres. La
            // atomicidad no depende de qué lado calcula el instante: viene de que la comparación y
            // la escritura ocurren en UNA sola sentencia UPDATE...WHERE...RETURNING. Usar el reloj
            // de Postgres acoplaría el gate al reloj real del contenedor, no al tiempo de la app.
            $now = Carbon::now();
            /** @var object{id: int, user_id: int, login_at: string}|null $touched */
            $touched = DB::selectOne(
                "UPDATE {$table}
                 SET last_activity_at = ?
                 WHERE token_hash = ?
                   AND revoked = false
                   AND last_activity_at > ?
                   AND login_at > ?
                 RETURNING id, user_id, login_at",
                [
                    $now,
                    $tokenHash,
                    $now->copy()->subHours($this->preferences->getSessionInactivityHours()),
                    $now->copy()->subDays(PreferencesService::SESSION_ABSOLUTE_CEILING_DAYS),
                ]
            );

            if ($touched === null) {
                return response()->json(['message' => trans('auth.token_invalid')], 401);
            }

            /** @var User|null $user */
            $user = User::find($touched->user_id);

            if ($user === null || ! $user->is_active || $user->isSecurityBlocked()) {
                return response()->json(['message' => trans('auth.failed')], 401);
            }

            // Fix adyacente (Threat Model Hallazgo 2, preexistente): revocación atómica con
            // `WHERE revoked = false` — sin esto, 2 requests concurrentes con el mismo refresh
            // token podían ambas pasar la validación antes de que cualquiera marcara `revoked =
            // true`, emitiendo 2 refresh tokens hijos válidos a partir de uno solo.
            $revokedRows = DB::table($table)
                ->where('id', $touched->id)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            if ($revokedRows === 0) {
                return response()->json(['message' => trans('auth.token_invalid')], 401);
            }

            $newRefreshToken = $this->issueRefreshToken($user, Carbon::parse($touched->login_at));

            $this->audit->log(
                action:       AuditService::AUTH_TOKEN_REFRESHED,
                entityType:   'user',
                entityId:     $user->id,
                actingUserId: $user->id,
            );

            $expirationMs = $this->preferences->getEffectiveSessionTimeoutMs($user);
            $claims       = $this->claims->claims($user, $tenant->slug);
            $accessToken  = $this->jwt->encode($claims, $expirationMs);

            return response()->json([
                'token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in_ms' => $expirationMs,
                'refresh_token' => $newRefreshToken,
            ]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * POST /api/auth/logout
     *
     * Revoca el refresh token. El JWT de acceso expira por sí solo.
     */
    #[OA\Post(
        path: '/auth/logout',
        summary: 'Cerrar sesión y revocar refresh token',
        tags: ['Autenticación'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Sesión cerrada'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(null, 204);
        }

        $tenant->makeCurrent();

        try {
            $tokenHash = hash('sha256', $data['refresh_token']);
            RefreshToken::where('token_hash', $tokenHash)->update(['revoked' => true]);
        } finally {
            Tenant::forgetCurrent();
        }

        return response()->json(null, 204);
    }

    /**
     * GET /api/auth/me
     *
     * Devuelve el perfil del usuario autenticado a partir de los claims del JWT.
     * No hace consulta a BD — los claims ya contienen los datos necesarios.
     */
    #[OA\Get(
        path: '/auth/me',
        summary: 'Perfil del usuario autenticado',
        tags: ['Autenticación'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Perfil del usuario',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'rol', type: 'string'),
                        new OA\Property(property: 'tenant', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'No autenticado'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->attributes->get('jwt');

        return response()->json([
            'id'          => $payload['sub'],
            'email'       => $payload['email'],
            'role'        => $payload['role'] ?? null,
            'role_id'     => $payload['role_id'] ?? null,
            'permissions' => $payload['permissions'],
            'modules'     => $payload['modules'] ?? null,
            'flags'       => $payload['flags'] ?? null,
            'tenant'      => $payload['tenant'],
        ]);
    }

    /**
     * Emite un refresh token nuevo.
     *
     * `$loginAt` distingue una emisión de LOGIN nuevo (login(), selectRole(), MFA verify — el
     * default `null` cae a `now()`, es una sesión que arranca en este momento) de una ROTACIÓN
     * dentro de `refresh()` (donde se pasa el `login_at` de la fila vieja para que el techo
     * absoluto de 30 días — SCRUM-746 — sobreviva la rotación en vez de resetearse en cada uso).
     */
    private function issueRefreshToken(User $user, ?Carbon $loginAt = null): string
    {
        $plain = Str::random(64);
        $days = (int) config('jwt.refresh_token_expiration_days', 30);
        $now  = now();

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $now->copy()->addDays($days),
            'revoked' => false,
            'login_at' => $loginAt ?? $now,
            'last_activity_at' => $now,
        ]);

        return $plain;
    }

    /**
     * POST /api/auth/select-role
     *
     * Finalizes a multi-role login. Validates the short-lived selection token,
     * confirms the requested role is in its payload, and issues a full JWT.
     */
    public function selectRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selection_token' => ['required', 'string'],
            'role'            => ['required', 'string'],
        ]);

        $decoded = $this->jwt->decodeSelectionToken($data['selection_token']);

        if ($decoded === null) {
            return response()->json(['message' => trans('auth.token_invalid')], 401);
        }

        if (! in_array($data['role'], $decoded['roles'], true)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var \App\Shared\Multitenancy\Tenant|null $tenant */
        $tenant = \App\Shared\Multitenancy\Tenant::where('slug', $decoded['tenant'])->first();

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            /** @var User|null $user */
            $user = User::find($decoded['sub']);

            if ($user === null || ! $user->is_active || $user->isSecurityBlocked()) {
                return response()->json(['message' => trans('auth.failed')], 401);
            }

            $activeRole   = $data['role'];
            $roles        = $decoded['roles'];
            $permissions  = $this->resolvePermissions($user);
            $expirationMs = $this->preferences->getEffectiveSessionTimeoutMs($user);

            $accessToken  = $this->jwt->encode([
                'sub'            => $user->id,
                'tenant'         => $tenant->slug,
                'email'          => $user->email,
                'role'           => $activeRole,
                'roles'          => $roles,
                'permissions'    => $permissions,
                'security_level' => $user->securityLevel?->level,
            ], $expirationMs);
            $refreshToken = $this->issueRefreshToken($user);

            return response()->json([
                'token'         => $accessToken,
                'token_type'    => 'Bearer',
                'expires_in_ms' => $expirationMs,
                'refresh_token' => $refreshToken,
                'user'          => $this->userPayload($user, $activeRole, $roles, $permissions),
            ]);
        } finally {
            \App\Shared\Multitenancy\Tenant::forgetCurrent();
        }
    }

    /**
     * POST /api/auth/switch-role
     *
     * Switches the active role within the current session.
     * Validates the requested role is among the JWT's `roles[]` claim.
     * Issues a new access token — the refresh token is unchanged.
     */
    public function switchRole(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string']]);

        /** @var array{sub: int, tenant: string, email: string, role?: string, roles?: list<string>, permissions: list<string>} $payload */
        $payload = $request->attributes->get('jwt');
        // ADR-006, Fase C — tokens emitidos por login()/refresh()/mfaVerify() ya no tienen 'roles'
        // (plural): el algoritmo v2 une role_id + user_additional_roles automáticamente, sin
        // selección. select-role/switch-role solo tienen sentido para un token nacido de
        // selectRole() (que sí sigue emitiendo 'roles'), que hoy no tiene llamador real. Sin este
        // fallback, un usuario normal pegándole a este endpoint con un JWT nuevo (sin 'roles')
        // crasheaba con 500 (in_array() con null) en vez del 403 correcto.
        $currentRoles = is_array($payload['roles'] ?? null) ? $payload['roles'] : [];

        if (! in_array($data['role'], $currentRoles, true)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var User|null $user */
        $user = User::find($payload['sub']);

        if ($user === null || ! $user->is_active || $user->isSecurityBlocked()) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $activeRole   = $data['role'];
        $permissions  = $payload['permissions'];
        $expirationMs = $this->preferences->getEffectiveSessionTimeoutMs($user);

        $accessToken = $this->jwt->encode([
            'sub'            => $payload['sub'],
            'tenant'         => $payload['tenant'],
            'email'          => $payload['email'],
            'role'           => $activeRole,
            'roles'          => $currentRoles,
            'permissions'    => $permissions,
            'security_level' => $payload['security_level'] ?? null,
        ], $expirationMs);

        return response()->json([
            'token'         => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in_ms' => $expirationMs,
            'user'          => $this->userPayload($user, $activeRole, $currentRoles, $permissions),
        ]);
    }

    /**
     * PUT /api/auth/password
     *
     * Changes the authenticated user's own password.
     * Revokes all existing refresh tokens after a successful change.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password'      => ['required', 'string'],
            'new_password'          => ['required', 'string', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
            'new_password_confirmation' => ['required', 'string'],
        ]);

        /** @var array{sub: int} $payload */
        $payload = $request->attributes->get('jwt');

        /** @var User|null $user */
        $user = User::find($payload['sub']);

        if ($user === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json(['message' => trans('auth.password_incorrect'), 'errors' => ['current_password' => [trans('auth.password_incorrect')]]], 422);
        }

        $user->password_hash = $data['new_password'];
        $user->save();

        // Revoke all refresh tokens so other sessions must re-authenticate
        RefreshToken::where('user_id', $user->id)->update(['revoked' => true]);

        return response()->json(null, 204);
    }

    /** @return list<string> */
    private function resolvePermissions(User $user): array
    {
        return $user->effectivePermissions();
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function userPayload(User $user, string $activeRole, array $roles, array $permissions): array
    {
        $prefs = is_array($user->preferences) ? $user->preferences : [];

        return [
            'id'            => $user->id,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'notes'         => $user->notes,
            'department_id' => $user->department_id,
            'language'      => $prefs['language'] ?? $this->preferences->getSystemSettings()['default_language'],
            'security_level' => $user->securityLevel?->level,
            'role'          => $activeRole,
            'roles'         => $roles,
            'permissions'   => $permissions,
        ];
    }

    /**
     * PATCH /api/auth/profile
     *
     * Updates the authenticated user's own profile fields (name, phone, notes).
     * Email cannot be changed here.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt');
        if (! is_array($payload) || empty($payload['sub'])) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::findOrFail((int) $payload['sub']);

        /** @var array{first_name?: string, last_name?: string, phone?: string|null, notes?: string|null, department_id?: int|null, language?: string} $data */
        $data = $request->validated();

        if (isset($data['first_name'])) {
            $user->first_name = $data['first_name'];
        }
        if (isset($data['last_name'])) {
            $user->last_name = $data['last_name'];
        }
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }
        if (array_key_exists('notes', $data)) {
            $user->notes = $data['notes'];
        }
        if (array_key_exists('department_id', $data)) {
            $user->department_id = $data['department_id'];
        }
        if (isset($data['language'])) {
            $prefs = is_array($user->preferences) ? $user->preferences : [];
            $user->preferences = array_merge($prefs, ['language' => $data['language']]);
        }
        $user->save();

        $prefs = is_array($user->preferences) ? $user->preferences : [];

        return response()->json([
            'id'            => $user->id,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'notes'         => $user->notes,
            'department_id' => $user->department_id,
            'language'      => $prefs['language'] ?? $this->preferences->getSystemSettings()['default_language'],
        ]);
    }

}
