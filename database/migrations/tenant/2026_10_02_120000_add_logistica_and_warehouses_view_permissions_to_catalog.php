<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1013 (rebote) / SCRUM-1021 — dos permisos angostos de SOLO LECTURA para los vendedores:
 *
 *  - `compras.logistica.view`: únicamente los GET de "Órdenes - Logística y Envío" (lista, detalle
 *    y documentos de la orden). A diferencia de `compras.limited.view` (Operaciones), NO abre
 *    Inventario ni Ingresos: ese último desenmascara costo y margen en Inventario, y un vendedor
 *    nunca los ve (regla SCRUM-818).
 *  - `bodega.warehouses.view`: únicamente la pantalla Bodegas (lista, detalle y catálogo de Zona
 *    Libre), sin el resto del módulo Bodega que abre `bodega.read`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, module: string, label: string, sort_order: int}> */
    private const PERMISSIONS = [
        ['key' => 'compras.logistica.view', 'module' => 'compras', 'label' => 'Ver Órdenes - Logística y Envío (solo consulta)', 'sort_order' => 75],
        ['key' => 'bodega.warehouses.view', 'module' => 'bodega', 'label' => 'Ver Bodegas (solo consulta)', 'sort_order' => 76],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            if (DB::table($this->table('permission_catalog'))->where('key', $permission['key'])->exists()) {
                continue;
            }
            DB::table($this->table('permission_catalog'))->insert([
                ...$permission, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column(self::PERMISSIONS, 'key'))
            ->delete();
    }
};
