<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Services\ProductoStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-750 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `catalog_product_id` (lo pone `CatalogProductLoader`,
 * este Loader nunca lo toca).
 */
final class ProductoStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_load_stagea_productos_precios_y_costos(): void
    {
        $loader = new ProductoStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(['productos' => 1, 'precios' => 2, 'costos' => 2], $counts);
        $this->assertDatabaseHas('atlantic_legacy.productos', ['icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED']);
        $this->assertSame(2, ProductoPrecio::query()->where('icg_codarticulo', 1001)->count());
        $this->assertSame(2, ProductoCosto::query()->where('icg_codarticulo', 1001)->count());
    }

    public function test_re_correr_el_mismo_extracto_no_duplica(): void
    {
        $loader = new ProductoStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Producto::query()->where('icg_codarticulo', 1001)->count());
        $this->assertSame(2, ProductoPrecio::query()->where('icg_codarticulo', 1001)->count());
        $this->assertSame(2, ProductoCosto::query()->where('icg_codarticulo', 1001)->count());
    }

    public function test_re_correr_actualiza_valores_cambiados(): void
    {
        $loader = new ProductoStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED (renombrada)', '3/7', '12', '55', false)],
            precios: [],
            costos: [],
        );
        $loader->load($updated);

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $this->assertSame('Lámpara LED (renombrada)', $producto->descripcion);
    }

    public function test_re_correr_nunca_resetea_catalog_product_id_ya_seteado(): void
    {
        $loader = new ProductoStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que Loader-B (CatalogProductLoader) ya linkeó este artículo en una corrida
        // anterior — crea un catalog_product real para no violar la FK.
        $catalogProduct = \App\Modules\VentasDiseno\Models\CatalogProduct::query()->create([
            'reference'   => 'ICG-1001',
            'name' => 'Lámpara LED',
            'price_full'  => 100.0,
            'is_active'   => true,
        ]);

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $producto->catalog_product_id = $catalogProduct->id;
        $producto->save();

        $loader->load(self::sampleExtraction());

        $producto->refresh();
        $this->assertSame($catalogProduct->id, $producto->catalog_product_id); // nunca lo tocó el staging loader
    }

    /**
     * Regresión — hallazgo real de la Tarea 6 (2026-08-12, primera corrida contra ICG real vía el
     * gateway): un solo `upsert()` con todas las filas de una corrida real (miles de artículos
     * activos) reventaba con `SQLSTATE[HY000]: General error: 7 number of parameters must be
     * between 0 and 65535` — Postgres tiene ese límite duro de parámetros bindeados por statement.
     * 600 filas (8 columnas c/u = 4800 params) ya superan el tamaño de un solo chunk
     * (`ProductoStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real de Postgres — este
     * test protege que el chunking no pierda ni duplique filas en el borde entre chunks.
     */
    public function test_load_con_mas_filas_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $productos = [];
        $precios   = [];
        for ($i = 1; $i <= 600; $i++) {
            $productos[] = new ProductoRow($i, "Producto {$i}", null, null, null, false);
            $precios[]   = new ProductoPrecioRow($i, 1, (float) $i, null, null, false);
        }

        $loader = new ProductoStagingLoader();
        $counts = $loader->load(new ExtractedProductos($productos, $precios, []));

        $this->assertSame(['productos' => 600, 'precios' => 600, 'costos' => 0], $counts);
        $this->assertSame(600, Producto::query()->count());
        $this->assertSame(600, ProductoPrecio::query()->count());
        $this->assertDatabaseHas('atlantic_legacy.productos', ['icg_codarticulo' => 1, 'descripcion' => 'Producto 1']);
        $this->assertDatabaseHas('atlantic_legacy.productos', ['icg_codarticulo' => 600, 'descripcion' => 'Producto 600']);
    }

    private static function sampleExtraction(): ExtractedProductos
    {
        return new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false)],
            precios: [
                new ProductoPrecioRow(1001, 1, 100.0, 119.0, 0.0, false),
                new ProductoPrecioRow(1001, 2, 90.0, 107.0, 0.0, false),
            ],
            costos: [
                new ProductoCostoRow(1001, 55, 60.0),
                new ProductoCostoRow(1001, 77, 62.0),
            ],
        );
    }
}
