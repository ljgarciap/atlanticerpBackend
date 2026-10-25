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
use Tests\Feature\FeatureTestCase;

/**
 * Fase 2 del aislamiento del núcleo (docs/specs/fase2-retiro-user-roles.md, paso 2.1) — Fase E del ADR-006.
 * Cubre: el backfill reversible de `role_id` desde `user_roles`, y los criterios de seguridad de la spec (§5.2): un superadmin que
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

    // ── backfill ───────────────────────────────────────────────────────────────

    public function test_el_backfill_pasa_los_roles_legados_con_equivalente_a_role_id_y_deja_null_los_demas(): void
    {
        $legados = ['superadmin', 'management', 'guest', 'designer'];
        $usuarios = [];
        foreach ($legados as $legacy) {
            $u = $this->makeUser(null);
            DB::table('atlantic_auth.user_roles')->insert(['user_id' => $u->id, 'role' => $legacy]);
            $usuarios[$legacy] = $u;
        }
        // Varios legados: gana el de más privilegio (superadmin).
        $multi = $this->makeUser(null);
        DB::table('atlantic_auth.user_roles')->insert([['user_id' => $multi->id, 'role' => 'management'], ['user_id' => $multi->id, 'role' => 'superadmin']]);
        // Quien ya tiene role_id NO se toca, aunque la fila legada diga otra cosa.
        $yaTiene = $this->makeUser($this->roleId('lider_compras'));
        DB::table('atlantic_auth.user_roles')->insert(['user_id' => $yaTiene->id, 'role' => 'superadmin']);

        $migracion = require database_path('migrations/tenant/2026_10_24_500000_backfill_role_id_from_user_roles.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame($this->roleId('superadmin'), $usuarios['superadmin']->fresh()->role_id);
        $this->assertSame($this->roleId('management'), $usuarios['management']->fresh()->role_id);
        $this->assertSame($this->roleId('guest'), $usuarios['guest']->fresh()->role_id);
        $this->assertNull($usuarios['designer']->fresh()->role_id, 'sin equivalente en el catálogo: queda sin rol, igual que hoy');
        $this->assertSame($this->roleId('superadmin'), $multi->fresh()->role_id);
        $this->assertSame($this->roleId('lider_compras'), $yaTiene->fresh()->role_id);
        $this->assertSame(1, Role::where('key', 'guest')->count());
        $this->assertFalse((bool) Role::where('key', 'guest')->value('is_active'));
    }

    public function test_el_down_del_backfill_restaura_el_estado_pendiente_de_quien_se_registro_despues(): void
    {
        $migracion = require database_path('migrations/tenant/2026_10_24_500000_backfill_role_id_from_user_roles.php');

        // Registrado DESPUÉS de la migración: solo role_id = guest, sin fila legada.
        $pendiente = $this->makeUser($this->roleId('guest'));
        $this->assertFalse(DB::table('atlantic_auth.user_roles')->where('user_id', $pendiente->id)->exists());

        $migracion->down();

        $this->assertTrue(DB::table('atlantic_auth.user_roles')->where('user_id', $pendiente->id)->where('role', 'guest')->exists());
        $this->assertNull($pendiente->fresh()->role_id);
        $this->assertSame(0, Role::where('key', 'guest')->count());

        $migracion->up(); // deja el entorno como estaba para el resto de los tests
    }

    // ── seguridad (criterio 2 de la spec) ──────────────────────────────────────

    public function test_un_superadmin_creado_solo_con_role_id_recibe_las_alertas_de_seguridad(): void
    {
        Mail::fake();
        $soloRoleId = $this->makeUser($this->roleId('superadmin'), 'super-roleid');
        $porAdicional = $this->makeUser($this->roleId('vendedor_disenador'), 'super-adicional');
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
        $normal = $this->makeUser($this->roleId('vendedor_disenador'), 'reset-normal');

        $ids = app(AdminPasswordResetService::class)->resolveTargets()->pluck('id')->all();

        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($soloRoleId->id, $ids);
    }

    public function test_with_role_key_encuentra_por_rol_base_o_adicional_y_no_por_otro(): void
    {
        $base = $this->makeUser($this->roleId('lider_bodega'), 'wrk-base');
        $adicional = $this->makeUser($this->roleId('vendedor_disenador'), 'wrk-adic');
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

        $this->assertSame(['vendedor_disenador', 'management', 'supervisor'], $regla->fresh()->recipient_value);
        $this->assertSame([1, 2], $porUsuario->fresh()->recipient_value, 'las reglas por usuario no se tocan');

        $migracion->down();
        $this->assertSame(['designer', 'management', 'supervisor'], $regla->fresh()->recipient_value);
    }
}
