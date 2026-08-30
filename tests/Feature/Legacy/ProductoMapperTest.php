<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Services\ProductoMapper;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-750 — `ProductoMapper`, resolución del shape de `catalog_products` desde staging ya
 * sembrado directamente en `legacy.*` (sin pasar por el Extractor real — eso es responsabilidad
 * de `ProductoStagingLoaderTest`).
 */
final class ProductoMapperTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_mapea_articulo_con_precio_publico_y_costo(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'marca_nombre' => '12',
            'ref_proveedor' => '42016B-WH', 'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1001, 'idtarifav' => 1, 'precio_neto' => 100.0, 'descatalogado_fila' => false,
        ]);
        // codproveedor 1001 = proveedor por defecto (ProductoMapper::DEFAULT_CODPROVEEDOR).
        ProductoCosto::query()->create(['icg_codarticulo' => 1001, 'codproveedor' => 1001, 'costo_neto' => 60.0]);

        $mapped = (new ProductoMapper())->buildPayloads([1001]);

        $this->assertCount(1, $mapped);
        $this->assertFalse($mapped[0]->isExcluded());
        // SCRUM-799 (2026-08-27) — reabre la decisión del 45fca4c: el código real de fabricante
        // pasa a `reference` (referencia pública), `factory_reference` queda vacío para
        // productos migrados desde ICG (sin dato propio para ese concepto).
        $this->assertSame('42016B-WH', $mapped[0]->reference);
        $this->assertNull($mapped[0]->factoryReference);
        $this->assertSame(100.0, $mapped[0]->priceFull);
        $this->assertSame(60.0, $mapped[0]->cost);
    }

    public function test_costo_de_proveedor_no_default_no_se_usa(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1006, 'descripcion' => 'Costo de otro proveedor', 'ref_proveedor' => 'XY-1',
            'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1006, 'idtarifav' => 1, 'precio_neto' => 70.0, 'descatalogado_fila' => false,
        ]);
        // Existe costo, pero no del proveedor por defecto (1001) — no se usa, cost queda null.
        ProductoCosto::query()->create(['icg_codarticulo' => 1006, 'codproveedor' => 55, 'costo_neto' => 40.0]);

        $mapped = (new ProductoMapper())->buildPayloads([1006]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertNull($mapped[0]->cost);
    }

    public function test_excluye_articulo_sin_precio_publico(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1002, 'descripcion' => 'Sin precio público', 'descatalogado' => false,
        ]);
        // Solo tarifa "proyecto" (2), sin tarifa 1 (público).
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1002, 'idtarifav' => 2, 'precio_neto' => 90.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1002]);

        $this->assertTrue($mapped[0]->isExcluded());
        $this->assertStringContainsString('sin precio público', (string) $mapped[0]->excludedReason);
        $this->assertNull($mapped[0]->priceFull);
    }

    public function test_articulo_sin_costo_se_mapea_igual_con_cost_null(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1003, 'descripcion' => 'Sin costo', 'ref_proveedor' => '999', 'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1003, 'idtarifav' => 1, 'precio_neto' => 50.0, 'descatalogado_fila' => false,
        ]);
        // Ningún ProductoCosto en absoluto para este artículo.

        $mapped = (new ProductoMapper())->buildPayloads([1003]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertSame(50.0, $mapped[0]->priceFull);
        $this->assertNull($mapped[0]->cost);
    }

    public function test_excluye_articulo_descatalogado_defensivamente(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1004, 'descripcion' => 'Descatalogado', 'descatalogado' => true,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1004, 'idtarifav' => 1, 'precio_neto' => 10.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1004]);

        $this->assertTrue($mapped[0]->isExcluded());
        $this->assertStringContainsString('descatalogado', (string) $mapped[0]->excludedReason);
    }

    public function test_excluye_articulo_no_encontrado_en_staging(): void
    {
        $mapped = (new ProductoMapper())->buildPayloads([9999]);

        $this->assertTrue($mapped[0]->isExcluded());
        $this->assertStringContainsString('no encontrado', (string) $mapped[0]->excludedReason);
    }

    public function test_factory_reference_alfanumerica_pasa_a_reference_publica_sin_afectar_costo(): void
    {
        // Corrección 2026-08-17 — `ref_proveedor` es alfanumérico en el ~92% de los casos reales
        // (referencia de fabricante, no un CODPROVEEDOR numérico). Ya no determina `cost`.
        // Corrección 2026-08-27 (SCRUM-799) — ese valor va a `reference`, no a `factoryReference`.
        Producto::query()->create([
            'icg_codarticulo' => 1005, 'descripcion' => 'Ref proveedor alfanumérica', 'ref_proveedor' => 'ABC-123',
            'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1005, 'idtarifav' => 1, 'precio_neto' => 30.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1005]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertSame('ABC-123', $mapped[0]->reference);
        $this->assertNull($mapped[0]->factoryReference);
        $this->assertNull($mapped[0]->cost);
    }

    public function test_articulo_sin_ref_proveedor_cae_a_referencia_sintetica_y_factory_reference_queda_null(): void
    {
        // SCRUM-799 — sin REFPROVEEDOR real, `reference` (NOT NULL/unique) cae al ID sintético
        // estable; `factory_reference` siempre queda null para productos migrados de ICG.
        Producto::query()->create([
            'icg_codarticulo' => 1007, 'descripcion' => 'Sin ref proveedor', 'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1007, 'idtarifav' => 1, 'precio_neto' => 20.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1007]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertSame('ICG-1007', $mapped[0]->reference);
        $this->assertNull($mapped[0]->factoryReference);
    }
}
