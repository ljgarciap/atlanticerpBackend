<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-810 (REQ-634, Epic SCRUM-788) — otorga al rol de runtime `atlanticerp_app` (sin superuser, ver
 * docs/architecture/bodega-telemetria-fraude-diseno.md sección REQ-634) los permisos que necesita
 * para operar sobre cada schema de tenant, con excepciones angostas: nunca UPDATE/DELETE sobre las
 * tablas que tienen que quedar inmutables para servir como evidencia real (`audit_logs`,
 * `db_audit_log` y, desde SCRUM-1001, `admin_contab.fe_transmisiones`). Desde SCRUM-1158 (condición C4 del threat model de «Ver como usuario»)
 * `auth.impersonation_sessions` pierde DELETE y su UPDATE queda limitado a las columnas de cierre de la sesión.
 *
 * Corre en CADA deploy (idempotente, GRANT/REVOKE no fallan si ya están aplicados), siempre
 * DESPUÉS de `tenants:artisan migrate` — mismo patrón que SyncTenantSchemasCommand, pero de
 * permisos en vez de creación de schema. Debe correr con las credenciales de `atlanticerp` (superuser/
 * dueño de los objetos) — `atlanticerp_app` nunca necesita ejecutar esto sobre sí misma.
 *
 * NO crea el rol `atlanticerp_app` — eso es un bootstrap único por entorno (SQL manual vía SSH, ver
 * el doc de diseño), no algo que deba repetirse en cada deploy.
 */
final class GrantAppRoleCommand extends Command
{
    private const APP_ROLE = 'atlanticerp_app';

    protected $signature   = 'tenant:grant-app-role';
    protected $description = 'Otorga a atlanticerp_app los permisos de runtime en cada schema de tenant, '
        .'con UPDATE/DELETE revocado sobre audit_logs. Idempotente, correr en cada deploy después de '
        .'tenants:artisan migrate.';

    public function handle(): int
    {
        // Hallazgo real 2026-08-30 (verificado en dev antes de tocar credenciales de runtime):
        // sin esto, atlanticerp_app queda sin acceso a `tenants`/`sessions`/`jobs`/`cache`/
        // `global_settings`/`migrations` — la app no puede ni resolver qué tenant está activo.
        // No hay tabla sensible que proteger acá (audit_logs vive en el schema de tenant), así
        // que es CRUD completo, sin excepciones.
        $this->grantOnSchema('public');
        $this->info('Schema landlord [public]: permisos de '.self::APP_ROLE.' aplicados.');

        foreach (Tenant::all() as $tenant) {
            foreach ($tenant->schemas() as $schema) {
                $this->grantOnSchema($schema);
            }

            $this->revokeAuditLogsWrite($tenant->slug);
            $this->revokeDbAuditLogWrite($tenant->slug);
            $this->revokeFeTransmisionesWrite($tenant->slug);
            $this->revokePaymentMovementsWrite($tenant->slug);
            $this->restrictImpersonationSessionsWrite($tenant->slug);

            $this->info("Tenant [{$tenant->slug}]: permisos de ".self::APP_ROLE." aplicados en ".count($tenant->schemas())." schema(s).");
        }

        return self::SUCCESS;
    }

    private function grantOnSchema(string $schema): void
    {
        $role = self::APP_ROLE;

        DB::statement("GRANT USAGE ON SCHEMA \"{$schema}\" TO {$role}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA \"{$schema}\" TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA \"{$schema}\" TO {$role}");

        // Retroactivo arriba (tablas que ya existen); prospectivo acá (tablas que cree una
        // migración futura de `atlanticerp`, sin necesitar correr este comando a mano de nuevo).
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE atlanticerp IN SCHEMA \"{$schema}\" GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE atlanticerp IN SCHEMA \"{$schema}\" GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
    }

