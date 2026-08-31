<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-403 (REQ-333) — "Ver bodegas" agregado por pedido. */
class OrderWarehouseBreakdownTest extends FeatureTestCase
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

    public function test_desglose_de_bodegas_incluye_todos_los_productos_del_pedido(): void
    {
        $productA = $this->makeCatalogProduct(['reference' => 'REF-A']);
        $productB = $this->makeCatalogProduct(['reference' => 'REF-B']);
        $this->setStock($productA, 12, 'Bodega Central');
        $this->setStock($productB, 5, 'Bodega Central');

        $order = $this->makeOrder([], ['catalog_product_id' => $productA->id, 'qty_requested' => 2]);
        $order->items()->create(['catalog_product_id' => $productB->id, 'qty_requested' => 1, 'reference_snapshot' => 'REF-B', 'description_snapshot' => 'B']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}/warehouse-breakdown")
            ->assertStatus(200);

        $this->assertSame($order->id, $response->json('order_id'));
        $products = $response->json('products');
        $this->assertArrayHasKey((string) $productA->id, $products);
        $this->assertArrayHasKey((string) $productB->id, $products);

        $central = collect($products[(string) $productA->id]['warehouses'])->firstWhere('name', 'Bodega Central');
        $this->assertSame(12, $central['quantity']);
        $centralB = collect($products[(string) $productB->id]['warehouses'])->firstWhere('name', 'Bodega Central');
        $this->assertSame(5, $centralB['quantity']);
    }

    /** Mismo cálculo que el endpoint por producto individual — no debe divergir. */
    public function test_desglose_por_pedido_coincide_con_el_desglose_por_producto_individual(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 7, 'Merma');

        $order = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 1]);

        $headers = $this->authHeadersForBodegaReader();

        $byOrder = $this->withHeaders($headers)
            ->getJson("/api/bodega/orders/{$order->id}/warehouse-breakdown")
            ->assertStatus(200)
            ->json("products.{$product->id}");

        $byProduct = $this->withHeaders($headers)
            ->getJson("/api/bodega/adjustment-requests/products/{$product->id}/warehouse-stock")
            ->assertStatus(200)
            ->json();

        $this->assertSame($byProduct['por_servir'], $byOrder['por_servir']);
        $this->assertSame($byProduct['warehouses'], $byOrder['warehouses']);
    }

    public function test_desglose_de_pedido_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/999999/warehouse-breakdown')
            ->assertStatus(404);
    }
}
