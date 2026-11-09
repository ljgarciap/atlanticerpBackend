<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 29b (grupo 5) — cantidad mínima y múltiplo de compra por proveedor y producto. Opcionales: NULL = sin
 * restricción, así que nada cambia al desplegar. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_product_providers'), function (Blueprint $table): void {
            $table->unsignedInteger('min_order_quantity')->nullable();
            $table->unsignedInteger('order_multiple')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_product_providers'), function (Blueprint $table): void {
            $table->dropColumn(['min_order_quantity', 'order_multiple']);
        });
    }
};
