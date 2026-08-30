<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Events\OrderLiquidationAssigned;
use App\Modules\Compras\Models\LiquidationAgency;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-138→146 (Ver Órdenes, sprint2, SCRUM-201→209) — ciclo de vida de estados, edición y
 * filtros/chips. Ver docs/specs/compras-proveedores-sprint2.md para el alcance de este batch
 * (16 de 27 tickets — el resto depende de Ingreso de Mercancía/Bodega/OCR, diferidos).
 */
class PurchaseOrderLifecycleTest extends FeatureTestCase
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
            'provider_id' => $providerId, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            ...$overrides,
        ]);
    }

    /** El cliente HTTP de test resetea el tenant "actual" al final de cada request. */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── REQ-141: secuencia de estados según origen ──────────────────────────

    public function test_proveedor_internacional_pasa_por_transito_y_aduana(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200);

        $response->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO);
    }

    public function test_proveedor_local_salta_transito_y_aduana(): void
    {
        // REQ-141 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200);

        $response->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);
    }

    /**
     * SCRUM-204 (REQ-141, 2026-08-06 — hallazgo Daniela Amaya): "Pendiente/Por liquidar" pasa a
     * ser parte de la secuencia automática, entre "Por aprobar" y "Ordenado", para TODA orden
     * (antes solo se alcanzaba vía el botón "Liquidar" de Zona Libre, diferido a backlog).
     */
    public function test_secuencia_completa_pasa_por_pendiente_liquidar_antes_de_ordenado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_ORDENADO);
    }

    /** Mismo comportamiento para proveedor local — pendiente_liquidar tampoco se salta ahí. */
    public function test_secuencia_local_tambien_pasa_por_pendiente_liquidar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);
    }

    public function test_avanzar_orden_ya_recibida_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(422);
    }

    /**
     * SCRUM-208 (REQ-145, 2026-08-06 — hallazgo Daniela Amaya): `advance()` no consideraba
     * `pending_remainder_status` (REQ-165) — una orden con mercancía todavía en tránsito/aduana
     * podía marcarse "Recibido" igual, sin ningún aviso.
     */
    public function test_avanzar_a_recibido_bloqueado_con_remanente_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_ADUANA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(422);

        $this->assertStringContainsString('falta registrar el Ingreso de Mercancía', $response->json('message'));
        $this->reMakeCurrent();
        $this->assertEquals(PurchaseOrder::STATUS_EN_TRANSITO_LOCAL, $order->fresh()->status);
    }

    public function test_avanzar_a_recibido_permitido_sin_remanente_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            'pending_remainder_status' => null,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)
            ->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);
    }

    /**
     * SCRUM-347 (rebote QA 2026-08-14, reproducido con 2 órdenes reales en dev.atlanticerp.ai) — una
     * orden CON líneas esperadas pero SIN ningún Ingreso de Mercancía registrado nunca seteaba
     * `pending_remainder_status` (ese campo solo se toca dentro de `GoodsReceiptController::
     * saveReceipt()`), así que el gate viejo no la frenaba: avanzaba hasta "Recibido" con 0
     * líneas confirmadas, 0 movimientos de Kardex y sin ningún error visible — 20 unidades
     * "recibidas" que no existían en ninguna ubicación. Este test cubre exactamente ese camino.
     */
    public function test_avanzar_a_recibido_bloqueado_sin_ingreso_registrado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'SCRUM347-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            'pending_remainder_status' => null,
        ]);
        \App\Modules\Compras\Models\PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(422);

        $this->assertStringContainsString('falta registrar el Ingreso de Mercancía', $response->json('message'));
        $this->reMakeCurrent();
        $this->assertEquals(PurchaseOrder::STATUS_EN_TRANSITO_LOCAL, $order->fresh()->status);
        $this->assertEquals(0, CatalogProduct::find($product->id)->stock_quantity);
    }

    // ── REQ-154 (SCRUM-217, batch fusionado 2026-08-05) — 1 columna de fecha por etapa ──────

    /**
     * Cada transición de `advance()` deja su propio registro histórico — a diferencia de
     * `status_changed_at`, que se pisa en cada avance, estas 4 columnas nunca se sobreescriben
     * entre sí (una por etapa, ver `PurchaseOrder::STAGE_DATE_COLUMNS`).
     */
    public function test_cada_transicion_setea_su_columna_de_fecha_correcta(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // ordenado -> en_transito
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO);
        $row = DB::table("{$slug}_compras.purchase_orders")->where('id', $order->id)->first();
        $this->assertNotNull($row->en_transito_at);
        $this->assertNull($row->en_aduana_at);
        $this->assertNull($row->en_transito_local_at);

        // en_transito -> en_aduana
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_ADUANA);
        $row = DB::table("{$slug}_compras.purchase_orders")->where('id', $order->id)->first();
        $this->assertNotNull($row->en_aduana_at);
        $this->assertNull($row->en_transito_local_at);

        // en_aduana -> en_transito_local
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);
        $row = DB::table("{$slug}_compras.purchase_orders")->where('id', $order->id)->first();
        $this->assertNotNull($row->en_transito_local_at);
        $this->assertNull($row->actual_arrival_date);
    }

    /** REQ-153/154 RN3 — Recibido auto-llena `actual_arrival_date` con la fecha real de hoy, sin ninguna escritura manual. */
    public function test_avanzar_a_recibido_autollena_llegada_real(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200);

        $response->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);
        $response->assertJsonPath('actual_arrival_date', now()->toDateString());
    }

    /** REQ-154 RN1 Escenario 2 — proveedor local nunca setea en_transito_at/en_aduana_at (saltea esas 2 etapas). */
    public function test_secuencia_local_no_setea_columnas_de_etapas_saltadas(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $order = $this->makeOrder($provider->id);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // ordenado -> en_transito_local (nunca pasa por en_transito/en_aduana)
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);

        $row = DB::table("{$slug}_compras.purchase_orders")->where('id', $order->id)->first();
        $this->assertNotNull($row->en_transito_local_at);
        $this->assertNull($row->en_transito_at);
        $this->assertNull($row->en_aduana_at);
    }

    // ── REQ-143: bloqueo real de Mark ───────────────────────────────────────

    public function test_avanzar_bloqueado_sin_aprobacion_de_mark(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(422);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $status = DB::table("{$slug}_compras.purchase_orders")->where('id', $order->id)->value('status');
        $this->assertSame(PurchaseOrder::STATUS_POR_APROBAR, $status);
    }

    public function test_avanzar_permitido_tras_aprobacion_de_mark(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $approver = \App\Models\User::where('email', 'designer@atlantic.test')->firstOrFail();
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'requires_mark_approval' => true,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        // SCRUM-204 (2026-08-06, hallazgo Daniela Amaya): "Pendiente/Por liquidar" ahora es parte
        // de la secuencia automática entre "Por aprobar" y "Ordenado" — el primer avance tras la
        // aprobación de Mark ya no salta directo a "Ordenado".
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)
            ->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);
    }

    /**
     * REQ-142 Escenario 1 (fix Pre-QA 2026-07-20, rechazado por marly: "al marcar la orden como
     * Recibido, los productos que estaban esperando confirmación NO se confirman solos") — el
     * docblock original de `advance()` decía que este efecto quedaba diferido porque Ingreso de
     * Mercancía no existía; ya existe, así que debe confirmar solo al llegar a "Recibido".
     */
    public function test_avanzar_orden_a_recibido_confirma_pendientes_de_ingreso_automaticamente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $product = CatalogProduct::create([
            'reference' => 'REC-AUTO-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id);
        \App\Modules\Compras\Models\PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 7, 'unit_cost' => 10, 'subtotal' => 70,
        ]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $warehouseId = (int) DB::table("{$slug}_bodega.warehouses")->value('id');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // Orden local: 1er advance ORDENADO -> EN_TRANSITO_LOCAL (todavía no Recibido).
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);
        $receipt->assertJsonPath('lines.0.is_pending', true);

        $this->reMakeCurrent();
        $this->assertEquals(0, CatalogProduct::find($product->id)->stock_quantity);

        // 2do advance: EN_TRANSITO_LOCAL -> RECIBIDO. Acá debe auto-confirmarse la línea pendiente.
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);

        $this->reMakeCurrent();
        $this->assertEquals(7, CatalogProduct::find($product->id)->stock_quantity);
        $lineId = $receipt->json('lines.0.id');
        $confirmedAt = DB::table("{$slug}_compras.purchase_order_receipt_lines")->where('id', $lineId)->value('confirmed_at');
        $this->assertNotNull($confirmedAt);
    }

    /**
     * Pre-QA 2026-08-06 (SCRUM-216/217) — regresión del hallazgo CRÍTICO real: 5 llamadas
     * concurrentes por `curl` a `PATCH .../advance` sobre la MISMA orden (reproducido contra
     * Docker local, no simulado) dejaban `stock_quantity` en 40 en vez de 10 y 4 movimientos de
     * Kardex "Entrada" idénticos para un solo ingreso físico de 10 unidades — el SELECT de líneas
     * "pendientes" en `GoodsReceiptConfirmationService::confirmPendingForOrder()` corría SIN lock
     * y FUERA de la transacción de `advance()`, así que 3-4 de las 5 llamadas concurrentes leían
     * las mismas líneas "sin confirmar" antes de que cualquiera escribiera `confirmed_at`.
     *
     * Una carrera real de Postgres no es reproducible dentro de un test PHPUnit síncrono (mismo
     * proceso, misma conexión) — la carrera real ya se verificó corregida con `curl` concurrente
     * real contra el stack local (ver `docs/pre-qa/scrum216-217-timeline-race-recheck-20260806.md`
     * en el repo frontend). Este test cubre el mismo contrato desde el ángulo que SÍ es
     * determinístico: el escenario al que colapsa la concurrencia una vez que el `lockForUpdate()`
     * de `advance()` serializa los requests es exactamente "una segunda invocación de
     * `confirmPendingForOrder()` sobre una orden ya procesada" — debe ser un no-op (0 líneas
     * nuevas, stock sin duplicar), no una segunda confirmación.
     */
    public function test_confirmar_pendientes_dos_veces_no_duplica_stock(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $product = CatalogProduct::create([
            'reference' => 'RACE-NO-DUP-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id);
        \App\Modules\Compras\Models\PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 7, 'unit_cost' => 10, 'subtotal' => 70,
        ]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $warehouseId = (int) DB::table("{$slug}_bodega.warehouses")->value('id');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $this->reMakeCurrent();
        $order->refresh();
        $service = app(\App\Modules\Compras\Services\GoodsReceiptConfirmationService::class);

        $confirmedFirstCall = $service->confirmPendingForOrder($order);
        $confirmedSecondCall = $service->confirmPendingForOrder($order);

        $this->assertSame(1, $confirmedFirstCall, 'la primera llamada debe confirmar la única línea pendiente');
        $this->assertSame(0, $confirmedSecondCall, 'la segunda llamada NO debe re-confirmar nada — ya no hay líneas pendientes');
        $this->assertEquals(7, CatalogProduct::find($product->id)->stock_quantity, 'el stock debe quedar en 7, nunca en 14');

        $movementCount = DB::table("{$slug}_bodega.inventory_movements")
            ->where('catalog_product_id', $product->id)->count();
        $this->assertSame(1, $movementCount, 'debe existir UN solo movimiento de Kardex "Entrada", no uno por llamada');
    }

    /**
     * REQ-142 Escenario 1 — hallazgo de Pre-QA en vivo contra dev.atlanticerp.ai (2026-07-20, no
     * cubierto por el test anterior): un producto creado vía "Nueva Orden > + Producto nuevo"
     * (`PurchaseOrderController::createProduct()`) nunca inicializa `stock_quantity`, queda NULL
     * ("disponibilidad no controlada", ver migración `2026_07_19_100002`). `increment()`/
     * `decrement()` sobre una columna NULL en SQL se quedan en NULL, no en el número esperado —
     * coincide exacto con lo que marly reportó 3 veces como "stock nulo" tras confirmar/recibir.
     * Esto es DISTINTO del test anterior (que arranca con stock_quantity=0 explícito) — sin este
     * caso, la regresión real (cualquier producto pedido por Nueva Orden, el camino normal de
     * Compras) queda sin cubrir.
     */
    public function test_avanzar_orden_a_recibido_confirma_producto_creado_sin_stock_inicial_null(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read', 'compras.write']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // Camino real: producto creado a través de Nueva Orden (new_product), no CatalogProduct::create()
        // directo -- así es como queda con stock_quantity NULL en la práctica.
        $orderRes = $this->withHeaders($headers)->postJson('/api/compras/orders', [
            'provider_id' => $provider->id,
            'lines' => [[
                'new_product' => [
                    'reference' => 'REC-NULL-1', 'name' => 'Producto sin stock inicial',
                    'price_full' => 20, 'cost' => 10,
                ],
                'quantity' => 4,
            ]],
        ])->assertStatus(201);
        $orderId = $orderRes->json('id');
        $productId = $orderRes->json('lines.0.catalog_product_id');

        $this->reMakeCurrent();
        $this->assertNull(CatalogProduct::find($productId)->stock_quantity);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $warehouseId = (int) DB::table("{$slug}_bodega.warehouses")->value('id');

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")->assertStatus(200); // por_aprobar -> ordenado
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")->assertStatus(200); // ordenado -> en_transito_local
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderId,
            'lines' => [['catalog_product_id' => $productId, 'quantity' => 4, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderId}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);

        $this->reMakeCurrent();
        $this->assertEquals(4, CatalogProduct::find($productId)->stock_quantity);
    }

    // ── REQ-139: "crítica" ───────────────────────────────────────────────────

    public function test_orden_critica_por_3_o_mas_dias_por_aprobar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR,
            'status_changed_at' => now()->subDays(3),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?chip=critical')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_orden_critica_por_llegada_estimada_vencida(): void
    {
        // REQ-139 Escenario 2.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO,
            'estimated_arrival_date' => now()->subDay()->toDateString(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?chip=critical')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_orden_no_critica_no_aparece_en_el_chip(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?chip=critical')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    // ── REQ-138/139: filtros y chips ─────────────────────────────────────────

    public function test_filtro_combinado_por_proveedor_y_estado(): void
    {
        // REQ-138 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $lightCorp = $this->makeProvider(['name' => 'LightCorp']);
        $other = $this->makeProvider(['name' => 'Otro']);
        $this->makeOrder($lightCorp->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);
        $this->makeOrder($lightCorp->id, ['status' => PurchaseOrder::STATUS_RECIBIDO]);
        $this->makeOrder($other->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders?provider_id={$lightCorp->id}&status=".PurchaseOrder::STATUS_EN_TRANSITO)
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    /** SCRUM-201 (hallazgo QA 2026-07-16): faltaba el buscador de texto — número de orden o proveedor. */
    public function test_busqueda_por_numero_de_orden_o_proveedor(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $lightCorp = $this->makeProvider(['name' => 'LightCorp']);
        $other = $this->makeProvider(['name' => 'Otro']);
        $order1 = $this->makeOrder($lightCorp->id);
        $this->makeOrder($other->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $byName = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?search=LightCorp')
            ->assertStatus(200);
        $this->assertCount(1, $byName->json('data'));

        $byId = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders?search={$order1->id}")
            ->assertStatus(200);
        $this->assertCount(1, $byId->json('data'));
        $this->assertEquals($order1->id, $byId->json('data.0.id'));
    }

    /** SCRUM-201 — responsables reales para el filtro, no un input de texto libre. */
    public function test_index_expone_lista_de_responsables_reales(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = \App\Modules\VentasDiseno\Models\CatalogProduct::create([
            'reference' => 'RESP-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $names = collect($response->json('filters.creators'))->pluck('name');
        $this->assertContains('Designer Demo', $names);
    }

    /**
     * SCRUM-213 (REQ-150) — el criterio literal del ticket depende de una pantalla "Ingreso de
     * Mercancía" que todavía no existe (ni en frontend ni en backend, ver docblock de
     * PurchaseOrderController::advance()) — queda diferida al sprint de Bodega. Lo que SÍ se
     * puede probar hoy, y es lo que realmente importa para "el estado nunca se desincroniza entre
     * pantallas": Ver Órdenes (index), Logística (index con active_shipments) y el Detalle (show)
     * leen el mismo `status` de la misma fila, sin ninguna copia intermedia — formatDetail()
     * literalmente hace spread de formatSummary() (PurchaseOrderController.php linea ~531), así
     * que no hay forma de que diverjan.
     */
    public function test_status_es_identico_entre_ver_ordenes_logistica_y_detalle(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        // Avanza el estado (simula lo que pasaría al corregir algo en cualquier pantalla futura).
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200);

        $fromIndex = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200)
            ->json('data');
        $orderInIndex = collect($fromIndex)->firstWhere('id', $order->id);

        $fromLogistics = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?active_shipments=1')
            ->assertStatus(200)
            ->json('data');
        $orderInLogistics = collect($fromLogistics)->firstWhere('id', $order->id);

        $fromDetail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->json();

        $this->assertNotNull($orderInIndex);
        $this->assertNotNull($orderInLogistics);
        $this->assertSame($orderInIndex['status'], $orderInLogistics['status']);
        $this->assertSame($orderInIndex['status'], $fromDetail['status']);
        $this->assertSame(PurchaseOrder::STATUS_EN_TRANSITO, $fromDetail['status']);
    }

    // ── SCRUM-210 (REQ-147, alcance reducido) — liquidación con agencias Zona Libre ──────────

    public function test_liquidar_orden_zona_libre_asigna_agencia_y_dispara_evento(): void
    {
        Event::fake([OrderLiquidationAssigned::class]);
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_ZONA_LIBRE]);
        $agency = LiquidationAgency::create(['name' => 'Agencia Aduanal Istmo']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/liquidate", ['agency_id' => $agency->id])
            ->assertStatus(200);

        $response->assertJsonPath('liquidation_agency_id', $agency->id);
        $response->assertJsonPath('liquidation_agency_name', 'Agencia Aduanal Istmo');
        // No cambia el status — alcance reducido, la secuencia de estados no se toca.
        $response->assertJsonPath('status', PurchaseOrder::STATUS_ORDENADO);

        Event::assertDispatched(OrderLiquidationAssigned::class, fn (OrderLiquidationAssigned $e): bool =>
            $e->orderId === $order->id && $e->agencyId === $agency->id && $e->agencyName === 'Agencia Aduanal Istmo');
    }

    public function test_liquidar_orden_no_zona_libre_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_DIRECTO]);
        $agency = LiquidationAgency::create(['name' => 'Agencia X']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/liquidate", ['agency_id' => $agency->id])
            ->assertStatus(422);
    }

    public function test_liquidar_con_agencia_inexistente_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_ZONA_LIBRE]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/liquidate", ['agency_id' => 999999])
            ->assertStatus(422);
    }

    public function test_liquidar_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_ZONA_LIBRE]);
        $agency = LiquidationAgency::create(['name' => 'Agencia X']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/liquidate", ['agency_id' => $agency->id])
            ->assertStatus(403);
    }

    public function test_cambiar_agencia_de_liquidacion_ya_asignada(): void
    {
        // El ticket pide "asignar (o cambiar)" — reasignar debe funcionar, no solo la primera vez.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $agencyA = LiquidationAgency::create(['name' => 'Agencia A']);
        $agencyB = LiquidationAgency::create(['name' => 'Agencia B']);
        $order = $this->makeOrder($provider->id, [
            'modality' => PurchaseOrder::MODALITY_ZONA_LIBRE, 'liquidation_agency_id' => $agencyA->id,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/liquidate", ['agency_id' => $agencyB->id])
            ->assertStatus(200);

        $response->assertJsonPath('liquidation_agency_id', $agencyB->id);
        $response->assertJsonPath('liquidation_agency_name', 'Agencia B');
    }

    public function test_chip_zona_libre_filtra_por_modalidad(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_ZONA_LIBRE]);
        $this->makeOrder($provider->id, ['modality' => PurchaseOrder::MODALITY_DIRECTO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?chip=zona_libre')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    // ── REQ-144: editar orden solo mientras "Por aprobar" ────────────────────

    public function test_no_se_puede_editar_una_orden_ya_ordenada(): void
    {
        // REQ-144 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'REF-1', 'name' => 'Producto', 'description' => 'Producto', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/orders/{$order->id}", [
                'provider_id' => $provider->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_editar_orden_por_aprobar_actualiza_modalidad_y_lineas(): void
    {
        // REQ-144 Escenario 2.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'REF-1', 'name' => 'Producto', 'description' => 'Producto', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/orders/{$order->id}", [
                'provider_id' => $provider->id,
                'modality' => PurchaseOrder::MODALITY_ZONA_LIBRE,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('modality', PurchaseOrder::MODALITY_ZONA_LIBRE);
        $response->assertJsonPath('lines.0.quantity', 3);
        $response->assertJsonPath('total_amount', 15);
    }

    public function test_editar_orden_con_referencia_repetida_retorna_422(): void
    {
        // REQ-144 Escenario 3 / RN6.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'EXISTENTE', 'name' => 'Ya existe', 'description' => 'Ya existe', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/orders/{$order->id}", [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'EXISTENTE', 'name' => 'Otro', 'price_full' => 20, 'cost' => 10,
                    ],
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.new_product.reference']);
    }

    // ── SCRUM-208 (rediseño 2026-08-15) — advance-remainder ──────────────────

    public function test_advance_remainder_404_sin_orden(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/compras/orders/999999/advance-remainder')
            ->assertStatus(404);
    }

    public function test_advance_remainder_422_sin_remanente_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['pending_remainder_status' => null]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(422);
    }

    public function test_advance_remainder_avanza_por_su_propia_secuencia_de_etapas(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['pending_remainder_status' => PurchaseOrder::REMAINDER_ORDENADO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(200)
            ->assertJsonPath('pending_remainder_status', PurchaseOrder::REMAINDER_SALIO_DE_ORIGEN)
            ->assertJsonPath('next_remainder_status', PurchaseOrder::REMAINDER_EN_TRANSITO);

        $this->reMakeCurrent();
        $refreshed = $order->fresh();
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->remainder_salio_de_origen_at);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(200)->assertJsonPath('pending_remainder_status', PurchaseOrder::REMAINDER_EN_TRANSITO);
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(200)->assertJsonPath('pending_remainder_status', PurchaseOrder::REMAINDER_EN_ADUANA);
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(200)
            ->assertJsonPath('pending_remainder_status', PurchaseOrder::REMAINDER_EN_TRANSITO_LOCAL)
            ->assertJsonPath('next_remainder_status', null);
    }

    public function test_advance_remainder_422_en_la_ultima_etapa(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO_LOCAL]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(422);
    }

    public function test_advance_remainder_no_toca_el_status_de_la_orden(): void
    {
        // El remanente avanza independiente del status de la orden — no deben pisarse.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_ORDENADO,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/advance-remainder")
            ->assertStatus(200)
            ->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);
    }

    // ── SCRUM-208 (rediseño 2026-08-15) — confirm-pending-receipts ───────────

    /**
     * Root cause real del reporte de Daniela Amaya (2026-08-12): una recepción parcial deja la
     * línea "Por ingresar" y nada la confirma hasta que la orden ENTERA llega a "Recibido" — la
     * capacidad de confirmar manualmente ya existía (`confirmPendingForProduct`, acotada por
     * producto en TODAS las órdenes, solo alcanzable desde /inventario) pero no había forma de
     * hacerlo acotado a ESTA orden desde el contexto de Ver Órdenes/Logística. Este test prueba
     * exactamente el escenario de su rebote: 1 de 10 unidades recibidas, confirmar sin esperar a
     * que la orden llegue a Recibido.
     */
    public function test_confirm_pending_receipts_acredita_stock_sin_esperar_status_recibido(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $product = CatalogProduct::create([
            'reference' => 'PARCIAL-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL]);
        \App\Modules\Compras\Models\PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $warehouseId = (int) DB::table("{$slug}_bodega.warehouses")->value('id');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        // Recepción parcial: 1 de 10. La orden sigue en_transito_local (lejos de Recibido).
        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id'         => $order->id,
            'lines'                     => [['catalog_product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
            'pending_remainder_status'  => PurchaseOrder::REMAINDER_ORDENADO,
        ])->assertStatus(201);
        $receipt->assertJsonPath('lines.0.is_pending', true);

        $this->reMakeCurrent();
        $productBefore = CatalogProduct::find($product->id);
        $this->assertNotNull($productBefore);
        $this->assertEquals(0, $productBefore->stock_quantity, 'sin confirmar todavía, sin efecto de stock');

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/confirm-pending-receipts")
            ->assertStatus(200)
            ->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL); // el status de la orden NO cambia

        $this->reMakeCurrent();
        $productAfter = CatalogProduct::find($product->id);
        $this->assertNotNull($productAfter);
        $this->assertEquals(1, $productAfter->stock_quantity, 'la unidad recibida debe quedar disponible sin esperar a que la orden llegue a Recibido');
        $lineId = $receipt->json('lines.0.id');
        $this->assertNotNull(DB::table("{$slug}_compras.purchase_order_receipt_lines")->where('id', $lineId)->value('confirmed_at'));
    }

    public function test_confirm_pending_receipts_422_sin_lineas_pendientes(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/confirm-pending-receipts")
            ->assertStatus(422);
    }

    /**
     * Diferencia real con `confirmPendingForProduct` (acotado por producto en TODAS las
     * órdenes): este endpoint NO debe tocar líneas "Por ingresar" de OTRA orden, aunque compartan
     * el mismo producto.
     */
    public function test_confirm_pending_receipts_no_confirma_lineas_de_otra_orden(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $product = CatalogProduct::create([
            'reference' => 'MULTI-ORDEN-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $orderA = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL]);
        $orderB = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL]);
        foreach ([$orderA, $orderB] as $o) {
            \App\Modules\Compras\Models\PurchaseOrderLine::create([
                'purchase_order_id' => $o->id, 'catalog_product_id' => $product->id,
                'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
            ]);
        }
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $warehouseId = (int) DB::table("{$slug}_bodega.warehouses")->value('id');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $receiptA = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderA->id,
            'lines'             => [['catalog_product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
            'pending_remainder_status' => PurchaseOrder::REMAINDER_ORDENADO,
        ])->assertStatus(201);
        $receiptB = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $orderB->id,
            'lines'             => [['catalog_product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
            'pending_remainder_status' => PurchaseOrder::REMAINDER_ORDENADO,
        ])->assertStatus(201);

        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$orderA->id}/confirm-pending-receipts")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $productAfter = CatalogProduct::find($product->id);
        $this->assertNotNull($productAfter);
        $this->assertEquals(2, $productAfter->stock_quantity, 'solo la línea de la orden A debe confirmarse');
        $lineIdA = $receiptA->json('lines.0.id');
        $lineIdB = $receiptB->json('lines.0.id');
        $this->assertNotNull(DB::table("{$slug}_compras.purchase_order_receipt_lines")->where('id', $lineIdA)->value('confirmed_at'));
        $this->assertNull(DB::table("{$slug}_compras.purchase_order_receipt_lines")->where('id', $lineIdB)->value('confirmed_at'), 'la línea de la orden B no debe tocarse');
    }
}
