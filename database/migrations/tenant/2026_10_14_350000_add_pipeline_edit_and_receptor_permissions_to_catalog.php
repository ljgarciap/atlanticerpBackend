<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1139 — registra en el catálogo de permisos los grants angostos que ya se asignan por persona vía `SpecialPermissionSeeder`:
 * - `ventas_diseno.pipeline.edit`: editar el Pipeline (solo rutas de /pipeline y la vinculación mínima de clientes). Félix.
 * - `admin_contab.edit.receptor_fiscal`: corregir RUC/DV/razón social del receptor al facturar. Félix, la asistente y operaciones.
 * - `ventas_diseno.edit.client_tax_data`: editar los datos fiscales de un SubCliente. Mark y Félix.
 *
 * Sin la fila en el catálogo, `UpdateUserRequest`/`CreateUserRequest` (`Rule::in(PermissionCatalog::activeKeys())`) responden 422 al guardar
 * a esas personas desde la pantalla de usuarios, aunque el permiso ya esté en su `extra_permissions`.
 *
 * Idempotente: solo inserta lo que falta; `down()` borra únicamente lo que esta migración agregó.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, module: string, label: string, sort_order: int}> */
    private const KEYS = [
        ['key' => 'ventas_diseno.pipeline.edit',        'module' => 'ventas_diseno', 'label' => 'Editar el Pipeline (solo sus rutas)',                  'sort_order' => 77],
        ['key' => 'ventas_diseno.edit.client_tax_data', 'module' => 'ventas_diseno', 'label' => 'Editar datos fiscales de un SubCliente',              'sort_order' => 78],
        ['key' => 'admin_contab.edit.receptor_fiscal',  'module' => 'admin_contab',  'label' => 'Corregir el receptor fiscal (RUC/DV/razón social) al facturar', 'sort_order' => 79],
    ];

    public function up(): void
    {
        $table = $this->table('permission_catalog');
        foreach (self::KEYS as $row) {
            if (DB::table($table)->where('key', $row['key'])->exists()) {
                continue;
            }
            DB::table($table)->insert($row + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column(self::KEYS, 'key'))
            ->delete();
    }
};
