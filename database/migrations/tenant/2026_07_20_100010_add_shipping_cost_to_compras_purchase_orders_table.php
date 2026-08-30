<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-197 (hallazgo QA 2026-07-16): la RN de REQ-134 dice que un envío aéreo captura "costo y
 * quién lo paga" — solo existía el campo de quién paga, faltaba el monto del flete.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->decimal('shipping_cost', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn('shipping_cost');
        });
    }
};
