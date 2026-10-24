<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1140 — registra en el catálogo los dos permisos angostos de quien factura, para que un superadmin pueda asignarlos
 * desde la pantalla de usuarios (`extra_permissions.*` se valida contra `permission_catalog`: una clave que no esté ahí da 422).
 * - `admin_contab.edit.receptor_fiscal` (SCRUM-1117): corregir RUC/DV y actualizar la razón social desde la vista previa.
 *   Existía solo en `SpecialPermissionSeeder` (Mark y Felix), sin entrada en el catálogo: nadie más podía recibirlo desde la UI.
 * - `admin_contab.edit.receptor_consumidor_final` (SCRUM-1140): facturar a Consumidor Final por decisión de quien factura.
 * Idempotente: no duplica una clave que ya esté.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEYS = [
        ['key' => 'admin_contab.edit.receptor_fiscal',            'label' => 'Corregir el RUC y la razón social del cliente al facturar', 'sort_order' => 103],
        ['key' => 'admin_contab.edit.receptor_consumidor_final',  'label' => 'Facturar a Consumidor final',                              'sort_order' => 104],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $row) {
            if (DB::table($this->table('permission_catalog'))->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table($this->table('permission_catalog'))->insert($row + [
                'module' => 'admin_contab', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column(self::KEYS, 'key'))
            ->delete();
    }
};
