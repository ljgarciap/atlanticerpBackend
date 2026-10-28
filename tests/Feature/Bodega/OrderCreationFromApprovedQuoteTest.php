<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardProductDelivery;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

    /**
     * SCRUM-1128 — aprobar la cotización en Pipeline ya NO genera el pedido: lo genera la acción
     * explícita "Pasar a Pedido" (Gerencia). Todos los tests de este archivo que esperan un
     * pedido pasan por acá después del PATCH .../stage.
     */
    private function passToOrderAsManagement(int $cardId): TestResponse
    {
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$cardId}/pass-to-order");
        $this->reMakeCurrent();

        return $response;
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
        $sub = SubClient::create([
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
        // SCRUM-849.3 — el check de cotización firmada se movió de Cotización→Propuesta a
        // Propuesta→Aprobado; este fixture necesita el archivo para poder aprobar la tarjeta.
        $card->files()->create([
            'type' => 'signed_quote', 'file_name' => 'cotizacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $ownerId,
        ]);
        $card->files()->create([
            'type' => 'approval_proof', 'file_name' => 'aprobacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $ownerId,
        ]);

        return $card;
    }

    private function makeGeneratedQuoteForCard(PipelineCard $card): Quote
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto colgante de prueba', 'description' => 'Producto colgante de prueba',
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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count(), 'Aprobar en Pipeline no debe generar el pedido.');
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

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
        $this->assertSame('Producto colgante de prueba', $catalogItem->description_snapshot);
        // SCRUM-786 — price_type/discount_mode/global_discount_percent por defecto de la
        // cotización (public/line/0%) hacen que unit_price quede igual al unit_price tecleado en
        // el QuoteItem (250) — ver test aparte para el caso con descuento/tarifa real aplicados.
        $this->assertSame(250.0, $catalogItem->unit_price);
        $customItem = $order->items()->whereNull('catalog_product_id')->first();
        $this->assertSame(2, $customItem->qty_requested);
        $this->assertSame('Ítem personalizado de prueba', $customItem->description_snapshot);
        $this->assertSame(80.0, $customItem->unit_price);

        // SCRUM-929 — cada OrderItem queda vinculado al QuoteItem del que salió (incluido el
        // personalizado, sin catalog_product_id).
        $quoteItems = Quote::where('sales_project_id', $card->sales_project_id)->firstOrFail()
            ->parts->flatMap(fn (QuotePart $p) => $p->items);
        $this->assertSame($quoteItems->firstWhere('is_custom', false)->id, $catalogItem->quote_item_id);
        $this->assertSame($quoteItems->firstWhere('is_custom', true)->id, $customItem->quote_item_id);

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');
        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);

        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Riel de catalogación de prueba', 'description' => 'Riel de catalogación de prueba',
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

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200);

        $this->reMakeCurrent();
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

        $order = Order::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($order);
        $item = $order->items()->first();
        $this->assertSame(191.25, $item->unit_price);
    }

    /**
     * Ajuste 2026-07-22 (decisión de Luis sobre el gap del Batch A1): si falta el mapeo
     * vendedor->asistente, el pedido SE CREA IGUAL (sin `assistant_id`) en vez de perderse, y se
     * notifica al Jefe de Bodega (rol `lider_bodega` — Lider Bodega Test, sembrado por
     * `BusinessRoleUserSeeder` en todos los entornos, incl. testing) para triage manual.
     */
    public function test_aprobar_cotizacion_sin_mapeo_de_asistente_crea_pedido_sin_asignar_y_notifica_al_jefe_de_bodega(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $bodegaLeadId = $this->userIdByEmail('liderbodega@test.com');

        // Sin WarehouseAssistantMapping activo para este vendedor.
        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // El cambio de etapa en Pipeline debe seguir funcionando aunque Bodega no pueda asignar
        // asistente — REQ-308 es un efecto secundario, no debe bloquear la aprobación real.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

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
        // SCRUM-1167 — el clic de la campana lleva al pedido.
        $this->assertSame('/bodega/pedidos/status?search='.rawurlencode((string) $order->order_number), $send->content['cta_url'] ?? null);

        $inAppNotification = Notification::where('notification_send_id', $send->id)
            ->where('user_id', $bodegaLeadId)
            ->first();
        $this->assertNotNull($inAppNotification, 'Se esperaba la notificación in-app para el Jefe de Bodega.');
        $this->assertStringContainsString($order->order_number, (string) $inAppNotification->content['subject']);
    }

    public function test_no_dispara_creacion_de_pedido_en_transicion_que_no_es_hacia_aprobado(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $master = MasterClient::create(['name' => 'Cliente Test 2']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente 2', 'tax_id' => '155-0000-1-2027']);
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

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'proposal'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'proposal');

        $this->reMakeCurrent();

        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());
    }

    /**
     * SCRUM-1128 — re-aprobar una tarjeta ya aprobada (no-op `approved -> approved`) no genera nada,
     * y "Pasar a Pedido" solo completa UNA vez: la segunda llamada (doble clic, otra pestaña) recibe
     * 409 con el estado actual y no crea un segundo pedido.
     */
    public function test_reaprobar_y_repetir_pasar_a_pedido_no_crea_un_segundo_pedido(): void
    {
        // ventas_diseno.edit.approved: PipelineCard::RESTRICTED_EDIT_PERMISSION — necesario para
        // la segunda llamada, que ocurre con la tarjeta ya en etapa "approved".
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved')
            ->assertJsonPath('order_status', 'pending');

        // Segunda llamada, mismo target stage — transición no-op según
        // PipelineCard::validationErrorForTransitionTo() (targetStage === stage actual).
        $this->withHeaders($headers)
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');

        $this->reMakeCurrent();
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());

        $this->passToOrderAsManagement($card->id)
            ->assertStatus(200)
            ->assertJsonPath('order_status', 'approved')
            ->assertJsonPath('can_pass_to_order', false);

        $this->passToOrderAsManagement($card->id)
            ->assertStatus(409)
            ->assertJsonPath('data.order_status', 'approved');

        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
    }

    /**
     * SCRUM-1128 — si ya existe un pedido raíz para la tarjeta (por la razón que sea), "Pasar a
     * Pedido" responde 409, no crea un segundo pedido (el índice único `orders_pipeline_card_root_uq`
     * quedaría como red de seguridad) y no registra una aprobación.
     */
    public function test_tarjeta_con_pedido_preexistente_responde_409_y_no_registra_aprobacion(): void
    {
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');

        $card = $this->makeCardInProposal($sellerId);
        $card->update(['stage' => 'approved']);
        $this->makeGeneratedQuoteForCard($card);

        // Pedido raíz preexistente para la misma tarjeta (sin rastro de aprobación).
        Order::create([
            'order_number' => 'PED-2026-9999', 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_ASIGNADO, 'assistant_id' => $assistantId,
        ]);

        // Ya hay pedido => es el camino idempotente (409), no una segunda creación.
        $this->passToOrderAsManagement($card->id)->assertStatus(409);
        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
        $this->assertNull(PipelineCard::find($card->id)->order_approved_at);
    }

    // ------------------------------------------------------ SCRUM-875 — entregas parciales (familia) ----

    /** @param list<string> $fechas */
    private function approveCardWithPartialDates(array $fechas): PipelineCard
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');
        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $card->update(['delivery_type' => 'partial']);
        $card->deliveryDates()->delete();
        foreach ($fechas as $f) {
            $card->deliveryDates()->create(['date' => $f]);
        }
        $quote = $this->makeGeneratedQuoteForCard($card);

        // SCRUM-909 — el gate de aprobación ahora exige que TODO ítem de la cotización tenga
        // una fecha asignada cuando delivery_type es 'partial' con 2+ fechas, y cada tarjeta
        // de la familia solo recibe los ítems asignados a SU fecha (ya no la lista completa
        // en cada una). Distribución round-robin (ítem N -> fecha N % total_fechas) — con las
        // 2 fechas/2 ítems de makeGeneratedQuoteForCard() y 3 fechas definidas, la 3ra fecha
        // queda deliberadamente sin ningún ítem asignado, para ejercitar ese caso también.
        $dateIds = $card->deliveryDates()->orderBy('date')->pluck('id')->values();
        $items = $quote->parts->flatMap(fn (QuotePart $part) => $part->items)->values();
        foreach ($items as $i => $item) {
            PipelineCardProductDelivery::create([
                'pipeline_card_id' => $card->id, 'quote_item_id' => $item->id,
                'pipeline_card_delivery_date_id' => $dateIds[$i % $dateIds->count()],
            ]);
        }

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200);
        $this->reMakeCurrent();
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

        return $card;
    }

    public function test_entrega_parcial_con_3_fechas_llega_a_bodega_dividido_en_familia_de_3_tarjetas(): void
    {
        // Fechas fuera de orden a propósito — deben quedar ordenadas de la más cercana a la más lejana.
        $card = $this->approveCardWithPartialDates(['2026-12-20', '2026-12-05', '2026-12-31']);

        $orders = Order::where('pipeline_card_id', $card->id)->orderBy('sequence_in_family')->get();
        $this->assertCount(3, $orders);

        // Todas comparten el mismo order_number.
        $this->assertCount(1, $orders->pluck('order_number')->unique());
        $this->assertStringStartsWith('PED-', $orders->first()->order_number);

        // Raíz + hijas.
        $root = $orders->firstWhere('family_id', null);
        $this->assertNotNull($root);
        $this->assertSame(1, $root->sequence_in_family);
        foreach ($orders as $o) {
            $this->assertSame(3, $o->total_in_family);
            if ($o->id !== $root->id) {
                $this->assertSame($root->id, $o->family_id);
            }
        }

        // Ordenadas por fecha ascendente = secuencia ascendente.
        $this->assertSame(
            ['2026-12-05', '2026-12-20', '2026-12-31'],
            $orders->map(fn (Order $o) => $o->committed_delivery_date->toDateString())->all(),
        );

        // Todas nacen en Asignado, con su fila de historial.
        foreach ($orders as $o) {
            $this->assertSame(Order::STAGE_ASIGNADO, $o->stage);
            $this->assertSame(1, OrderStageHistory::where('order_id', $o->id)->count());
        }

        // SCRUM-909 — cada tarjeta lleva SOLO los ítems asignados a su fecha (ver distribución
        // round-robin de approveCardWithPartialDates): la más cercana (12-05) y la del medio
        // (12-20) llevan 1 ítem cada una, la más lejana (12-31) ninguno — nunca los 2 ítems
        // juntos en la misma tarjeta, eso solo pasa con Entrega Única.
        $byDate = $orders->keyBy(fn (Order $o) => $o->committed_delivery_date->toDateString());
        $this->assertSame(1, $byDate['2026-12-05']->items()->count());
        $this->assertSame(1, $byDate['2026-12-20']->items()->count());
        $this->assertSame(0, $byDate['2026-12-31']->items()->count());

        // SCRUM-929 — cada tarjeta enlaza EXACTAMENTE el QuoteItem que se le asignó a su fecha.
        foreach (['2026-12-05', '2026-12-20'] as $fecha) {
            $dateId = $card->deliveryDates()->whereDate('date', $fecha)->value('id');
            $assigned = PipelineCardProductDelivery::where('pipeline_card_id', $card->id)
                ->where('pipeline_card_delivery_date_id', $dateId)->value('quote_item_id');
            $this->assertNotNull($assigned);
            $this->assertSame((int) $assigned, $byDate[$fecha]->items()->first()->quote_item_id, "Tarjeta {$fecha}");
        }
    }

    /**
     * SCRUM-929 — el caso que motiva el vínculo: el MISMO producto en 2 partes de la cotización
     * (con cantidades y precios distintos) más un ítem personalizado. Mapear por
     * `catalog_product_id` los mezclaría; `quote_item_id` los mantiene separados.
     */
    public function test_producto_repetido_en_dos_partes_y_personalizado_se_vinculan_cada_uno_a_su_quote_item(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');
        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto repetida', 'description' => 'Producto repetida',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $quote = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización repetidos', 'description' => 'Cotización repetidos', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => now(),
        ]);
        $partA = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida A', 'position' => 1]);
        $partB = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida B', 'position' => 2]);
        $a = $partA->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 2, 'unit_price' => 100, 'cost' => 60,
        ]);
        $b = $partB->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 5, 'unit_price' => 90, 'cost' => 60,
        ]);
        $c = $partA->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'CUSTOM-REP',
            'name' => 'Personalizado repetidos', 'description' => 'Personalizado repetidos', 'quantity' => 1, 'unit_price' => 30,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200);
        $this->reMakeCurrent();
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

        $order = Order::where('pipeline_card_id', $card->id)->firstOrFail();
        $this->assertSame(3, $order->items()->count());
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id, $c->id],
            $order->items()->pluck('quote_item_id')->all(),
            'Cada ítem del pedido debe enlazar a SU quote item, sin colapsar el producto repetido.',
        );
        $this->assertSame(2, $order->items()->where('quote_item_id', $a->id)->value('qty_requested'));
        $this->assertSame(5, $order->items()->where('quote_item_id', $b->id)->value('qty_requested'));
        $this->assertSame(1, $order->items()->where('quote_item_id', $c->id)->value('qty_requested'));
    }

    public function test_entrega_parcial_con_1_sola_fecha_llega_como_una_sola_tarjeta_sin_familia(): void
    {
        $card = $this->approveCardWithPartialDates(['2026-12-10']);

        $orders = Order::where('pipeline_card_id', $card->id)->get();
        $this->assertCount(1, $orders);
        $this->assertNull($orders->first()->family_id);
        $this->assertNull($orders->first()->total_in_family);
        $this->assertSame('2026-12-10', $orders->first()->committed_delivery_date->toDateString());
    }

    public function test_entrega_unica_no_se_divide_aunque_haya_varias_fechas(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $sellerId = $this->userIdByEmail('vendedor@atlantic.test');
        $assistantId = $this->userIdByEmail('management@atlantic.test');
        WarehouseAssistantMapping::create([
            'seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true,
        ]);

        $card = $this->makeCardInProposal($sellerId);
        $card->update(['delivery_type' => 'single']);
        $card->deliveryDates()->delete();
        $card->deliveryDates()->create(['date' => '2026-12-15']);
        $card->deliveryDates()->create(['date' => '2027-01-15']);
        $this->makeGeneratedQuoteForCard($card);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200);
        $this->reMakeCurrent();
        $this->passToOrderAsManagement($card->id)->assertStatus(200);

        $orders = Order::where('pipeline_card_id', $card->id)->get();
        $this->assertCount(1, $orders);
        $this->assertNull($orders->first()->family_id);
        // Entrega única sigue tomando la fecha más próxima (comportamiento sin cambios).
        $this->assertSame('2026-12-15', $orders->first()->committed_delivery_date->toDateString());
    }
}
