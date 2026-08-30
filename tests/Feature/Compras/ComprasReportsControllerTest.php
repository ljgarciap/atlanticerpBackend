<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\LiquidationAgency;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderClaim;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-262→268 (REQ-199→205) — Reportes avanzados de Compras. */
class ComprasReportsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    private function makeOrder(int $providerId, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD', 'total_amount' => 100,
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function makeOrderWithStockState(int $providerId, string $stockState): PurchaseOrder
    {
        $order = $this->makeOrder($providerId);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo', 'description' => 'Bombillo', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $providerId, 'is_active' => true,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'stock_state_at_order' => $stockState,
        ]);

        return $order;
    }

    public function test_periodo_invalido_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchased-by-period?period=fortnight')
            ->assertStatus(422);
    }

    public function test_comprado_por_periodo_marca_el_mes_en_curso(): void
    {
        // REQ-200 Escenario 1 — el mes en curso se distingue de los ya cerrados.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['total_amount' => 500]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchased-by-period?period=month')
            ->assertStatus(200);

        $buckets = $response->json('buckets');
        $this->assertCount(6, $buckets);
        $current = end($buckets);
        $this->assertTrue($current['is_current']);
        $this->assertEquals(500, $current['total_amount']);
        // Los 5 buckets anteriores no son el actual.
        foreach (array_slice($buckets, 0, 5) as $b) {
            $this->assertFalse($b['is_current']);
        }
    }

    public function test_oportunidad_de_compra_muestra_aviso_si_reactivas_superan_50_por_ciento(): void
    {
        // REQ-202 Escenario 1 (definición real del Excel, no del texto de Jira solo) — el aviso
        // dispara si "A demanda" + "Tarde" COMBINADOS superan el 50%, no una sola categoría.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_DISPONIBLE);
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_BAJO_STOCK);
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_SIN_STOCK);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchase-opportunity')
            ->assertStatus(200);

        // 1 a_tiempo (33.3%) + 1 a_demanda (33.3%) + 1 tarde (33.3%) -> reactivas = 66.6% > 50%.
        $response->assertJsonPath('classified_orders', 3);
        $response->assertJsonPath('show_warning', true);
    }

    public function test_oportunidad_de_compra_sin_aviso_si_reactivas_no_superan_50_por_ciento(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_DISPONIBLE);
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_DISPONIBLE);
        $this->makeOrderWithStockState($provider->id, InventoryKpiService::STOCK_SIN_STOCK);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchase-opportunity')
            ->assertStatus(200);

        $response->assertJsonPath('show_warning', false);
    }

    public function test_oportunidad_de_compra_clasifica_por_el_peor_estado_de_la_orden(): void
    {
        // Orden con 2 líneas: una "disponible", otra "sin_stock" -> la orden completa cuenta
        // como "Tarde" (el peor estado, criterio conservador documentado en la clase).
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);
        $p1 = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'P1', 'description' => 'P1', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $p2 = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'P2', 'description' => 'P2', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $p1->id,
            'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'stock_state_at_order' => InventoryKpiService::STOCK_DISPONIBLE,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $p2->id,
            'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'stock_state_at_order' => InventoryKpiService::STOCK_SIN_STOCK,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchase-opportunity')
            ->assertStatus(200);

        $response->assertJsonPath('classified_orders', 1);
        $response->assertJsonPath('tarde_percent', 100);
        $response->assertJsonPath('a_tiempo_percent', 0);
    }

    public function test_oportunidad_de_compra_excluye_ordenes_sin_el_dato_capturado(): void
    {
        // Órdenes de antes de este batch (líneas sin stock_state_at_order) no se cuentan.
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Viejo', 'description' => 'Viejo', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10, 'stock_state_at_order' => null,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchase-opportunity')
            ->assertStatus(200);

        $response->assertJsonPath('classified_orders', 0);
        $response->assertJsonPath('show_warning', false);
    }

    public function test_calificacion_de_proveedores_coincide_con_proveedores(): void
    {
        // REQ-201 Escenario 1 — nunca un número distinto al de Proveedores. Esta orden está
        // "Por aprobar" (nunca llegó a "recibido"), así que sigue "Sin calificar" (RN2).
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['total_amount' => 300]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/provider-rating')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('provider_id', $provider->id);
        $this->assertEquals(300, $row['purchased_amount']);
        $this->assertNull($row['rating']);
    }

    public function test_calificacion_de_proveedores_expone_entregas_a_tiempo_y_completas(): void
    {
        // SCRUM-264 (hallazgo de QA 2026-07-18) — el ticket pide estas 2 columnas explícitamente.
        // 2 órdenes recibidas: una a tiempo, una tarde -> on_time_percent = 50. La tardía tiene
        // 1 reclamo -> no_claims_percent = 50. Renormalizado (40/65 + 25/65): (50*40+50*25)/65 = 50.
        $this->grantCompras('designer@atlantic.test', ['compras.write', 'compras.read']);
        $provider = $this->makeProvider();
        $onTimeOrder = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_RECIBIDO,
            'estimated_arrival_date' => '2026-01-10', 'actual_arrival_date' => '2026-01-08',
        ]);
        $lateOrder = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_RECIBIDO,
            'estimated_arrival_date' => '2026-01-10', 'actual_arrival_date' => '2026-01-15',
        ]);
        PurchaseOrderClaim::create([
            'purchase_order_id' => $lateOrder->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/provider-rating')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('provider_id', $provider->id);
        $this->assertEquals(50.0, $row['on_time_percent']);
        $this->assertNull($row['complete_percent']);
        $this->assertEquals(50.0, $row['rating']);
    }

    /**
     * Hallazgo de Senior Review (2026-07-18): "Calificación de proveedores" scopeaba
     * claims_count por la fecha de la ORDEN, "Reclamos" (REQ-203) por la fecha del RECLAMO —
     * mismo período seleccionado, dos números distintos para el mismo proveedor. Un reclamo
     * sobre una orden VIEJA, presentado DENTRO del período, debe contar en ambos paneles.
     */
    public function test_conteo_de_reclamos_es_consistente_entre_calificacion_y_reclamos(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo', 'description' => 'Bombillo', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        // Orden vieja (fuera del período actual), reclamo presentado AHORA (dentro del período).
        $oldOrder = $this->makeOrder($provider->id, ['created_at' => now()->subMonths(3)]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $oldOrder->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
        ]);
        PurchaseOrderClaim::create([
            'purchase_order_id' => $oldOrder->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(), 'created_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $claimsResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/claims?period=month')
            ->assertStatus(200);
        $ratingResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/provider-rating?period=month')
            ->assertStatus(200);

        $this->assertEquals(1, $claimsResponse->json('open_count'));
        $row = collect($ratingResponse->json('data'))->firstWhere('provider_id', $provider->id);
        $this->assertEquals(1, $row['claims_count']);
    }

    public function test_reclamos_tasa_y_ranking_por_proveedor(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo', 'description' => 'Bombillo', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order1 = $this->makeOrder($provider->id);
        $line1 = PurchaseOrderLine::create([
            'purchase_order_id' => $order1->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
        ]);
        $this->makeOrder($provider->id); // 2da orden, sin reclamo

        PurchaseOrderClaim::create([
            'purchase_order_id' => $order1->id, 'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/claims')
            ->assertStatus(200);

        $response->assertJsonPath('open_count', 1);
        $response->assertJsonPath('resolved_count', 0);
        $response->assertJsonPath('claim_rate', 50); // 1 reclamo / 2 órdenes
        $ranking = $response->json('provider_ranking');
        $this->assertEquals($provider->id, $ranking[0]['provider_id']);
        $this->assertEquals(1, $ranking[0]['claims_count']);
    }

    public function test_inventario_atencion_usa_los_mismos_numeros_que_inventario(): void
    {
        // REQ-204 — mismos números que la pantalla de Inventario, no un cálculo paralelo.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bajo stock', 'description' => 'Bajo stock', 'price_full' => 20,
            'cost' => 10, 'provider_id' => $provider->id, 'is_active' => true,
            'stock_quantity' => 2, 'reorder_point' => 5,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $reportResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/inventory-attention')
            ->assertStatus(200);
        $inventoryResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $this->assertEquals($inventoryResponse->json('kpis'), $reportResponse->json());
    }

    public function test_liquidacion_de_importaciones_solo_cuenta_zona_libre(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['origin' => 'internacional']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        // "Liquidada" = llegó a "recibido" (PurchaseOrder::isLiquidated(), hallazgo de QA
        // 2026-07-18 — la definición vieja usaba en_aduana, que un proveedor local salta).
        $zonaLibre = $this->makeOrder($provider->id, [
            'modality' => 'zona_libre', 'status' => PurchaseOrder::STATUS_RECIBIDO,
            'liquidation_agency_id' => $agency->id,
        ]);
        $this->makeOrder($provider->id, ['modality' => 'directo']); // no debe contar

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/liquidation-imports')
            ->assertStatus(200);

        $response->assertJsonPath('liquidated_count', 1);
        $response->assertJsonPath('por_liquidar_count', 0);
        $response->assertJsonPath('liquidated_percent', 100);
        $ranking = $response->json('agency_ranking');
        $this->assertEquals($agency->id, $ranking[0]['agency_id']);
    }

    /**
     * SCRUM-268 (corrección de Daniela Amaya, 2026-08-11) — "Por liquidar" son las órdenes
     * ordenadas que todavía no llegaron a Liquidado, y el universo del panel EXCLUYE las órdenes
     * que ni siquiera llegaron a "Ordenado" (subtítulo del mockup: "basado en órdenes Ordenado /
     * En tránsito / Recibido") — antes esas órdenes sin arrancar diluían el % dentro de
     * `total_imports`.
     */
    public function test_liquidacion_de_importaciones_por_liquidar_y_universo_excluye_no_ordenadas(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['origin' => 'internacional']);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $this->makeOrder($provider->id, [
            'modality' => 'zona_libre', 'status' => PurchaseOrder::STATUS_RECIBIDO,
            'liquidation_agency_id' => $agency->id,
        ]); // Liquidada
        $this->makeOrder($provider->id, [
            'modality' => 'zona_libre', 'status' => PurchaseOrder::STATUS_EN_TRANSITO,
            'liquidation_agency_id' => $agency->id,
        ]); // Ordenada, todavía no liquidada -> Por liquidar
        $this->makeOrder($provider->id, [
            'modality' => 'zona_libre', 'status' => PurchaseOrder::STATUS_POR_APROBAR,
        ]); // NI SIQUIERA Ordenada -> fuera del universo del panel por completo
        $this->makeOrder($provider->id, [
            'modality' => 'zona_libre', 'status' => PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR,
        ]); // idem

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/liquidation-imports')
            ->assertStatus(200);

        $response->assertJsonPath('liquidated_count', 1);
        $response->assertJsonPath('por_liquidar_count', 1);
        // 1 liquidada / (1 liquidada + 1 por liquidar) = 50%, NO 1/4 (25%) que daría si las 2
        // órdenes sin ordenar todavía se contaran en el universo.
        $response->assertJsonPath('liquidated_percent', 50);
    }

    public function test_sin_permiso_compras_read_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['ventas_diseno.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/reports/purchased-by-period')
            ->assertStatus(403);
    }
}
