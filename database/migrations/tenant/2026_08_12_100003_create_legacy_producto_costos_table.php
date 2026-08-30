<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-750 (épica SCRUM-749). Staging de `PRECIOSCOMPRA` — 1 fila por artículo+proveedor, sin
 * filtrar por tarifa/proveedor (captura completa, ver Illumination/migracion-icg-diseno.md §6.1).
 * La selección del proveedor por defecto (`ARTICULOS.REFPROVEEDOR`) se aplica recién en
 * `App\Modules\Legacy\Services\ProductoMapper`, al resolver `catalog_products.cost` — nunca se
 * pierde el resto de proveedores en este staging.
 *
 * `icg_codarticulo` referencia la llave natural de `legacy.productos` — mismo criterio que
 * `legacy.producto_precios` (ver su docblock).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('producto_costos'), function (Blueprint $table): void {
            $table->id();

            $table->unsignedInteger('icg_codarticulo');
            $table->foreign('icg_codarticulo', 'legacy_producto_costos_articulo_fk')
                ->references('icg_codarticulo')->on($this->table('productos'))
                ->cascadeOnDelete();

            $table->unsignedInteger('codproveedor');
            $table->decimal('costo_neto', 12, 2);

            $table->timestamps();

            $table->unique(['icg_codarticulo', 'codproveedor'], 'legacy_producto_costos_articulo_proveedor_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('producto_costos'));
    }
};
