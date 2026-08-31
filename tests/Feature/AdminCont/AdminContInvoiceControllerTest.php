<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCustomerCreditBalance;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). */
class AdminContInvoiceControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    /** @return array{order: Order, quote: Quote} */
    private function makeFacturableOrder(int $ownerId, array $orderOverrides = [], array $cardOverrides = [], string|false|null $folio = false): array
    {
        // `false` = generar un folio único por llamada (default); `null` = a propósito sin
        // cotización, para los tests que validan el mensaje de "falta N° de Cotización".
        if ($folio === false) {
            $folio = 'COT-2026-'.static::uniqueTestNumber();
        }

        $master = MasterClient::create(['name' => 'Cliente Facturación '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Facturación '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Facturación '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 500.0, 'stage_changed_at' => Carbon::now(),
            ...$cardOverrides,
        ]);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'folio' => $folio, 'generated_at' => Carbon::now(),
        ]);

        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
            ...$orderOverrides,
        ]);
        $order->items()->create(['qty_requested' => 5, 'reference_snapshot' => 'REF-TEST', 'description_snapshot' => 'Ítem de prueba']);

        return ['order' => $order->fresh() ?? $order, 'quote' => $quote];
    }

    public function test_summary_cuenta_las_4_tarjetas_sin_ver_afectadas_por_filtros(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);

        // pendiente-entrega: stage anterior a por_despachar.
        Order::create(['order_number' => 'PED-A', 'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_PACKING, 'customer_name' => 'X']);

        // pendiente-facturar: por_despachar, sin factura.
        ['order' => $pendingOrder] = $this->makeFacturableOrder($ownerId);

        // facturada: por_despachar + AdminContInvoice.
        ['order' => $invoicedOrder] = $this->makeFacturableOrder($ownerId);
        AdminContInvoice::create(['numero' => 'F-0001', 'order_id' => $invoicedOrder->id, 'cliente' => 'X', 'monto' => 500]);

        // loginAs() hace un round-trip HTTP real — spatie/laravel-multitenancy olvida el tenant
        // "actual" al terminar cada request, así que los fixtures cross-schema (ventas_diseno) se
        // crean ANTES de loguear, no después (mismo criterio que AdminContBankAccountControllerTest).
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('pendientes_entrega'));
        $this->assertSame(1, $response->json('pendientes_facturar'));
        $this->assertSame(1, $response->json('facturadas_mes'));
        // assertEquals, no assertSame: json_encode() sin JSON_PRESERVE_ZERO_FRACTION serializa
        // un float entero (500.0) como "500" — al decodificar vuelve int, no es un bug del service.
        $this->assertEquals(500.0, $response->json('monto_facturado_mes'));
    }

    public function test_preview_ok_cuando_cotizacion_monto_y_cliente_estan_completos(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId, folio: 'COT-2026-0099');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $response->assertJsonFragment(['ok' => true, 'cotizacion_folio' => 'COT-2026-0099', 'monto' => 500.0]);
        $this->assertEquals(0.0, $response->json('results.0.saldo_disponible'));
    }

    public function test_preview_reporta_campos_faltantes_cuando_no_hay_cotizacion(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId, folio: null);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $this->assertSame(false, $response->json('results.0.ok'));
        $this->assertContains('N° de Cotización', $response->json('results.0.missing'));
    }

    public function test_preview_rechaza_una_entrega_que_todavia_no_llega_a_por_despachar(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        // cotización+monto+cliente completos, pero la entrega sigue en Bodega antes de por_despachar.
        ['order' => $order] = $this->makeFacturableOrder($ownerId, orderOverrides: ['stage' => Order::STAGE_PACKING]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $this->assertSame(false, $response->json('results.0.ok'));
        $this->assertContains('La entrega todavía no llega a la etapa "Por despachar" de Bodega', $response->json('results.0.missing'));
    }

    public function test_store_confirma_factura_asigna_folio_y_marca_invoice_ready_en_bodega(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        $this->assertSame('F-0001', $response->json('invoices.0.numero'));
        $this->assertSame([], $response->json('errors'));

        // No usar `$order->fresh()` acá: el postJson anterior es un round-trip HTTP real, y
        // spatie/laravel-multitenancy olvida el tenant "actual" al terminar cada request — un
        // Eloquent directo después de eso rompe con "relation orders does not exist" (mismo
        // gotcha ya resuelto arriba en `makeFacturableOrder`). Se verifica por SQL calificado en
        // vez de re-establecer el tenant, mismo criterio que `userIdByEmail()` en este archivo.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertTrue((bool) DB::table("{$slug}_bodega.orders")->where('id', $order->id)->value('invoice_ready'));
        $this->assertDatabaseHas("{$slug}_admin_contab.invoices", ['order_id' => $order->id, 'numero' => 'F-0001']);
    }

    public function test_store_rechaza_facturar_dos_veces_la_misma_entrega(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(422);

        $this->assertStringContainsString('ya tiene una factura', $response->json('errors.0.missing.0'));
    }

    public function test_store_rechaza_a_un_rol_no_autorizado(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [1]])
            ->assertStatus(403);
    }

    /** Batch 3 (SCRUM-519→523, REQ-442) — Escenario 1/2: aplica solo sobre la primera entrega. */
    public function test_preview_aplica_saldo_a_favor_solo_a_la_primera_entrega_del_lote(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $orderA] = $this->makeFacturableOrder($ownerId);
        $masterClientId = $orderA->pipelineCard->master_client_id;

        ['order' => $orderB] = $this->makeFacturableOrder($ownerId, cardOverrides: [
            'master_client_id' => $masterClientId,
            'sub_client_id' => $orderA->pipelineCard->sub_client_id,
        ]);

        AdminContCustomerCreditBalance::create(['master_client_id' => $masterClientId, 'saldo' => 200]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$orderA->id, $orderB->id]])
            ->assertStatus(200);

        $this->assertEquals(200.0, $response->json('results.0.saldo_disponible'));
        $this->assertEquals(200.0, $response->json('results.0.saldo_aplicable'));
        $this->assertEquals(300.0, $response->json('results.0.total_a_pagar'));
        $this->assertEquals(0.0, $response->json('results.1.saldo_disponible'));
        $this->assertEquals(0.0, $response->json('results.1.saldo_aplicable'));
        $this->assertTrue($response->json('aviso_saldo_solo_primera'));
    }

    /** REQ-442 Escenario 3 — sin saldo, ningún dato relacionado. */
    public function test_preview_sin_saldo_a_favor_devuelve_todo_en_cero(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $this->assertEquals(0.0, $response->json('results.0.saldo_disponible'));
        $this->assertEquals(0.0, $response->json('results.0.saldo_aplicable'));
        $this->assertEquals(500.0, $response->json('results.0.total_a_pagar'));
        $this->assertFalse($response->json('aviso_saldo_solo_primera'));
    }

    public function test_store_aplica_saldo_a_favor_y_lo_descuenta_del_balance(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $masterClientId = $order->pipelineCard->master_client_id;
        AdminContCustomerCreditBalance::create(['master_client_id' => $masterClientId, 'saldo' => 200]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        $this->assertEquals(200.0, $response->json('invoices.0.saldo_aplicado'));
        $this->assertEquals(300.0, $response->json('invoices.0.total_a_pagar'));

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.customer_credit_balances", ['master_client_id' => $masterClientId, 'saldo' => 0]);
    }

    /** REQ-445 — passthrough completo de filtros + RN4 (`total_en_tab` sin otros filtros). */
    public function test_index_filtra_por_cliente_y_calcula_total_en_tab_sin_otros_filtros(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $orderA] = $this->makeFacturableOrder($ownerId);
        $this->makeFacturableOrder($ownerId);
        $clienteA = $orderA->pipelineCard->subClient->business_name;
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana&cliente='.urlencode($clienteA))
            ->assertStatus(200);

        $this->assertCount(1, $response->json('rows'));
        $this->assertSame(2, $response->json('total_en_tab'));
    }

    /** REQ-446 — export respeta filtros (RN1), mismas columnas visibles (RN2). */
    public function test_export_excel_devuelve_archivo_con_filtros_aplicados(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/invoices/export?format=excel')
            ->assertStatus(200);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_export_rechaza_a_un_rol_no_autorizado(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/invoices/export?format=excel')
            ->assertStatus(403);
    }

    /** Batch 4 (SCRUM-524, REQ-447) Escenario 1 — sin factura, cotización completa, resto pendiente. */
    public function test_detail_muestra_trazabilidad_pendiente_cuando_no_hay_factura(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId, folio: 'COT-2026-0500');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);

        $response->assertJsonFragment(['estado' => 'pendiente-facturar', 'cotizacion_folio' => 'COT-2026-0500']);
        $this->assertFalse($response->json('guia_entrega.existe'));
        $this->assertNull($response->json('numero_factura'));
        $this->assertFalse($response->json('puede_registrar_cobro'));
    }

    /** REQ-447 Escenario 2 — facturada + guía de entrega ya subida por Bodega: trazabilidad completa. */
    public function test_detail_muestra_trazabilidad_completa_con_guia_de_entrega(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4321', 'moneda' => 'USD', 'activa' => true]);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        // El postJson anterior es un round-trip HTTP real — spatie/laravel-multitenancy olvida el
        // tenant "actual" al terminar cada request (mismo gotcha de `makeFacturableOrder` arriba).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $order->documents()->create(['tipo' => OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-test.pdf']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);

        $this->assertSame('facturada', $response->json('estado'));
        $this->assertTrue($response->json('guia_entrega.existe'));
        $this->assertTrue($response->json('puede_registrar_cobro'));

        // RN1 REQ-449 — bloque de datos de pago (gap real detectado en Senior Review: solo se
        // exponía "Pago a", faltaban cuenta número y responsable). Sin
        // `responsable_administracion_user_id` configurado (Batch 9, REQ-458 RN3), "responsable"
        // es null en vez de "quien generó la factura" — corregido porque Estado de Cuenta agrega
        // facturas de posibles creadores distintos y necesita un único valor consistente.
        $this->assertNull($response->json('responsable'));
        $this->assertNotNull($response->json('cuenta_pago'));
    }

    /** REQ-447 Escenario 3 — la guía nunca puede completarse antes que la factura, ni aunque el
     *  `OrderDocument` ya exista (no debería poder pasar por el gate de Bodega, se defiende igual). */
    public function test_detail_guia_de_entrega_nunca_completa_si_no_hay_factura_aunque_exista_el_documento(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $order->documents()->create(['tipo' => OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-fuera-de-orden.pdf']);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);

        $this->assertSame('pendiente-facturar', $response->json('estado'));
        $this->assertFalse($response->json('guia_entrega.existe'));
    }

    /** `detail()` expone `master_client_id` (para preseleccionar cliente al navegar a Cobros,
     *  REQ-451 RN3) y `puede_decidir_incobrable` (dependiente del actor, solo true para Mark y
     *  solo mientras hay una propuesta pendiente — gap real detectado en Senior Review, Frontend
     *  Dev ya construyó contra estos 2 campos sin coordinación en vivo con Backend Dev). */
    public function test_detail_expone_master_client_id_y_puede_decidir_incobrable_solo_para_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        $asFelixBeforePropuesta = $this->withHeaders($this->authHeaders($felix))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertNotNull($asFelixBeforePropuesta->json('master_client_id'));
        $this->assertFalse($asFelixBeforePropuesta->json('puede_decidir_incobrable'));

        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $asFelixPendiente = $this->withHeaders($this->authHeaders($felix))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertFalse($asFelixPendiente->json('puede_decidir_incobrable'));

        $mark = $this->loginAsMark();
        $asMarkPendiente = $this->withHeaders($this->authHeaders($mark))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertTrue($asMarkPendiente->json('puede_decidir_incobrable'));
    }

    /** REQ-448 Escenario 1 — propone, queda pendiente pero sigue en cartera cobrable. */
    public function test_mark_uncollectible_propone_y_queda_pendiente_en_cartera_cobrable(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'Cliente sin respuesta a 3 intentos de contacto'])
            ->assertStatus(200);

        $this->assertTrue($response->json('incobrable_pendiente'));
        $this->assertFalse($response->json('es_incobrable'));

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?tab=cobrable&view=plana')
            ->assertStatus(200);
        $this->assertContains($order->id, array_column($list->json('rows'), 'order_id'));

        // RN3 REQ-448 — "visible en la fila con una pastilla de estado distinta" (hallazgo MAJOR
        // de Pre-QA: `list()` nunca proyectaba `incobrable_pendiente`, la tabla principal mostraba
        // "Facturada" en vez de la pastilla de pendiente de aprobación, pese a que `detail()` sí
        // lo exponía correctamente).
        $row = collect($list->json('rows'))->firstWhere('order_id', $order->id);
        $this->assertTrue($row['incobrable_pendiente']);
        $this->assertSame('facturada', $row['estado']);
    }

    /** RN2 — motivo obligatorio. */
    public function test_mark_uncollectible_exige_motivo(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => ''])
            ->assertStatus(422);
    }

    /** RN1 — solo sobre entregas ya facturadas, nunca sobre "pendiente-facturar". */
    public function test_mark_uncollectible_rechaza_sobre_entrega_no_facturada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'x'])
            ->assertStatus(422);
    }

    /** RN5 — no se puede proponer un segundo cambio mientras el primero sigue sin resolver. */
    public function test_mark_uncollectible_rechaza_propuesta_duplicada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'primer intento']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'segundo intento'])
            ->assertStatus(422);
    }

    /** REQ-448 Escenario 2 — Mark aprueba: pasa a cartera incobrable y sale de la antigüedad. */
    public function test_uncollectible_decision_aprobar_pasa_a_incobrable_y_sale_del_aging(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $mark = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(200);

        $this->assertTrue($response->json('es_incobrable'));
        $this->assertFalse($response->json('incobrable_pendiente'));

        $aging = $this->withHeaders($this->authHeaders($mark))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(200);
        $totalFacturas = array_sum(array_column($aging->json('ranges'), 'cantidad'));
        $this->assertSame(0, $totalFacturas);
    }

    /** REQ-448 Escenario 3 — Mark rechaza: vuelve exactamente al estado normal. */
    public function test_uncollectible_decision_rechazar_regresa_al_estado_normal(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $mark = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => false])
            ->assertStatus(200);

        $this->assertFalse($response->json('es_incobrable'));
        $this->assertFalse($response->json('incobrable_pendiente'));
    }

    /** `primary_approver_only` — un rol distinto de Mark no puede decidir, aunque sea Gerencia genérica. */
    public function test_uncollectible_decision_rechaza_a_un_no_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $this->withHeaders($this->authHeaders($felix))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(403);
    }

    /** superadmin.all bypassa el gate exclusivo de Mark (decisión de Luis, 2026-08-22) — tanto la
     *  acción real (primary_approver_only) como el flag `puede_decidir_incobrable` que ve el frontend. */
    public function test_uncollectible_decision_permite_a_superadmin_aunque_no_sea_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $superadmin = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $detail = $this->withHeaders($this->authHeaders($superadmin))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertTrue($detail->json('puede_decidir_incobrable'));

        $this->withHeaders($this->authHeaders($superadmin))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(200);
    }

    /** REQ-450 RN2 — verifica el signo real de la aritmética de antigüedad (Senior Review: el test
     *  de abajo prueba la exclusión por incobrable, pero nunca ejercita una factura vencida que
     *  SIGUE siendo cobrable cayendo en el rango correcto — este test cierra ese gap). */
    public function test_aging_ubica_una_factura_vencida_y_cobrable_en_el_rango_correcto(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        // vencida hace 45 días (dentro del plazo de crédito + 45) — debe caer en el rango 31-60.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('order_id', $order->id)
            ->update(['created_at' => now()->subDays($diasCredito + 45)]);

        $response = $this->withHeaders($this->authHeaders($felix))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(200);

        $ranges = $response->json('ranges');
        $this->assertSame(0, $ranges[0]['cantidad'], '0-30: no debe contarla');
        $this->assertSame(1, $ranges[1]['cantidad'], '31-60: sí debe contarla, vencida hace 45 días');
        $this->assertSame(0, $ranges[2]['cantidad']);
        $this->assertSame(0, $ranges[3]['cantidad']);
        $this->assertEquals(500.0, $ranges[1]['monto']);
    }

    /** REQ-450 Escenario 1/2 — 4 rangos, y una factura aprobada como incobrable sale del cálculo. */
    public function test_aging_agrupa_por_rango_y_excluye_incobrables_aprobadas(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        // vigente (0-30): factura recién creada, todavía no vence.
        ['order' => $orderVigente] = $this->makeFacturableOrder($ownerId);
        // vencida +90: retrocedemos `created_at` para simular una factura vieja.
        ['order' => $orderVieja] = $this->makeFacturableOrder($ownerId);

        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$orderVigente->id]]);
        $this->withHeaders($this->authHeaders($felix))->postJson('/api/admin-contab/invoices', ['order_ids' => [$orderVieja->id]]);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('order_id', $orderVieja->id)
            ->update(['created_at' => now()->subDays($diasCredito + 95)]);

        // aprobamos como incobrable la vieja — no debe contar en ningún rango.
        $this->withHeaders($this->authHeaders($felix))
            ->postJson("/api/admin-contab/invoices/{$orderVieja->id}/mark-uncollectible", ['motivo' => 'x']);
        $mark = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/invoices/{$orderVieja->id}/uncollectible-decision", ['approve' => true]);

        $response = $this->withHeaders($this->authHeaders($felix))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(200);

        $ranges = $response->json('ranges');
        $this->assertCount(4, $ranges);
        $this->assertSame(1, $ranges[0]['cantidad']); // 0-30: la vigente
        $this->assertSame(0, $ranges[3]['cantidad']); // +90: la vieja quedó excluida por incobrable aprobada
    }

    /** REQ-449 — documento fiscal real, 200 + content-type PDF tanto vigente como anulada. */
    public function test_pdf_genera_documento_fiscal_para_factura_vigente_y_anulada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/invoices/{$order->id}/pdf")
            ->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // No hay flujo real todavía para anular (depende de Notas de Crédito, batch futuro) —
        // se simula el campo directamente, mismo criterio que el saldo a favor de Batch 3.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('order_id', $order->id)->update(['anulada_at' => now()]);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/invoices/{$order->id}/pdf")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertTrue((bool) DB::table("{$slug}_admin_contab.invoices")->where('order_id', $order->id)->value('anulada_at'));

        // Hallazgo MENOR de Pre-QA — `list()` tampoco reflejaba `anulada_at`, la fila hubiera
        // seguido mostrando "facturada" en la tabla principal para una factura ya anulada.
        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?tab=cobrable&view=plana')
            ->assertStatus(200);
        $row = collect($list->json('rows'))->firstWhere('order_id', $order->id);
        $this->assertSame('anulada', $row['estado']);
    }

    /** Rol sin acceso → 403 en los endpoints nuevos del batch. */
    public function test_detail_y_aging_rechazan_a_un_rol_no_autorizado(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices/1/detail')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(403);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }
}