    private function revokeAuditLogsWrite(string $tenantSlug): void
    {
        // Excepción angosta, DESPUÉS del grant general de arriba — auth.audit_logs es el único
        // caso hoy. Si audit_logs se agrega a otro schema en el futuro, agregar esa línea acá
        // explícitamente — nunca inferir "toda tabla que se llame audit_logs" automáticamente.
        DB::statement("REVOKE UPDATE, DELETE ON \"{$tenantSlug}_auth\".audit_logs FROM ".self::APP_ROLE);
    }

    private function revokeDbAuditLogWrite(string $tenantSlug): void
    {
        // SCRUM-916 — mismo criterio que audit_logs: db_audit_log es el backstop a nivel de base
        // de datos, tiene que quedar inmutable para el mismo rol que puede escribir todo lo demás.
        DB::statement("REVOKE UPDATE, DELETE ON \"{$tenantSlug}_auth\".db_audit_log FROM ".self::APP_ROLE);
    }

    private function revokeFeTransmisionesWrite(string $tenantSlug): void
    {
        // SCRUM-1001 — registro append-only de lo enviado/recibido del PAC (evidencia fiscal). Hasta
        // acá solo el modelo Eloquent lo impedía; DB::table()/SQL crudo lo saltaban. Guardia de
        // existencia: en un deploy donde la migración aún no corrió la tabla no existe todavía.
        $qualified = "{$tenantSlug}_admin_contab.fe_transmisiones";

        /** @var object{ok: bool} $row */
        $row = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', ["\"{$tenantSlug}_admin_contab\".fe_transmisiones"]);

        if (! (bool) $row->ok) {
            $this->warn("Tenant [{$tenantSlug}]: {$qualified} no existe todavía, se omite el REVOKE.");

            return;
        }

        DB::statement("REVOKE UPDATE, DELETE ON \"{$tenantSlug}_admin_contab\".fe_transmisiones FROM ".self::APP_ROLE);
    }

    private function revokePaymentMovementsWrite(string $tenantSlug): void
    {
        // SCRUM-1168 F1 — libro de movimientos de cobros: la traza es append-only. El modelo Eloquent ya lo impide; esto lo impide también
        // para SQL crudo y `DB::table()`. Guardia de existencia: en un deploy donde la migración aún no corrió la tabla no existe todavía.
        $schema = "{$tenantSlug}_admin_contab";

        /** @var object{ok: bool} $row */
        $row = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', ["\"{$schema}\".payment_movements"]);

        if (! (bool) $row->ok) {
            $this->warn("Tenant [{$tenantSlug}]: {$schema}.payment_movements no existe todavía, se omite el REVOKE.");

            return;
        }

        DB::statement("REVOKE UPDATE, DELETE ON \"{$schema}\".payment_movements FROM ".self::APP_ROLE);
    }

    /**
     * SCRUM-1158 (C4) — la evidencia de quién miró a quién no puede borrarse ni reescribirse con el rol de runtime. La tabla se actualiza
     * legítimamente al CERRAR la sesión (`ended_at`, `ended_reason`, `ended_by`), así que no es append-only como `audit_logs`: se
     * revoca el UPDATE de tabla completa y se concede solo sobre esas 3 columnas (un `UPDATE ... SET reason = ...` falla con permission
     * denied). DELETE se revoca por completo. Guardia de existencia por si la migración aún no corrió.
     */
    private function restrictImpersonationSessionsWrite(string $tenantSlug): void
    {
        $schema = "{$tenantSlug}_auth";

        /** @var object{ok: bool} $row */
        $row = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', ["\"{$schema}\".impersonation_sessions"]);

        if (! (bool) $row->ok) {
            $this->warn("Tenant [{$tenantSlug}]: {$schema}.impersonation_sessions no existe todavía, se omite la restricción.");

            return;
        }

        $role = self::APP_ROLE;

        DB::statement("REVOKE DELETE, TRUNCATE ON \"{$schema}\".impersonation_sessions FROM {$role}");
        DB::statement("REVOKE UPDATE ON \"{$schema}\".impersonation_sessions FROM {$role}");
        DB::statement("GRANT UPDATE (ended_at, ended_reason, ended_by) ON \"{$schema}\".impersonation_sessions TO {$role}");
    }
}
