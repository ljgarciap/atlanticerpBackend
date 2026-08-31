<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-467→472 (REQ-397→402) — el Kardex. */
class KardexServiceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto de prueba', 'description' => 'Producto de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_registrar_crea_stock_desde_cero_cuando_no_habia_fila(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();

        $movement = (new KardexService)->registrar(
            $product->id, $warehouse->id, InventoryMovement::TIPO_ENTRADA, 10, 'Orden #1 recibida',
        );

        $this->assertSame(0, $movement->saldo_inicial);
        $this->assertSame(10, $movement->saldo_resultante);
        $this->assertSame(10, $movement->cantidad);
        $this->assertSame(
            10,
            ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'),
        );
    }

    /**
     * SCRUM-429 (rebote de Gerencia Test 2026-08-13) — caso real reproducido: producto con 11
     * unidades (10 en Showroom Obarrio + 1 en Bodega Central), ajuste de -1 en Showroom Obarrio.
     * Antes de este fix, `catalog_products.stock_quantity` (el agregado del que derivan
     * "Disponible"/"Stock Total" en todo el resto de Inventario) se quedaba en 11 aunque el
     * desglose por bodega sí bajara a 9+1 — acá se verifica que ambos quedan sincronizados.
     */
    public function test_registrar_sincroniza_el_agregado_stock_quantity_del_producto(): void
    {
        $showroom = Warehouse::where('name', 'Bodega Central')->first();
        $central = Warehouse::where('name', 'Merma')->first();
        $product = $this->makeProduct(['stock_quantity' => 11]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $showroom->id, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 1]);

        (new KardexService)->registrar(
            $product->id, $showroom->id, InventoryMovement::TIPO_CONTEO, -1, 'Conteo general aplicado',
        );

        $this->assertSame(
            9,
            ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $showroom->id)->value('quantity'),
        );
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_saldo_inicial_mas_cantidad_siempre_cuadra_con_saldo_resultante(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();
        $service = new KardexService;

        $service->registrar($product->id, $warehouse->id, InventoryMovement::TIPO_ENTRADA, 25, 'Ingreso inicial');
        $second = $service->registrar($product->id, $warehouse->id, InventoryMovement::TIPO_SALIDA, -8, 'Pedido #55 despachado');

        $this->assertSame(25, $second->saldo_inicial);
        $this->assertSame(-8, $second->cantidad);
        $this->assertSame(17, $second->saldo_resultante);
        $this->assertSame($second->saldo_inicial + $second->cantidad, $second->saldo_resultante);
    }

    public function test_cada_movimiento_es_independiente_y_encadena_el_saldo_del_anterior(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();
        $service = new KardexService;

        $m1 = $service->registrar($product->id, $warehouse->id, InventoryMovement::TIPO_ENTRADA, 20, 'A');
        $m2 = $service->registrar($product->id, $warehouse->id, InventoryMovement::TIPO_AJUSTE, 5, 'B');
        $m3 = $service->registrar($product->id, $warehouse->id, InventoryMovement::TIPO_SALIDA, -12, 'C');

        $this->assertSame([0, 20, 25], [$m1->saldo_inicial, $m2->saldo_inicial, $m3->saldo_inicial]);
        $this->assertSame([20, 25, 13], [$m1->saldo_resultante, $m2->saldo_resultante, $m3->saldo_resultante]);
    }

    public function test_referencia_entidad_polimorfica_se_guarda_para_trazabilidad(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();

        $movement = (new KardexService)->registrar(
            $product->id, $warehouse->id, InventoryMovement::TIPO_ENTRADA, 5, 'Orden #7 recibida',
            referenciaEntidadTipo: 'ORDEN_COMPRA', referenciaEntidadId: 7,
        );

        $this->assertSame('ORDEN_COMPRA', $movement->referencia_entidad_tipo);
        $this->assertSame(7, $movement->referencia_entidad_id);
    }

    public function test_realizado_por_null_representa_sistema(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();

        $movement = (new KardexService)->registrar(
            $product->id, $warehouse->id, InventoryMovement::TIPO_ENTRADA, 5, 'Automatico',
        );

        $this->assertNull($movement->realizado_por);
    }
}
