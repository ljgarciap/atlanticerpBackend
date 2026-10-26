<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Exceptions\WarehouseSoloConsultaException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\StockReservationService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 (Senior Review ALTO-1) — entre el deploy de B1-B3 y la ejecución de `lafayette:aislar-catalogo` (o para siempre, en un entorno donde
 * no se corra) siguen existiendo las ~25K filas residuales de stock de Zona Libre. Esas filas NO pueden asignarse a pedidos, ni desglosarse, ni
 * contarse: se siembran aquí EXACTAMENTE como están en dev/test/prod (un producto con stock real en Bodega Central y una fila residual en Zona Libre).
 */
final class ZonaLibreFilasResidualesTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
    }

    private function zl(): int
    {
        return (int) Warehouse::query()->where('name', 'Bodega Zona Libre')->value('id');
    }

    /** Reproduce el estado histórico: se abre la bodega un instante, se inserta la fila residual y se vuelve a cerrar. */
    private function sembrarResidual(CatalogProduct $product, int $qty): void
    {
        $wh = (new Warehouse())->getTable();
        $zl = $this->zl();
        DB::table($wh)->where('id', $zl)->update(['solo_consulta' => false]);
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $zl, 'quantity' => $qty]);
        DB::table($wh)->where('id', $zl)->update(['solo_consulta' => true]);
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
    }

    public function test_la_reserva_de_pedidos_no_se_asigna_a_una_fila_residual_de_zona_libre(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 5);              // 5 reales en Bodega Central
        $this->sembrarResidual($product, 100);     // 100 residuales de Lafayette en Zona Libre

        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $product->id, 'qty_requested' => 10]);

        app(StockReservationService::class)->reallocateForProducts([$product->id]);

        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(5, (int) $item->qty_reserved, 'solo se puede reservar lo que hay en bodegas operativas (5), no las 100 de Zona Libre');
        $this->assertNotSame($this->zl(), (int) $item->warehouse_id, 'la reserva jamás se asigna a Zona Libre');
        $this->assertSame((int) Warehouse::query()->where('name', 'Bodega Central')->value('id'), (int) $item->warehouse_id);
    }

    public function test_con_stock_solo_en_zona_libre_no_hay_nada_que_reservar(): void
    {
        $product = $this->makeCatalogProduct();
        $this->sembrarResidual($product, 100);

        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $product->id, 'qty_requested' => 10]);

        app(StockReservationService::class)->reallocateForProducts([$product->id]);

        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(0, (int) $item->qty_reserved);
        $this->assertNull($item->warehouse_id);
    }

    public function test_el_desglose_de_compras_no_incluye_la_fila_residual(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 7);
        $this->sembrarResidual($product, 100);

        $map = app(InventoryKpiService::class)->warehousesMap([$product->id]);

        $this->assertSame(['Bodega Central'], array_column($map[$product->id], 'warehouse_name'));
        $this->assertSame(7, $map[$product->id][0]['quantity']);
    }

    public function test_el_endpoint_de_compras_warehouse_stock_no_incluye_la_fila_residual(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 7);
        $this->sembrarResidual($product, 100);
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $data = $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/inventory/{$product->id}/warehouse-stock")->assertOk()->json('data');

        $this->assertSame(['Bodega Central'], array_column($data, 'warehouse_name'));
    }

    public function test_el_desglose_y_el_conteo_de_bodegas_de_ver_inventario_no_incluyen_la_fila_residual(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 7);
        $this->sembrarResidual($product, 100);
        $this->grantBodegaPermissions('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $breakdown = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/inventory/{$product->id}/warehouse-breakdown")->assertOk()->json('data');
        $this->assertSame(['Bodega Central'], array_column($breakdown, 'name'));

        $show = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/inventory/{$product->id}")->assertOk()->json();
        $this->assertSame(1, $show['warehouse_count'], 'cuenta 1 bodega (Central), no 2');
    }

    public function test_el_controlador_de_ubicaciones_de_admin_no_lista_zona_libre(): void
    {
        $json = app(\App\Modules\AdminCont\Http\Controllers\AdminContLocationController::class)->index()->getData(true);

        $nombres = collect($json['data'] ?? $json)->pluck('nombre');
        $this->assertFalse($nombres->contains('Bodega Zona Libre'));
        $this->assertTrue($nombres->contains('Bodega Central'));
    }

    public function test_el_guard_cachea_el_conjunto_pero_se_invalida_al_cambiar_una_bodega(): void
    {
        $product = $this->makeCatalogProduct();
        $central = (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');

        // Primer guardado: llena la caché. Los siguientes no consultan `warehouses` (se cuenta con DB::listen).
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central, 'quantity' => 1]);
        $consultas = 0;
        DB::listen(function ($q) use (&$consultas): void {
            if (str_contains($q->sql, 'from "atlantic_bodega"."warehouses"') || str_contains($q->sql, 'atlantic_bodega.warehouses')) {
                $consultas++;
            }
        });
        for ($i = 1; $i <= 5; $i++) {
            ProductWarehouseStock::query()->where('catalog_product_id', $product->id)->where('warehouse_id', $central)->firstOrFail()->update(['quantity' => 1 + $i]);
        }
        $this->assertSame(0, $consultas, 'con la caché caliente, guardar stock ya no consulta las bodegas por cada fila');

        // Invalidación: marcar una bodega operativa como solo consulta (por el modelo) la hace efectiva de inmediato.
        $merma = Warehouse::query()->where('name', 'Merma')->firstOrFail();
        $merma->update(['solo_consulta' => true]);
        try {
            ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $merma->id, 'quantity' => 1]);
            $this->fail('el guard debía rechazar la bodega recién marcada como solo consulta');
        } catch (WarehouseSoloConsultaException) {
            $this->addToAssertionCount(1);
        }

        $merma->update(['solo_consulta' => false]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $merma->id, 'quantity' => 1]);
        $this->assertSame(1, ProductWarehouseStock::query()->where('warehouse_id', $merma->id)->count(), 'al reabrirla, vuelve a admitir stock');
    }
}
