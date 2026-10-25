<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Http\Requests\UpdateProfileRequest;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Services\ImpersonationService;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\PasswordNotifier;
use App\Modules\Auth\Services\PasswordResetPolicy;
use App\Modules\Auth\Services\PreferencesService;
use App\Modules\Auth\Support\AuthThrottle;
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
        private readonly PasswordResetPolicy $resetPolicy,
        private readonly PasswordNotifier    $passwordNotifier,
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

        // H4: tope de intentos FALLIDOS por IP y por correo normalizado (config/throttle.php). El cupo se RESERVA antes de evaluar las
        // credenciales (incremento atómico: una ráfaga paralela no multiplica los intentos) y un login correcto lo devuelve, así que
        // solo cuentan los fallos. El límite por correo se aplica igual exista o no la cuenta (no revela si el correo existe). Sin
        // bloqueo permanente.
        $ipKey    = 'login:ip:' . $request->ip();
        $emailKey = 'login:email:' . AuthThrottle::emailKey($data['email']);
        $cfg      = (array) config('throttle.login');
        if (($retry = AuthThrottle::reservar([
            $ipKey    => [(int) $cfg['ip_max'], (int) $cfg['ip_decay']],
            $emailKey => [(int) $cfg['email_max'], (int) $cfg['email_decay']],
        ], AuthThrottle::ABRIR)) !== null) {
            return AuthThrottle::respuesta($retry);
        }

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            $user = User::query()->where('email', $data['email'])->first();

            // SCRUM-1034 (A-04): el bcrypt corre SIEMPRE (contra un hash ficticio del mismo coste si no hay cuenta), para que el
            // tiempo de respuesta no revele si el correo existe, está inactivo, bloqueado o es invitado.
            $passwordOk = Hash::check($data['password'], $user !== null ? $user->password_hash : self::dummyPasswordHash());

            if (
                ! $user
                || ! $user->is_active
                || $user->isSecurityBlocked()
                || ! $passwordOk
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

            // Credenciales correctas: se libera el cupo de ESE correo y se devuelve el intento reservado de la IP (solo cuentan fallos).
            AuthThrottle::limpiar($emailKey);
            AuthThrottle::devolver($ipKey, (int) $cfg['ip_decay']);

            // H2: contraseña temporal de un reset administrativo CADUCADA. Se responde solo con credenciales correctas (con la
            // contraseña = correo, que es justo la temporal) y sin emitir ningún token.
            if ($this->resetPolicy->isExpired($user)) {
                $this->audit->log(
                    action:       AuditService::AUTH_FAILED,
                    entityType:   'user',
                    entityId:     $user->id,
                    metadata:     ['reason' => 'password_reset_expired'],
                    actingUserId: $user->id,
                );

                return response()->json([
                    'message' => trans('auth.password_reset_expired'),
                    'code'    => 'password_reset_expired',
                ], 401);
            }

            $this->audit->log(
                action:       AuditService::AUTH_LOGIN,
                entityType:   'user',
                entityId:     $user->id,
                actingUserId: $user->id,
            );

            // MFA check — happens before role selection
            if ($this->mfaService->isRequired($user)) {
                // R3-4: cada login correcto con MFA envía un código por correo; sin tope, 15 logins = 15 correos. Tope por usuario.
                $sendCfg = (array) config('throttle.mfa_send');
                if (($retry = AuthThrottle::reservar([
                    'mfa:send:user:' . $user->id => [(int) $sendCfg['max'], (int) $sendCfg['decay']],
                ], AuthThrottle::ABRIR)) !== null) {
                    return AuthThrottle::respuesta($retry);
                }

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
            // SCRUM-1035: select-role/switch-role y los tokens de selección se eliminaron (código muerto, A-06).
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

        // H4: tope de refresh tokens inválidos por IP (solo se cuentan los fallos).
        $refreshKey = 'refresh:ip:' . $request->ip();
        $refreshCfg = (array) config('throttle.refresh');
        if (($retry = AuthThrottle::reservar([$refreshKey => [(int) $refreshCfg['ip_max'], (int) $refreshCfg['ip_decay']]], AuthThrottle::ABRIR)) !== null) {
            return AuthThrottle::respuesta($retry);
        }

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
                // SCRUM-1033 (A-03): un refresh token ya REVOCADO que se vuelve a presentar es la señal de reuso/robo (la
                // víctima o el atacante usaron primero el token rotado). Se audita para SecurityAnalysisJob; el cliente
                // recibe el mismo 401 que con un token inexistente o vencido (no se distingue hacia afuera).
                $reused = RefreshToken::where('token_hash', $tokenHash)->where('revoked', true)->first(['user_id']);
                if ($reused !== null) {
                    $this->audit->log(
                        action:       AuditService::AUTH_REFRESH_TOKEN_REUSE,
                        entityType:   'user',
                        entityId:     $reused->user_id,
                        metadata:     ['reason' => 'revoked_refresh_token_presented'],
                        actingUserId: $reused->user_id,
                    );
                }

                return response()->json(['message' => trans('auth.token_invalid')], 401);
            }

            // Token válido: se devuelve el intento reservado (solo cuentan los refresh inválidos).
            AuthThrottle::devolver($refreshKey, (int) $refreshCfg['ip_decay']);

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

        // Una query por PK: el frontend la usa al recargar para saber si debe forzar el cambio de contraseña.
        $mustChangePassword = User::where('id', (int) $payload['sub'])->value('must_change_password') === true;

        return response()->json([
            'id'          => $payload['sub'],
            'email'       => $payload['email'],
            'role'        => $payload['role'] ?? null,
            'role_id'     => $payload['role_id'] ?? null,
            'permissions' => $payload['permissions'],
            'modules'     => $payload['modules'] ?? null,
            'flags'       => $payload['flags'] ?? null,
            'tenant'      => $payload['tenant'],
            'must_change_password' => $mustChangePassword,
            // SCRUM-1158 — con una sesión «Ver como usuario» viva, el frontend (tras recargar) recupera de acá el banner. Null si el token es normal.
            'impersonation' => isset($payload['act']) ? app(ImpersonationService::class)->describe($payload) : null,
        ]);
    }

    /**
     * Emite un refresh token nuevo.
     *
     * `$loginAt` distingue una emisión de LOGIN nuevo (login(), MFA verify — el
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

        // Reset administrativo (contraseña = correo): la nueva no puede volver a ser el correo ni repetir la actual.
        if (mb_strtolower($data['new_password']) === mb_strtolower($user->email)) {
            return response()->json(['message' => trans('auth.password_same_as_email'), 'errors' => ['new_password' => [trans('auth.password_same_as_email')]]], 422);
        }

        if ($data['new_password'] === $data['current_password']) {
            return response()->json(['message' => trans('auth.password_same_as_current'), 'errors' => ['new_password' => [trans('auth.password_same_as_current')]]], 422);
        }

        $user->password_hash = $data['new_password'];
        $user->must_change_password = false;
        // SCRUM-1033 (A-02): los access tokens emitidos antes de este instante dejan de valer (AuthenticateJwt). Truncado al
        // segundo a propósito (el `iat` del JWT es de segundos): un login inmediato tras el cambio (ForcePasswordChangePage)
        // emite un token con `iat` igual a ese segundo y debe valer; solo un token del MISMO segundo previo al cambio se cuela.
        $user->password_changed_at = Carbon::now()->startOfSecond();
        $user->save();

        // Revoke all refresh tokens so other sessions must re-authenticate
        RefreshToken::where('user_id', $user->id)->update(['revoked' => true]);

        // S2: aviso al dueño de la cuenta (un fallo de envío no rompe el cambio).
        $this->passwordNotifier->notifyChanged($user);

        return response()->json(null, 204);
    }

    private static ?string $dummyPasswordHash = null;

    /**
     * SCRUM-1034 (A-04): hash ficticio con el MISMO coste que los reales (se genera con el hasher configurado, una vez por proceso)
     * contra el que login() hace el `Hash::check` cuando no hay cuenta válida, para igualar los tiempos de respuesta.
     */
    private static function dummyPasswordHash(): string
    {
        return self::$dummyPasswordHash ??= Hash::make(bin2hex(random_bytes(16)));
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
