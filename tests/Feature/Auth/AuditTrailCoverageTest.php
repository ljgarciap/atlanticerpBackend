<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-915 — Auth era el único módulo de escritura sin ningún modelo usando LogsAuditTrail
 * (78 modelos ya cubiertos en AdminCont/Bodega/Servicios/VentasDiseno/Compras). Este test fija el
 * comportamiento para los dos modelos más sensibles (User, SecurityLevel) — el resto de modelos
 * de Auth con el trait agregado (Role, UserRole, UserAdditionalRole,
 * SecurityLevelModulePermission, RoleModuleVisibility, UserModuleVisibility,
 * UserModuleMenuVisibility, SystemSetting, Department) reusan el mismo mecanismo genérico del
 * trait, ya cubierto por AuditTrailModulePrefixTest (Compras) — no se duplica un test idéntico por
 * modelo acá.
 *
 * GlobalSetting quedó EXCLUIDO a propósito (no tiene el trait): es landlord-level (sin
 * Tenant::current()), y AuditLog::getTable() cae a una tabla `audit_logs` plana a nivel landlord
 * que no existe (solo hay migración de audit_logs bajo database/migrations/tenant) — agregar el
 * trait ahí hoy solo produciría el warning "audit_log.write_failed" en laravel.log en cada guardado,
 * nunca una fila real. Requiere una migración landlord nueva antes de sumarlo, fuera de alcance de
 * este ticket.
 */
final class AuditTrailCoverageTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function actingForAudit(User $user): void
    {
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    public function test_crear_actualizar_y_borrar_un_usuario_queda_en_audit_logs_con_prefijo_auth(): void
    {
        $actor = User::query()->firstOrFail();
        $this->actingForAudit($actor);

        $nuevo = User::query()->create([
            'first_name' => 'Auditoria', 'last_name' => 'Test', 'email' => 'auditoria.test@atlantic.test',
            'password_hash' => 'x', 'security_level_id' => $actor->security_level_id, 'is_active' => true,
        ]);

        $this->assertTrue(
            DB::table('atlantic_auth.audit_logs')
                ->where('action', 'auth.user.created')->where('entity_id', (string) $nuevo->id)->exists(),
        );

        $nuevo->update(['first_name' => 'Auditoria Editada']);
        $updated = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'auth.user.updated')->where('entity_id', (string) $nuevo->id)->first();
        $this->assertNotNull($updated);
        $newValues = json_decode((string) $updated->new_values, true);
        $this->assertSame('Auditoria Editada', $newValues['first_name']);
        // password_hash nunca debe aparecer, ni siquiera hasheado — ver auditableAttributes() override en User.
        $this->assertArrayNotHasKey('password_hash', $newValues);

        $nuevo->delete();
        $this->assertTrue(
            DB::table('atlantic_auth.audit_logs')
                ->where('action', 'auth.user.deleted')->where('entity_id', (string) $nuevo->id)->exists(),
        );
    }

    public function test_cambio_de_security_level_a_un_usuario_queda_registrado(): void
    {
        $actor = User::query()->firstOrFail();
        $this->actingForAudit($actor);

        // level=99 a propósito: los niveles reales 1-10 ya están seedeados con datos de negocio
        // reales (CoreUserSeeder/reseed de vocabulario) — cualquier valor en ese rango choca con
        // el UNIQUE real. La columna es smallInteger sin CHECK de rango, así que un valor fuera de
        // 1-10 es seguro para un test que no depende de la semántica real de ningún nivel.
        $nivel = SecurityLevel::query()->create([
            'level' => 99, 'name' => 'Auditoria Test', 'permissions' => ['crm.read'], 'is_active' => true,
        ]);

        $this->assertTrue(
            DB::table('atlantic_auth.audit_logs')
                ->where('action', 'auth.security_level.created')->where('entity_id', (string) $nivel->id)->exists(),
        );

        $nivel->update(['permissions' => ['crm.read', 'crm.write']]);
        $this->assertTrue(
            DB::table('atlantic_auth.audit_logs')
                ->where('action', 'auth.security_level.updated')->where('entity_id', (string) $nivel->id)->exists(),
        );
    }

    /**
     * Pre-QA 2026-09-14 — el test de arriba solo confirma que password_hash no aparece cuando
     * también cambian otros campos. Escenario más estricto: el ÚNICO campo que cambia es
     * password_hash — tras `auditableAttributes()` filtrarlo, `getChanges()` queda vacío y el
     * trait no debería loguear nada (ni una fila vacía/ruidosa).
     */
    public function test_actualizar_solo_password_hash_no_genera_fila_de_auditoria(): void
    {
        $user = User::query()->firstOrFail();
        $this->actingForAudit($user);

        $before = DB::table('atlantic_auth.audit_logs')->count();
        $user->update(['password_hash' => 'un-hash-cualquiera']);
        $after = DB::table('atlantic_auth.audit_logs')->count();

        $this->assertSame($before, $after, 'Un cambio SOLO de password_hash no debería generar ninguna fila de auditoría');
    }

    /**
     * Pre-QA 2026-09-14 — un `->save()` que no deja ningún atributo "dirty" no debería generar una
     * fila de auditoría vacía/ruidosa.
     */
    public function test_save_sin_cambios_reales_no_genera_fila_de_auditoria(): void
    {
        $user = User::query()->firstOrFail();
        $this->actingForAudit($user);

        $before = DB::table('atlantic_auth.audit_logs')->count();
        $user->first_name = $user->first_name;
        $user->save();
        $after = DB::table('atlantic_auth.audit_logs')->count();

        $this->assertSame($before, $after, 'Un save() sin cambios reales no debería generar una fila de auditoría');
    }
}
