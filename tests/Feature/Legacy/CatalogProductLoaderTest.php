<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\CatalogProductLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-750 — "Loader-B" (destino). Criterio conservador: nunca pisa un `catalog_products` que ya
 * existía por otra vía que no sea este pipeline.
 */
final class CatalogProductLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_crea_catalog_product_nuevo_y_linkea_legacy_productos(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);

        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0),
        ]);

        $this->assertSame(1, $result['loaded']);
        $this->assertSame(0, $result['excluded']);
        $this->assertSame(0, $result['errors']);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(100.0, $catalogProduct->price_full);
        $this->assertSame('42016B-WH', $catalogProduct->factory_reference);

        $producto->refresh();
        $this->assertSame($catalogProduct->id, $producto->catalog_product_id);
    }

    public function test_re_correr_actualiza_el_mismo_catalog_product_sin_duplicar(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0)]);

        $result = $loader->load([new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED (v2)', '12', 110.0, 65.0)]);

        $this->assertSame(1, $result['loaded']);
        $this->assertSame(1, CatalogProduct::query()->where('reference', 'ICG-1001')->count());

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(110.0, $catalogProduct->price_full);
        $this->assertSame('Lámpara LED (v2)', $catalogProduct->name);
        $this->assertSame('42016B-WH', $catalogProduct->factory_reference);
    }

    public function test_re_correr_nunca_pisa_factory_reference_completado_a_mano(): void
    {
        // SCRUM-799 (2026-08-27) — desde el fix de ProductoMapper este pipeline solo manda
        // `factoryReference: null`; si el update siguiera pisando la columna, cualquier valor que
        // alguien complete a mano en Inventario después de la migración se perdería en la
        // siguiente corrida de legacy:sync-productos.
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([new MappedProducto(1001, '42016B-WH', null, 'Lámpara LED', '12', 100.0, 60.0)]);

        $catalogProduct = CatalogProduct::query()->where('reference', '42016B-WH')->firstOrFail();
        $catalogProduct->factory_reference = 'COMPLETADO-A-MANO';
        $catalogProduct->save();

        // Re-corrida real del pipeline: sigue mandando null (comportamiento post-fix).
        $loader->load([new MappedProducto(1001, '42016B-WH', null, 'Lámpara LED (v2)', '12', 110.0, 65.0)]);

        $catalogProduct->refresh();
        $this->assertSame('COMPLETADO-A-MANO', $catalogProduct->factory_reference);
        $this->assertSame('Lámpara LED (v2)', $catalogProduct->name);
    }

    public function test_excluye_sin_pisar_un_catalog_product_preexistente_no_creado_por_el_pipeline(): void
    {
        $existing = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Producto real, cargado a mano',
            'price_full' => 999.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            // catalog_product_id se queda NULL — este legacy.productos nunca fue linkeado a $existing.
        ]);

        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0),
        ]);

        $this->assertSame(0, $result['loaded']);
        $this->assertSame(1, $result['excluded']);

        $existing->refresh();
        $this->assertSame(999.0, $existing->price_full); // nunca se tocó
        $this->assertSame('Producto real, cargado a mano', $existing->name);
    }

    public function test_items_ya_excluidos_por_el_mapper_se_cuentan_pero_no_se_procesan(): void
    {
        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', null, 'X', null, null, null, 'sin precio público (IDTARIFAV=1) en PRECIOSVENTA'),
        ]);

        $this->assertSame(0, $result['loaded']);
        $this->assertSame(1, $result['excluded']);
        $this->assertSame(0, CatalogProduct::query()->count());
    }
}
