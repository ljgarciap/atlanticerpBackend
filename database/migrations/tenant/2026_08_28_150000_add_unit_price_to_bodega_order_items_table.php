<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-786 — `OrderItem` nunca snapshoteó el precio unitario desde `QuoteItem` al crear el
 * pedido, pese a que `OrderService::createItemsFromQuote()` tiene el `QuoteItem` de origen (con
 * `effectiveUnitPrice()`/descuento ya resueltos) disponible en ese mismo momento. Nullable porque
 * los pedidos ya existentes no lo tienen — ver `bodega:backfill-order-item-prices` para el
 * intento de backfill retroactivo (best-effort, no garantizado para todos).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->decimal('unit_price', 12, 2)->nullable()->after('description_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->dropColumn('unit_price');
        });
    }
};
