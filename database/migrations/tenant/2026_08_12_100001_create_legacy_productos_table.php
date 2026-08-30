<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-750 (épica SCRUM-749 — Migración ICG→AtlanticERP), Tareas 1-5. Staging del piloto de Catálogo
 * (ver Illumination/migracion-icg-diseno.md §4/§6.1) — `legacy.productos` es el equivalente
 * staged de ARTICULOS, con trazabilidad completa vía `icg_codarticulo` (llave natural de ICG,
 * UNIQUE — idempotencia del Loader-A) y `catalog_product_id`, seteado por el Mapper/Loader-B solo
 * cuando el producto se carga de verdad en `ventas_diseno.catalog_products` (nunca antes).
 *
 * Alcance de este primer batch: SOLO artículos activos (`ARTICULOS.DESCATALOGADO = 'F'`, ver
 * GOTCHA NDBOOLEANF en el docblock de `App\Modules\Legacy\Extractors\ProductoExtractor`) — el
 * histórico completo (73,010 filas) queda fuera de este piloto.
 *
 * `familia_nombre`/`marca_nombre` guardan el CÓDIGO crudo de ICG (`ARTICULOS.FAMILIA`+
 * `SUBFAMILIA`/`MARCA`), NO el nombre resuelto contra las tablas `FAMILIAS`/`MARCA` — decisión de
 * diseño documentada en el docblock de `ProductoExtractor::mapProductoRow()` (el join de
 * `FAMILIAS` tiene PK compuesta `NUMDPTO+NUMSECCION+NUMFAMILIA` sin confirmar contra
 * `ARTICULOS.FAMILIA`/`SUBFAMILIA` con data real — no se inventa un join no confirmado).
 *
 * Cross-schema FK hacia `ventas_diseno.catalog_products` — mismo patrón ya usado por
 * `servicios_ticket_products`/`compras_purchase_order_lines` (ver esas migraciones). Constraint
 * nombrado explícitamente — el nombre autogenerado con schema calificado trunca en silencio a 63
 * bytes (mismo gotcha ya documentado en `servicios_ticket_products`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('productos'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->unsignedInteger('icg_codarticulo')->unique('legacy_productos_codarticulo_uq');
            $table->string('descripcion');
            $table->string('familia_nombre')->nullable();
            $table->string('marca_nombre')->nullable();
            $table->string('ref_proveedor')->nullable();
            $table->boolean('descatalogado')->default(false);

            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'legacy_productos_catalog_product_fk')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('descatalogado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('productos'));
    }
};
