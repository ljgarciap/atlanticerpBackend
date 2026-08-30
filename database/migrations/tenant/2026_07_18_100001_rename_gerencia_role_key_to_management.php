<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006, Fase C — daily 2026-07-09. `role:superadmin,management` en `routes/crm.php:52,71`
 * (EnforceRole) matchea el claim `role` del JWT como string exacto. El rol nuevo "Gerencia" (key
 * `gerencia`) y el rol legado `management` de `user_roles` son el MISMO rol de negocio — en vez de
 * migrar esas 2 rutas a `permission:crm.view_team` (Riesgo 6) o parchear `EnforceRole` con un alias
 * legado→nuevo, se unifica el identificador: el rol pasa a llamarse `management` también en la
 * tabla `roles`. El `name` visible ("Gerencia") no cambia, solo el `key` interno. Con esto, el JWT
 * emitido por Fase C sigue teniendo `role: "management"` para este rol y las 2 rutas de CRM
 * siguen funcionando sin tocarlas.
 *
 * No afecta `ModuleCatalog::GERENCIA` ('gerencia') — es el key de uno de los 7 MÓDULOS de negocio
 * (tabla `security_level_module_permissions.module`), un catálogo distinto sin relación con `roles`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('roles'))
            ->where('key', 'gerencia')
            ->update(['key' => 'management']);
    }

    public function down(): void
    {
        DB::table($this->table('roles'))
            ->where('key', 'management')
            ->update(['key' => 'gerencia']);
    }
};
