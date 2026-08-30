<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-237/238 (hallazgo de Daniela Amaya 2026-08-09) — "Código de barras" es un campo editable
 * pedido por el mockup del modal Detalle del producto (sección "Información del producto"), sin
 * precedente en el modelo hasta ahora. Nullable (no todos los productos existentes lo tienen) y
 * único cuando se informa (no se valida contra un formato de barcode específico — el mockup no
 * exige un estándar EAN/UPC concreto, solo un campo de texto libre).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->string('barcode', 100)->nullable()->after('category');
            $table->unique('barcode', 'catalog_products_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropUnique('catalog_products_barcode_unique');
            $table->dropColumn('barcode');
        });
    }
};
