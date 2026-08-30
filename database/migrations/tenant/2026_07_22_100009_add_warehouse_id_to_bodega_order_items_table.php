<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A2 (decisión de Luis 2026-07-22, sobre la nota de diseño pendiente
 * de `DeliveryService::dispatch()`) — FK real a qué bodega física salió cada línea de un pedido,
 * para que `dispatch()` pueda llamar a `KardexService::registrar()` con un `warehouseId` real en
 * vez de tener que adivinarlo. Mismo patrón que `product_warehouse_stock.warehouse_id`
 * (2026_07_20_100014): FK simple dentro del mismo schema `bodega`, sin cruzar schemas.
 *
 * NULLABLE a propósito: los `order_items` ya existentes se crean en
 * `OrderService::createFromApprovedQuote()` (Batch A1), antes de que exista ninguna noción de
 * bodega física — recién se completa cuando el picking de esa línea se COMPLETA (ver
 * `PickingService::completePicking()`), resuelto automáticamente contra `product_warehouse_stock`
 * (no hay un selector de bodega en la Hoja de Picking en los tickets REQ-315/316 — ver el reporte
 * del batch para el criterio exacto de resolución).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('catalog_product_id')
                ->constrained($this->table('warehouses'), 'id', 'order_items_warehouse_fk')
                ->nullOnDelete();

            $table->index('warehouse_id', 'order_items_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            // Nombres explícitos (no el default de Laravel) — mismo constraint que se creó en up().
            $table->dropForeign('order_items_warehouse_fk');
            $table->dropIndex('order_items_warehouse_idx');
            $table->dropColumn('warehouse_id');
        });
    }
};
