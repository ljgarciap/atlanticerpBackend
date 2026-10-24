<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-810 (REQ-634, Epic SCRUM-788) — verifica que tenant:grant-app-role deja al rol atlanticerp_app
 * con acceso normal a una tabla cualquiera, pero SIN UPDATE/DELETE sobre audit_logs.
 *
 * No reconecta como atlanticerp_app (agregaría una segunda conexión PDO solo para el test) — usa
 * has_table_privilege(), la misma función que Postgres usa internamente para chequear permisos,
 * así que el resultado es equivalente a intentarlo de verdad.
 */
class GrantAppRoleCommandTest extends FeatureTestCase
{
    private const APP_ROLE = 'atlanticerp_app';

    protected function setUp(): void
    {
        parent::setUp();

        // Bootstrap del rol — en producción es un paso manual único por entorno (ver doc de
        // diseño), acá se recrea por test porque infra/test.sh dropea todo entre corridas.
        $role = self::APP_ROLE;
        DB::statement(<<<SQL
            DO \$\$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} WITH LOGIN PASSWORD 'test-only' NOSUPERUSER;
                END IF;
            END \$\$;
        SQL);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function hasPrivilege(string $table, string $privilege): bool
    {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne(
            "SELECT has_table_privilege(?, ?, ?) as ok",
            [self::APP_ROLE, $table, $privilege],
        );

        return (bool) $row->ok;
    }

    public function test_atlanticerp_app_no_puede_update_ni_delete_sobre_audit_logs(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertTrue($this->hasPrivilege('atlantic_auth.audit_logs', 'SELECT'));
        $this->assertTrue($this->hasPrivilege('atlantic_auth.audit_logs', 'INSERT'));
        $this->assertFalse($this->hasPrivilege('atlantic_auth.audit_logs', 'UPDATE'));
        $this->assertFalse($this->hasPrivilege('atlantic_auth.audit_logs', 'DELETE'));
    }

    public function test_atlanticerp_app_tiene_crud_normal_en_otras_tablas(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertTrue($this->hasPrivilege('atlantic_crm.projects', 'SELECT'));
        $this->assertTrue($this->hasPrivilege('atlantic_crm.projects', 'INSERT'));
        $this->assertTrue($this->hasPrivilege('atlantic_crm.projects', 'UPDATE'));
        $this->assertTrue($this->hasPrivilege('atlantic_crm.projects', 'DELETE'));
    }

    /**
     * Hallazgo real (2026-08-30, verificado en dev antes de tocar credenciales de runtime): sin
     * grant en `public`, atlanticerp_app no puede resolver el tenant activo, manejar sesiones, ni
     * procesar jobs — la app se rompe al toque. Sin excepción acá, no hay tabla sensible en el
     * schema landlord (audit_logs vive en el schema de tenant).
     */
    public function test_atlanticerp_app_tiene_crud_normal_en_el_schema_landlord_public(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertTrue($this->hasPrivilege('public.tenants', 'SELECT'));
        $this->assertTrue($this->hasPrivilege('public.sessions', 'INSERT'));
        $this->assertTrue($this->hasPrivilege('public.jobs', 'DELETE'));
        $this->assertTrue($this->hasPrivilege('public.global_settings', 'UPDATE'));
    }

    /** SCRUM-916 — mismo criterio de inmutabilidad que audit_logs, aplicado a db_audit_log. */
    public function test_atlanticerp_app_no_puede_update_ni_delete_sobre_db_audit_log(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertTrue($this->hasPrivilege('atlantic_auth.db_audit_log', 'SELECT'));
        $this->assertTrue($this->hasPrivilege('atlantic_auth.db_audit_log', 'INSERT'));
        $this->assertFalse($this->hasPrivilege('atlantic_auth.db_audit_log', 'UPDATE'));
        $this->assertFalse($this->hasPrivilege('atlantic_auth.db_audit_log', 'DELETE'));
    }

    /** SCRUM-1001 — fe_transmisiones es append-only también a nivel de BD, no solo del modelo. */
    public function test_atlanticerp_app_no_puede_update_ni_delete_sobre_fe_transmisiones(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertTrue($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'SELECT'));
        $this->assertTrue($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'INSERT'));
        $this->assertFalse($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'UPDATE'));
        $this->assertFalse($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'DELETE'));
    }

    public function test_comando_es_idempotente(): void
    {
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $this->assertFalse($this->hasPrivilege('atlantic_auth.audit_logs', 'UPDATE'));
        $this->assertFalse($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'UPDATE'));
        $this->assertFalse($this->hasPrivilege('atlantic_admin_contab.fe_transmisiones', 'DELETE'));
    }
}
