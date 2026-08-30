<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-781 (punto 4.2, REQ-247) — el buscador de "Productos reclamados/afectados" filtra por
 * `sales_project_id` opcional: solo productos REALMENTE ENTREGADOS a ese proyecto (decisión
 * confirmada con Luis 2026-08-20 — cadena vía Bodega, no vía Cotización). No hay FK directa
 * `sales_projects` ↔ `catalog_products` en el modelo, ver docblock de ProductLookupController.
 */
class TicketProductProjectFilterTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private const LIDER_SERVICIOS = 'servicio@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function ownerId(): int
    {
        return (int) User::where('email', self::LIDER_SERVICIOS)->value('id');
    }

    public function test_sin_sales_project_id_devuelve_el_catalogo_completo(): void
    {
        $productA = $this->makeCatalogProduct(['reference' => 'PROJ-A']);
        $productB = $this->makeCatalogProduct(['reference' => 'PROJ-B']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertContains('PROJ-A', $refs);
        $this->assertContains('PROJ-B', $refs);
    }

    public function test_con_sales_project_id_solo_muestra_productos_entregados_a_ese_proyecto(): void
    {
        $delivered   = $this->makeCatalogProduct(['reference' => 'PROJ-DELIVERED']);
        $notOrdered  = $this->makeCatalogProduct(['reference' => 'PROJ-NOT-ORDERED']);

        $order = $this->makeOrderWithPipelineCard(
            $this->ownerId(),
            ['stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $delivered->id],
        );
        $this->makeCurrentTenant();
        $salesProjectId = $order->fresh('pipelineCard')->pipelineCard->sales_project_id;

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/products?sales_project_id={$salesProjectId}")
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertContains('PROJ-DELIVERED', $refs);
        $this->assertNotContains('PROJ-NOT-ORDERED', $refs);
    }

    public function test_producto_de_pedido_sin_entregar_no_aparece_para_ese_proyecto(): void
    {
        $notDelivered = $this->makeCatalogProduct(['reference' => 'PROJ-PENDING']);

        $order = $this->makeOrderWithPipelineCard(
            $this->ownerId(),
            ['stage' => Order::STAGE_ASIGNADO],
            ['catalog_product_id' => $notDelivered->id],
        );
        $this->makeCurrentTenant();
        $salesProjectId = $order->fresh('pipelineCard')->pipelineCard->sales_project_id;

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/products?sales_project_id={$salesProjectId}")
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertNotContains('PROJ-PENDING', $refs);
    }

    public function test_producto_entregado_a_otro_proyecto_no_aparece(): void
    {
        $ownProduct   = $this->makeCatalogProduct(['reference' => 'PROJ-OWN']);
        $otherProduct = $this->makeCatalogProduct(['reference' => 'PROJ-OTHER']);

        $order = $this->makeOrderWithPipelineCard(
            $this->ownerId(),
            ['stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $ownProduct->id],
        );
        $this->makeCurrentTenant();
        $salesProjectId = $order->fresh('pipelineCard')->pipelineCard->sales_project_id;

        $this->makeOrderWithPipelineCard(
            $this->ownerId(),
            ['stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $otherProduct->id],
        );
        $this->makeCurrentTenant();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/products?sales_project_id={$salesProjectId}")
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertContains('PROJ-OWN', $refs);
        $this->assertNotContains('PROJ-OTHER', $refs);
    }
}
