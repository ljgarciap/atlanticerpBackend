<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1166 — permiso angosto para marcar o quitar la exención de ITBMS de un cliente fiscal. Se concede a personas puntuales por
 * `SpecialPermissionSeeder` (Mark, Felix y Yaneth), no por rol. Va también al catálogo: `extra_permissions.*` se valida contra el catálogo activo,
 * así que sin esta fila guardar el formulario de Seguridad > Usuarios de quien lo tiene devolvería 422 (mismo hueco que halló Pre-QA en SCRUM-1140).
 * Idempotente: no duplica una clave que ya esté.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEYS = [
        ['key' => 'admin_contab.edit.client_itbms_exemption', 'module' => 'admin_contab', 'label' => 'Marcar a un cliente como exento de ITBMS', 'sort_order' => 107],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $row) {
            if (DB::table($this->table('permission_catalog'))->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table($this->table('permission_catalog'))->insert($row + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->whereIn('key', array_column(self::KEYS, 'key'))->delete();
    }
};
