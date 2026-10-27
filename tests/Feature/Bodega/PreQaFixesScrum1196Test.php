<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\OrderItemAllocation;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1196 — correcciones del Pre-QA: «Quitar» persiste, «Ver bodegas» de Bodega con negativos, alerta de la cotización contra el Disponible, panel solo de superadmin. */
class PreQaFixesScrum1196Test extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    /** @return array<string, string> */
    private function bodegaHeaders(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com');
        $h = $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
        $this->makeCurrentTenant();

        return $h;
    }

    /** @return array<string, string> */
    private function superadminHeaders(): array
    {
        $h = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
        $this->makeCurrentTenant();

        return $h;
    }

    private function wh(string $name): int
    {
        return (int) Warehouse::where('name', $name)->value('id');
    }

    private function pickingOrder(int $productId, int $qty, array $h): array
    {
        $order = $this->makeOrder([], ['catalog_product_id' => $productId, 'qty_requested' => $qty]);
        $item  = $order->items->first();
        $this->withHeaders($h)->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $this->userIdByEmail('ayudantegeneralbodega@test.com')])->assertStatus(200);
        $this->withHeaders($h)->postJson("/api/bodega/orders/{$order->id}/start-picking")->assertStatus(200);
        $this->makeCurrentTenant();

        return [$order, $item];
    }

    // ── B1: «Quitar» persiste y se puede volver a todo-Central ────────────────────────────────────────────────────────

    public function test_se_puede_volver_de_un_reparto_con_obarrio_a_todo_central_y_la_pieza_de_obarrio_no_se_descuenta(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 15]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 5, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$order, $item] = $this->pickingOrder($product->id, 5, $h);
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');

        // 4 de Central + 1 de Obarrio...
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 5, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 4], ['warehouse_id' => $obarrio, 'qty_picked' => 1],
        ]]]])->assertStatus(200);
        $this->makeCurrentTenant();
        $this->assertSame(1, OrderItemAllocation::where('order_item_id', $item->id)->where('manual', true)->count());

        // ...y «Quitar»: todo a Central en un solo paso (antes el tope de Central quedaba recortado en 4)
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 5, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 5],
        ]]]])->assertStatus(200);
        $this->makeCurrentTenant();
        $this->assertSame(0, OrderItemAllocation::where('order_item_id', $item->id)->where('manual', true)->count(), 'la pieza de Obarrio se quitó');
        $this->assertSame(5, (int) OrderItemAllocation::where('order_item_id', $item->id)->where('warehouse_id', $central)->value('qty_picked'));

        $this->withHeaders($h)->postJson("/api/bodega/orders/{$order->id}/complete-picking", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 5, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 5],
        ]]]])->assertStatus(200);
        $this->makeCurrentTenant();
        $this->assertSame(5, $this->stockIn($product, 'Showroom Obarrio'), 'Obarrio intacta hasta despachar');
    }

    private function stockIn($product, string $name): int
    {
        return (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $this->wh($name))->value('quantity');
    }

    public function test_un_reparto_que_no_suma_lo_alistado_o_con_bodega_inexistente_se_rechaza_con_mensaje_claro(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10, 'Bodega Central');
        $h = $this->bodegaHeaders();
        [$order, $item] = $this->pickingOrder($product->id, 5, $h);
        $central = $this->wh('Bodega Central');

        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 5, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 3],
        ]]]])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'no coincide'));

        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 2, 'allocations' => [
            ['warehouse_id' => 99999999, 'qty_picked' => 2],
        ]]]])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'no existe'));
    }

    // ── B2: «Ver bodegas» de Bodega con negativos ─────────────────────────────────────────────────────────────────────

    public function test_ver_bodegas_de_bodega_muestra_las_negativas_y_el_conteo_las_incluye(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 11]);
        $this->setStock($product, 7, 'Bodega Central');
        $this->setStock($product, -3, 'Reclamos y Devoluciones');
        $this->setStock($product, 2, 'Merma');
        $this->setStock($product, 1, 'Showroom Obarrio');
        $this->setStock($product, 4, 'Showroom Cliente');
        $h = $this->bodegaHeaders();

        $rows = collect($this->withHeaders($h)->getJson("/api/bodega/inventory/{$product->id}/warehouse-breakdown")->assertStatus(200)->json('data'));

        $this->assertCount(5, $rows, 'las 5 bodegas con saldo distinto de 0, la negativa incluida');
        $this->assertSame(-3, $rows->firstWhere('name', 'Reclamos y Devoluciones')['quantity']);
        $this->assertSame(11, $rows->sum('quantity'), 'la suma coincide con el Stock total');
    }

    // ── M1: la alerta de stock de la línea de cotización usa el Disponible, no el stock total ─────────────────────────

    public function test_el_disponible_de_venta_de_la_linea_de_cotizacion_solo_cuenta_central(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 30]); // Central 10 + Reclamos 20
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 20, 'Reclamos y Devoluciones');

        $map = app(CatalogService::class)->availableForSaleMap([$product->id]);

        $this->assertSame(10, $map[$product->id], 'Reclamos no se puede vender: el disponible es 10, no 30');
    }

    public function test_el_detalle_de_la_cotizacion_devuelve_el_disponible_como_stock_de_la_linea(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 30]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 20, 'Reclamos y Devoluciones');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $master = MasterClient::create(['name' => 'Cliente 1196']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub 1196', 'tax_id' => '155-1196-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto 1196']);
        $quote = Quote::create(['owner_id' => $managerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id]);
        $quote->parts()->create(['name' => 'Partida 1'])->items()->create([
            'catalog_product_id' => $product->id, 'reference' => $product->reference, 'description' => 'Producto', 'quantity' => 25, 'unit_price' => 100,
        ]);
        $h = $this->superadminHeaders();

        $detail = $this->withHeaders($h)->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200);

        $this->assertSame(10, $detail->json('parts.0.items.0.stock_quantity'), 'pide 25 y hay 10 vendibles: el frontend alerta (antes decía 30 y no alertaba)');
    }

    // ── M2/M3: panel solo de superadmin, con sus invariantes ──────────────────────────────────────────────────────────

    public function test_el_panel_de_reglas_es_solo_de_superadmin(): void
    {
        $this->withHeaders($this->superadminHeaders())->getJson('/api/bodega/settings/warehouses')->assertStatus(200);

        // un usuario de Bodega con el permiso angosto bodega.settings.configure (lo tienen otros roles) NO puede tocar estas reglas
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read', 'bodega.write', 'bodega.settings.configure']);
        $other = $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
        $this->makeCurrentTenant();
        $id = $this->wh('Merma');
        $this->withHeaders($other)->getJson('/api/bodega/settings/warehouses')->assertStatus(403);
        $this->withHeaders($other)->putJson("/api/bodega/settings/warehouses/{$id}", ['permite_venta' => true])->assertStatus(403);
    }

    public function test_la_bodega_por_defecto_no_puede_excluirse_del_disponible_ni_una_excluida_ser_la_por_defecto(): void
    {
        $h = $this->superadminHeaders();
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio'); // de venta y excluida del disponible

        $this->withHeaders($h)->putJson("/api/bodega/settings/warehouses/{$central}", ['excluye_disponible' => true])->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertFalse(Warehouse::find($central)->excluye_disponible);
        $this->assertSame(['Bodega Central'], Warehouse::where('despacho_por_defecto', true)->pluck('name')->all());
    }

    public function test_cambiar_las_reglas_recalcula_de_inmediato_las_reservas_de_los_pedidos_en_picking(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        $this->setStock($product, 10, 'Bodega Central');
        $h = $this->bodegaHeaders();
        [$order, $item] = $this->pickingOrder($product->id, 5, $h);
        $this->assertSame($this->wh('Bodega Central'), $item->fresh()->warehouse_id);

        // un superadmin deja de permitir ventas desde Central... no puede (es la por defecto): se traslada la por defecto a Obarrio primero
        $sa = $this->superadminHeaders();
        $obarrio = $this->wh('Showroom Obarrio');
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        // Obarrio no tiene stock: la reserva se recalculó ya (no hay nada reservable en la nueva por defecto)
        $this->assertSame(0, $item->fresh()->qty_reserved);
    }

    public function test_al_cambiar_la_bodega_por_defecto_con_un_pedido_en_picking_la_reserva_no_queda_duplicada(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 14]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 4, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$order, $item] = $this->pickingOrder($product->id, 4, $h);
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');
        $this->assertSame(4, OrderItemAllocation::where('order_item_id', $item->id)->sum('qty_reserved'));

        // un superadmin traslada la bodega por defecto a Obarrio (con stock): la reserva se MUEVE, no se duplica
        $sa = $this->superadminHeaders();
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        $rows = OrderItemAllocation::where('order_item_id', $item->id)->get();
        $this->assertSame(4, (int) $rows->sum('qty_reserved'), 'reservado = lo que necesita el pedido, no el doble');
        $this->assertSame(0, $rows->where('warehouse_id', $central)->count(), 'la fila automática de la bodega anterior se liberó');
        $this->assertSame(4, (int) $rows->where('warehouse_id', $obarrio)->sum('qty_reserved'));
        $this->assertSame(4, $item->fresh()->qty_reserved);

        // y de vuelta a Central tampoco queda nada huérfano
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$central}", ['despacho_por_defecto' => true])->assertStatus(200);
        $this->makeCurrentTenant();
        $rows = OrderItemAllocation::where('order_item_id', $item->id)->get();
        $this->assertSame(4, (int) $rows->sum('qty_reserved'));
        $this->assertSame(0, $rows->where('warehouse_id', $obarrio)->count());
    }

    public function test_lo_ya_alistado_en_la_bodega_anterior_se_respeta_al_cambiar_la_por_defecto(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 14]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 4, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$order, $item] = $this->pickingOrder($product->id, 4, $h);
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');

        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", ['items' => [['order_item_id' => $item->id, 'qty_picked' => 3, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 3],
        ]]]])->assertStatus(200);

        $sa = $this->superadminHeaders();
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        $rows = OrderItemAllocation::where('order_item_id', $item->id)->get()->keyBy('warehouse_id');
        $this->assertSame(3, (int) $rows[$central]->qty_picked, 'lo ya alistado en Central se conserva');
        $this->assertSame(3, (int) $rows[$central]->qty_reserved);
        $this->assertSame(4, (int) $rows->sum('qty_reserved'), 'total reservado = necesidad (3 alistadas + 1 en la nueva por defecto)');
    }

    // ── Pre-QA ronda 3, N2: una fila manual en la bodega que pasa a ser la por defecto no rompe (500) ───────────────────────

    public function test_una_fila_manual_en_la_bodega_que_pasa_a_ser_la_por_defecto_se_fusiona_sin_romper(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 15]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 5, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$o1, $i1] = $this->pickingOrder($product->id, 4, $h);
        [$o2, $i2] = $this->pickingOrder($product->id, 3, $h);
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');

        // el picker toma 1 de Obarrio (fila MANUAL) en el primer pedido
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$o1->id}/picking-sheet", ['items' => [['order_item_id' => $i1->id, 'qty_picked' => 4, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 3], ['warehouse_id' => $obarrio, 'qty_picked' => 1],
        ]]]])->assertStatus(200);

        // un superadmin pasa Obarrio a ser la bodega por defecto: antes era un 500 (violación de order_item_allocations_unique)
        $sa = $this->superadminHeaders();
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        $rows = OrderItemAllocation::where('order_item_id', $i1->id)->get();
        $this->assertSame(1, $rows->where('warehouse_id', $obarrio)->count(), 'una sola fila por (línea, bodega)');
        $this->assertFalse((bool) $rows->firstWhere('warehouse_id', $obarrio)->manual, 'la fila manual pasó a ser la automática de la nueva por defecto');
        $this->assertSame(1, (int) $rows->firstWhere('warehouse_id', $obarrio)->qty_picked, 'lo ya alistado allí se conserva');
        $this->assertLessThanOrEqual(4, (int) $rows->sum('qty_reserved'), 'nunca más reservado que lo necesitado');

        // y los pedidos del producto siguen operables (antes todo PATCH/complete daba 500)
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$o2->id}/picking-sheet", ['items' => [['order_item_id' => $i2->id, 'qty_picked' => 1, 'allocations' => [
            ['warehouse_id' => $obarrio, 'qty_picked' => 1],
        ]]]])->assertSuccessful();
        // un segundo cambio de regla tampoco rompe
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$central}", ['despacho_por_defecto' => true])->assertStatus(200);
    }

    // ── Pre-QA ronda 4, N4: lo ya alistado por un pedido se aparta del saldo y la reserva total no supera el stock ──────────

    public function test_tras_cambiar_la_por_defecto_la_reserva_total_no_supera_el_stock_y_lo_alistado_a_mano_se_respeta(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 13]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 3, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$a, $ia] = $this->pickingOrder($product->id, 3, $h); // pedido A, primero
        [$b, $ib] = $this->pickingOrder($product->id, 4, $h); // pedido B, con 2 alistadas a mano en Obarrio
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');

        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$b->id}/picking-sheet", ['items' => [['order_item_id' => $ib->id, 'qty_picked' => 4, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 2], ['warehouse_id' => $obarrio, 'qty_picked' => 2],
        ]]]])->assertStatus(200);

        $sa = $this->superadminHeaders();
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['excluye_disponible' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['despacho_por_defecto' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        $reservedAtObarrio = (int) OrderItemAllocation::whereIn('order_item_id', [$ia->id, $ib->id])->where('warehouse_id', $obarrio)->sum('qty_reserved');
        $this->assertLessThanOrEqual(3, $reservedAtObarrio, 'lo reservado en Obarrio nunca supera su stock (3)');
        $this->assertGreaterThanOrEqual(2, (int) OrderItemAllocation::where('order_item_id', $ib->id)->where('warehouse_id', $obarrio)->sum('qty_reserved'), 'el pedido B conserva lo que ya alistó');

        // y B puede completar sus piezas ya alistadas (antes: 422 «hasta 0 y se intentó 2»)
        $this->withHeaders($h)->postJson("/api/bodega/orders/{$b->id}/complete-picking", ['items' => [['order_item_id' => $ib->id, 'qty_picked' => 4, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 2], ['warehouse_id' => $obarrio, 'qty_picked' => 2],
        ]]]])->assertStatus(200);
    }

    // ── Pre-QA ronda 4, N3: la entrega de dos pedidos del mismo producto a la vez ya no depende del deadlock ────────────────

    public function test_registrar_la_entrega_de_dos_pedidos_del_mismo_producto_deja_las_reservas_coherentes(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        $this->setStock($product, 10, 'Bodega Central');
        $h = $this->bodegaHeaders();
        [$o1, $i1] = $this->pickingOrder($product->id, 3, $h);
        [$o2, $i2] = $this->pickingOrder($product->id, 4, $h);
        foreach ([[$o1, $i1, 3], [$o2, $i2, 4]] as [$o, $i, $q]) {
            $this->withHeaders($h)->postJson("/api/bodega/orders/{$o->id}/complete-picking", ['items' => [['order_item_id' => $i->id, 'qty_picked' => $q]]])->assertStatus(200);
        }

        foreach ([[$o1, $i1, 3], [$o2, $i2, 4]] as [$o, $i, $q]) {
            $this->withHeaders($h)->postJson("/api/bodega/orders/{$o->id}/register-delivery", ['items' => [['order_item_id' => $i->id, 'qty_delivered' => $q]]])->assertStatus(200);
        }

        $this->makeCurrentTenant();
        // tras entregar, las reservas siguen siendo las alistadas (el re-reparto corre tras el commit) y no superan el stock
        $this->assertSame(7, (int) OrderItemAllocation::whereIn('order_item_id', [$i1->id, $i2->id])->sum('qty_reserved'));
        $this->assertLessThanOrEqual(10, (int) OrderItemAllocation::whereIn('order_item_id', [$i1->id, $i2->id])->sum('qty_reserved'));
    }

    // ── Pre-QA ronda 5, N5: una bodega que deja de ser de venta y vuelve a serlo recupera lo ya alistado a mano ────────────────

    public function test_quitar_y_reponer_permite_venta_no_deja_alistado_a_mano_sin_reserva(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 14]);
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 4, 'Showroom Obarrio');
        $h = $this->bodegaHeaders();
        [$x, $ix] = $this->pickingOrder($product->id, 3, $h);
        [$y, $iy] = $this->pickingOrder($product->id, 3, $h);
        $central = $this->wh('Bodega Central');
        $obarrio = $this->wh('Showroom Obarrio');

        // X alista 2 de Obarrio a mano
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$x->id}/picking-sheet", ['items' => [['order_item_id' => $ix->id, 'qty_picked' => 3, 'allocations' => [
            ['warehouse_id' => $central, 'qty_picked' => 1], ['warehouse_id' => $obarrio, 'qty_picked' => 2],
        ]]]])->assertStatus(200);

        $sa = $this->superadminHeaders();
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['permite_venta' => false])->assertStatus(200);
        $this->withHeaders($sa)->putJson("/api/bodega/settings/warehouses/{$obarrio}", ['permite_venta' => true])->assertStatus(200);

        $this->makeCurrentTenant();
        $row = OrderItemAllocation::where('order_item_id', $ix->id)->where('warehouse_id', $obarrio)->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->qty_picked);
        $this->assertSame(2, (int) $row->qty_reserved, 'recupera su reserva: lo alistado nunca queda sin reservar');

        // Y no puede llevarse las que X ya alistó: Obarrio tiene 4, X 2, a Y le quedan 2
        $this->withHeaders($h)->patchJson("/api/bodega/orders/{$y->id}/picking-sheet", ['items' => [['order_item_id' => $iy->id, 'qty_picked' => 3, 'allocations' => [
            ['warehouse_id' => $obarrio, 'qty_picked' => 3],
        ]]]])->assertStatus(422);
    }
}
