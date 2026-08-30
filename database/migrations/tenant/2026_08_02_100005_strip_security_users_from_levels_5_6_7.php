<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Senior Review, 2026-08-02 — hallazgo CRÍTICO durante Batch 1 de Servicios (docs/architecture/
 * servicios-fase4-diseno.md), antes de pushear/promover: `User::plainEscapeHatchPermissions()`
 * mezcla el catálogo legado de `security_levels.permissions` en `effectivePermissionsV2()` para
 * TODO usuario, tenga o no `role_id` (línea ~271 de `app/Models/User.php`) — no es solo el
 * fallback de cuentas legacy que su nombre sugiere. `JwtClaimsBuilder` vuelca ese resultado sin
 * filtrar al claim `permissions` del JWT, y `CheckPermission` lo usa tal cual.
 *
 * `2026_08_02_100003`/`100004` (mismo commit) reasignaron `vendedor_disenador` a Nivel 6 y
 * `tecnico_servicios`/`garantias_servicios` a Nivel 5 — ambos con `security.users` baked in desde
 * el `SecurityLevelSeeder` original (2026-06-XX). Sin este fix, esas 14 personas reales habrían
 * ganado acceso genuino a `permission:security.users` (`routes/users.php`/`admin.php` — crear,
 * editar, activar/desactivar, aprobar/rechazar OTROS usuarios), sin haber pasado nunca por el flag
 * transversal `manage_users` que es el mecanismo real e intencional para eso (ver
 * `RoleBackfillSeeder`, que se lo da explícito a Gerencia/superadmin, nunca por nivel).
 *
 * Fix — quita `security.users` de los Niveles 5, 6 y 7 (deja el resto de `CRM_FULL` intacto,
 * ver `SecurityLevelSeeder::LEVELS` ya actualizado para nuevos tenants). NO toca Niveles 8-10 —
 * Gerencia real (Nivel 9) SÍ depende hoy de `security.levels`/`settings.global` del catálogo
 * legado (confirmado con `SecurityLevelControllerTest`, no vía flag), tocar esos niveles rompería
 * funcionalidad real en producción. Nivel 7 se limpia preventivamente aunque nadie lo usa todavía
 * (confirmado libre) — mismo hueco esperando a quien lo reuse después si no se corrige ahora.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const LEVELS_TO_FIX = [5, 6, 7];

    private const CRM_FULL = ['crm.read', 'crm.write', 'crm.edit', 'crm.delete', 'reports.read', 'reports.export', 'ai.use'];

    public function up(): void
    {
        $table = $this->table('security_levels');

        foreach (self::LEVELS_TO_FIX as $level) {
            DB::table($table)
                ->where('level', $level)
                ->update(['permissions' => json_encode(self::CRM_FULL)]);
        }
    }

    public function down(): void
    {
        $table = $this->table('security_levels');

        foreach (self::LEVELS_TO_FIX as $level) {
            DB::table($table)
                ->where('level', $level)
                ->update(['permissions' => json_encode([...self::CRM_FULL, 'security.users'])]);
        }
    }
};
