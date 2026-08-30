<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-467→472 (REQ-397→402, entidad MOVIMIENTO_INVENTARIO) — el Kardex: registro histórico e
 * inmutable de todo movimiento de inventario. Se genera automáticamente desde otras entidades
 * (`KardexService::registrar()`, único punto de escritura) — nunca se crea manualmente, nunca
 * se edita ni se borra (REQ-401), por eso no tiene `updated_at`.
 *
 * `referencia_entidad_tipo`/`referencia_entidad_id` son polimórficos EN TEXTO (no
 * `morphs()` de Laravel) a propósito: apuntan a modelos de al menos 3 schemas distintos
 * (compras.purchase_orders, bodega.* futuro para Ajustes/Reubicación/Conteos, bodega.devoluciones
 * futuro) — una FK real cruzada a 3+ tablas no es viable, y el criterio de aceptación (REQ-402)
 * solo pide trazabilidad estructurada, no integridad referencial.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('inventory_movements'), function (Blueprint $table) use ($ventasDisenoSchema, $authSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'inventory_movements_product_fk');
            $table->foreignId('warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'inventory_movements_warehouse_fk');
            $table->string('tipo');
            $table->integer('cantidad');
            $table->string('referencia_texto');
            $table->string('referencia_entidad_tipo')->nullable();
            $table->unsignedBigInteger('referencia_entidad_id')->nullable();
            $table->foreignId('realizado_por')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'inventory_movements_user_fk')
                ->nullOnDelete();
            $table->integer('saldo_inicial');
            $table->integer('saldo_resultante');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['catalog_product_id', 'warehouse_id'], 'inventory_movements_product_warehouse_idx');
            $table->index('tipo', 'inventory_movements_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inventory_movements'));
    }
};
