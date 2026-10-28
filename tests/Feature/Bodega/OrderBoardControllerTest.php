<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — REQ-305/306/309/310/311/312/332: tablero Kanban de Pedidos.
 */
class OrderBoardControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        return $this->authHeaders($token);
    }

    /** REQ-311 — conteo correcto por columna. */
    public function test_tablero_cuenta_correctamente_por_etapa(): void
    {
        $this->makeOrder(['stage' => Order::STAGE_ASIGNADO]);
        $this->makeOrder(['stage' => Order::STAGE_ASIGNADO]);
        $this->makeOrder(['stage' => Order::STAGE_EN_PICKING]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('counts_by_stage.'.Order::STAGE_ASIGNADO));
        $this->assertSame(1, $response->json('counts_by_stage.'.Order::STAGE_EN_PICKING));
        $this->assertSame(0, $response->json('counts_by_stage.'.Order::STAGE_ENTREGADO));
        $this->assertSame(3, $response->json('total'));
    }

    /** REQ-309 — buscador encuentra por número de pedido. */
    public function test_buscador_encuentra_por_numero_de_pedido(): void
    {
        $order = $this->makeOrder(['order_number' => 'PED-2026-8888']);
        $this->makeOrder(['order_number' => 'PED-2026-1111']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders?search=8888')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('cards'));
        $this->assertSame($order->id, $response->json('cards.0.id'));
    }

    /** REQ-310 RN1 — chip Urgentes: entrega hoy o mañana. */
    public function test_chip_urgentes_filtra_por_fecha_de_entrega(): void
    {
        $urgent = $this->makeOrder(['committed_delivery_date' => Carbon::tomorrow()]);
        $this->makeOrder(['committed_delivery_date' => Carbon::today()->addDays(20)]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders?chip=urgentes')
            ->assertStatus(200);

        $ids = collect($response->json('cards'))->pluck('id')->all();
        $this->assertContains($urgent->id, $ids);
        $this->assertCount(1, $ids);
    }

    /** REQ-310 RN2 — chip Atrasados: fecha de entrega ya pasó y sigue sin llegar a Entregado. */
    public function test_chip_atrasados_filtra_pedidos_con_fecha_vencida(): void
    {
        $late = $this->makeOrder(['committed_delivery_date' => Carbon::yesterday(), 'stage' => Order::STAGE_ASIGNADO]);
        $this->makeOrder(['committed_delivery_date' => Carbon::yesterday(), 'stage' => Order::STAGE_ENTREGADO]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders?chip=atrasados')
            ->assertStatus(200);

        $ids = collect($response->json('cards'))->pluck('id')->all();
        $this->assertContains($late->id, $ids);
        $this->assertCount(1, $ids, 'El entregado a tiempo (o ya entregado) no debe contar como atrasado.');
    }

    /** REQ-310 RN3 — chip Sin stock: tarjetas "Faltante" en Asignado. */
    public function test_chip_sin_stock_filtra_tarjetas_faltante(): void
    {
        $shortage = $this->makeOrder(['order_type' => Order::ORDER_TYPE_FALTANTE, 'stage' => Order::STAGE_ASIGNADO]);
        $this->makeOrder(['order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_ASIGNADO]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders?chip=sin_stock')
            ->assertStatus(200);

        $ids = collect($response->json('cards'))->pluck('id')->all();
        $this->assertSame([$shortage->id], $ids);
    }

    /** REQ-306 — panel de carga por asistente, % del total del equipo. */
    public function test_panel_de_carga_por_asistente(): void
    {
        $assistantId = $this->userIdByEmail('asistentebodega2@test.com');
        $this->makeOrder(['assistant_id' => $assistantId]);
        $this->makeOrder(['assistant_id' => $assistantId]);
        $this->makeOrder(['assistant_id' => $this->userIdByEmail('asistentebodega@test.com')]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/assistant-load')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('assistant_id', $assistantId);
        $this->assertSame(2, $row['orders_count']);
        $this->assertEqualsWithDelta(66.7, $row['percent_of_total'], 0.1);
    }

    /**
     * REQ-306 (corrección 2026-08-11, Gerencia Test, SCRUM-376) — el panel debe mostrar SIEMPRE
     * las 2 tarjetas de los 2 Asistentes de Bodega reales (Asistente Bodega Test/Asistente Bodega Test 2),
     * incluso cuando uno tiene 0 pedidos asignados — antes, `groupBy('assistant_id')` hacía
     * desaparecer al asistente sin pedidos.
     */
    public function test_panel_de_carga_siempre_muestra_los_2_asistentes_incluso_con_cero_pedidos(): void
    {
        $asistentebodega2 = $this->userIdByEmail('asistentebodega2@test.com');
        $asistentebodega = $this->userIdByEmail('asistentebodega@test.com');
        // Solo Asistente Bodega tiene pedidos asignados — Asistente Bodega se queda en 0.
        $this->makeOrder(['assistant_id' => $asistentebodega2]);
        $this->makeOrder(['assistant_id' => $asistentebodega2]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/assistant-load')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertCount(2, $rows, 'Deben aparecer las 2 tarjetas de asistentes, sin importar la carga.');

        $asistentebodega2Row = $rows->firstWhere('assistant_id', $asistentebodega2);
        $asistentebodegaRow = $rows->firstWhere('assistant_id', $asistentebodega);
        $this->assertNotNull($asistentebodega2Row);
        $this->assertNotNull($asistentebodegaRow);
        $this->assertSame(2, $asistentebodega2Row['orders_count']);
        // json_encode() de un float "entero" (100.0) pierde el ".0" (sin JSON_PRESERVE_ZERO_FRACTION),
        // así que decodifica como int — assertEquals en vez de assertSame evita el falso negativo
        // por tipo (mismo criterio que el resto de este test file, ver `assertEqualsWithDelta` arriba).
        $this->assertEquals(100.0, $asistentebodega2Row['percent_of_total']);
        $this->assertSame(0, $asistentebodegaRow['orders_count'], 'Asistente Bodega sin pedidos NO debe desaparecer del panel.');
        $this->assertEquals(0.0, $asistentebodegaRow['percent_of_total']);
    }

    /** REQ-306 — con 0 pedidos en todo el tablero, las 2 tarjetas igual aparecen, en 0%. */
    public function test_panel_de_carga_con_cero_pedidos_en_todo_el_tablero_igual_muestra_las_2_tarjetas(): void
    {
        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/assistant-load')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (array $r) => $r['orders_count'] === 0 && (float) $r['percent_of_total'] === 0.0));
    }

    /**
     * SCRUM-854, rebote de Pre-QA — el selector del filtro seller_ids debe funcionar para el
     * permiso real de esta pantalla (bodega.read), no depender de ventas_diseno.read como el
     * endpoint viejo que reusaba el frontend. Login real con rol lider_bodega (vía
     * authHeadersForBodegaReader(), mismo helper que el resto de este archivo).
     */
    public function test_sellers_devuelve_duenos_de_pedidos_para_un_usuario_solo_con_bodega_read(): void
    {
        $ownerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeOrderWithPipelineCard($ownerId);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/sellers')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownerId, $ids);
    }

    /** SCRUM-854, rebote de Pre-QA — sin pedidos con pipeline_card, la lista viene vacía, no error. */
    public function test_sellers_sin_pedidos_con_pipeline_card_devuelve_lista_vacia(): void
    {
        $this->makeOrder();

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/sellers')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    /**
     * REQ-312 (corrección 2026-08-11, Gerencia Test, SCRUM-382) — "resumen de productos y
     * cantidades" en cada tarjeta del tablero.
     */
    public function test_tarjeta_expone_resumen_de_productos_y_cantidades(): void
    {
        $order = $this->makeOrder([], ['qty_requested' => 7]);
        $order->items()->create(['qty_requested' => 3, 'reference_snapshot' => 'REF-2']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders')
            ->assertStatus(200);

        $card = collect($response->json('cards'))->firstWhere('id', $order->id);
        $this->assertSame(2, $card['items_summary']['product_count']);
        $this->assertSame(10, $card['items_summary']['unit_count']);
    }

    /**
     * REQ-315 (corrección 2026-08-11, Gerencia Test, SCRUM-385) — "Cliente Master" y "Subcliente"
     * expuestos como campos independientes en el detalle, distintos del combinado `cliente`.
     */
    public function test_ver_detalle_expone_cliente_master_y_subcliente_independientes(): void
    {
        $order = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200);

        $this->assertNotNull($response->json('cliente_master'));
        $this->assertNotNull($response->json('subcliente'));
        $this->assertSame($response->json('cliente'), $response->json('subcliente'), 'subcliente debe coincidir con el snapshot cliente ya existente.');
    }

    // SCRUM-1149 — las Observaciones del proyecto en el Pipeline llegan al detalle del pedido en Bodega.
    public function test_ver_detalle_expone_las_observaciones_del_proyecto_del_pipeline(): void
    {
        $order = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));
        $order->pipelineCard->update(['observations' => "COMENTARIOS 1 ENTREGA EN BODEGA\nLlamar antes de despachar"]);

        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('observaciones', "COMENTARIOS 1 ENTREGA EN BODEGA\nLlamar antes de despachar");
    }

    public function test_ver_detalle_sin_observaciones_o_en_blanco_devuelve_null(): void
    {
        $vacio = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));
        $blanco = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));
        $blanco->pipelineCard->update(['observations' => "  \n "]);
        $sinTarjeta = $this->makeOrder();

        $headers = $this->authHeadersForBodegaReader();
        foreach ([$vacio, $blanco, $sinTarjeta] as $order) {
            $this->withHeaders($headers)->getJson("/api/bodega/orders/{$order->id}")
                ->assertStatus(200)->assertJsonPath('observaciones', null);
        }
    }

    // SCRUM-1149 — entrega Parcial: todas las tarjetas de la familia comparten tarjeta de Pipeline, todas muestran la observación.
    public function test_ver_detalle_de_entregas_parciales_muestra_la_misma_observacion(): void
    {
        $root = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));
        $root->pipelineCard->update(['observations' => 'Entregar en dos viajes']);
        $child = $this->makeOrder(['pipeline_card_id' => $root->pipeline_card_id, 'order_number' => $root->order_number, 'family_id' => $root->id]);

        $headers = $this->authHeadersForBodegaReader();
        foreach ([$root, $child] as $order) {
            $this->withHeaders($headers)->getJson("/api/bodega/orders/{$order->id}")
                ->assertStatus(200)->assertJsonPath('observaciones', 'Entregar en dos viajes');
        }
    }

    /**
     * Lote 4 (SCRUM-395/402, 2026-08-17) — Guía de Entrega y Ver detalle rediseñados piden
     * teléfono/correo del contacto como campos propios, no el string combinado `contacto_cliente`.
     */
    public function test_ver_detalle_expone_telefono_y_correo_del_contacto_por_separado(): void
    {
        $order = $this->makeOrderWithPipelineCard($this->userIdByEmail('vendedor2@test.com'));

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200);

        $this->assertSame('6000-9999', $response->json('contacto_telefono'));
        $this->assertNull($response->json('contacto_correo'));
    }

    /** REQ-332 — modal Ver detalle expone los ítems del pedido. */
    public function test_ver_detalle_expone_items_del_pedido(): void
    {
        $order = $this->makeOrder([], ['qty_requested' => 7, 'qty_delivered' => 2]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200);

        $this->assertSame($order->id, $response->json('id'));
        $this->assertCount(1, $response->json('items'));
        $this->assertSame(7, $response->json('items.0.qty_requested'));
    }

    /**
     * Frontend Dev (Batch A3, 2026-07-23) — `customer_contact`/`delivery_address` (poblados desde
     * REQ-308 en el modelo) y `factory_reference` (del producto de catálogo) nunca se serializaban
     * en `detail()`, dejando el modal "Ver detalle" (REQ-332, que pide ambos) mostrando "—" siempre.
     */
    public function test_ver_detalle_expone_contacto_direccion_y_referencia_de_fabrica(): void
    {
        $product = $this->makeCatalogProduct(['factory_reference' => 'FAB-9001']);
        $order = $this->makeOrder(
            ['customer_contact' => 'Juan Pérez (6000-1234)', 'delivery_address' => 'Av. Balboa, Ciudad de Panamá'],
            ['catalog_product_id' => $product->id],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200);

        $this->assertSame('Juan Pérez (6000-1234)', $response->json('contacto_cliente'));
        $this->assertSame('Av. Balboa, Ciudad de Panamá', $response->json('direccion_entrega'));
        $this->assertSame('FAB-9001', $response->json('items.0.factory_reference'));
    }

    /**
     * SCRUM-930 (Pre-QA, CRÍTICO) — `GuiaEntregaModal` reusado desde Estado de Cuenta llamaba a
     * este endpoint sin que Admin&Contab tuviera `bodega.read`, quedando colgado en "Cargando..."
     * (403 indistinguible de "todavía cargando" en el frontend). Mismos roles ya establecidos y
     * revisados en `OrderStatusController::resolveAccess()` para el mismo problema.
     */
    public function test_930_lider_admin_contab_ve_el_detalle_sin_tener_bodega_read(): void
    {
        $order = $this->makeOrder([], ['qty_requested' => 3, 'qty_delivered' => 3]);
        $token = $this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $order->id);
    }

    /** SCRUM-1139 — `lider_operaciones` espeja a la asistente en el visor de pedidos desde Admin&Contab; ninguno de los dos tiene `bodega.read`. */
    public function test_1139_asistente_y_operaciones_ven_el_detalle_del_pedido_como_admin_contab(): void
    {
        $order = $this->makeOrder([], ['qty_requested' => 3, 'qty_delivered' => 3]);

        foreach ([
            ['asistenteadministrativa@test.com', 'asistente_administrativa'],
            ['lideroperaciones@test.com', 'lider_operaciones'],
        ] as [$email, $role]) {
            $token = $this->loginAs($email, $email, $role);

            $this->withHeaders($this->authHeaders($token))
                ->getJson("/api/bodega/orders/{$order->id}")
                ->assertStatus(200)
                ->assertJsonPath('id', $order->id);
        }
    }

    /** SCRUM-930 — un rol sin `bodega.read` NI uno de los roles de Admin&Contab sigue bloqueado. */
    public function test_930_un_rol_sin_bodega_read_ni_rol_de_admin_contab_sigue_bloqueado(): void
    {
        $order = $this->makeOrder();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}")
            ->assertStatus(403);
    }

    public function test_ver_detalle_de_pedido_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/999999')
            ->assertStatus(404);
    }

    /** REQ-319 RN2 — badge de familia refleja "pendiente" hasta que TODOS los miembros lleguen a Por despachar. */
    public function test_badge_de_familia_pendiente_hasta_que_todos_lleguen_a_por_despachar(): void
    {
        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'total_in_family' => 2, 'sequence_in_family' => 1]);
        $this->makeOrder(['stage' => Order::STAGE_ASIGNADO, 'family_id' => $root->id, 'total_in_family' => 2, 'sequence_in_family' => 2]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$root->id}")
            ->assertStatus(200);

        $this->assertSame('pendiente_falta_parte', $response->json('family.badge'));
    }

    /**
     * SCRUM-406 (REQ-336) — la ETA del proveedor viaja directo en la tarjeta del tablero (no solo
     * en el modal "Ver detalle") para un pedido "sin stock" (Faltante + Asignado).
     */
    public function test_tablero_expone_eta_proveedor_en_la_tarjeta_para_pedidos_sin_stock(): void
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-ETA', 'name' => 'Producto en falta', 'description' => 'Producto en falta', 'price_full' => 100,
            'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $purchaseOrder = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            'estimated_arrival_date' => '2026-08-15', 'total_amount' => 100,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $purchaseOrder->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
            'sales_project_id' => SalesProject::create(['name' => 'Proyecto ETA'])->id,
        ]);

        $sinStock = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_FALTANTE, 'stage' => Order::STAGE_ASIGNADO],
            ['catalog_product_id' => $product->id],
        );
        $normal = $this->makeOrder(['stage' => Order::STAGE_ASIGNADO]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders')
            ->assertStatus(200);

        $cards = collect($response->json('cards'));
        $sinStockCard = $cards->firstWhere('id', $sinStock->id);
        $normalCard = $cards->firstWhere('id', $normal->id);

        $this->assertSame('2026-08-15', $sinStockCard['eta_proveedor']);
        $this->assertNull($normalCard['eta_proveedor']);
    }

    /**
     * Senior Review 2026-07-23 (Blocker) — `eta_proveedor` hacía una query de `PurchaseOrder` POR
     * tarjeta "sin stock" (N+1 real, confirmado por el reviewer con `DB::enableQueryLog()` sobre
     * 15 pedidos reales: 33 queries). El fix batchea todo el cálculo del board en 2 queries fijas
     * (`bulkSupplierEtaByProduct()`), sin importar cuántos pedidos "sin stock" haya. Este test
     * fija ese comportamiento contra una regresión futura: el conteo de queries con 6 pedidos "sin
     * stock" (3 productos distintos) debe ser el mismo que con 1 solo.
     */
    public function test_eta_proveedor_no_escala_en_queries_con_mas_pedidos_sin_stock(): void
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);

        for ($i = 1; $i <= 3; $i++) {
            $product = CatalogProduct::create([
                'reference' => "REF-ETA-{$i}", 'name' => "Producto en falta {$i}", 'description' => "Producto en falta {$i}", 'price_full' => 100,
                'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
            ]);
            $purchaseOrder = PurchaseOrder::create([
                'provider_id' => $provider->id, 'currency' => 'USD',
                'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
                'estimated_arrival_date' => '2026-08-15', 'total_amount' => 100,
            ]);
            PurchaseOrderLine::create([
                'purchase_order_id' => $purchaseOrder->id, 'catalog_product_id' => $product->id,
                'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
                'sales_project_id' => SalesProject::create(['name' => "Proyecto ETA {$i}"])->id,
            ]);

            // 2 pedidos "sin stock" por producto — 6 en total, 3 productos distintos.
            $this->makeOrder(
                ['order_type' => Order::ORDER_TYPE_FALTANTE, 'stage' => Order::STAGE_ASIGNADO],
                ['catalog_product_id' => $product->id],
            );
            $this->makeOrder(
                ['order_type' => Order::ORDER_TYPE_FALTANTE, 'stage' => Order::STAGE_ASIGNADO],
                ['catalog_product_id' => $product->id],
            );
        }

        DB::enableQueryLog();
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders')
            ->assertStatus(200);
        // Solo cuenta las queries de `purchase_orders`/`purchase_order_lines` — el resto (auth,
        // permisos, audit log) es overhead fijo de cualquier request autenticado, no escala con
        // la cantidad de pedidos "sin stock" y no es lo que este test verifica.
        $purchaseOrderQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'purchase_order'))
            ->count();
        DB::flushQueryLog();
        DB::disableQueryLog();

        // 2 queries fijas (`bulkSupplierEtaByProduct()`: 1 para las PurchaseOrder candidatas + 1
        // eager load de sus líneas) sin importar cuántos pedidos "sin stock" haya en el board — si
        // el N+1 vuelve, esto escalaría a 2 por pedido (12 con los 6 de este test).
        $this->assertSame(
            2,
            $purchaseOrderQueries,
            "El N+1 corregido no debería volver: {$purchaseOrderQueries} queries de purchase_order para 6 pedidos sin stock (esperado: 2, fijo).",
        );
    }
}
