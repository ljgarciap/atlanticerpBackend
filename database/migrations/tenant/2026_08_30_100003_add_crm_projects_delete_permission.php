<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30) —
 * último permiso hardcodeado que quedaba en routes/crm.php (`role:superadmin,management` en
 * `DELETE /projects/{id}`). El otro (`GET /reports/team`) se resolvió reusando
 * `ventas_diseno.view_team`, ya sembrado — pero "quién puede borrar un proyecto" es un concepto
 * distinto (no es alcance de visibilidad, es una restricción de escritura más angosta que el
 * `crm.delete` genérico que niveles 4+ ya tienen), así que necesita su propio permiso.
 *
 * Asignado solo al nivel 9 ("Admin") — exclusivo del rol `management`, verificado antes de esta
 * migración (ver hallazgo de seguridad de la Fase 2: nunca escribir en el JSONB de un nivel
 * compartido sin confirmar antes qué otros roles lo usan).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'crm.projects.delete', 'label' => 'Borrar proyectos de CRM (exclusivo de Gerencia)',
            'module' => 'crm', 'sort_order' => 5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $level9 = DB::table($this->table('security_levels'))->where('level', 9)->first();
        /** @var list<string> $current */
        $current = json_decode($level9->permissions, true) ?? [];
        $merged = array_values(array_unique(array_merge($current, ['crm.projects.delete'])));
        DB::table($this->table('security_levels'))->where('id', $level9->id)->update(['permissions' => json_encode($merged)]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->where('key', 'crm.projects.delete')->delete();
    }
};
