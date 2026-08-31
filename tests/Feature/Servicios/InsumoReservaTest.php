<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Servicios\Models\InsumoPurchaseRequest;
use App\Modules\Servicios\Models\InsumoSetting;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-273→275/277/291, SCRUM-343/344/345/347/361) — "Reserva
 * Servicios" de insumos. Cubre: listado con estado ok/bajo_minimo (REQ-273), alta de insumo nuevo
 * que dispara una solicitud en 0 disponible (REQ-275), solicitud de compra genera una
 * `PurchaseOrder` real con `origin_module=servicios` (REQ-274), y el ciclo completo end-to-end vía
 * el flujo HTTP real de Compras hasta "Recibido" — Entrada (infra genérica ya existente) +
 * Reubicación Bodega Central -> Reserva Servicios (REQ-277), mismo criterio que
 * `StockReleaseOnPurchaseOrderReceivedTest` (sin `Event::fake()`, para probar el listener real
 * registrado en `AppServiceProvider`).
 */
class InsumoReservaTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** El cliente HTTP de test resetea el tenant "actual" al final de cada request. */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeLocalProvider(): Provider
    {
        return Provider::create(['name' => 'Proveedor Insumos SA', 'currency' => 'USD', 'origin' => Provider::ORIGIN_LOCAL]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'INS-'.uniqid(), 'name' => 'Silicón industrial', 'description' => '',
            'price_full' => 20, 'cost' => 8, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function reservaWarehouseId(): int
    {
        return (int) Warehouse::where('name', 'Reserva Servicios')->value('id');
    }

    private function bodegaCentralWarehouseId(): int
    {
        return (int) Warehouse::where('name', 'Bodega Central')->value('id');
    }

    // ── REQ-291 RN1/RN2 — la 8va bodega existe y arranca sin capacidad física propia ────────

    public function test_reserva_servicios_existe_como_octava_bodega(): void
    {
        $warehouse = Warehouse::where('name', 'Reserva Servicios')->first();
        $this->assertNotNull($warehouse);
        $this->assertSame('ubicacion_exacta', $warehouse->modo_detalle);
    }

    // ── REQ-275 — alta de insumo nuevo ────────────────────────────────────────────────────────

    public function test_agregar_insumo_crea_setting_y_dispara_solicitud_en_0_disponible(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 5,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(201);

        $this->assertSame(0, $response->json('disponible'));
        $this->assertSame(5, $response->json('minimo'));
        $this->assertSame('bajo_minimo', $response->json('estado'));

        $this->reMakeCurrent();
        $this->assertDatabaseCount((new InsumoSetting)->getTable(), 1);

        $purchaseRequest = InsumoPurchaseRequest::where('catalog_product_id', $product->id)->firstOrFail();
        $this->assertSame(InsumoPurchaseRequest::ESTADO_PENDIENTE, $purchaseRequest->estado);
        $this->assertSame(10, $purchaseRequest->cantidad_solicitada);
        $this->assertNotNull($purchaseRequest->purchase_order_id);

        $order = PurchaseOrder::findOrFail($purchaseRequest->purchase_order_id);
        $this->assertSame(PurchaseOrder::ORIGIN_SERVICIOS, $order->origin_module);
        $this->assertSame($provider->id, $order->provider_id);
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame(10, $order->lines()->first()->quantity);

        // Pre-QA 2026-08-13 (REQ-274 RN3) — hallazgo real: `origin_module` ya se guardaba en BD
        // pero `PurchaseOrderController::formatSummary()`/`formatDetail()` nunca lo exponían, así
        // que Yirena no tenía forma real de "diferenciarla de solicitudes que vienen de Ventas &
        // Diseño u otras áreas" (RN3 explícita) — la orden era indistinguible en Compras. Corregido
        // en el mismo dispatch (PurchaseOrderController.php + OriginBadge.tsx). Se verifica acá vía
        // el endpoint HTTP real que usa Compras, no solo el modelo, para que una futura regresión de
        // "olvidar exponer el campo" se detecte en este nivel.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $comprasToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($comprasToken))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('origin_module', PurchaseOrder::ORIGIN_SERVICIOS);
    }

    public function test_agregar_insumo_ya_trackeado_retorna_422(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);
        InsumoSetting::create(['catalog_product_id' => $product->id, 'minimo' => 3]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 5,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(422);
    }

    public function test_agregar_insumo_producto_sin_proveedor_retorna_422(): void
    {
        $product = $this->makeProduct(['provider_id' => null]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 5,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('proveedor', (string) $response->json('message'));

        // RN — sin proveedor, no debe quedar ningún setting/solicitud huérfana.
        $this->reMakeCurrent();
        $this->assertDatabaseCount((new InsumoSetting)->getTable(), 0);
    }

    public function test_tecnico_interno_no_puede_agregar_insumo(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);

        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 5,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(403);
    }

    // ── REQ-273 — listado con estado ok/bajo_minimo, disponible desde product_warehouse_stock ──

    public function test_listado_calcula_disponible_y_estado_desde_reserva_servicios(): void
    {
        $provider = $this->makeLocalProvider();
        $ok = $this->makeProduct(['provider_id' => $provider->id, 'reference' => 'INS-OK-'.uniqid()]);
        $bajo = $this->makeProduct(['provider_id' => $provider->id, 'reference' => 'INS-BAJO-'.uniqid()]);

        InsumoSetting::create(['catalog_product_id' => $ok->id, 'minimo' => 5]);
        InsumoSetting::create(['catalog_product_id' => $bajo->id, 'minimo' => 5]);

        $reservaId = $this->reservaWarehouseId();
        ProductWarehouseStock::create(['catalog_product_id' => $ok->id, 'warehouse_id' => $reservaId, 'quantity' => 10]);
        ProductWarehouseStock::create(['catalog_product_id' => $bajo->id, 'warehouse_id' => $reservaId, 'quantity' => 2]);

        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/insumos')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $okRow = $rows->firstWhere('catalog_product_id', $ok->id);
        $bajoRow = $rows->firstWhere('catalog_product_id', $bajo->id);

        $this->assertSame(10, $okRow['disponible']);
        $this->assertSame('ok', $okRow['estado']);
        $this->assertSame(2, $bajoRow['disponible']);
        $this->assertSame('bajo_minimo', $bajoRow['estado']);
        // SCRUM-361/779 (rebote QA 2026-08-19) — el fixture ya crea el producto con description=''
        // y name poblado (forma real post-migración 2026_08_15_100000/ICG); nombre no debe quedar
        // vacío. Guarda contra volver a leer $product->description en formatSetting().
        $this->assertSame($ok->name, $okRow['nombre']);
        $this->assertNotSame('', $okRow['nombre']);
    }

    // ── REQ-274/277 — ciclo completo real hasta "Recibido" ───────────────────────────────────

    public function test_orden_recibida_reubica_de_bodega_central_a_reserva_servicios_y_marca_solicitud_recibida(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);

        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 3,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(201);

        $this->reMakeCurrent();
        $purchaseRequest = InsumoPurchaseRequest::where('catalog_product_id', $product->id)->firstOrFail();
        $orderId = $purchaseRequest->purchase_order_id;
        $bodegaCentralId = $this->bodegaCentralWarehouseId();

        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read', 'compras.write']);
        $comprasToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($comprasToken);

        // PENDIENTE_LIQUIDAR -> ORDENADO (la orden nace en pendiente_liquidar porque
        // InsumoService::requestPurchase() nunca captura shipping_type/who_pays_shipping, ver su
        // docblock).
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_ORDENADO);

        // ORDENADO -> EN_TRANSITO_LOCAL (proveedor local salta aduana/tránsito internacional).
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);

        // Ingreso de Mercancía real -- acá es donde el flujo genérico ya existente
        // (GoodsReceiptConfirmationService::confirmLine()) va a generar la Entrada a Bodega
        // Central (REQ-277 RN1) cuando la orden llegue a Recibido.
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderId,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 8, 'warehouse_id' => $bodegaCentralId]],
        ])->assertStatus(201);

        // EN_TRANSITO_LOCAL -> RECIBIDO -- acá se dispara PurchaseOrderReceived, y con él nuestro
        // listener (SyncInsumoReservationOnPurchaseOrderReceived).
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);

        $this->reMakeCurrent();

        // RN1 (ya infraestructura genérica) — Entrada a Bodega Central, luego RN2 -- Reubicación
        // resta lo mismo de ahí. Neto: Bodega Central vuelve a 0 (todo se reubicó a Servicios).
        $centralStock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $bodegaCentralId)->first();
        $this->assertSame(0, $centralStock->quantity ?? null);

        $reservaStock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $this->reservaWarehouseId())->first();
        $this->assertNotNull($reservaStock);
        $this->assertSame(10, $reservaStock->quantity);

        // RN2 — 2 movimientos de Reubicación (salida de Bodega Central, entrada a Reserva Servicios).
        $reubicaciones = InventoryMovement::where('catalog_product_id', $product->id)
            ->where('tipo', InventoryMovement::TIPO_REUBICACION)
            ->get();
        $this->assertCount(2, $reubicaciones);
        $this->assertTrue($reubicaciones->contains(fn ($m) => $m->warehouse_id === $bodegaCentralId && $m->cantidad === -10));
        $this->assertTrue($reubicaciones->contains(fn ($m) => $m->warehouse_id === $this->reservaWarehouseId() && $m->cantidad === 10));

        // RN3 — la solicitud local pasa a "recibida" en el MISMO momento (misma transacción).
        $purchaseRequest->refresh();
        $this->assertSame(InsumoPurchaseRequest::ESTADO_RECIBIDA, $purchaseRequest->estado);
        $this->assertNotNull($purchaseRequest->received_at);

        // El listado de Servicios ahora refleja el disponible real (ok, por encima del mínimo=3).
        $listResponse = $this->withHeaders($this->authHeaders($liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios')))
            ->getJson('/api/servicios/insumos')
            ->assertStatus(200);
        $row = collect($listResponse->json('data'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(10, $row['disponible']);
        $this->assertSame('ok', $row['estado']);
    }

    // Senior Review 2026-08-13 — endurecido para sumar la cantidad reubicada desde los recibos
    // REALES (PurchaseOrderReceiptLine confirmada) en vez de la orden. No es explotable hoy (el
    // gate de `advance()` no deja llegar a Recibido con remanente pendiente, ver docblock del
    // listener) pero SÍ ejercita un caso real que la versión anterior manejaba por casualidad más
    // que por diseño: una orden recibida en 2 entregas parciales (6 + 4) — confirma que ambos
    // recibos se agregan correctamente y el total reubicado es 10, no solo la última línea.
    public function test_orden_recibida_en_dos_entregas_parciales_reubica_la_suma_de_ambos_recibos(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);

        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/insumos', [
                'catalog_product_id' => $product->id,
                'minimo' => 3,
                'cantidad_inicial' => 10,
            ])
            ->assertStatus(201);

        $this->reMakeCurrent();
        $purchaseRequest = InsumoPurchaseRequest::where('catalog_product_id', $product->id)->firstOrFail();
        $orderId = $purchaseRequest->purchase_order_id;
        $bodegaCentralId = $this->bodegaCentralWarehouseId();

        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read', 'compras.write']);
        $comprasToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($comprasToken);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")->assertStatus(200);
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")->assertStatus(200);

        // Primera entrega parcial: 6 de 10. `pending_remainder_status` (REQ-165) es obligatorio
        // para confirmar sin persistir a la espera del resto — la orden está en_transito_local en
        // este punto (proveedor local).
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderId,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 6, 'unit_cost' => 8, 'warehouse_id' => $bodegaCentralId]],
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO_LOCAL,
        ])->assertStatus(201);

        // Segunda entrega, completa el remanente (4 más) — un segundo `PurchaseOrderReceipt`
        // separado para la misma orden, no una corrección del primero.
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderId,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 8, 'warehouse_id' => $bodegaCentralId]],
        ])->assertStatus(201);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);

        $this->reMakeCurrent();

        // Bodega Central recibió 6+4=10 (2 Entradas) y reubica el total agregado: 10, no solo la
        // última línea de 4 (la versión anterior a este fix sí sumaba correctamente el order-level,
        // pero por casualidad — este test fija que la agregación real venga de los recibos).
        $centralStock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $bodegaCentralId)->first();
        $this->assertSame(0, $centralStock->quantity ?? null);

        $reservaStock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $this->reservaWarehouseId())->first();
        $this->assertSame(10, $reservaStock->quantity);

        $reubicaciones = InventoryMovement::where('catalog_product_id', $product->id)
            ->where('tipo', InventoryMovement::TIPO_REUBICACION)
            ->get();
        $this->assertCount(2, $reubicaciones);
        $this->assertTrue($reubicaciones->contains(fn ($m) => $m->warehouse_id === $bodegaCentralId && $m->cantidad === -10));
        $this->assertTrue($reubicaciones->contains(fn ($m) => $m->warehouse_id === $this->reservaWarehouseId() && $m->cantidad === 10));

        $purchaseRequest->refresh();
        $this->assertSame(InsumoPurchaseRequest::ESTADO_RECIBIDA, $purchaseRequest->estado);
    }

    public function test_orden_de_compras_normal_sin_origen_servicios_no_dispara_reubicacion(): void
    {
        $provider = $this->makeLocalProvider();
        $product = $this->makeProduct(['provider_id' => $provider->id]);
        $bodegaCentralId = $this->bodegaCentralWarehouseId();

        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read', 'compras.write']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // loginAs() ya disparó una request HTTP real, que resetea el search_path/tenant "actual"
        // que setUp() había dejado activo — mismo gotcha ya documentado en otros tests del
        // proyecto (ver ToolsBatch13Part1Test::reactivateTenant()). Necesario acá porque el resto
        // de este test crea la PurchaseOrder directo vía Eloquent (no vía HTTP).
        $this->reMakeCurrent();

        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        $order->lines()->create(['catalog_product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 8, 'subtotal' => 32]);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);

        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 8, 'warehouse_id' => $bodegaCentralId]],
        ])->assertStatus(201);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);

        $this->reMakeCurrent();

        // Entrada normal a Bodega Central, sin ninguna Reubicación (esta orden no es de Servicios).
        $centralStock = ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $bodegaCentralId)->first();
        $this->assertSame(4, $centralStock->quantity);

        $this->assertDatabaseCount((new InsumoPurchaseRequest)->getTable(), 0);
        $reubicaciones = InventoryMovement::where('catalog_product_id', $product->id)
            ->where('tipo', InventoryMovement::TIPO_REUBICACION)->count();
        $this->assertSame(0, $reubicaciones);
    }
}
