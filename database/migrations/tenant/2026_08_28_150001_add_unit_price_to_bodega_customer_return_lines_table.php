<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-786 — snapshot de `OrderItem.unit_price` al momento de crear la línea de devolución
 * (mismo criterio que `reference_snapshot`/`description_snapshot` de esta misma tabla). Nullable
 * por el mismo motivo que `order_items.unit_price`: pedidos/devoluciones ya existentes no lo
 * tienen. Alimenta el monto sugerido de Nota de Crédito (REQ-491, `CreditNoteService::
 * devolucionDetail()`) — antes siempre arrancaba en $0.00.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('customer_return_lines'), function (Blueprint $table): void {
            $table->decimal('unit_price', 12, 2)->nullable()->after('description_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('customer_return_lines'), function (Blueprint $table): void {
            $table->dropColumn('unit_price');
        });
    }
};
