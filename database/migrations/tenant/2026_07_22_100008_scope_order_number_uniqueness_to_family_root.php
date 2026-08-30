<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A2 — corrige una inconsistencia real entre la migración de Batch A1
 * (`..._100002_create_bodega_orders_table`) y HU-335 (Batch A2, confirmado contra Jira): esa
 * migración declaró `order_number` con un UNIQUE global (`orders_order_number_uq`), pero HU-335
 * exige explícitamente que las tarjetas de una misma familia (REQ-319 — picking parcial, revisión
 * mixta, entrega parcial en Packing) COMPARTAN el mismo `order_number` ("todas heredan el mismo
 * número de pedido de la tarjeta original, para mantener la trazabilidad"; "el buscador... debe
 * poder encontrar tarjetas por su número de pedido" — plural, varias tarjetas, un solo número).
 * El primer intento de crear una segunda tarjeta de la misma familia con
 * `PickingService::createInventoryReviewCard()`/`InventoryReviewService::
 * splitFoundItemsIntoNewOrder()`/`DeliveryService` habría reventado contra este constraint.
 *
 * Fix: mismo patrón ya usado para `orders_pipeline_card_root_uq` en la migración original —
 * unicidad acotada a `family_id IS NULL` (una sola tarjeta RAÍZ puede "estrenar" un número de
 * pedido; sus hijas de familia lo heredan sin competir por la unicidad). Se agrega además un
 * índice NO único sobre toda la tabla para que el buscador (REQ-309/HU-335) siga siendo rápido
 * también sobre las tarjetas hijas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $table = $this->table('orders');

        Schema::table($table, function (Blueprint $table): void {
            $table->dropUnique('orders_order_number_uq');
        });

        DB::statement("CREATE UNIQUE INDEX orders_order_number_root_uq ON {$table} (order_number) WHERE family_id IS NULL");
        DB::statement("CREATE INDEX orders_order_number_idx ON {$table} (order_number)");
    }

    public function down(): void
    {
        $table = $this->table('orders');

        DB::statement('DROP INDEX IF EXISTS orders_order_number_root_uq');
        DB::statement('DROP INDEX IF EXISTS orders_order_number_idx');

        Schema::table($table, function (Blueprint $table): void {
            $table->unique('order_number', 'orders_order_number_uq');
        });
    }
};
