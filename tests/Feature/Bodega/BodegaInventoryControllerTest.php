<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Events\ArrivalConfirmedByWarehouse;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\Concerns\DemoModeTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-329 Bloque B2 (REQ-344→362, SCRUM-414→432) — "Ver Inventario" de Bodega. */
class BodegaInventoryControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;
    use DemoModeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function readerHeaders(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        return $this->authHeaders($token);
    }

    private function writerHeaders(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        return $this->authHeaders($token);
    }

    // ── Gate de permisos ────────────────────────────────────────────────────

    public function test_index_sin_bodega_read_retorna_403(): void
    {
        // designer@ (Ventas & Diseño) no tiene ningún permiso bodega.* — mismo usuario "sin
        // permiso" que ya usan WarehouseControllerTest/KardexControllerTest.
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/inventory')
            ->assertStatus(403);
    }

    public function test_confirm_arrival_sin_bodega_write_retorna_403(): void
    {
        $product = $this->makeCatalogProduct();

        // Usuario con bodega.read pero SIN bodega.write — el gate de escritura debe rechazarlo
        // aunque sí pueda leer el resto de la pantalla.
        $this->grantBodegaPermissions('designer@atlantic.test', ['bodega.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 5])
            ->assertStatus(403);
    }

    // ── Campos nunca expuestos a Bodega (REQ-344/361) ───────────────────────

    public function test_index_nunca_expone_precio_ni_costo(): void
    {
        $this->makeCatalogProduct(['price_full' => 999, 'cost' => 555]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('price_full', $row);
        $this->assertArrayNotHasKey('cost', $row);
        $this->assertArrayNotHasKey('cost_total', $row);
        $this->assertArrayNotHasKey('margin_percent', $row);
    }

    public function test_index_si_expone_rotacion_a_diferencia_del_modo_restringido_de_ventas(): void
    {
        $this->makeCatalogProduct(['rotation' => CatalogProduct::ROTATION_ALTA]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertJsonPath('data.0.rotation', CatalogProduct::ROTATION_ALTA);
    }

    // ── Ficha técnica (SCRUM-425/426, REQ-355) ───────────────────────────────

    /**
     * SCRUM-425 — hallazgo de QA 2026-07-27 (marly): el modal "Ver ficha técnica" ya está
     * cableado en el frontend de Bodega (`TechnicalSpecModal.tsx`, compartido con Compras) contra
     * `GET /bodega/inventory/{id}`, pero el endpoint nunca exponía `technical_spec` — a diferencia
     * de `Compras\Http\Controllers\InventoryController::format()`, que sí lo expone desde
     * SCRUM-425/426. Mismo campo/shape en ambos endpoints, misma columna JSONB de `CatalogProduct`.
     */
    public function test_show_expone_technical_spec_con_los_12_campos_cuando_el_producto_lo_tiene(): void
    {
        $spec = [
            'voltage' => '110V - 220V',
            'power' => '12 W',
            'socket_type' => 'E27',
            'color_temperature' => '3000K (cálida)',
            'luminous_flux' => '1050 lúmenes',
            'dimensions' => '40 x 40 x 55 cm',
            'weight' => '2.3 kg',
            'material_finish' => 'Metal pintado, difusor en vidrio',
            'ip_rating' => 'IP20 (interior)',
            'estimated_lifespan' => '25000 horas',
            'warranty' => '2 años',
            'certifications' => 'CE, RoHS',
        ];
        $product = $this->makeCatalogProduct(['technical_spec' => $spec]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}")
            ->assertStatus(200);

        // assertEqualsCanonicalizing, no assertJsonPath: JSONB de Postgres no preserva el orden
        // de inserción de las keys.
        $this->assertEqualsCanonicalizing($spec, $response->json('technical_spec'));
    }

    public function test_show_producto_legado_sin_ficha_tecnica_devuelve_technical_spec_null(): void
    {
        $product = $this->makeCatalogProduct();

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}")
            ->assertStatus(200);

        $response->assertJsonPath('technical_spec', null);
    }

    public function test_index_tambien_expone_technical_spec_por_fila(): void
    {
        $spec = ['voltage' => '110V'] + array_fill_keys(
            array_diff(CatalogProduct::TECHNICAL_SPEC_FIELDS, ['voltage']),
            'N/A',
        );
        $this->makeCatalogProduct(['technical_spec' => $spec]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $this->assertEqualsCanonicalizing($spec, $response->json('data.0.technical_spec'));
    }

    // ── Facetas de filtro Proveedor/Marca (REQ-349, SCRUM-419) ───────────────

    /**
     * Fix QA 2026-07-24 (marly.rangel): los dropdowns "Proveedor"/"Marca" aparecían vacíos porque
     * no había ningún endpoint accesible con solo `bodega.read` para poblarlos. `bodega.read`
     * (sin `compras.read`) debe alcanzar para ver las opciones — derivadas del propio catálogo,
     * no de una lista completa de `Provider` en Compras.
     */
    public function test_index_expone_proveedores_y_marcas_distintos_del_catalogo_activo(): void
    {
        $providerA = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $providerB = Provider::create(['name' => 'QA Prov Gamma', 'currency' => 'USD']);

        $this->makeCatalogProduct(['brand' => 'Marca Demo A', 'provider_id' => $providerA->id]);
        $this->makeCatalogProduct(['brand' => 'Marca Demo B', 'provider_id' => $providerB->id]);
        // Mismo proveedor/marca repetido en un segundo producto — no debe duplicarse en la faceta.
        $this->makeCatalogProduct(['brand' => 'Marca Demo B', 'provider_id' => $providerB->id]);
        // Producto inactivo — no cuenta para el universo "activo" de la faceta.
        $this->makeCatalogProduct(['brand' => 'Descontinuada SA', 'provider_id' => $providerA->id, 'is_active' => false]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $providers = $response->json('providers');
        $brands = $response->json('brands');

        // Fix SCRUM-419/422 (2026-07-28): `providers` trae {id, name}, no solo el nombre — el
        // filtro real (`provider_id`) es por id, un selector solo-nombre no podía armarlo.
        $providerNames = array_column($providers, 'name');
        $this->assertCount(2, $providers);
        $this->assertContains('Proveedor Demo A', $providerNames);
        $this->assertContains('QA Prov Gamma', $providerNames);
        $this->assertNotContains('Descontinuada SA', $providerNames);
        $this->assertContains($providerA->id, array_column($providers, 'id'));
        $this->assertContains($providerB->id, array_column($providers, 'id'));

        $this->assertCount(2, $brands);
        $this->assertContains('Marca Demo A', $brands);
        $this->assertContains('Marca Demo B', $brands);
    }

    /**
     * Fix SCRUM-419/422 (2026-07-28): filtrar por el `id` que trae la faceta de Proveedor debe
     * reducir la tabla a los productos de ese proveedor — antes la faceta solo traía el nombre y
     * el selector no tenía forma de armar el `provider_id` que exige el filtro real.
     */
    public function test_filtro_provider_id_usa_el_id_expuesto_por_la_faceta_de_proveedores(): void
    {
        $providerA = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $providerB = Provider::create(['name' => 'QA Prov Gamma', 'currency' => 'USD']);

        $this->makeCatalogProduct(['provider_id' => $providerA->id]);
        $this->makeCatalogProduct(['provider_id' => $providerB->id]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson('/api/bodega/inventory?provider_id='.$providerA->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($providerA->id, $response->json('data.0.provider_id'));
    }

    /**
     * Las facetas reflejan el universo COMPLETO de productos activos, no el subconjunto ya
     * filtrado por otros parámetros del request — mismo criterio documentado en
     * `BodegaInventoryListResponse.providers/brands` (frontend): el dropdown no debe encogerse a
     * medida que se aplican otros filtros.
     */
    public function test_index_facetas_no_se_encogen_al_combinar_con_otros_filtros(): void
    {
        $providerA = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $providerB = Provider::create(['name' => 'Proveedor Demo B SA', 'currency' => 'USD']);

        $this->makeCatalogProduct([
            'brand' => 'Marca Demo A', 'provider_id' => $providerA->id,
            'rotation' => CatalogProduct::ROTATION_ALTA,
        ]);
        $this->makeCatalogProduct([
            'brand' => 'Marca Demo B', 'provider_id' => $providerB->id,
            'rotation' => CatalogProduct::ROTATION_BAJA,
        ]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson('/api/bodega/inventory?rotation='.CatalogProduct::ROTATION_ALTA);

        $response->assertStatus(200);
        // La tabla sí queda filtrada a 1 fila (rotación alta)...
        $this->assertCount(1, $response->json('data'));
        // ...pero las facetas de Proveedor/Marca siguen mostrando AMBAS opciones.
        $this->assertCount(2, $response->json('providers'));
        $this->assertCount(2, $response->json('brands'));
    }

    // ── "Por servir" de Bodega — REQ-353/423, distinto del de Ventas & Diseño ──

    public function test_por_servir_suma_pedidos_en_etapas_activas_y_excluye_despachado_y_entregado(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 20]);

        $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $product->id, 'qty_requested' => 3]);
        $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR], ['catalog_product_id' => $product->id, 'qty_requested' => 2]);
        // Ya despachado/entregado — NO debe contar como comprometido (RN1 REQ-353).
        $this->makeOrder(['stage' => Order::STAGE_DESPACHADO], ['catalog_product_id' => $product->id, 'qty_requested' => 100]);
        $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['catalog_product_id' => $product->id, 'qty_requested' => 100]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertSame(5, $row['por_servir']);
        $this->assertSame(15, $row['disponible']);
        $this->assertSame(20, $row['stock_total']);
    }

    public function test_por_servir_descuenta_lo_ya_entregado_de_una_entrega_parcial(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);

        $this->makeOrder(
            ['stage' => Order::STAGE_PACKING],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10, 'qty_delivered' => 6],
        );

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        // pendingQty() = 10 - 6 = 4, no los 10 originales.
        $this->assertSame(4, $row['por_servir']);
    }

    public function test_commitment_detail_lista_cliente_proyecto_pedido_y_estado(): void
    {
        $product = $this->makeCatalogProduct();
        $ownerId = $this->userIdByEmail('liderbodega@test.com');
        $order = $this->makeOrderWithPipelineCard(
            $ownerId,
            ['stage' => Order::STAGE_EN_PICKING],
            ['catalog_product_id' => $product->id, 'qty_requested' => 4],
        );

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}/commitment-detail");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.order_id', $order->id);
        $response->assertJsonPath('data.0.quantity', 4);
        $response->assertJsonPath('data.0.stage', Order::STAGE_EN_PICKING);
        $this->assertNotNull($response->json('data.0.project_name'));
    }

    /** REQ-353 Escenario 2 — sin compromisos activos, [] en vez de un error. */
    public function test_commitment_detail_vacio_cuando_no_hay_compromisos(): void
    {
        $product = $this->makeCatalogProduct();

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}/commitment-detail");

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    // ── "En atención" — REQ-346 RN1, ALLOWLIST (alta/media + bajo/sin stock) ─

    /**
     * REQ-346 RN1 / SCRUM-416 — Pre-QA (2026-07-28) encontró que el KPI reusaba
     * `InventoryKpiService::isInAttention()` (Compras/REQ-169, BLOCKLIST: cuenta todo excepto
     * `baja`, `NULL` incluido). Bodega usa un ALLOWLIST propio
     * (`BodegaInventoryKpiService::isInAttentionBodega()`): solo `alta`/`media` cuentan;
     * `null` ("Compra única") y `baja` NUNCA cuentan, aunque sí cuenten en "Sin stock" aparte.
     */
    public function test_kpi_en_atencion_solo_cuenta_rotacion_alta_y_media(): void
    {
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_ALTA]);
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_MEDIA]);
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_BAJA]);
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => null]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        // Solo alta + media cuentan en "En atención"; los 4 productos cuentan en "Sin stock".
        $this->assertSame(2, $response->json('kpis.in_attention'));
        $this->assertSame(4, $response->json('kpis.out_of_stock'));
    }

    /** REQ-350 / SCRUM-420 — el chip "Atención" usa el mismo criterio ALLOWLIST que el KPI. */
    public function test_chip_en_atencion_solo_cuenta_rotacion_alta_y_media(): void
    {
        $alta = $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_ALTA]);
        $media = $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_MEDIA]);
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => CatalogProduct::ROTATION_BAJA]);
        $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5, 'rotation' => null]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory?chip=en_atencion');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($alta->id, $ids);
        $this->assertContains($media->id, $ids);
    }

    // ── Filtros combinables — REQ-349 ────────────────────────────────────────

    public function test_filtro_combinado_bodega_y_categoria(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $family = CatalogProductFamily::create(['name' => 'Bombillos', 'is_active' => true]);

        $matching = $this->makeCatalogProduct(['description' => 'Cilindro XL']);
        $family->products()->attach($matching->id);
        ProductWarehouseStock::create(['catalog_product_id' => $matching->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        // Misma familia, pero SIN stock en Bodega Central — RN2 REQ-349 debe excluirlo.
        $wrongWarehouse = $this->makeCatalogProduct(['description' => 'Cilindro XL 2']);
        $family->products()->attach($wrongWarehouse->id);

        // Stock en Bodega Central, pero OTRA familia — debe excluirlo del filtro combinado.
        $wrongFamily = $this->makeCatalogProduct(['description' => 'Modelo']);
        ProductWarehouseStock::create(['catalog_product_id' => $wrongFamily->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory?warehouse_id={$central->id}&family_id={$family->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    /**
     * SCRUM-361 (rebote QA 2026-08-20/21, RN5 de REQ-291) — regresión del bug real: "Disponible"
     * al filtrar por bodega debe ser la cantidad EN ESA bodega puntual, no `stock_quantity` (total
     * del producto en todas las bodegas). Antes del fix, un producto con 8 unidades en "Reserva
     * Servicios" y 2 en "Bodega Central" (10 en total) mostraba "10" acá al filtrar por Reserva
     * Servicios — el mismo número que Servicios → Insumos leía como "8" (ProductWarehouseStock
     * real de esa bodega, ver InsumoService::list()). Ambos módulos deben coincidir siempre.
     *
     * Assert sin filtro actualizado por SCRUM-851: "Reserva Servicios" ahora es
     * `excluye_disponible=true` (mismo problema que describe ese ticket: unidades reservadas para
     * Servicios se contaban como vendibles) — el global "disponible" ya resta esas 8 unidades
     * (10 - 8 = 2), sigue contando en `stock_quantity`/Inventario Total (ver
     * InventoryKpiService::disponibleParaVenta()). El filtro POR bodega (RN5 de este test) no
     * cambia: dentro de esa bodega puntual el número sigue siendo la cantidad real ahí.
     */
    public function test_disponible_filtrado_por_bodega_refleja_esa_bodega_no_el_total_global(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $reserva = Warehouse::firstOrCreate(['name' => 'Reserva Servicios']);

        $product = $this->makeCatalogProduct(['stock_quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $reserva->id, 'quantity' => 8]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 2]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory?warehouse_id={$reserva->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.disponible', 8);

        // Sin filtro de bodega: stock_quantity(10) - excluida en Reserva Servicios(8) = 2 (SCRUM-851).
        $unfiltered = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');
        $unfiltered->assertJsonPath('data.0.disponible', 2);
        $unfiltered->assertJsonPath('data.0.stock_total', 10);
    }

    public function test_filtro_por_estado_sin_stock(): void
    {
        $this->makeCatalogProduct(['stock_quantity' => 0]);
        $this->makeCatalogProduct(['stock_quantity' => 50, 'reorder_point' => 5]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory?estado=sin_stock');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.estado', 'sin_stock');
    }

    // ── Deep-link chips desde Home — REQ-362/SCRUM-432 ───────────────────────

    /**
     * Decisión de Luis 2026-07-23 (Pre-QA bloqueante, SCRUM-432): `chip=rotacion` reusa el
     * `rotation` que esta pantalla ya expone/filtra, NO el top-N por `InventoryMovement` de
     * `BodegaHomeService::mayorRotacion()` (conceptos parecidos, no forzados a coincidir).
     */
    public function test_chip_rotacion_filtra_solo_rotation_alta(): void
    {
        $alta = $this->makeCatalogProduct(['rotation' => CatalogProduct::ROTATION_ALTA]);
        $this->makeCatalogProduct(['rotation' => CatalogProduct::ROTATION_MEDIA]);
        $this->makeCatalogProduct(['rotation' => CatalogProduct::ROTATION_BAJA]);
        $this->makeCatalogProduct(['rotation' => null]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory?chip=rotacion');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $alta->id);
    }

    /**
     * Decisión de Luis 2026-07-23 (Pre-QA bloqueante, SCRUM-432): `chip=criticos` reusa
     * `OrderCommitmentService::committedMap()` (el "por servir" que esta pantalla ya usa para su
     * propia columna), NO `InventoryKpiService::porServirMap()` que usa
     * `BodegaHomeService::articulosCriticos()` — evita mezclar 2 definiciones de compromiso.
     */
    public function test_chip_criticos_incluye_bajo_y_sin_stock_excluye_disponible(): void
    {
        $sinStock = $this->makeCatalogProduct(['stock_quantity' => 0, 'reorder_point' => 5]);
        $bajoStock = $this->makeCatalogProduct(['stock_quantity' => 3, 'reorder_point' => 5]);
        $this->makeCatalogProduct(['stock_quantity' => 50, 'reorder_point' => 5]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory?chip=criticos');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$sinStock->id, $bajoStock->id], $ids);
    }

    // ── Vista Familias — REQ-347/348/417/418, sin costo ni "Generar compra" ──

    public function test_family_detail_no_expone_costo(): void
    {
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $product = $this->makeCatalogProduct(['cost' => 40]);
        $family->products()->attach($product->id);

        $response = $this->withHeaders($this->readerHeaders())->getJson("/api/bodega/inventory/families/{$family->id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('cost', $response->json('products.0'));
        $this->assertArrayNotHasKey('total_value', $response->json());
    }

    public function test_no_existe_ruta_de_generar_compra_para_bodega(): void
    {
        $family = CatalogProductFamily::create(['name' => 'Kit Baño 2', 'is_active' => true]);

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/families/{$family->id}/generate-purchase")
            ->assertStatus(404);
    }

    // ── Confirmar llegada física — REQ-357/427 ──────────────────────────────

    private function makePendingReceiptLine(CatalogProduct $product, int $quantity): PurchaseOrderReceiptLine
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 0,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => $quantity, 'unit_cost' => 10, 'subtotal' => $quantity * 10,
        ]);
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $order->id]);
        $central = Warehouse::where('name', 'Bodega Central')->firstOrFail();

        return PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $product->id,
            'quantity' => $quantity, 'unit_cost' => 10, 'warehouse_id' => $central->id,
            'confirmed_at' => null,
        ]);
    }

    public function test_confirm_arrival_marca_la_linea_y_dispara_evento(): void
    {
        Event::fake([ArrivalConfirmedByWarehouse::class]);
        $product = $this->makeCatalogProduct();
        $line = $this->makePendingReceiptLine($product, 10);

        $response = $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 10]);

        $response->assertStatus(200);
        $response->assertJsonPath('confirmed_quantity', 10);

        // El cliente HTTP de test resetea el tenant "actual" al final de cada request.
        $this->makeCurrentTenant();
        $line->refresh();
        $this->assertNotNull($line->bodega_confirmed_at);
        $this->assertSame(10, $line->bodega_confirmed_quantity);
        $this->assertNull($line->confirmed_at); // Paso 2 de Compras sigue pendiente — no se factura.

        Event::assertDispatched(ArrivalConfirmedByWarehouse::class);
    }

    public function test_confirm_arrival_cantidad_mayor_a_pedida_retorna_422(): void
    {
        $product = $this->makeCatalogProduct();
        $this->makePendingReceiptLine($product, 10);

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 11])
            ->assertStatus(422);
    }

    public function test_confirm_arrival_llegada_incompleta_reparte_fifo_y_deja_la_segunda_linea_sin_tocar(): void
    {
        $product = $this->makeCatalogProduct();
        $lineA = $this->makePendingReceiptLine($product, 10);
        $lineB = $this->makePendingReceiptLine($product, 10);

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 6])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $lineA->refresh();
        $lineB->refresh();
        $this->assertSame(6, $lineA->bodega_confirmed_quantity);
        $this->assertNotNull($lineA->bodega_confirmed_at);
        // La segunda línea no recibió nada de este reparto — sigue "pendiente de reportar".
        $this->assertNull($lineB->bodega_confirmed_at);
    }

    public function test_confirm_arrival_sin_nada_pendiente_retorna_422(): void
    {
        $product = $this->makeCatalogProduct();

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 1])
            ->assertStatus(422);
    }

    public function test_index_refleja_boton_y_luego_nota_de_espera_tras_confirmar(): void
    {
        $product = $this->makeCatalogProduct();
        $this->makePendingReceiptLine($product, 5);

        $before = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');
        $rowBefore = collect($before->json('data'))->firstWhere('id', $product->id);
        $this->assertTrue($rowBefore['arrival_confirmation']['pending_bodega_action']);
        $this->assertFalse($rowBefore['arrival_confirmation']['awaiting_compras']);

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 5])
            ->assertStatus(200);

        $after = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');
        $rowAfter = collect($after->json('data'))->firstWhere('id', $product->id);
        $this->assertFalse($rowAfter['arrival_confirmation']['pending_bodega_action']);
        $this->assertTrue($rowAfter['arrival_confirmation']['awaiting_compras']);
    }

    /** Rebote REQ-427 (Gerencia Test 2026-08-13) — la nota de espera debe traer cantidad + quién confirmó. */
    public function test_confirm_arrival_expone_cantidad_y_usuario_que_confirmo(): void
    {
        $product = $this->makeCatalogProduct();
        $this->makePendingReceiptLine($product, 7);

        $this->withHeaders($this->writerHeaders())
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 7])
            ->assertStatus(200);

        $show = $this->withHeaders($this->readerHeaders())->getJson("/api/bodega/inventory/{$product->id}");
        $show->assertStatus(200);
        $show->assertJsonPath('arrival_confirmation.confirmed_quantity', 7);
        $show->assertJsonPath('arrival_confirmation.confirmed_by_name', 'Lider Bodega Test');
    }

    public function test_show_expone_barcode(): void
    {
        $product = $this->makeCatalogProduct(['barcode' => '7501234567890']);

        $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}")
            ->assertStatus(200)
            ->assertJsonPath('barcode', '7501234567890');
    }

    // ── "En camino" — REQ-354/424 ────────────────────────────────────────────

    public function test_en_camino_detail_expone_proveedor_y_fecha_estimada(): void
    {
        $product = $this->makeCatalogProduct();
        $provider = Provider::create(['name' => 'ProveedorX', 'currency' => 'USD']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            'total_amount' => 0, 'estimated_arrival_date' => '2026-08-01',
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 120, 'unit_cost' => 5, 'subtotal' => 600,
        ]);

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}/en-camino-detail");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.provider_name', 'ProveedorX');
        $response->assertJsonPath('data.0.quantity', 120);
        $response->assertJsonPath('data.0.estimated_arrival_date', '2026-08-01');
    }

    public function test_en_camino_detail_vacio_cuando_no_hay_nada_en_camino(): void
    {
        $product = $this->makeCatalogProduct();

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}/en-camino-detail");

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    // ── Desglose por bodega — REQ-352/422 ────────────────────────────────────

    public function test_warehouse_breakdown_solo_muestra_bodegas_con_stock(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 7, 'Bodega Central');

        $response = $this->withHeaders($this->readerHeaders())
            ->getJson("/api/bodega/inventory/{$product->id}/warehouse-breakdown");

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $this->assertSame(1, $rows->count());
        $this->assertSame(7, $rows->first()['quantity']);
    }

    /**
     * SCRUM-422 (REQ-352) — hallazgo de QA 2026-07-24: la columna "Bodega(s)" del listado daba
     * "0 bodegas" para productos con stock real. La causa raíz resultó ser dato (fixtures de dev
     * sin `ProductWarehouseStock` sembrado, ver `ProductWarehouseStockBackfillSeeder`), no la
     * query — este test fija el comportamiento correcto de `warehouse_count` en el listado
     * principal (no solo en el modal de desglose) para que una regresión futura de la query sí
     * quede cubierta.
     */
    public function test_index_warehouse_count_refleja_bodegas_reales_con_stock(): void
    {
        $product = $this->makeCatalogProduct(['stock_quantity' => 300]);
        $this->setStock($product, 200, 'Bodega Central');
        $this->setStock($product, 100, 'Showroom Obarrio');

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.warehouse_count', 2);
    }

    // ── Modo Demo (docs/specs/demo-mode.md) ─────────────────────────────────

    public function test_gate_global_apagado_muestra_inventario_completo_aunque_preferencia_personal_este_activa(): void
    {
        $this->setUserDemoModePreference('liderbodega@test.com', true);
        $this->makeCatalogProduct(['reference' => 'SIN-FOTO', 'photo_url' => null]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $this->assertContains('SIN-FOTO', collect($response->json('data'))->pluck('reference'));
    }

    public function test_ambos_gates_activos_excluye_producto_sin_imagen_en_inventario(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('liderbodega@test.com', true);
        $this->makeCatalogProduct(['reference' => 'SIN-FOTO', 'photo_url' => null, 'photo_key' => null]);
        $this->makeCatalogProduct(['reference' => 'CON-FOTO', 'photo_url' => 'https://cdn.test/foto.jpg']);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-FOTO', $refs);
        $this->assertContains('CON-FOTO', $refs);
    }

    public function test_ambos_gates_activos_excluye_producto_con_precio_cero_en_inventario(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('liderbodega@test.com', true);
        $this->makeCatalogProduct(['reference' => 'SIN-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 0]);
        $this->makeCatalogProduct(['reference' => 'CON-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 150]);

        $response = $this->withHeaders($this->readerHeaders())->getJson('/api/bodega/inventory');

        $response->assertStatus(200);
        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-PRECIO', $refs);
        $this->assertContains('CON-PRECIO', $refs);
    }

    /** SCRUM-992 — búsqueda en SQL: sin acentos/mayúsculas y también por nombre de proveedor. */
    public function test_busqueda_sin_acentos_y_por_nombre_de_proveedor(): void
    {
        $prov = Provider::create(['name' => 'Catálica Panamá', 'currency' => 'USD']);
        CatalogProduct::create([
            'reference' => 'BSQ-1', 'name' => 'Reflector', 'description' => 'Reflector', 'price_full' => 10,
            'provider_id' => $prov->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'BSQ-2', 'name' => 'Vástago colgante', 'description' => 'Vástago colgante', 'price_full' => 10, 'is_active' => true,
        ]);
        $headers = $this->readerHeaders();

        $byName = $this->withHeaders($headers)->getJson('/api/bodega/inventory?search=VASTAGO')->assertStatus(200);
        $this->assertSame(['BSQ-2'], collect($byName->json('data'))->pluck('reference')->all());
        $this->assertFalse($byName->json('fuzzy'));

        $byProvider = $this->withHeaders($headers)->getJson('/api/bodega/inventory?search=catalic panama')->assertStatus(200);
        $this->assertSame(['BSQ-1'], collect($byProvider->json('data'))->pluck('reference')->all());
    }

    public function test_busqueda_con_typo_usa_el_respaldo_y_marca_fuzzy(): void
    {
        CatalogProduct::create([
            'reference' => 'BSQ-3', 'name' => 'Catalogación exterior', 'description' => 'x', 'price_full' => 10, 'is_active' => true,
        ]);
        $headers = $this->readerHeaders();

        $response = $this->withHeaders($headers)->getJson('/api/bodega/inventory?search=catalogacon')->assertStatus(200);
        $this->assertSame(['BSQ-3'], collect($response->json('data'))->pluck('reference')->all());
        $this->assertTrue($response->json('fuzzy'));
    }

    public function test_busqueda_combinada_con_chip_calculado_sigue_filtrando(): void
    {
        CatalogProduct::create([
            'reference' => 'BSQ-4', 'name' => 'Panel solo', 'description' => 'x', 'price_full' => 10, 'is_active' => true,
        ]);
        $headers = $this->readerHeaders();

        // chip "criticos" fuerza la rama con candidate set en PHP: la búsqueda ya lo redujo en SQL.
        $response = $this->withHeaders($headers)->getJson('/api/bodega/inventory?search=panel&chip=criticos')->assertStatus(200);
        $this->assertLessThanOrEqual(1, count($response->json('data')));
        $this->assertNotContains('BSQ-9', collect($response->json('data'))->pluck('reference')->all());
    }
}
