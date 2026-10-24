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
use App\Modules\Legacy\Services\ProductoIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — variante incremental (hash-compare) de ProductoStagingLoader. El criterio central a
 * probar es que una segunda corrida SIN cambios reales en origen no genera ningún write en
 * legacy.* (a diferencia del full-sync, que siempre re-escribe cada fila vía upsert incondicional).
 */
final class ProductoIncrementalStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_primera_corrida_cuenta_todo_como_nuevo(): void
    {
        $loader = new ProductoIncrementalStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['productos']['nuevos']);
        $this->assertSame(0, $counts['productos']['cambiados']);
        $this->assertSame(0, $counts['productos']['sin_cambios']);
        $this->assertSame(2, $counts['precios']['nuevos']);
        $this->assertSame(2, $counts['costos']['nuevos']);

        $this->assertDatabaseHas('atlantic_legacy.productos', ['icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED']);
    }

    public function test_re_correr_sin_cambios_reales_no_escribe_nada(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $updatedAtAntes = $producto->updated_at;

        // Mismo extracto exacto — nada cambió del lado de ICG.
        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(0, $counts['productos']['nuevos']);
        $this->assertSame(0, $counts['productos']['cambiados']);
        $this->assertSame(1, $counts['productos']['sin_cambios']);
        $this->assertSame(0, $counts['precios']['cambiados']);
        $this->assertSame(2, $counts['precios']['sin_cambios']);
        $this->assertSame(0, $counts['costos']['cambiados']);
        $this->assertSame(2, $counts['costos']['sin_cambios']);

        $producto->refresh();
        $this->assertTrue($updatedAtAntes->equalTo($producto->updated_at)); // Postgres nunca tocó la fila
    }

    public function test_re_correr_con_un_cambio_real_solo_actualiza_esa_fila(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $cambiado = new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED (renombrada)', '3/7', '12', '55', false)],
            precios: [],
            costos: [],
        );
        $counts = $loader->load($cambiado);

        $this->assertSame(0, $counts['productos']['nuevos']);
        $this->assertSame(1, $counts['productos']['cambiados']);
        $this->assertSame(0, $counts['productos']['sin_cambios']);

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $this->assertSame('Lámpara LED (renombrada)', $producto->descripcion);
    }

    public function test_un_cambio_de_solo_precio_se_detecta_aunque_el_producto_no_cambie(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $mismoProducto = new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false)], // idéntico
            precios: [new ProductoPrecioRow(1001, 1, 150.0, 179.0, 0.0, false)], // precio nuevo
            costos: [],
        );
        $counts = $loader->load($mismoProducto);

        $this->assertSame(0, $counts['productos']['cambiados']);
        $this->assertSame(1, $counts['productos']['sin_cambios']);
        $this->assertSame(1, $counts['precios']['cambiados']);

        $precio = ProductoPrecio::query()->where('icg_codarticulo', 1001)->where('idtarifav', 1)->firstOrFail();
        $this->assertSame(150.0, (float) $precio->precio_neto);
    }

    // ── SCRUM-921 — stock_al4 NUNCA participa del hash de cambio ───────────────────────────────

    public function test_primera_corrida_stagea_stock_al4(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', null, null, null, false, stockAl4: 42.0)],
            precios: [],
            costos: [],
        ));

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $this->assertSame(42.0, $producto->stock_al4);
    }

    public function test_un_cambio_de_solo_stock_al4_no_cuenta_como_cambiado(): void
    {
        // Crítico para SCRUM-921 — si stock_al4 participara del hash, el movimiento normal de un
        // almacén en vivo (AL4) marcaría "cambiado" a casi todo el catálogo activo en cada corrida,
        // disparando el circuit breaker del 20% (ver LegacySyncIncrementalProductosCommand) por un
        // motivo que nunca debería contar como cambio de negocio.
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false, stockAl4: 42.0)],
            precios: [],
            costos: [],
        ));

        $soloStockCambio = new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false, stockAl4: 10.0)],
            precios: [],
            costos: [],
        );
        $counts = $loader->load($soloStockCambio);

        $this->assertSame(0, $counts['productos']['cambiados']);
        $this->assertSame(1, $counts['productos']['sin_cambios']);
        $this->assertSame([], $counts['changed_codarticulos']);
    }

    public function test_stock_al4_se_refresca_gratis_cuando_hay_un_cambio_real_de_otro_campo(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false, stockAl4: 42.0)],
            precios: [],
            costos: [],
        ));

        // El nombre SÍ cambió de verdad — la fila se actualiza, y stock_al4 viaja con ella.
        $loader->load(new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED (renombrada)', '3/7', '12', '55', false, stockAl4: 10.0)],
            precios: [],
            costos: [],
        ));

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $this->assertSame(10.0, $producto->stock_al4);
    }

    public function test_re_correr_nunca_resetea_catalog_product_id_ya_seteado(): void
    {
        $loader = new ProductoIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $catalogProduct = \App\Modules\VentasDiseno\Models\CatalogProduct::query()->create([
            'reference'  => 'ICG-1001',
            'name'       => 'Lámpara LED',
            'price_full' => 100.0,
            'is_active'  => true,
        ]);
        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $producto->catalog_product_id = $catalogProduct->id;
        $producto->save();

        $loader->load(self::sampleExtraction());

        $producto->refresh();
        $this->assertSame($catalogProduct->id, $producto->catalog_product_id);
    }

    public function test_load_con_mas_filas_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $productos = [];
        for ($i = 1; $i <= 600; $i++) {
            $productos[] = new ProductoRow($i, "Producto {$i}", null, null, null, false);
        }

        $loader = new ProductoIncrementalStagingLoader();
        $counts = $loader->load(new ExtractedProductos($productos, [], []));

        $this->assertSame(600, $counts['productos']['nuevos']);
        $this->assertSame(600, Producto::query()->count());
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
