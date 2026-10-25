<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Listeners\NotifySuperadminsOfAnomaly;
use App\Modules\Auth\Mail\SecurityAnomalyMail;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityAnomaly;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Modules\CRM\Models\NotificationRule;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 2 del aislamiento del núcleo (docs/specs/fase2-retiro-user-roles.md, pasos 2.1 y 2.2) — Fase E del ADR-006.
 * Cubre: el borrado de `user_roles` y del rol `guest` (con su salvaguarda y su `down()`), y los criterios de seguridad de la spec (§5.2): un superadmin que
 * solo tiene `role_id` (creado desde la pantalla de usuarios) SÍ recibe las alertas y NO entra en un reset masivo.
 */
class Fase2RetiroUserRolesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeUser(?int $roleId, string $prefix = 'f2'): User
    {
        return User::create([
            'first_name' => 'Fase', 'last_name' => 'Dos', 'email' => uniqid("{$prefix}-").'@atlantic.test', 'password_hash' => 'x',
            'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id, 'role_id' => $roleId, 'is_active' => true,
        ]);
    }

    private function roleId(string $key): int
    {
        return (int) Role::where('key', $key)->value('id');
    }

    // ── paso 2.2: borrado de la tabla legada y del rol guest ──────────────────

    private const MIGRACION_22 = 'migrations/tenant/2026_10_25_100000_drop_user_roles_table_and_guest_role.php';

    /** El entorno de pruebas ya corrió el 2.2 (la tabla no existe): `down()` la recrea para poder ejercitar el `up()`. */
    private function conTablaLegada(): object
    {
        $migracion = require database_path(self::MIGRACION_22);
        $migracion->down();
        $this->assertTrue(Schema::hasTable('atlantic_auth.user_roles'));

        return $migracion;
    }

    public function test_en_este_entorno_la_tabla_user_roles_y_el_rol_guest_ya_no_existen(): void
    {
        $this->assertFalse(Schema::hasTable('atlantic_auth.user_roles'));
        $this->assertSame(0, Role::where('key', 'guest')->count());
        $this->assertSame(14, Role::count());
    }

    public function test_up_borra_la_tabla_y_el_rol_guest_y_deja_a_los_pendientes_sin_rol_ni_borrarlos(): void
    {
        $migracion = $this->conTablaLegada();
        $pendiente = $this->makeUser($this->roleId('guest'), 'pendiente');
        $pendiente->forceFill(['is_active' => false])->save();
        $normal = $this->makeUser($this->roleId('lider_bodega'), 'normal');

        $migracion->up();

        $this->assertFalse(Schema::hasTable('atlantic_auth.user_roles'));
        $this->assertSame(0, Role::where('key', 'guest')->count());
        $this->assertNull($pendiente->fresh()->role_id, 'el pendiente queda sin rol');
        $this->assertFalse((bool) $pendiente->fresh()->is_active, 'y sigue inactivo: nadie se borra ni se activa');
        $this->assertSame($this->roleId('lider_bodega'), $normal->fresh()->role_id);
    }

    public function test_up_aborta_si_alguien_con_rol_legado_perderia_el_acceso(): void
    {
        $migracion = $this->conTablaLegada();
        $sinRoleId = $this->makeUser(null, 'sin-role-id');
        DB::table('atlantic_auth.user_roles')->insert(['user_id' => $sinRoleId->id, 'role' => 'superadmin']);

        try {
            $migracion->up();
            $this->fail('debió abortar: ese usuario perdería el acceso');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('perderían el acceso', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('atlantic_auth.user_roles'), 'al abortar no se borra nada');
        $this->assertSame(1, Role::where('key', 'guest')->count());
    }

    public function test_down_recrea_tabla_con_restriccion_trigger_y_repuebla_superadmin_y_management(): void
    {
        $migracion = $this->conTablaLegada();

        $super = DB::table('atlantic_auth.user_roles')->where('role', 'superadmin')->count();
        $this->assertSame(User::where('role_id', $this->roleId('superadmin'))->count(), $super);
        $this->assertSame(User::where('role_id', $this->roleId('management'))->count(), DB::table('atlantic_auth.user_roles')->where('role', 'management')->count());

        // Restricción de valores y trigger de auditoría, como los dejaron las migraciones originales.
        $this->assertTrue((bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_audit_user_roles')"));
        try {
            // En un savepoint: la violación aborta la transacción de Postgres y el resto del test (el `up()` final) debe poder seguir.
            DB::transaction(fn () => DB::table('atlantic_auth.user_roles')->insert(['user_id' => User::query()->value('id'), 'role' => 'inventado']));
            $this->fail('la restricción CHECK debió rechazar un rol fuera del enum');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }

        $migracion->up();
    }

    // ── seguridad (criterio 2 de la spec) ──────────────────────────────────────

    public function test_un_superadmin_creado_solo_con_role_id_recibe_las_alertas_de_seguridad(): void
    {
        Mail::fake();
        $soloRoleId = $this->makeUser($this->roleId('superadmin'), 'super-roleid');
        $porAdicional = $this->makeUser($this->roleId('vendedor'), 'super-adicional');
        UserAdditionalRole::create(['user_id' => $porAdicional->id, 'role_id' => $this->roleId('superadmin')]);
        $normal = $this->makeUser($this->roleId('gerencia') ?: $this->roleId('management'), 'no-super');

        $anomalia = SecurityAnomaly::create([
            'severity' => SecurityAnomaly::SEVERITY_LOW, 'reasoning' => 'prueba Fase 2', 'raw_response' => ['source' => 'test'],
            'affected_user_id' => null, 'range_start' => now()->subHour(), 'range_end' => now(), 'status' => SecurityAnomaly::STATUS_PENDING,
        ]);
        (new NotifySuperadminsOfAnomaly)->handle(new SecurityAnomalyDetected($anomalia));

        Mail::assertSent(SecurityAnomalyMail::class, fn ($m) => $m->hasTo($soloRoleId->email));
        Mail::assertSent(SecurityAnomalyMail::class, fn ($m) => $m->hasTo($porAdicional->email));
        Mail::assertNotSent(SecurityAnomalyMail::class, fn ($m) => $m->hasTo($normal->email));
    }

    public function test_un_reset_masivo_excluye_al_superadmin_que_solo_tiene_role_id(): void
    {
        $soloRoleId = $this->makeUser($this->roleId('superadmin'), 'reset-super');
        $normal = $this->makeUser($this->roleId('vendedor'), 'reset-normal');

        $ids = app(AdminPasswordResetService::class)->resolveTargets()->pluck('id')->all();

        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($soloRoleId->id, $ids);
    }

    public function test_with_role_key_encuentra_por_rol_base_o_adicional_y_no_por_otro(): void
    {
        $base = $this->makeUser($this->roleId('lider_bodega'), 'wrk-base');
        $adicional = $this->makeUser($this->roleId('vendedor'), 'wrk-adic');
        UserAdditionalRole::create(['user_id' => $adicional->id, 'role_id' => $this->roleId('lider_bodega')]);
        $otro = $this->makeUser($this->roleId('lider_compras'), 'wrk-otro');

        $ids = User::query()->withRoleKey(['lider_bodega'])->pluck('id')->all();

        $this->assertContains($base->id, $ids);
        $this->assertContains($adicional->id, $ids);
        $this->assertNotContains($otro->id, $ids);
    }

    public function test_las_reglas_de_notificacion_por_rol_pasan_de_designer_a_vendedor_disenador_y_vuelven(): void
    {
        $regla = NotificationRule::create([
            'name' => 'Regla Fase 2', 'trigger_type' => 'model_event', 'trigger_model' => 'Project', 'trigger_event' => 'updated',
            'field' => 'etapa', 'operator' => 'changed_to', 'value' => 'cerrado', 'channels' => ['in_app'],
            'recipient_type' => 'role', 'recipient_value' => ['designer', 'management', 'supervisor'], 'is_active' => true,
        ]);
        $porUsuario = NotificationRule::create([
            'name' => 'Regla por usuario', 'trigger_type' => 'model_event', 'trigger_model' => 'Project', 'trigger_event' => 'updated',
            'field' => 'etapa', 'operator' => 'changed_to', 'value' => 'cerrado', 'channels' => ['in_app'],
            'recipient_type' => 'user', 'recipient_value' => [1, 2], 'is_active' => true,
        ]);

        $migracion = require database_path('migrations/tenant/2026_10_24_500001_migrate_notification_rule_role_recipients_to_role_keys.php');
        $migracion->up();
        $migracion->up(); // idempotente

        // La migración 500001 es HISTÓRICA (Fase 2): traduce al nombre de ese momento; la Fase 3 lo renombra después.
        $this->assertSame(['vendedor_disenador', 'management', 'supervisor'], $regla->fresh()->recipient_value);
        $this->assertSame([1, 2], $porUsuario->fresh()->recipient_value, 'las reglas por usuario no se tocan');

        $migracion->down();
        $this->assertSame(['designer', 'management', 'supervisor'], $regla->fresh()->recipient_value);
    }
}
