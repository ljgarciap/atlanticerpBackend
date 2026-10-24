<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1140 (Pre-QA, 2.ª pasada, hallazgo M-A) — dos permisos que `SpecialPermissionSeeder` concede a personas puntuales pero
 * nunca se registraron en `permission_catalog`. `extra_permissions.*` se valida contra el catálogo activo, así que guardar el
 * formulario de Seguridad > Usuarios > Editar de quien ya los tiene (Felix y Mark, gerencia2) devolvía 422 aunque no se tocara
 * nada, y nadie más podía recibirlos desde la pantalla. Misma familia de hueco que `receptor_fiscal` (ver
 * `2026_10_14_500100_add_receptor_permissions_to_catalog.php`).
 * - `ventas_diseno.edit.client_tax_data` (SCRUM-1002/1003): editar RUC, DV y tipo ante la DGI de un cliente, y marcarlo Consumidor Final.
 * - `bodega.read` (permiso de lectura de Bodega que ya exigen las rutas y la barra lateral).
 * Idempotente: no duplica una clave que ya esté.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEYS = [
        ['key' => 'ventas_diseno.edit.client_tax_data', 'module' => 'ventas_diseno', 'label' => 'Editar los datos fiscales de un cliente (RUC, DV y tipo ante la DGI)', 'sort_order' => 105],
        ['key' => 'bodega.read',                        'module' => 'bodega',        'label' => 'Bodega — Ver (solo lectura)',                                       'sort_order' => 106],
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
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column(self::KEYS, 'key'))
            ->delete();
    }
};
