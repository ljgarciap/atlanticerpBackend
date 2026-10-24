<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-851 — flag reusable por bodega: cuenta en Inventario Total pero se excluye del cálculo de
 * "Disponible" (no se puede usar para ventas/cotizaciones nuevas).
 *
 * ⚠️ CORRECCIÓN DURANTE IMPLEMENTACIÓN (no en el diseño original aprobado por Luis) — el diseño
 * aprobado pedía crear una 8va bodega física nueva "Bodega de Servicios". Al implementar se
 * encontró que esa bodega YA EXISTE con otro nombre: "Reserva Servicios"
 * (`InsumoService::RESERVA_WAREHOUSE_NAME`, Fase 4 Servicios Grupo D parte 2, REQ-273→275/291,
 * SCRUM-343/344/345/361) — insumos reales de Servicios trackeados en
 * `bodega.product_warehouse_stock` en esa bodega exacta, y **hoy sin ningún mecanismo que la
 * excluya de "Disponible"** (confirmado por grep, cero referencias) — es decir, exactamente el bug
 * que describe SCRUM-851. Crear una 9na bodega nueva habría sido un duplicado real del mismo
 * concepto. Esta migración marca la bodega EXISTENTE en vez de crear una nueva — el Arquitecto
 * debe confirmar esta corrección con Luis antes de dar el ticket por cerrado, ya que cambia una
 * decisión de diseño ya aprobada (ver reporte de implementación).
 *
 * Aplica también, retroactivamente, a "Merma" y "Reclamos y Devoluciones" (mismo tipo de
 * inventario no vendible, decisión de Luis).
 *
 * Cálculo de "disponible" centralizado en InventoryKpiService::disponibleParaVenta().
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->boolean('excluye_disponible')->default(false)->after('modo_detalle');
        });

        DB::table($this->table('warehouses'))
            ->whereIn('name', ['Reserva Servicios', 'Merma', 'Reclamos y Devoluciones'])
            ->update(['excluye_disponible' => true]);
    }

    public function down(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->dropColumn('excluye_disponible');
        });
    }
};
