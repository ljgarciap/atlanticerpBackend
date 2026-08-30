<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-750 (épica SCRUM-749). Staging de `PRECIOSVENTA` — 1 fila por artículo+tarifa, solo las 4
 * tarifas vigentes que mapean 1:1 a `price_type` de AtlanticERP (1 público / 2 proyecto / 3 especial /
 * 5 socio, ver `App\Modules\Legacy\Support\PriceTypeMap`). La tarifa 4 (D.BAHIA, vencida) NUNCA
 * se stagea — `ProductoExtractor::mapPrecioRow()` la excluye en PHP (defensa en profundidad,
 * no confía únicamente en `PRECIOSVENTA.DESCATALOGADO = 1` del lado de ICG).
 *
 * `icg_codarticulo` referencia la llave natural de `legacy.productos` (no su `id` interno) —
 * ambas tablas se puebla desde el mismo filtro de ARTICULOS.DESCATALOGADO='F', así que todo
 * `icg_codarticulo` que llega acá ya existe en `legacy.productos` (mismo INNER JOIN a ARTICULOS
 * en ambas queries del Extractor, ver docblock de la clase).
 *
 * `TALLA='.' AND COLOR='.' AND CODFORMATO=0` (confirmado 2026-08-06 con data real, 100% de la
 * muestra) garantiza 1 fila por artículo+tarifa — de ahí el UNIQUE compuesto sin necesitar esas 3
 * columnas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('producto_precios'), function (Blueprint $table): void {
            $table->id();

            $table->unsignedInteger('icg_codarticulo');
            $table->foreign('icg_codarticulo', 'legacy_producto_precios_articulo_fk')
                ->references('icg_codarticulo')->on($this->table('productos'))
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('idtarifav');
            $table->decimal('precio_neto', 12, 2);
            $table->decimal('precio_bruto', 12, 2)->nullable();
            $table->decimal('descuento', 5, 2)->nullable();
            $table->boolean('descatalogado_fila')->default(false);

            $table->timestamps();

            $table->unique(['icg_codarticulo', 'idtarifav'], 'legacy_producto_precios_articulo_tarifa_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('producto_precios'));
    }
};
