<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\ProductoReferenceSwapService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-799 — corrección de DATA para productos ya migrados con la asignación vieja de
 * `ProductoMapper` (`reference`/`factory_reference` invertidos, corregida por separado en el
 * mapper). Ver docblock de `ProductoReferenceSwapService` para el diseño completo.
 */
final class ProductoReferenceSwapServiceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function migratedProduct(int $codigo, string $reference, ?string $factoryReference): Producto
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference'         => $reference,
            'factory_reference' => $factoryReference,
            'name'              => "Producto {$codigo}",
            'price_full'        => 100.0,
            'is_active'         => true,
        ]);

        return Producto::query()->create([
            'icg_codarticulo'    => $codigo,
            'descripcion'        => "Producto {$codigo}",
            'descatalogado'      => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);
    }

    public function test_swapea_reference_y_factory_reference_cuando_no_hay_colision(): void
    {
        $this->migratedProduct(1001, 'ICG-1001', '42016B-WH');

        $result = (new ProductoReferenceSwapService())->swap();

        $this->assertSame(1, $result['candidatos']);
        $this->assertSame(1, $result['corregidos']);
        $this->assertSame(0, $result['excluidos']);
        $this->assertSame(0, $result['errores']);

        $catalogProduct = CatalogProduct::query()->where('reference', '42016B-WH')->firstOrFail();
        $this->assertNull($catalogProduct->factory_reference);
    }

    public function test_sin_cambios_cuando_factory_reference_ya_es_null(): void
    {
        // O nunca tuvo REFPROVEEDOR real, o ya se corrigió en una corrida anterior.
        $this->migratedProduct(1002, 'ICG-1002', null);

        $result = (new ProductoReferenceSwapService())->swap();

        $this->assertSame(1, $result['candidatos']);
        $this->assertSame(0, $result['corregidos']);
        $this->assertSame(1, $result['sin_cambios']);
        $this->assertSame(0, $result['excluidos']);
    }

    public function test_correrlo_dos_veces_es_idempotente(): void
    {
        $this->migratedProduct(1003, 'ICG-1003', '99-XYZ');
        $service = new ProductoReferenceSwapService();

        $first  = $service->swap();
        $second = $service->swap();

        $this->assertSame(1, $first['corregidos']);
        $this->assertSame(0, $second['corregidos']);
        $this->assertSame(1, $second['sin_cambios']);

        $catalogProduct = CatalogProduct::query()->where('reference', '99-XYZ')->firstOrFail();
        $this->assertNull($catalogProduct->factory_reference);
    }

    public function test_excluye_por_colision_sin_tocar_ninguno_de_los_dos(): void
    {
        // Dos proveedores distintos con el mismo código de fabricante — escenario documentado en
        // la migración (`docs/.../2026_07_20_100003_...` — factory_reference único solo por
        // provider_id, no global).
        $this->migratedProduct(1004, 'ICG-1004', 'DUP-CODE');
        $this->migratedProduct(1005, 'ICG-1005', 'DUP-CODE');

        $result = (new ProductoReferenceSwapService())->swap();

        $this->assertSame(2, $result['candidatos']);
        $this->assertSame(1, $result['corregidos']);
        $this->assertSame(1, $result['excluidos']);

        // El primero procesado (orden por id) se corrige; el segundo, al chocar contra el
        // `reference` que el primero ya tomó, se excluye sin tocar nada.
        $this->assertNotNull(CatalogProduct::query()->where('reference', 'DUP-CODE')->first());
        $stillPending = CatalogProduct::query()->where('reference', 'ICG-1005')->first();
        $this->assertNotNull($stillPending);
        $this->assertSame('DUP-CODE', $stillPending->factory_reference);
    }

    public function test_excluye_por_colision_con_producto_cargado_a_mano(): void
    {
        // Producto creado a mano en Compras/Inventario cuya `reference` ya coincide con el código
        // de fabricante de un producto migrado — nunca se pisa, aunque no venga de este pipeline.
        CatalogProduct::query()->create([
            'reference' => 'MANUAL-REF', 'name' => 'Cargado a mano', 'price_full' => 50.0, 'is_active' => true,
        ]);
        $this->migratedProduct(1006, 'ICG-1006', 'MANUAL-REF');

        $result = (new ProductoReferenceSwapService())->swap();

        $this->assertSame(0, $result['corregidos']);
        $this->assertSame(1, $result['excluidos']);

        $migrated = CatalogProduct::query()->where('reference', 'ICG-1006')->firstOrFail();
        $this->assertSame('MANUAL-REF', $migrated->factory_reference); // sin tocar
    }

    public function test_nunca_toca_un_producto_creado_a_mano_sin_link_al_pipeline(): void
    {
        // Sin legacy.productos.catalog_product_id → no es candidato, aunque tenga
        // factory_reference poblado por alguna otra vía.
        CatalogProduct::query()->create([
            'reference' => 'PUB-1', 'factory_reference' => 'FAB-1', 'name' => 'Manual', 'price_full' => 10.0, 'is_active' => true,
        ]);

        $result = (new ProductoReferenceSwapService())->swap();

        $this->assertSame(0, $result['candidatos']);
        $catalogProduct = CatalogProduct::query()->where('reference', 'PUB-1')->firstOrFail();
        $this->assertSame('FAB-1', $catalogProduct->factory_reference);
    }
}
