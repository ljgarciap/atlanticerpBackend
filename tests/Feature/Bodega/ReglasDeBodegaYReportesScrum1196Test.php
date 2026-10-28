<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1196 — panel de superadmin «Reglas de bodega», «Ver bodegas» con saldos negativos, stock de exhibición aparte del disponible y los dos comandos de
 * datos (reporte de solo lectura y reasignación de pedidos en vuelo).
 */
class ReglasDeBodegaYReportesScrum1196Test extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        $headers = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
        $this->makeCurrentTenant();

        return $headers;
    }

    private function id(string $name): int
    {
        return (int) Warehouse::where('name', $name)->value('id');
    }

    // ── Panel de reglas ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_superadmin_lista_las_reglas_de_las_bodegas_operativas(): void
    {
        $rows = collect($this->withHeaders($this->superadmin())->getJson('/api/bodega/settings/warehouses')->assertStatus(200)->json('data'))->keyBy('name');

        $this->assertTrue($rows['Bodega Central']['permite_venta']);
        $this->assertTrue($rows['Bodega Central']['despacho_por_defecto']);
        $this->assertTrue($rows['Sala de exhibición 1']['permite_venta']);
        $this->assertFalse($rows['Sala de exhibición 1']['despacho_por_defecto']);
        $this->assertFalse($rows['Reclamos y Devoluciones']['permite_venta']);
        $this->assertFalse($rows->has('Bodega Zona Libre'), 'las de solo consulta no se administran aquí');
    }

    public function test_sin_el_permiso_de_configurar_no_se_pueden_editar_las_reglas(): void
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $this->makeCurrentTenant();
        $id = $this->id('Merma');

        $this->withHeaders($this->authHeaders($token))->putJson("/api/bodega/settings/warehouses/{$id}", ['permite_venta' => true])->assertStatus(403);
        $this->makeCurrentTenant();
        $this->assertFalse(Warehouse::find($id)->permite_venta);
    }

    public function test_superadmin_puede_habilitar_una_bodega_para_ventas_y_quitar_el_disponible(): void
    {
        $headers = $this->superadmin();
        $id = $this->id('Sala de exhibición 2');

        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$id}", ['permite_venta' => true])
            ->assertStatus(200)->assertJsonPath('permite_venta', true);

        $this->makeCurrentTenant();
        $this->assertContains('Sala de exhibición 2', Warehouse::paraVenta()->pluck('name')->all(), 'ahora la hoja de picking la ofrecería');
    }

    public function test_cambiar_la_bodega_por_defecto_deja_exactamente_una(): void
    {
        $headers = $this->superadmin();
        $exhibicion1 = $this->id('Sala de exhibición 1');

        // la bodega por defecto no puede estar excluida del Disponible: primero se le quita esa exclusión
        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$exhibicion1}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$exhibicion1}", ['despacho_por_defecto' => true])
            ->assertStatus(200)->assertJsonPath('despacho_por_defecto', true);

        $this->makeCurrentTenant();
        $this->assertSame(['Sala de exhibición 1'], Warehouse::where('despacho_por_defecto', true)->pluck('name')->all());
    }

    public function test_no_se_puede_dejar_el_sistema_sin_bodega_por_defecto_ni_una_por_defecto_que_no_venda(): void
    {
        $headers = $this->superadmin();
        $central = $this->id('Bodega Central');
        $merma   = $this->id('Merma');

        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$central}", ['despacho_por_defecto' => false])->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$central}", ['permite_venta' => false])->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/bodega/settings/warehouses/{$merma}", ['despacho_por_defecto' => true])->assertStatus(422);
        $this->withHeaders($headers)->putJson('/api/bodega/settings/warehouses/99999999', ['permite_venta' => true])->assertStatus(404);

        $this->makeCurrentTenant();
        $this->assertSame(['Bodega Central'], Warehouse::where('despacho_por_defecto', true)->pluck('name')->all());
        $this->assertTrue(Warehouse::find($central)->permite_venta);
    }

    // ── «Ver bodegas» y stock de exhibición ───────────────────────────────────────────────────────────────────────────

    public function test_ver_bodegas_muestra_tambien_las_negativas_y_su_suma_coincide_con_el_stock_total(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 3]); // 10 Central − 6 Reclamos... = suma de bodegas
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 1, 'Sala de exhibición 1');
        $this->setStock($product, -8, 'Reclamos y Devoluciones');
        $this->setStock($product, 0, 'Merma');

        $rows = collect($this->withHeaders($this->superadmin())->getJson("/api/compras/inventory/{$product->id}/warehouse-stock")->assertStatus(200)->json('data'));

        $this->assertSame(['Bodega Central', 'Reclamos y Devoluciones', 'Sala de exhibición 1'], $rows->pluck('warehouse_name')->sort()->values()->all(), 'sin las de saldo 0, con la negativa');
        $this->assertSame(-8, $rows->firstWhere('warehouse_name', 'Reclamos y Devoluciones')['quantity']);
        $this->assertSame($product->stock_quantity, $rows->sum('quantity'), 'la suma de las bodegas coincide con el Stock total');
    }

    public function test_el_inventario_y_el_catalogo_exponen_el_stock_de_exhibición_aparte_sin_sumarlo_al_disponible(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 13]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 3, 'Sala de exhibición 1');
        $headers = $this->superadmin();

        $inv = $this->withHeaders($headers)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $this->assertSame(3, $inv->json('stock_aparte'));
        $this->assertEquals(10, $inv->json('disponible'), 'el disponible cuenta solo Central');

        $cat = $this->withHeaders($headers)->getJson("/api/ventas-diseno/catalog-products/{$product->id}");
        if ($cat->status() === 200) {
            $this->assertSame(3, $cat->json('stock_aparte'));
            $this->assertSame(10, $cat->json('stock_quantity'));
        }
    }

    // ── Comandos ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_reporte_lista_despachos_fuera_de_central_y_bodegas_en_negativo_sin_modificar_nada(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 5]);
        $this->setStock($product, 20, 'Bodega Central');
        $this->setStock($product, -15, 'Reclamos y Devoluciones');
        $order = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 64]);
        InventoryMovement::create([
            'catalog_product_id' => $product->id, 'warehouse_id' => $this->id('Reclamos y Devoluciones'), 'tipo' => InventoryMovement::TIPO_SALIDA,
            'cantidad' => -64, 'referencia_texto' => 'Pedido despachado', 'referencia_entidad_tipo' => 'PEDIDO', 'referencia_entidad_id' => $order->id,
            'saldo_inicial' => 28, 'saldo_resultante' => -36, 'created_at' => now(),
        ]);
        $before = ProductWarehouseStock::count().'|'.InventoryMovement::count().'|'.ProductWarehouseStock::sum('quantity');

        $this->assertSame(0, Artisan::call('bodega:reporte-despachos-fuera-de-central', ['--desde' => '2020-01-01']));
        $out = Artisan::output();

        $this->assertStringContainsString($order->order_number, $out);
        $this->assertStringContainsString('Reclamos y Devoluciones', $out);
        $this->assertStringContainsString('-64', $out);
        $this->assertStringContainsString($product->reference, $out, 'el producto con saldo negativo aparece');
        $this->assertSame($before, ProductWarehouseStock::count().'|'.InventoryMovement::count().'|'.ProductWarehouseStock::sum('quantity'), 'solo lectura');
    }

    public function test_reasignar_en_vuelo_simula_por_defecto_y_con_force_pasa_a_central_solo_lo_no_alistado(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 20, 'Reclamos y Devoluciones');
        $reclamos = $this->id('Reclamos y Devoluciones');

        // pedido en picking que ya había reservado Reclamos (comportamiento anterior), sin alistar nada
        $sinAlistar = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $product->id, 'qty_requested' => 5, 'warehouse_id' => $reclamos, 'qty_reserved' => 5]);
        // pedido ya en Packing con lo alistado en Reclamos: NO se toca
        $yaAlistado = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['catalog_product_id' => $product->id, 'qty_requested' => 4, 'warehouse_id' => $reclamos, 'qty_reserved' => 4, 'qty_picked' => 4]);

        $this->assertSame(0, Artisan::call('bodega:reasignar-pedidos-en-vuelo'));
        $this->assertStringContainsString('Simulación', Artisan::output());
        $this->assertSame($reclamos, $sinAlistar->items->first()->fresh()->warehouse_id, 'sin --force no cambia nada');

        $this->assertSame(0, Artisan::call('bodega:reasignar-pedidos-en-vuelo', ['--force' => true]));

        $this->assertSame($this->id('Bodega Central'), $sinAlistar->items->first()->fresh()->warehouse_id, 'el no alistado pasa a Central');
        $this->assertSame(5, $sinAlistar->items->first()->fresh()->qty_reserved);
        $this->assertSame($reclamos, $yaAlistado->items->first()->fresh()->warehouse_id, 'el ya alistado no se toca');
        $this->assertSame(4, $yaAlistado->items->first()->fresh()->qty_picked);
    }
}
