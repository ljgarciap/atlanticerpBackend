<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1126 — un producto puede estar asociado a varios proveedores. `catalog_products.provider_id`
 * se conserva como proveedor PRINCIPAL (unicidad de `factory_reference`, reportes y reposición
 * siguen leyéndolo); esta pivote guarda TODOS los proveedores del producto, principal incluido.
 * `provider_id` es FK cross-schema hacia `compras.providers`, mismo patrón que
 * `catalog_products_provider_fk`. Backfill: cada producto con proveedor principal queda con su fila.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $comprasSchema = Tenant::current()->slug.'_compras';

        Schema::create($this->table('catalog_product_providers'), function (Blueprint $table) use ($comprasSchema): void {
            $table->id();
            // Nombres explícitos: el autogenerado supera los 63 bytes de Postgres y se trunca en
            // silencio (memory/feedback_tenant_migration_constraint_name_truncation.md).
            $table->foreignId('catalog_product_id')
                ->constrained($this->table('catalog_products'), 'id', 'catalog_product_providers_product_fk')
                ->cascadeOnDelete();
            $table->foreignId('provider_id')
                ->constrained("{$comprasSchema}.providers", 'id', 'catalog_product_providers_provider_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['catalog_product_id', 'provider_id'], 'catalog_product_providers_unique');
            $table->index('provider_id', 'catalog_product_providers_provider_idx');
        });

        DB::statement(
            'INSERT INTO '.$this->table('catalog_product_providers').' (catalog_product_id, provider_id, created_at, updated_at) '
            .'SELECT id, provider_id, NOW(), NOW() FROM '.$this->table('catalog_products').' WHERE provider_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_product_providers'));
    }
};
