<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Events\ReturnReceived;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\CustomerReturnService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md.
 */
class CustomerReturnControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    /** REQ-414 — el buscador solo mira Order en por_despachar/despachado/entregado. */
    public function test_search_orders_solo_incluye_etapas_permitidas(): void
    {
        $entregado = $this->makeOrder(['order_number' => 'PED-2026-991001', 'stage' => Order::STAGE_ENTREGADO, 'customer_name' => 'Cliente Uno']);
        $this->makeOrder(['order_number' => 'PED-2026-991002', 'stage' => Order::STAGE_ASIGNADO, 'customer_name' => 'Cliente Dos']);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns/search-orders?q=991001');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.order_id', $entregado->id);

        $miss = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns/search-orders?q=991002');
        $miss->assertJsonCount(0, 'data');
    }

    /** REQ-415 — cada resultado ya trae los productos realmente entregados, para autocompletar. */
    public function test_search_orders_solo_incluye_items_con_qty_delivered(): void
    {
        $order = $this->makeOrder(
            ['order_number' => 'PED-2026-991010', 'stage' => Order::STAGE_DESPACHADO, 'customer_name' => 'Cliente Búsqueda'],
            ['qty_requested' => 10, 'qty_delivered' => 6, 'reference_snapshot' => 'REF-DEV-1'],
        );
        // segundo ítem sin entregar (qty_delivered = 0) — no debe aparecer en la respuesta.
        $order->items()->create(['qty_requested' => 4, 'qty_delivered' => 0, 'reference_snapshot' => 'REF-DEV-2', 'description_snapshot' => 'No entregado']);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns/search-orders?q=Cliente Búsqueda');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonCount(1, 'data.0.items');
        $response->assertJsonPath('data.0.items.0.reference', 'REF-DEV-1');
        $response->assertJsonPath('data.0.items.0.qty_delivered', 6);
    }

    /** REQ-419 — crea en pendiente. */
    public function test_store_crea_devolucion_pendiente(): void
    {
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_ENTREGADO],
            ['qty_requested' => 10, 'qty_delivered' => 8],
        );
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'danado_defectuoso'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'return_number']);

        $this->makeCurrentTenant();
        $customerReturn = CustomerReturn::find($response->json('id'));
        $this->assertNotNull($customerReturn);
        $this->assertSame(CustomerReturn::STATUS_PENDIENTE, $customerReturn->status);
        $this->assertStringStartsWith('DEV-', $customerReturn->return_number);
        $this->assertSame(1, $customerReturn->lines()->count());
    }

    /**
     * SCRUM-786 — la línea de devolución hereda `unit_price` del `OrderItem` (FK real, sin
     * ambigüedad) al momento de crearse, mismo criterio que `reference_snapshot`/
     * `description_snapshot`.
     */
    public function test_store_copia_unit_price_del_order_item(): void
    {
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_ENTREGADO],
            ['qty_requested' => 10, 'qty_delivered' => 8, 'unit_price' => 123.45],
        );
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'danado_defectuoso'],
            ],
        ]);
        $response->assertStatus(201);

        $this->makeCurrentTenant();
        $line = CustomerReturn::find($response->json('id'))->lines()->first();
        $this->assertSame(123.45, $line->unit_price);
    }

    /**
     * SCRUM-786 — pedidos creados antes del fix (`OrderItem.unit_price` sigue NULL, nunca
     * corrido el backfill) no deben reventar la creación de la devolución — la línea también
     * queda NULL, mismo comportamiento de siempre.
     */
    public function test_store_con_order_item_sin_unit_price_deja_la_linea_en_null(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 8]);
        $item = $order->items->first();
        $this->assertNull($item->unit_price);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'danado_defectuoso'],
            ],
        ]);
        $response->assertStatus(201);

        $this->makeCurrentTenant();
        $line = CustomerReturn::find($response->json('id'))->lines()->first();
        $this->assertNull($line->unit_price);
    }

    /** REQ-418 — "otra" persiste el texto libre embebido, mismo patrón que OrderItem. */
    public function test_store_con_motivo_otra_persiste_texto_libre(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 8]);
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 2, 'reason' => 'otra', 'reason_detail' => 'Motivo particular'],
            ],
        ]);
        $response->assertStatus(201);

        $this->makeCurrentTenant();
        $customerReturn = CustomerReturn::find($response->json('id'));
        $this->assertSame('otra:Motivo particular', $customerReturn->lines->first()->reason);

        // Hallazgo de Senior Reviewer 2026-07-26 — la API nunca debe exponer el string crudo
        // "otra:<texto>"; reason/reason_detail deben llegar separados al frontend.
        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/returns/{$customerReturn->id}")
            ->assertStatus(200);
        $detail->assertJsonPath('products.0.reason', 'otra');
        $detail->assertJsonPath('products.0.reason_detail', 'Motivo particular');
    }

    public function test_store_sin_productos_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO]);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [],
        ])->assertStatus(422);
    }

    /** REQ-417 RN2 — tope = order_item.qty_delivered. */
    public function test_store_cantidad_excede_lo_entregado_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 3]);
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 5, 'reason' => 'danado_defectuoso'],
            ],
        ]);

        $response->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertSame(0, CustomerReturn::count());
    }

    /**
     * REQ-417 RN2 (hallazgo Pre-QA 2026-07-26) — el tope es ACUMULADO entre devoluciones: sin
     * este chequeo, dos `CustomerReturn` distintas del mismo `order_item_id` podían, sumadas,
     * pedir más unidades de las que el cliente recibió (confirmado en vivo con la fixture
     * VR-9001/PERFIL-2M-VR: 4 solicitudes activas de 1 unidad ya existentes sobre un ítem con
     * qty_delivered=3, y el sistema todavía aceptaba una quinta de +3 unidades).
     */
    public function test_store_cantidad_acumulada_entre_devoluciones_excede_lo_entregado_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 3]);
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // primera devolución: pide 2 de las 3 entregadas — debe pasar.
        $first = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 2, 'reason' => 'danado_defectuoso'],
            ],
        ]);
        $first->assertStatus(201);

        // segunda devolución: pide 2 más (solo queda 1 disponible) — debe rechazarse, acumulado
        // excede lo entregado (2 + 2 = 4 > 3).
        $second = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Otro Contacto',
            'contact_phone' => '6000-5678',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 2, 'reason' => 'excedente'],
            ],
        ]);
        $second->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertSame(1, CustomerReturn::count());

        // una tercera pidiendo exactamente la 1 unidad restante SÍ debe pasar (no es un bloqueo
        // total del ítem, solo del excedente).
        $third = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Tercer Contacto',
            'contact_phone' => '6000-9999',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 1, 'reason' => 'excedente'],
            ],
        ]);
        $third->assertStatus(201);
    }

    /**
     * REQ-417 RN2 — una devolución RECHAZADA no debe contar contra el tope acumulado: el cliente
     * puede volver a intentar devolver las mismas unidades tras un rechazo.
     */
    public function test_store_devolucion_rechazada_no_cuenta_para_el_tope_acumulado(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 3]);
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $first = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'danado_defectuoso'],
            ],
        ]);
        $first->assertStatus(201);

        $this->makeCurrentTenant();
        CustomerReturn::find($first->json('id'))->update(['status' => CustomerReturn::STATUS_RECHAZADA]);

        $second = $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'contact_name' => 'Otro Contacto',
            'contact_phone' => '6000-5678',
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'excedente'],
            ],
        ]);
        $second->assertStatus(201);
    }

    /** REQ-416 — contacto (nombre + teléfono) obligatorio. */
    public function test_store_sin_contacto_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 8]);
        $item = $order->items->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/returns', [
            'order_id' => $order->id,
            'items' => [
                ['order_item_id' => $item->id, 'qty_requested' => 3, 'reason' => 'danado_defectuoso'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['contact_name', 'contact_phone']);
    }

    /** REQ-408 — habilita el paso siguiente del ciclo. */
    public function test_upload_signed_document_setea_timestamp(): void
    {
        $customerReturn = $this->makeCustomerReturn();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/signed-document", [
                'file' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $customerReturn->id);
        $this->assertNotNull($response->json('signed_document_uploaded_at'));

        $this->makeCurrentTenant();
        $this->assertNotNull(CustomerReturn::find($customerReturn->id)->signed_document_path);
    }

    /**
     * SCRUM-478 (rebote de Gerencia Test 2026-08-16) — subir el documento funcionaba pero no
     * había ninguna forma de verlo/descargarlo después. GET presigna la key ya subida.
     */
    public function test_signed_document_url_devuelve_presigned_url_del_archivo_ya_subido(): void
    {
        $customerReturn = $this->makeCustomerReturn();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)
            ->postJson("/api/bodega/returns/{$customerReturn->id}/signed-document", [
                'file' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
            ])->assertStatus(200);

        $response = $this->withHeaders($headers)
            ->getJson("/api/bodega/returns/{$customerReturn->id}/signed-document");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('url'));
    }

    /** SCRUM-478 — sin documento firmado todavía, 404 en vez de una URL rota. */
    public function test_signed_document_url_sin_documento_subido_retorna_404(): void
    {
        $customerReturn = $this->makeCustomerReturn();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/returns/{$customerReturn->id}/signed-document")
            ->assertStatus(404);
    }

    /** REQ-409 RN1 (precondición) — sin documento firmado no se puede confirmar recepción. */
    public function test_confirm_reception_sin_documento_firmado_retorna_422(): void
    {
        $customerReturn = $this->makeCustomerReturn();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $line = $customerReturn->lines->first();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/confirm-reception", [
                'destination_warehouse_id' => $warehouse->id,
                'lines' => [['customer_return_line_id' => $line->id, 'qty_received' => $line->qty_requested]],
            ])
            ->assertStatus(422);
    }

    /** REQ-409 RN1 — no se recibe más de lo entregado: el inventario no sube y la devolución sigue pendiente. */
    public function test_confirm_reception_no_acepta_mas_de_lo_entregado(): void
    {
        $product = $this->makeCatalogProduct();
        $customerReturn = $this->makeCustomerReturn(itemOverrides: ['catalog_product_id' => $product->id, 'qty_requested' => 3, 'qty_delivered' => 3]);
        $line = $customerReturn->lines->first();
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();
        $this->setStock($product, 10, 'Bodega Central');

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/signed-document", [
                'file' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
            ])->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/confirm-reception", [
                'destination_warehouse_id' => $warehouse->id,
                'lines' => [['customer_return_line_id' => $line->id, 'qty_received' => 4]],
            ])
            ->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertSame(CustomerReturn::STATUS_PENDIENTE, CustomerReturn::find($customerReturn->id)->status);
        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'), 'el stock no cambió');
    }

    /**
     * REQ-409/410 — cantidad REAL (puede diferir de lo solicitado) + suma al inventario vía
     * Kardex (TIPO_DEVOLUCION) + notifica (sella credit_note_notified_at) en el mismo momento.
     */
    public function test_confirm_reception_suma_inventario_con_cantidad_real_y_notifica(): void
    {
        Event::fake();

        $product = $this->makeCatalogProduct();
        $customerReturn = $this->makeCustomerReturn(itemOverrides: ['catalog_product_id' => $product->id, 'qty_requested' => 10, 'qty_delivered' => 8]);
        $line = $customerReturn->lines->first();
        $line->update(['qty_requested' => 5]);

        $warehouse = Warehouse::where('name', 'Bodega Central')->first();
        $this->setStock($product, 2, 'Bodega Central');

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // carga el documento firmado primero (precondición).
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/signed-document", [
                'file' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
            ])->assertStatus(200);

        // el operativo cuenta 4 unidades reales, distinto de las 5 solicitadas (RN1).
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/confirm-reception", [
                'destination_warehouse_id' => $warehouse->id,
                'lines' => [['customer_return_line_id' => $line->id, 'qty_received' => 4]],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO);

        $this->makeCurrentTenant();
        $fresh = CustomerReturn::with('lines')->find($customerReturn->id);
        $this->assertSame(CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO, $fresh->status);
        $this->assertNotNull($fresh->credit_note_notified_at);
        $this->assertNotNull($fresh->received_at);
        $this->assertSame($warehouse->id, $fresh->destination_warehouse_id);
        $this->assertSame(4, $fresh->lines->first()->qty_received);

        // stock sumado con la cantidad REAL (4), no la solicitada (5).
        $this->assertSame(6, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));

        $movement = InventoryMovement::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame(InventoryMovement::TIPO_DEVOLUCION, $movement->tipo);
        $this->assertSame(4, $movement->cantidad);

        Event::assertDispatched(ReturnReceived::class);
    }

    /** REQ-411 — potestad EXCLUSIVA de Bodega, sin aprobación de Gerencia. */
    public function test_reject_no_toca_inventario_y_no_requiere_aprobacion_de_mark(): void
    {
        $product = $this->makeCatalogProduct();
        $customerReturn = $this->makeCustomerReturn(itemOverrides: ['catalog_product_id' => $product->id, 'qty_requested' => 10, 'qty_delivered' => 8]);
        $warehouse = Warehouse::where('name', 'Bodega Central')->first();
        $this->setStock($product, 2, 'Bodega Central');

        // Solo bodega.write, SIN bodega.approve ni ComprasSettings::primary_approver_user_id —
        // a diferencia de Reubicación/Ajuste, rechazar una devolución no exige a Gerencia.
        $this->grantBodegaPermissions('vendedor@atlantic.test', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/reject", ['rejection_reason' => 'Cliente desistió']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', CustomerReturn::STATUS_RECHAZADA);

        $this->makeCurrentTenant();
        $this->assertSame(2, ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::where('catalog_product_id', $product->id)->count());
        $this->assertNull(CustomerReturn::find($customerReturn->id)->credit_note_notified_at);
    }

    public function test_reject_sin_motivo_retorna_422(): void
    {
        $customerReturn = $this->makeCustomerReturn();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/returns/{$customerReturn->id}/reject", [])
            ->assertStatus(422);
    }

    /** REQ-473/REQ-483 — bandeja con 5 chips de filtro. */
    public function test_index_filtra_por_status(): void
    {
        $this->makeCustomerReturn();
        $rejected = $this->makeCustomerReturn();
        $rejected->update(['status' => CustomerReturn::STATUS_RECHAZADA]);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $all = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns');
        $all->assertJsonCount(2, 'data');

        $filtered = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns?status=rechazada');
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('data.0.id', $rejected->id);
    }

    /** REQ-405 — historial cronológico reconstruido desde los timestamps de la fila. */
    public function test_show_incluye_historial_cronologico(): void
    {
        $customerReturn = $this->makeCustomerReturn();

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/returns/{$customerReturn->id}");

        $response->assertStatus(200);
        $history = $response->json('history');
        $this->assertIsArray($history);
        $this->assertSame('created', $history[0]['step']);
    }

    /**
     * Hallazgos de Senior Reviewer + Visual Reviewer 2026-07-26 — el mockup 3J muestra la
     * columna "Proyecto" en la bandeja; se propaga también a "Ver detalle" (formatDetail hace
     * spread de formatRow).
     */
    public function test_index_y_show_exponen_el_proyecto_del_pedido_origen(): void
    {
        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $order = $this->makeOrderWithPipelineCard(
            ownerId: $this->userIdByEmail('vendedor@atlantic.test'),
            orderOverrides: ['stage' => Order::STAGE_ENTREGADO],
            itemOverrides: ['qty_requested' => 5, 'qty_delivered' => 5],
        );
        $item = $order->items->first();
        $project = $order->pipelineCard->salesProject->name;

        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999),
            'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_PENDIENTE,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by' => $this->userIdByEmail('vendedor@atlantic.test'),
        ]);
        $customerReturn->lines()->create([
            'order_item_id' => $item->id, 'reference_snapshot' => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot, 'qty_requested' => 3, 'reason' => 'danado_defectuoso',
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $index = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns');
        $index->assertJsonPath('data.0.project', $project);

        $show = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/returns/{$customerReturn->id}");
        $show->assertJsonPath('project', $project);
    }

    /** Hallazgo de Visual Reviewer 2026-07-26 — "Ref. fábrica" ausente en Nueva Devolución. */
    public function test_search_orders_expone_factory_reference(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-PUB-DEV', 'factory_reference' => 'REF-FAB-DEV']);
        $order = $this->makeOrder(
            ['order_number' => 'PED-2026-991020', 'stage' => Order::STAGE_DESPACHADO, 'customer_name' => 'Cliente Fábrica'],
            ['qty_requested' => 5, 'qty_delivered' => 5, 'catalog_product_id' => $product->id],
        );

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/returns/search-orders?q=991020');
        $response->assertJsonPath('data.0.items.0.factory_reference', 'REF-FAB-DEV');
    }

    /**
     * SCRUM-865 punto 2 — `finalizeForCreditNote()` recibía el id de la nota de crédito generada
     * pero nunca lo persistía; el listado de Devoluciones no podía armar el enlace "Ver nota de
     * crédito". Test directo del servicio (no vía AdminCont) para no depender del round-trip HTTP
     * cross-módulo completo del registro de notas.
     */
    public function test_finalize_for_credit_note_persiste_status_y_credit_note_id(): void
    {
        $customerReturn = $this->makeCustomerReturn();
        $customerReturn->update(['status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO]);
        $actor = User::find($this->userIdByEmail('management@atlantic.test'));

        app(CustomerReturnService::class)->finalizeForCreditNote($customerReturn->id, 999, 'NC-999', $actor);

        $customerReturn->refresh();
        $this->assertSame(CustomerReturn::STATUS_FINALIZADO, $customerReturn->status);
        $this->assertSame(999, $customerReturn->credit_note_id);
        $this->assertNotNull($customerReturn->finalized_at);
    }

    /**
     * SCRUM-865 punto 2c — "Ver nota de crédito" en Bodega, gateado por `bodega.read` (no
     * `admin_contab.*`, un usuario de Bodega no lo tiene). Cubre el gate 404 cuando todavía no hay
     * nota vinculada; la generación real del PDF ya está cubierta en
     * `AdminContCreditNoteControllerTest::test_pdf_genera_el_documento_formal` (mismo
     * `CreditNoteService::show()` + `CreditNotePdfService::generate()`, solo cambia el controller
     * que los invoca).
     */
    public function test_credit_note_pdf_404_sin_nota_vinculada(): void
    {
        $customerReturn = $this->makeCustomerReturn();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/returns/{$customerReturn->id}/credit-note-pdf")
            ->assertStatus(404);
    }

    /**
     * SCRUM-865 punto 2 — el frontend decide mostrar "Ver nota de crédito" en la fila según
     * `credit_note_id` del listado; antes esa columna estaba siempre vacía. Confirma que el id
     * viaja en la respuesta de la bandeja una vez finalizada la devolución.
     */
    public function test_index_expone_credit_note_id_tras_finalizar(): void
    {
        $customerReturn = $this->makeCustomerReturn();
        $customerReturn->update(['status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO]);
        $actor = User::find($this->userIdByEmail('management@atlantic.test'));
        app(CustomerReturnService::class)->finalizeForCreditNote($customerReturn->id, 777, 'NC-777', $actor);

        $this->grantBodegaPermissions('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/returns')
            ->assertJsonPath('data.0.credit_note_id', 777);
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function makeCustomerReturn(array $itemOverrides = []): CustomerReturn
    {
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_ENTREGADO],
            ['qty_requested' => 10, 'qty_delivered' => 8, ...$itemOverrides],
        );
        $item = $order->items->first();
        $creatorId = $this->userIdByEmail('management@atlantic.test');

        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999),
            'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_PENDIENTE,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by' => $creatorId,
        ]);

        $customerReturn->lines()->create([
            'order_item_id' => $item->id,
            'reference_snapshot' => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot,
            'qty_requested' => min(3, $item->qty_delivered),
            'reason' => 'danado_defectuoso',
        ]);

        return $customerReturn->fresh('lines') ?? $customerReturn;
    }
}
