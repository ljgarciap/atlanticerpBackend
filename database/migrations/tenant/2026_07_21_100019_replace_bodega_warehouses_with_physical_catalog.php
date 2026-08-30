<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-451 (REQ-381) — reemplaza el catálogo de 5 bodegas de SCRUM-244/REQ-181 (que resultó ser
 * un concepto distinto y ya obsoleto, ver hallazgo de Pre-QA 2026-07-21) por las 7 bodegas físicas
 * reales del negocio. Decisión de Luis: única fuente de stock por ubicación para Compras y
 * Bodega — se reemplaza la fila, no se mapea 1 a 1 (no hay datos de cliente reales todavía en
 * ningún ambiente, solo demo). `product_warehouse_stock` cae en cascada (cascadeOnDelete ya
 * definido en 2026_07_20_100014) al borrar las filas viejas.
 *
 * `responsable` (hallazgo de Senior Review 2026-07-21): el Modelo de Datos lo define como
 * "UUID (FK, usuario) o Texto | No" — Analista todavía no decidió cuál. Se deja NULL para las 7
 * filas en vez de sembrar un nombre inventado (el mockup usa "Jorge P.", que no es ningún usuario
 * real de `CoreUserSeeder`) — asignar responsable real es tarea aparte, pendiente de esa
 * decisión de tipo de columna.
 *
 * FIX 2026-07-21 (falló el deploy a dev.atlanticerp.ai): `purchase_order_receipt_lines.warehouse_id`
 * (2026_07_21_100014) también referencia `warehouses` — sin cascade y sin nullable, a diferencia
 * de `product_warehouse_stock`. En dev.atlanticerp.ai ya había líneas de ingreso de mercancía reales
 * (demo Sprint 2) apuntando a las 5 bodegas viejas, y el DELETE directo rompía esa FK (el
 * ambiente de test local parte de un schema vacío en cada corrida, por eso nunca se vio ahí).
 * Se insertan las 7 nuevas ANTES de borrar, se reapuntan esas líneas históricas a "Bodega
 * Central" (son datos de demo, sin significado de negocio real que preservar), y recién ahí se
 * borran las 5 viejas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    private const NAMES = [
        ['name' => 'Bodega Central', 'capacidad_pct' => 68, 'modo_detalle' => 'ubicacion_exacta'],
        ['name' => 'Bodega Zona Libre', 'capacidad_pct' => null, 'modo_detalle' => 'pendiente'],
        ['name' => 'Showroom Obarrio', 'capacidad_pct' => 40, 'modo_detalle' => 'ubicacion_exacta'],
        ['name' => 'Showroom SM', 'capacidad_pct' => 25, 'modo_detalle' => 'ubicacion_exacta'],
        ['name' => 'Showroom Cliente', 'capacidad_pct' => null, 'modo_detalle' => 'cliente'],
        ['name' => 'Merma', 'capacidad_pct' => 12, 'modo_detalle' => 'ubicacion_exacta'],
        ['name' => 'Reclamos y Devoluciones', 'capacidad_pct' => 18, 'modo_detalle' => 'ubicacion_exacta'],
    ];

    public function up(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->string('responsable')->nullable()->after('name');
            $table->decimal('capacidad_pct', 5, 2)->nullable()->after('responsable');
            $table->string('modo_detalle')->default('ubicacion_exacta')->after('capacidad_pct');
        });

        $oldIds = DB::table($this->table('warehouses'))->pluck('id');

        $now = now();
        DB::table($this->table('warehouses'))->insert(
            array_map(
                static fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now],
                self::NAMES
            )
        );

        if ($oldIds->isNotEmpty()) {
            $centralId = (int) DB::table($this->table('warehouses'))->where('name', 'Bodega Central')->value('id');
            $comprasSchema = Tenant::current()->slug.'_compras';
            DB::table("{$comprasSchema}.purchase_order_receipt_lines")
                ->whereIn('warehouse_id', $oldIds)
                ->update(['warehouse_id' => $centralId]);
            DB::table($this->table('warehouses'))->whereIn('id', $oldIds)->delete();
        }
    }

    public function down(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->dropColumn(['responsable', 'capacidad_pct', 'modo_detalle']);
        });
    }
};
