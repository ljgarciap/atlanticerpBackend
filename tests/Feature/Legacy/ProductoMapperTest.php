<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
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
        // codproveedor 1001 = proveedor por defecto (ProductoCostoResolver::DEFAULT_CODPROVEEDOR).
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

    public function test_mapea_stock_inicial_desde_stock_al4(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1010, 'descripcion' => 'Con stock AL4', 'descatalogado' => false,
            'stock_al4' => -3.0, // negativo, se preserva tal cual (decisión de Luis 2026-09-20)
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1010, 'idtarifav' => 1, 'precio_neto' => 80.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1010]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertSame(-3.0, $mapped[0]->stockInicial);
    }

    public function test_stock_inicial_null_si_articulo_sin_fila_en_al4(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1011, 'descripcion' => 'Sin stock AL4', 'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 1011, 'idtarifav' => 1, 'precio_neto' => 80.0, 'descatalogado_fila' => false,
        ]);

        $mapped = (new ProductoMapper())->buildPayloads([1011]);

        $this->assertNull($mapped[0]->stockInicial);
    }

    public function test_costo_de_unico_proveedor_no_default_se_usa(): void
    {
        // Caso A691-451471527K: su único costo estaba con MARSET (proveedor 70) y el mapper lo dejaba en null.
        $this->articulo(1006);
        ProductoCosto::query()->create(['icg_codarticulo' => 1006, 'codproveedor' => 70, 'costo_neto' => 480.06]);

        $mapped = (new ProductoMapper())->buildPayloads([1006]);

        $this->assertFalse($mapped[0]->isExcluded());
        $this->assertSame(480.06, $mapped[0]->cost);
    }

    public function test_proveedor_por_defecto_gana_sobre_otros(): void
    {
        $this->articulo(1007);
        ProductoCosto::query()->create(['icg_codarticulo' => 1007, 'codproveedor' => 70, 'costo_neto' => 480.06]);
        ProductoCosto::query()->create(['icg_codarticulo' => 1007, 'codproveedor' => 1001, 'costo_neto' => 400.0]);

        $this->assertSame(400.0, (new ProductoMapper())->buildPayloads([1007])[0]->cost);
    }

    public function test_varios_proveedores_usa_el_de_la_compra_mas_reciente(): void
    {
        $this->articulo(1008);
        ProductoCosto::query()->create(['icg_codarticulo' => 1008, 'codproveedor' => 70, 'costo_neto' => 480.0]);
        ProductoCosto::query()->create(['icg_codarticulo' => 1008, 'codproveedor' => 38, 'costo_neto' => 510.0]);
        $this->compra(1008, 70, '2026-01-10 00:00:00', 1);
        $this->compra(1008, 38, '2026-06-10 00:00:00', 2);
        // Una compra más reciente de OTRO proveedor sin costo registrado no cuenta.
        $this->compra(1008, 99, '2026-09-10 00:00:00', 3);

        $this->assertSame(510.0, (new ProductoMapper())->buildPayloads([1008])[0]->cost);
    }

    public function test_varios_proveedores_sin_compras_o_con_empate_deja_cost_null(): void
    {
        $this->articulo(1009);
        ProductoCosto::query()->create(['icg_codarticulo' => 1009, 'codproveedor' => 70, 'costo_neto' => 480.0]);
        ProductoCosto::query()->create(['icg_codarticulo' => 1009, 'codproveedor' => 38, 'costo_neto' => 510.0]);
        $this->assertNull((new ProductoMapper())->buildPayloads([1009])[0]->cost, 'sin compras no se adivina');

        $this->compra(1009, 70, '2026-06-10 00:00:00', 4);
        $this->compra(1009, 38, '2026-06-10 00:00:00', 5);
        $this->assertNull((new ProductoMapper())->buildPayloads([1009])[0]->cost, 'empate en la fecha más reciente no se adivina');
    }

    private function articulo(int $codigo): void
    {
        Producto::query()->create(['icg_codarticulo' => $codigo, 'descripcion' => "Art {$codigo}", 'ref_proveedor' => "R-{$codigo}", 'descatalogado' => false]);
        ProductoPrecio::query()->create(['icg_codarticulo' => $codigo, 'idtarifav' => 1, 'precio_neto' => 70.0, 'descatalogado_fila' => false]);
    }

    private function compra(int $codarticulo, int $codproveedor, string $fecha, int $n): void
    {
        $cab = AlbaranCompra::query()->create(['icg_numserie' => 'T', 'icg_numalbaran' => $n, 'icg_n' => 'B', 'icg_codproveedor' => $codproveedor, 'fecha' => $fecha]);
        AlbaranCompraItem::query()->create(['legacy_albaran_compra_id' => $cab->id, 'icg_codarticulo' => $codarticulo, 'precio' => 1.0]);
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
