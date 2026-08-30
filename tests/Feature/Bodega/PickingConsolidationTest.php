<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-391 (REQ-321) — "Consolidado del día por picker". */
class PickingConsolidationTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    /** RN1/consolidación — dos pedidos en_picking del mismo picker con el mismo producto se juntan en una línea, cantidades sumadas. */
    public function test_consolida_el_mismo_producto_de_varios_pedidos_del_mismo_picker(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-CONSOL']);
        $pickerId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $orderA = $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5],
        );
        $orderB = $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 3],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/consolidated-picking?picker_id={$pickerId}")
            ->assertStatus(200);

        $this->assertSame(2, $response->json('orders_count'));
        $this->assertCount(1, $response->json('items'));
        $line = $response->json('items.0');
        $this->assertSame(8, $line['qty_requested']);
        $this->assertCount(2, $line['orders']);
        $orderIds = collect($line['orders'])->pluck('order_id')->all();
        $this->assertContains($orderA->id, $orderIds);
        $this->assertContains($orderB->id, $orderIds);
    }

    /**
     * REQ-321 (corrección 2026-08-11, Daniela Amaya, SCRUM-391) — columna "Ubicación" obligatoria
     * en el consolidado: usa `found_location` (Revisión de Inventario) si existe, si no cae a
     * `location_snapshot` (la original).
     */
    public function test_expone_ubicacion_priorizando_found_location_sobre_la_original(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-LOC']);
        $pickerId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5, 'location_snapshot' => 'A-01', 'found_location' => 'B-02'],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/consolidated-picking?picker_id={$pickerId}")
            ->assertStatus(200);

        $this->assertSame('B-02', $response->json('items.0.location'));
    }

    /** REQ-321 — sin `found_location`, cae a la ubicación original. */
    public function test_expone_ubicacion_original_cuando_no_hay_found_location(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-LOC2']);
        $pickerId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5, 'location_snapshot' => 'A-01'],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/consolidated-picking?picker_id={$pickerId}")
            ->assertStatus(200);

        $this->assertSame('A-01', $response->json('items.0.location'));
    }

    /** RN1 — un pedido en picking_pendiente (todavía sin picker trabajando) no cuenta. */
    public function test_pedidos_en_picking_pendiente_no_se_incluyen(): void
    {
        $product = $this->makeCatalogProduct();
        $pickerId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $this->makeOrder(
            ['stage' => Order::STAGE_PICKING_PENDIENTE, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/consolidated-picking?picker_id={$pickerId}")
            ->assertStatus(200);

        $this->assertSame(0, $response->json('orders_count'));
        $this->assertSame([], $response->json('items'));
    }

    /** Ítems personalizados (sin catalog_product_id) de pedidos distintos nunca se consolidan entre sí. */
    public function test_items_personalizados_sin_catalogo_no_se_consolidan_entre_pedidos(): void
    {
        $pickerId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => null, 'reference_snapshot' => 'CUSTOM', 'qty_requested' => 4],
        );
        $this->makeOrder(
            ['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId],
            ['catalog_product_id' => null, 'reference_snapshot' => 'CUSTOM', 'qty_requested' => 6],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/consolidated-picking?picker_id={$pickerId}")
            ->assertStatus(200);

        $this->assertCount(2, $response->json('items'));
    }

    public function test_picker_id_faltante_retorna_422(): void
    {
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/consolidated-picking')
            ->assertStatus(422);
    }
}
