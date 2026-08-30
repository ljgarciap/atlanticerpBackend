<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307/308) — criterio de aceptación de REQ-308 tal cual está
 * en Jira: "Dado que una cotización pasa a Aprobado en Ventas & Diseño, cuando eso ocurre,
 * entonces aparece automáticamente una tarjeta nueva en Pedidos, en Asignado, con los datos
 * correctos y ya asignada al asistente correcto".
 */
class OrderCreationFromApprovedQuoteTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoEdit(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $permissions = array_merge(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'], $extra);
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCardInProposal(int $ownerId): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id, 'business_name' => 'Subcliente Test',
            'tax_id' => '155-0000-1-2026', 'delivery_address' => 'Calle 50, Ciudad de Panamá',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'proposal', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);

        $card->contacts()->create(['name' => 'Cliente Contacto', 'role' => 'client', 'phone' => '6000-1234']);
        $card->deliveryDates()->create(['date' => '2026-12-01']);
        $card->files()->create([
            'type' => 'approval_proof', 'file_name' => 'aprobacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $ownerId,
        ]);

        return $card;
    }

    private function makeGeneratedQuoteForCard(PipelineCard $card): Quote
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Lámpara colgante de prueba', 'description' => 'Lámpara colgante de prueba',
            'price_full' => 250, 'cost' => 150, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        $quote = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización Test', 'description' => 'Cotización Test', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-0001', 'generated_at' => now(),
        ]);

        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 3, 'unit_price' => 250, 'cost' => 150,
        ]);
        $part->items()->create([
            'catalog_product_id' => null, 'is_custom' => true,
            'reference' => 'CUSTOM-1', 'name' => 'Ítem personalizado de prueba', 'description' => 'Ítem personalizado de prueba',
            'quantity' => 2, 'unit_price' => 80,
        ]);

        return $quote;
    }

    public function test_aprobar_cotizacion_crea_pedido_en_bodega_asignado_al_asistente_correcto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();

        $order = Order::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($order, 'Se esperaba que se creara un Order automáticamente al aprobar la cotización.');
        $this->assertStringStartsWith('PED-', $order->order_number);
        $this->assertSame(Order::STAGE_ASIGNADO, $order->stage);
        $this->assertSame($assistantId, $order->assistant_id);
        $this->assertSame('Subcliente Test', $order->customer_name);
        $this->assertSame('Calle 50, Ciudad de Panamá', $order->delivery_address);
        $this->assertNotNull($order->customer_contact);
        $this->assertStringContainsString('Cliente Contacto', (string) $order->customer_contact);

        $this->assertSame(2, $order->items()->count());
        $catalogItem = $order->items()->whereNotNull('catalog_product_id')->first();
        $this->assertSame(3, $catalogItem->qty_requested);
        $this->assertSame('Lámpara colgante de prueba', $catalogItem->description_snapshot);
        // SCRUM-786 — price_type/discount_mode/global_discount_percent por defecto de la
        // cotización (public/line/0%) hacen que unit_price quede igual al unit_price tecleado en
        // el QuoteItem (250) — ver test aparte para el caso con descuento/tarifa real aplicados.
        $this->assertSame(250.0, $catalogItem->unit_price);
        $customItem = $order->items()->whereNull('catalog_product_id')->first();
        $this->assertSame(2, $customItem->qty_requested);
        $this->assertSame('Ítem personalizado de prueba', $customItem->description_snapshot);
        $this->assertSame(80.0, $customItem->unit_price);

        $history = OrderStageHistory::where('order_id', $order->id)->first();
        $this->assertNotNull($history);
        $this->assertNull($history->from_stage);
        $this->assertSame(Order::STAGE_ASIGNADO, $history->to_stage);
    }

    /**
     * SCRUM-786 — `unit_price` debe quedar en el precio REALMENTE resuelto para la cotización
     * (tipo de precio + descuento), no en el `unit_price` crudo tecleado en el `QuoteItem`. Precio
     * público 250, tipo "project" (descuento de proyecto por defecto 15%) → 212.50, más un
     * descuento global de línea del 10% → 191.25.
     */
    public function test_unit_price_del_pedido_refleja_tipo_de_precio_y_descuento_de_la_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');
        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);

        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Riel de iluminación de prueba', 'description' => 'Riel de iluminación de prueba',
            'price_full' => 250, 'cost' => 150, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $quote = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización Test', 'description' => 'Cotización Test', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-0002', 'generated_at' => now(),
            'price_type' => 'project', 'discount_mode' => 'global', 'global_discount_percent' => 10,
        ]);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 1, 'unit_price' => 250, 'cost' => 150,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200);

        $this->reMakeCurrent();

        $order = Order::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($order);
        $item = $order->items()->first();
        $this->assertSame(191.25, $item->unit_price);
    }

    /**
     * Ajuste 2026-07-22 (decisión de Luis sobre el gap del Batch A1): si falta el mapeo
     * vendedor->asistente, el pedido SE CREA IGUAL (sin `assistant_id`) en vez de perderse, y se
     * notifica al Jefe de Bodega (rol `lider_bodega` — Esteban Cardenas, sembrado por
     * `BusinessRoleUserSeeder` en todos los entornos, incl. testing) para triage manual.
     */
    public function test_aprobar_cotizacion_sin_mapeo_de_asistente_crea_pedido_sin_asignar_y_notifica_al_jefe_de_bodega(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $bodegaLeadId = $this->userIdByEmail('almacen@atlantic.com.pa');

        // Sin WarehouseAssistantMapping activo para este vendedor.
        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        // El cambio de etapa en Pipeline debe seguir funcionando aunque Bodega no pueda asignar
        // asistente — REQ-308 es un efecto secundario, no debe bloquear la aprobación real.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();

        $order = Order::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($order, 'El pedido debe crearse igual aunque falte el mapeo de asistente.');
        $this->assertNull($order->assistant_id);
        $this->assertSame(Order::STAGE_ASIGNADO, $order->stage);

        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_UNASSIGNED)
            ->where('source_ref', "order:{$order->id}")
            ->first();
        $this->assertNotNull($send, 'Se esperaba una notificación al Jefe de Bodega.');
        $this->assertContains($bodegaLeadId, $send->audience);
        $this->assertContains(NotificationSend::CHANNEL_IN_APP, $send->channels);

        $inAppNotification = Notification::where('notification_send_id', $send->id)
            ->where('user_id', $bodegaLeadId)
            ->first();
        $this->assertNotNull($inAppNotification, 'Se esperaba la notificación in-app para el Jefe de Bodega.');
        $this->assertStringContainsString($order->order_number, (string) $inAppNotification->content['subject']);
    }

    public function test_no_dispara_creacion_de_pedido_en_transicion_que_no_es_hacia_aprobado(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $master = MasterClient::create(['name' => 'Cliente Test 2']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente 2', 'tax_id' => '155-0000-1-2027']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test 2']);
        // RN5 (SCRUM-677/680) — design->quote vía el endpoint genérico ahora siempre bloquea (la
        // única vía es "Crear cotización"), así que esta card arranca ya en "quote" para poder
        // seguir probando una transición no-aprobada (quote->proposal) por este mismo endpoint.
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $sellerId, 'stage_changed_at' => Carbon::now(),
            'worked_area_m2' => 30,
        ]);
        $card->files()->create([
            'type' => 'signed_quote', 'file_name' => 'cotizacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $sellerId,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'proposal'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'proposal');

        $this->reMakeCurrent();

        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());
    }

    /**
     * Sugerencia no bloqueante de Senior Review (2026-07-22): el comentario de
     * `PipelineService::changeStage()` es explícito sobre que un no-op `approved -> approved`
     * no debe volver a disparar `QuoteApproved` — la lógica ya era correcta, pero no estaba
     * fijada con un test dedicado (el test de "no dispara" existente cubre `design -> quote`,
     * una transición distinta). Este test fija el caso real que el comentario describe.
     */
    public function test_reaprobar_una_tarjeta_ya_aprobada_no_crea_un_segundo_pedido(): void
    {
        // ventas_diseno.edit.approved: PipelineCard::RESTRICTED_EDIT_PERMISSION — necesario para
        // la segunda llamada, que ocurre con la tarjeta ya en etapa "approved".
        $this->grantVentasDisenoEdit('designer@atlantic.test', ['ventas_diseno.edit.approved']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        // Segunda llamada, mismo target stage — transición no-op según
        // PipelineCard::validationErrorForTransitionTo() (targetStage === stage actual).
        $this->withHeaders($headers)
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();

        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
    }

    /**
     * Blocker 1 de Senior Review (2026-07-22): un fallo dentro de
     * `OrderService::createFromApprovedQuote()` (acá, una violación real del índice único
     * `orders_pipeline_card_root_uq` — Blocker 2, simulando que ya existe un pedido raíz para
     * esta tarjeta por la razón que sea) NO debe propagarse hasta el request HTTP que aprueba la
     * cotización — `CreateOrderFromApprovedQuote::handle()` debe capturarlo y loggear, y la
     * aprobación en Ventas & Diseño debe seguir respondiendo 200. Se fuerza una excepción REAL
     * (no un mock) para probar el try/catch de punta a punta.
     */
    public function test_fallo_en_bodega_no_rompe_la_aprobacion_de_la_cotizacion(): void
    {
        Log::spy();

        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        // Pedido raíz preexistente para la misma tarjeta — cualquier intento de crear otro
        // (family_id null) choca con orders_pipeline_card_root_uq y OrderService::
        // createFromApprovedQuote() revienta con una QueryException real.
        Order::create([
            'order_number'     => 'PED-2026-9999',
            'pipeline_card_id' => $card->id,
            'order_type'       => Order::ORDER_TYPE_PEDIDO,
            'stage'            => Order::STAGE_ASIGNADO,
            'assistant_id'     => $assistantId,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();

        // La cotización quedó aprobada de verdad (el fallo de Bodega no bloqueó la venta)...
        $this->assertSame('approved', PipelineCard::find($card->id)->stage);
        // ...y no se creó un segundo Order (el intento falló y quedó solo el preexistente).
        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'bodega.order.creation-failed'
                && $context['pipeline_card_id'] === $card->id);
    }
}
