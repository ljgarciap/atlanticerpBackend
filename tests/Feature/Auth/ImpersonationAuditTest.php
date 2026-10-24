<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\SystemSetting;
use App\Shared\Auth\JwtActor;
use App\Shared\Http\Middleware\AttributeDbChangesToUser;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T4) — atribución de la auditoría al ACTOR REAL durante «Ver como usuario» (CA14, condiciones C1 y C2 del threat model): ningún
 * `audit_logs` de la sesión puede quedar con `user_id` = el usuario suplantado.
 */
final class ImpersonationAuditTest extends FeatureTestCase
{
    private const PASSWORD = 'Password123!';
    private const REASON   = 'SCRUM-1158 reproducir pantalla vacia';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMakeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userId(string $email): int
    {
        $this->reMakeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function beginImpersonation(): array
    {
        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $response = $this->postJson('/api/users/' . $this->userId('designer@atlantic.test') . '/impersonate', [
            'reason' => self::REASON, 'operator_password' => self::PASSWORD,
        ], $admin)->assertStatus(201);

        return [$this->authHeaders((string) $response->json('token')), (string) $response->json('user.impersonation.id')];
    }

    /** Pone una request con claim `act` como la actual, igual que la dejaría AuthenticateJwt, para ejercitar código que escribe sin pasar por HTTP. */
    private function actAsImpersonation(int $actorId, int $targetId, string $impersonationId): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', ['sub' => $targetId, 'act' => ['sub' => $actorId, 'imp_id' => $impersonationId], 'tenant' => 'atlantic']);
        $this->app->instance('request', $request);
    }

    // ── CA14 por HTTP ─────────────────────────────────────────────────────────────────────────────────────

    public function test_un_evento_auditado_durante_la_sesion_queda_a_nombre_del_actor_con_el_objetivo_en_la_metadata(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        $designer   = $this->userId('designer@atlantic.test');
        $superadmin = $this->userId('superadmin@atlantic.test');

        // Un GET sin permiso genera `security.access_denied` vía CheckPermission → AuditService, con el sub del token (el diseñador).
        $this->getJson('/api/admin/security/impersonations', $headers)->assertStatus(403);

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', AuditService::SECURITY_ACCESS_DENIED)->latest('id')->firstOrFail();
        $this->assertSame($superadmin, $log->user_id, 'el autor es el superadmin, no el suplantado');
        $this->assertSame($designer, $log->metadata['impersonated_user_id']);
        $this->assertSame($sessionId, $log->metadata['impersonation_id']);
    }

    public function test_ningun_registro_de_la_sesion_queda_a_nombre_del_objetivo(): void
    {
        [$headers] = $this->beginImpersonation();
        $designer = $this->userId('designer@atlantic.test');

        $this->getJson('/api/auth/me', $headers)->assertOk();
        $this->getJson('/api/admin/security/impersonations', $headers)->assertStatus(403);
        $this->patchJson('/api/auth/profile', ['phone' => '+507 6000-0000'], $headers)->assertStatus(403);
        $this->deleteJson('/api/crm/contacts/1', [], $headers)->assertStatus(403);
        $this->postJson('/api/auth/impersonation/stop', [], $headers)->assertNoContent();

        $this->reMakeCurrent();
        $sessionLogs = AuditLog::query()->whereNotNull('metadata->impersonation_id')->get();
        $this->assertGreaterThanOrEqual(4, $sessionLogs->count());
        $this->assertSame(0, $sessionLogs->where('user_id', $designer)->count(), 'ningún registro lleva al suplantado como autor');
        $this->assertSame([$this->userId('superadmin@atlantic.test')], $sessionLogs->pluck('user_id')->unique()->values()->all());
    }

    public function test_el_inicio_y_el_cierre_de_la_sesion_se_atribuyen_al_actor(): void
    {
        [$headers] = $this->beginImpersonation();
        $this->postJson('/api/auth/impersonation/stop', [], $headers)->assertNoContent();
        $superadmin = $this->userId('superadmin@atlantic.test');

        $this->reMakeCurrent();
        foreach ([AuditService::AUTH_IMPERSONATION_STARTED, AuditService::AUTH_IMPERSONATION_ENDED] as $action) {
            $this->assertSame($superadmin, AuditLog::query()->where('action', $action)->firstOrFail()->user_id, $action);
        }
    }

