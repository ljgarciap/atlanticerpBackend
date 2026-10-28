<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderItemAllocation;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1196 — los despachos salen de Bodega Central por defecto (reglas de Lider Bodega Liderbodega, Líder de Bodega, 22-10-2026). Un caso por criterio de
 * aceptación del ticket, recorriendo el flujo real (asignar picker → iniciar picking → completar picking → entrega → repartidor → despachar).
 */
class DespachoDesdeBodegaCentralScrum1196Test extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);
        $this->makeCurrentTenant(); // el login HTTP deja el tenant sin "current"

        return $headers;
    }

    private function warehouse(string $name): Warehouse
    {
        return Warehouse::where('name', $name)->firstOrFail();
    }

    private function stockOf(CatalogProduct $product, string $warehouse): int
    {
        return (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $this->warehouse($warehouse)->id)->value('quantity');
    }

    /** Pedido de `$qty` unidades del producto, ya en «En picking» (asignó picker e inició picking por la API, como en producción). */
    private function orderInPicking(CatalogProduct $product, int $qty, array $headers): array
    {
        $order = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => $qty]);
        $item  = $order->items->first();
        $pickerId = $this->userIdByEmail('auxiliarbodega@test.com');
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/start-picking")->assertStatus(200);
        $this->makeCurrentTenant();

        return [$order, $item];
    }

    private function dispatch(Order $order, OrderItem $item, int $delivered, array $headers)
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
            'items' => [['order_item_id' => $item->id, 'qty_delivered' => $delivered]],
        ])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/assign-courier", ['courier_id' => $courierId])->assertStatus(200);
        $this->makeCurrentTenant();
        Order::find($order->id)->update(['invoice_ready' => true]);

        return $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/dispatch");
    }

    // ── Reglas de bodega (datos sembrados por la migración) ──────────────────────────────────────────────────────────

    public function test_la_migracion_deja_central_como_despacho_por_defecto_y_solo_central_y_exhibicion1_como_bodegas_de_venta(): void
    {
        $this->assertSame(['Bodega Central', 'Sala de exhibición 1'], Warehouse::paraVenta()->orderBy('id')->pluck('name')->all());
        $this->assertSame('Bodega Central', Warehouse::despachoPorDefecto()?->name);

        foreach (['Reclamos y Devoluciones', 'Merma', 'Reserva Servicios', 'Exhibición en cliente', 'Sala de exhibición 2'] as $name) {
            $this->assertFalse($this->warehouse($name)->permite_venta, "{$name} NUNCA se usa para ventas");
        }
        // «Disponible» cuenta solo Central: las demás (incluidos los 3 exhibiciones) lo excluyen.
        $this->assertFalse($this->warehouse('Bodega Central')->excluye_disponible);
        foreach (['Sala de exhibición 1', 'Exhibición en cliente', 'Sala de exhibición 2', 'Merma', 'Reclamos y Devoluciones', 'Reserva Servicios'] as $name) {
            $this->assertTrue($this->warehouse($name)->excluye_disponible, "{$name} excluye del disponible");
        }
    }

    public function test_la_base_impide_dos_bodegas_de_despacho_por_defecto_y_un_defecto_que_no_es_de_venta(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        Warehouse::where('name', 'Sala de exhibición 1')->update(['despacho_por_defecto' => true]); // ya hay una por defecto (Central)
    }

    public function test_la_base_impide_que_una_bodega_por_defecto_no_permita_venta(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table($this->warehouse('Merma')->getTable())->where('name', 'Merma')->update(['despacho_por_defecto' => true, 'permite_venta' => false]);
    }

    // ── Criterio 1: Central 10 + Reclamos 20, pedido de 25 → propone Central con 10 y deja 15 pendientes ─────────────────

    public function test_picking_propone_central_con_10_y_deja_15_pendientes_de_compra_y_reclamos_nunca_se_ofrece(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 20, 'Reclamos y Devoluciones');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 25, $headers);

        $item->refresh();
        $this->assertSame($this->warehouse('Bodega Central')->id, $item->warehouse_id);
        $this->assertSame(10, $item->qty_reserved, 'se reserva lo que hay en Central, no más');

        // La hoja ofrece solo Central y Sala de exhibición 1.
        $sheet = $this->withHeaders($headers)->getJson("/api/bodega/orders/{$order->id}/picking-sheet")->assertStatus(200);
        $this->assertSame(['Bodega Central', 'Sala de exhibición 1'], collect($sheet->json('sale_warehouses'))->pluck('name')->all());
        $this->assertSame(['Bodega Central', 'Sala de exhibición 1'], collect($sheet->json('items.0.warehouse_options'))->pluck('name')->all());
        $this->assertSame(10, collect($sheet->json('items.0.warehouse_options'))->firstWhere('name', 'Bodega Central')['available']);

        // Pedir 25 alistados es imposible; 10 sí, y los 15 restantes caen en faltante (tarjeta de revisión de inventario).
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 25]],
        ])->assertStatus(422);

        $done = $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 10]],
        ])->assertStatus(200)->assertJsonPath('order.stage', Order::STAGE_PACKING);

        $this->makeCurrentTenant();
        $review = Order::find($done->json('review.id'));
        $this->assertNotNull($review, 'el resto queda pendiente: tarjeta de Revisión de Inventario');
        $this->assertSame(15, $review->items->first()->qty_requested);
        $this->assertSame(20, $this->stockOf($product, 'Reclamos y Devoluciones'), 'Reclamos no se toca');
    }

    // ── Criterio 2: stock solo en Reclamos/Merma/Reserva → el pedido de venta no lo toma ─────────────────────────────────

    public function test_un_producto_con_stock_solo_en_bodegas_que_no_son_de_venta_cae_en_faltante(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 8, 'Reclamos y Devoluciones');
        $this->setStock($product, 6, 'Merma');
        $this->setStock($product, 4, 'Reserva Servicios');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 5, $headers);

        $item->refresh();
        $this->assertNull($item->warehouse_id);
        $this->assertSame(0, $item->qty_reserved);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 5]],
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 0]],
        ])->assertStatus(200);

        $this->makeCurrentTenant();
        foreach (['Reclamos y Devoluciones' => 8, 'Merma' => 6, 'Reserva Servicios' => 4] as $name => $qty) {
            $this->assertSame($qty, $this->stockOf($product, $name), "{$name} intacta");
        }
    }

    // ── Criterio 3: 10 en Central + 1 en Sala de exhibición 1, pedido de 11 → Kardex −10 y −1 ──────────────────────────────────────

    public function test_bodega_puede_tomar_10_de_central_y_escoger_1_de_exhibicion1_y_el_kardex_registra_ambas_salidas(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 11]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 1, 'Sala de exhibición 1');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 11, $headers);

        $central = $this->warehouse('Bodega Central');
        $exhibicion1 = $this->warehouse('Sala de exhibición 1');

        // La reserva automática solo toma Central; Sala de exhibición 1 la escoge Bodega en el picking.
        $this->assertSame(10, $item->fresh()->qty_reserved);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 11, 'allocations' => [
                ['warehouse_id' => $central->id, 'qty_picked' => 10],
                ['warehouse_id' => $exhibicion1->id, 'qty_picked' => 1],
            ]]],
        ])->assertStatus(200)->assertJsonPath('order.stage', Order::STAGE_PACKING)->assertJsonPath('review', null);

        $this->makeCurrentTenant();
        $this->assertSame(11, $item->fresh()->qty_picked);
        $this->assertSame([10, 1], OrderItemAllocation::where('order_item_id', $item->id)->orderBy('id')->pluck('qty_picked')->map(fn ($v) => (int) $v)->all());

        $this->dispatch($order, $item, 11, $headers)->assertStatus(200);
        $this->makeCurrentTenant();

        $moves = InventoryMovement::where('referencia_entidad_id', $order->id)->orderBy('id')->get();
        $this->assertSame([-10, -1], $moves->pluck('cantidad')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$central->id, $exhibicion1->id], $moves->pluck('warehouse_id')->all());
        $this->assertSame(0, $this->stockOf($product, 'Bodega Central'));
        $this->assertSame(0, $this->stockOf($product, 'Sala de exhibición 1'));
    }

    // ── Criterio 4: el selector solo ofrece Central y Sala de exhibición 1; una bodega que no es de venta se rechaza ────────────────

    public function test_no_se_puede_escoger_en_el_picking_una_bodega_que_no_es_de_venta(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 20, 'Reclamos y Devoluciones');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 5, $headers);

        $noVenta = [];
        foreach (['Reclamos y Devoluciones', 'Merma', 'Reserva Servicios', 'Exhibición en cliente', 'Sala de exhibición 2'] as $name) {
            $noVenta[$name] = $this->warehouse($name)->id; // antes de las llamadas HTTP, que dejan el tenant sin "current"
        }
        foreach ($noVenta as $name => $warehouseId) {
            $this->withHeaders($headers)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 5, 'allocations' => [['warehouse_id' => $warehouseId, 'qty_picked' => 5]]]],
            ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'no está habilitada para ventas'));
        }

        $this->makeCurrentTenant();
        $this->assertSame(20, $this->stockOf($product, 'Reclamos y Devoluciones'));
        $this->assertSame(0, OrderItemAllocation::where('order_item_id', $item->id)->where('qty_picked', '>', 0)->count());
    }

    // ── Criterio 5: ninguna bodega queda en negativo ─────────────────────────────────────────────────────────────────

    public function test_no_se_puede_alistar_mas_de_lo_que_tiene_exhibicion1(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 1, 'Sala de exhibición 1');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 12, $headers);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 12, 'allocations' => [
                ['warehouse_id' => $this->warehouse('Bodega Central')->id, 'qty_picked' => 10],
                ['warehouse_id' => $this->warehouse('Sala de exhibición 1')->id, 'qty_picked' => 2],
            ]]],
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'No hay saldo suficiente en «Sala de exhibición 1»'));
    }

    public function test_dos_pedidos_del_mismo_producto_no_pueden_dejar_central_en_negativo(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        $this->setStock($product, 10, 'Bodega Central');
        $headers = $this->headers();
        [$o1, $i1] = $this->orderInPicking($product, 7, $headers);
        [$o2, $i2] = $this->orderInPicking($product, 7, $headers);

        $this->assertSame(7, $i1->fresh()->qty_reserved);
        $this->assertSame(3, $i2->fresh()->qty_reserved, 'al segundo solo le quedan 3 en Central');

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$o2->id}/complete-picking", [
            'items' => [['order_item_id' => $i2->id, 'qty_picked' => 7]],
        ])->assertStatus(422);
    }

    public function test_si_el_saldo_cambia_entre_el_picking_y_el_despacho_el_despacho_se_bloquea_y_no_deja_negativo(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        $this->setStock($product, 10, 'Bodega Central');
        $headers = $this->headers();
        [$order, $item] = $this->orderInPicking($product, 10, $headers);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 10]],
        ])->assertStatus(200);

        // alguien baja el saldo de Central a 4 después del picking (p. ej. un ajuste/conteo)
        $this->makeCurrentTenant();
        $this->setStock($product, 4, 'Bodega Central');

        $this->dispatch($order, $item, 10, $headers)->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'saldo suficiente'));

        $this->makeCurrentTenant();
        $this->assertSame(4, $this->stockOf($product, 'Bodega Central'), 'no se descontó nada');
        $this->assertSame(0, InventoryMovement::where('referencia_entidad_id', $order->id)->count());
        $this->assertNotSame(Order::STAGE_DESPACHADO, Order::find($order->id)->stage, 'el pedido sigue sin despacharse');
    }

    // ── Criterio 6/7: «Disponible» cuenta solo Central; lo de Sala de exhibición 1 aparece aparte ─────────────────────────────────

    public function test_el_disponible_cuenta_solo_central_y_exhibicion1_se_expone_aparte(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 14]); // 10 Central + 3 Sala de exhibición 1 + 1 Reclamos
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 3, 'Sala de exhibición 1');
        $this->setStock($product, 1, 'Reclamos y Devoluciones');

        $kpis = app(InventoryKpiService::class);
        $enExcluida = $kpis->enBodegaExcluidaMap([$product->id])[$product->id] ?? 0;

        $this->assertSame(4, $enExcluida, 'Sala de exhibición 1 (3) + Reclamos (1) se excluyen del disponible');
        $this->assertSame(10.0, (float) $kpis->disponibleParaVenta($product, 0, $enExcluida), 'Disponible = solo lo de Bodega Central');
    }

    // ── Compatibilidad: pedidos anteriores al reparto (sin asignaciones) siguen despachando por warehouse_id ────────

    public function test_un_pedido_anterior_sin_asignaciones_sigue_saliendo_de_su_warehouse_id(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 8]);
        $this->setStock($product, 8, 'Bodega Central');
        $central = $this->warehouse('Bodega Central');
        $headers = $this->headers();

        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING, 'picker_id' => $this->userIdByEmail('auxiliarbodega@test.com')], [
            'catalog_product_id' => $product->id, 'qty_requested' => 5, 'qty_picked' => 5, 'qty_reserved' => 5, 'warehouse_id' => $central->id,
        ]);
        $item = $order->items->first();
        $this->assertSame(0, OrderItemAllocation::where('order_item_id', $item->id)->count());

        $this->dispatch($order, $item, 5, $headers)->assertStatus(200);
        $this->makeCurrentTenant();

        $this->assertSame(3, $this->stockOf($product, 'Bodega Central'));
        $this->assertSame([-5], InventoryMovement::where('referencia_entidad_id', $order->id)->pluck('cantidad')->map(fn ($v) => (int) $v)->all());
    }

    public function test_una_linea_personalizada_sin_producto_de_catalogo_se_alista_sin_tocar_bodegas(): void
    {
        $headers = $this->headers();
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 4]);
        $item  = $order->items->first();

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 4]],
        ])->assertStatus(200)->assertJsonPath('order.stage', Order::STAGE_PACKING);
    }
}
