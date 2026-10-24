<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-921 — backfill de una sola corrida de `catalog_products.stock_quantity` desde
 * `legacy.productos.stock_al4`, para productos ya migrados ANTES de este ticket (cuando
 * `CatalogProductLoader` todavía no seteaba esta columna en ningún camino).
 */
final class LegacyBackfillStockAl4CommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_backfillea_catalog_product_ya_migrado_sin_stock_seteado(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, 'stock_al4' => 42.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertSame(42, $catalogProduct->stock_quantity);
    }

    public function test_preserva_stock_negativo(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, 'stock_al4' => -5.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertSame(-5, $catalogProduct->stock_quantity);
    }

    public function test_nunca_pisa_un_stock_que_bodega_ya_gestiona(): void
    {
        // Guard de seguridad central de este comando — si ya corrió antes (o Bodega ya movió el
        // número por su cuenta), stock_quantity dejó de ser NULL y una corrida repetida no debe
        // pisarlo con el snapshot viejo de ICG.
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => true,
            'stock_quantity' => 7,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, 'stock_al4' => 999.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertSame(7, $catalogProduct->stock_quantity);
    }

    public function test_nunca_toca_un_catalog_product_desactivado_localmente(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => false,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, 'stock_al4' => 42.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertNull($catalogProduct->stock_quantity);
    }

    public function test_omite_producto_sin_fila_en_al4(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, // stock_al4 queda NULL
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertNull($catalogProduct->stock_quantity);
    }

    public function test_omite_producto_no_vinculado_a_ningun_catalog_product(): void
    {
        // legacy.productos sin catalog_product_id — nunca llegó a cargarse en catalog_products.
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'stock_al4' => 42.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $this->assertSame(0, CatalogProduct::query()->whereNotNull('stock_quantity')->count());
    }

    public function test_dry_run_no_escribe(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Lámpara LED', 'price_full' => 100.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id, 'stock_al4' => 42.0,
        ]);

        $this->artisan('legacy:backfill-stock-al4', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $catalogProduct->refresh();
        $this->assertNull($catalogProduct->stock_quantity);
    }
}