    public function test_un_evento_fuera_de_impersonacion_no_lleva_metadata_de_impersonacion(): void
    {
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));
        $this->getJson('/api/admin/security/impersonations', $headers)->assertStatus(403);

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', AuditService::SECURITY_ACCESS_DENIED)->latest('id')->firstOrFail();
        $this->assertSame($this->userId('designer@atlantic.test'), $log->user_id);
        $this->assertArrayNotHasKey('impersonated_user_id', $log->metadata);
        $this->assertArrayNotHasKey('impersonation_id', $log->metadata);
    }

    // ── C2: claves reservadas ─────────────────────────────────────────────────────────────────────────────

    public function test_el_llamador_no_puede_pisar_las_claves_de_impersonacion(): void
    {
        $impId = (string) Str::uuid();
        $this->actAsImpersonation(actorId: 11, targetId: 22, impersonationId: $impId);

        app(AuditService::class)->log(
            action:   'test.reserved_keys',
            metadata: ['impersonated_user_id' => 999, 'impersonation_id' => 'falso', 'otro' => 'se conserva'],
        );

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'test.reserved_keys')->firstOrFail();
        $this->assertSame(22, $log->metadata['impersonated_user_id']);
        $this->assertSame($impId, $log->metadata['impersonation_id']);
        $this->assertSame('se conserva', $log->metadata['otro']);
    }

    public function test_sin_impersonacion_el_llamador_tampoco_puede_fabricar_la_huella(): void
    {
        app(AuditService::class)->log(
            action:   'test.forged_keys',
            metadata: ['impersonated_user_id' => 5, 'impersonation_id' => 'falso', 'otro' => 'ok'],
        );

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'test.forged_keys')->firstOrFail();
        $this->assertArrayNotHasKey('impersonated_user_id', $log->metadata);
        $this->assertArrayNotHasKey('impersonation_id', $log->metadata);
        $this->assertSame('ok', $log->metadata['otro']);
    }

    public function test_un_actingUserId_explicito_no_desvia_la_atribucion_durante_la_impersonacion(): void
    {
        $superadmin = $this->userId('superadmin@atlantic.test');
        $designer   = $this->userId('designer@atlantic.test');
        $this->actAsImpersonation($superadmin, $designer, (string) Str::uuid());

        app(AuditService::class)->log(action: 'test.explicit_acting_user', actingUserId: $designer);

        $this->reMakeCurrent();
        $this->assertSame($superadmin, AuditLog::query()->where('action', 'test.explicit_acting_user')->firstOrFail()->user_id);
    }

    /** Senior Review R2 — con contexto de impersonación el autor es SIEMPRE el `actor_id`, aunque sea null: nunca cae al `actingUserId` (el suplantado). */
    public function test_con_contexto_de_impersonacion_sin_actor_no_se_cae_al_acting_user_id(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $request  = Request::create('/api/test', 'GET');
        // `act` mal formado: hay contexto de impersonación pero no se puede resolver el actor.
        $request->attributes->set('jwt', ['sub' => $designer, 'act' => [], 'tenant' => 'atlantic']);
        $this->app->instance('request', $request);

        app(AuditService::class)->log(action: 'test.actor_null', actingUserId: $designer);

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'test.actor_null')->firstOrFail();
        $this->assertNull($log->user_id, 'un registro sin autor es mejor que uno a nombre del suplantado');
        $this->assertSame($designer, $log->metadata['impersonated_user_id']);
    }

    public function test_sin_contexto_de_impersonacion_el_acting_user_id_explicito_sigue_ganando(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $request  = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', ['sub' => $this->userId('superadmin@atlantic.test'), 'tenant' => 'atlantic']);
        $this->app->instance('request', $request);

        app(AuditService::class)->log(action: 'test.explicit_normal', actingUserId: $designer);

        $this->reMakeCurrent();
        $this->assertSame($designer, AuditLog::query()->where('action', 'test.explicit_normal')->firstOrFail()->user_id);
    }

    // ── C1: rastro ORM (LogsAuditTrail) y trigger de BD ───────────────────────────────────────────────────

    public function test_el_rastro_automatico_de_eloquent_tambien_va_al_actor(): void
    {
        $superadmin = $this->userId('superadmin@atlantic.test');
        $designer   = $this->userId('designer@atlantic.test');
        $impId      = (string) Str::uuid();
        $this->actAsImpersonation($superadmin, $designer, $impId);

        // SystemSetting usa LogsAuditTrail: un cambio por Eloquent genera su evento `*.updated` sin que el controlador haga nada.
        SystemSetting::updateOrCreate(['key' => 'default_theme'], ['value' => 'dark']);

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'like', '%system_setting.updated')->latest('id')->firstOrFail();
        $this->assertSame($superadmin, $log->user_id);
        $this->assertSame($designer, $log->metadata['impersonated_user_id']);
        $this->assertSame($impId, $log->metadata['impersonation_id']);
    }

    public function test_el_trigger_de_base_de_datos_atribuye_al_actor_real(): void
    {
        $superadmin = $this->userId('superadmin@atlantic.test');
        $designer   = $this->userId('designer@atlantic.test');
        $request    = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', ['sub' => $designer, 'act' => ['sub' => $superadmin, 'imp_id' => (string) Str::uuid()]]);

        $seen = null;
        (new AttributeDbChangesToUser())->handle($request, function () use (&$seen, $designer) {
            $seen = DB::selectOne("SELECT current_setting('app.current_user_id', true) AS v")->v;
            DB::table('atlantic_auth.users')->where('id', $designer)->update(['notes' => 'cambio durante impersonacion']);

            return response()->json([]);
        });

        $this->assertSame((string) $superadmin, $seen, 'la variable de sesión lleva al actor, no al suplantado');
        $row = DB::table('atlantic_auth.db_audit_log')->where('table_name', 'users')->orderByDesc('id')->first();
        $this->assertSame($superadmin, (int) $row->app_user_id);
        $after = DB::selectOne("SELECT current_setting('app.current_user_id', true) AS v")->v;
        $this->assertContains($after, [null, ''], 'la variable de sesión se limpia al terminar la request');
    }

    public function test_sin_impersonacion_el_trigger_sigue_atribuyendo_al_sub(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $request  = Request::create('/api/test', 'GET');
        $request->attributes->set('jwt', ['sub' => $designer]);

        $seen = null;
        (new AttributeDbChangesToUser())->handle($request, function () use (&$seen) {
            $seen = DB::selectOne("SELECT current_setting('app.current_user_id', true) AS v")->v;

            return response()->json([]);
        });

        $this->assertSame((string) $designer, $seen);
    }

    // ── JwtActor ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_jwt_actor_resuelve_el_autor_en_cada_forma_del_payload(): void
    {
        $this->assertSame(7, JwtActor::id(['sub' => 7]));
        $this->assertSame(7, JwtActor::id(['sub' => '7']));
        $this->assertNull(JwtActor::id(['sub' => 'abc']));
        $this->assertNull(JwtActor::id(null));
        $this->assertNull(JwtActor::id([]));

        $this->assertSame(3, JwtActor::id(['sub' => 7, 'act' => ['sub' => 3, 'imp_id' => 'x']]));
        // `act` mal formado nunca cae a `sub` (sería atribuir al suplantado).
        $this->assertNull(JwtActor::id(['sub' => 7, 'act' => null]));
        $this->assertNull(JwtActor::id(['sub' => 7, 'act' => []]));
        $this->assertNull(JwtActor::id(['sub' => 7, 'act' => 'texto']));

        $this->assertNull(JwtActor::impersonation(['sub' => 7]));
        $this->assertSame(
            ['actor_id' => 3, 'impersonation_id' => 'abc', 'impersonated_user_id' => 7],
            JwtActor::impersonation(['sub' => 7, 'act' => ['sub' => 3, 'imp_id' => 'abc']]),
        );
    }
}
