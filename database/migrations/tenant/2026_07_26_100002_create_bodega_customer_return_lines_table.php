<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-487/488 (REQ-417/418, Bloque B6 "Devoluciones") — una línea de producto dentro de una
 * devolución. `order_item_id` ata cada línea a lo realmente entregado (REQ-417 RN2: tope =
 * `order_item.qty_delivered`). `reference_snapshot`/`description_snapshot` copiados de
 * `catalog_product` al crear, mismo criterio que `OrderItem`.
 *
 * `qty_received` queda NULL hasta la confirmación de recepción física (REQ-409 RN1: puede diferir
 * de `qty_requested`, el operativo cuenta lo que llega de verdad).
 *
 * `reason` — catálogo fijo (REQ-418): danado_defectuoso|no_corresponde|cliente_cambio_opinion|
 * excedente|error_instalacion|otra, con texto libre embebido "otra:<texto>" cuando aplica — mismo
 * patrón que `OrderItem::SHORTAGE_REASONS`/`setShortageReason()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::create($this->table('customer_return_lines'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')
                ->constrained($this->table('customer_returns'), 'id', 'customer_return_lines_return_fk')
                ->cascadeOnDelete();
            $table->foreignId('order_item_id')
                ->constrained($this->table('order_items'), 'id', 'customer_return_lines_order_item_fk');

            $table->string('reference_snapshot')->nullable();
            $table->string('description_snapshot')->nullable();

            $table->integer('qty_requested');
            $table->integer('qty_received')->nullable();

            $table->string('reason');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('customer_return_lines'));
    }
};
