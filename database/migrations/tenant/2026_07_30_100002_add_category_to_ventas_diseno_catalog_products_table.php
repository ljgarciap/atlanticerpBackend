<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-194 (REQ-131, hallazgo de Gerencia Test 2026-07-30) — el mockup `2H__Compras_NuevaOrden.html`
 * pide "Categoría" (8 valores fijos, ver `CatalogProduct::CATEGORIES`) al crear un producto nuevo
 * desde Nueva Orden; no existía ningún campo de categoría en el Catálogo — distinto de
 * `catalog_product_families` (REQ-037, combos que se cotizan juntos, concepto no relacionado).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->string('category', 50)->nullable()->after('rotation');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
