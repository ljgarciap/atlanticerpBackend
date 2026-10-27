<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\InvoiceExportService;
use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Services\InvoiceService;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\AdminCont\Concerns\ReadsXlsxResponse;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/** Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). */
class AdminContInvoiceControllerTest extends FeatureTestCase
{
    use ReadsXlsxResponse;
    use StubsReceptorGuard;

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->stubReceptorGuard();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
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

    /**
     * SCRUM-876 — un pedido dividido en `$partes` entregas parciales: 1 tarjeta raíz + hijas, todas
     * con el mismo `pipeline_card_id` y `order_number`. Por default las hijas están en Por Despachar
     * y la raíz en el stage que se le pase (default Por Despachar).
     *
     * @return array{root: Order, children: list<Order>, quote: Quote}
     */
    private function makeFacturableFamily(int $ownerId, int $partes = 3, array $rootOverrides = []): array
    {
        ['order' => $root, 'quote' => $quote] = $this->makeFacturableOrder($ownerId, $rootOverrides);
        $root->update(['sequence_in_family' => 1, 'total_in_family' => $partes]);

        $children = [];
        for ($i = 2; $i <= $partes; $i++) {
            $children[] = Order::create([
                'order_number' => $root->order_number,
                'pipeline_card_id' => $root->pipeline_card_id,
                'order_type' => Order::ORDER_TYPE_PEDIDO,
                'stage' => Order::STAGE_POR_DESPACHAR,
                'customer_name' => $root->customer_name,
                'family_id' => $root->id,
                'sequence_in_family' => $i,
                'total_in_family' => $partes,
            ]);
        }

        return ['root' => $root->fresh() ?? $root, 'children' => $children, 'quote' => $quote];
    }

    /**
     * SCRUM-929 (reescrito; antes `test_876_familia_de_3_entregas_genera_una_sola_factura_por_el_total`,
     * que afirmaba "un pedido = una factura" para TODA familia). Este fixture es un pedido cuyos
     * productos NO están vinculados a la cotización (`quote_item_id` nulo — los pedidos anteriores a
     * SCRUM-929): por la degradación segura del ADR solo admite facturación de pedido completo, así
     * que se factura una sola vez por el total y la facturación por entrega se rechaza. La familia
     * CON vínculo (parcial por entrega o completo, a elección) se cubre en
     * `AdminContPartialInvoicingTest`.
     */
    public function test_929_familia_sin_vinculo_a_la_cotizacion_solo_admite_pedido_completo(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['root' => $root, 'children' => $children] = $this->makeFacturableFamily($ownerId, 3);
        $token = $this->loginAsFelix();

        // Por entrega: el backend lo rechaza aunque el request lo pida.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$children[0]->id], 'modo' => 'entrega'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.');

        // Completo (también es el default sin `modo`, compatible con el frontend anterior).
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$root->id], 'modo' => 'completo'])
            ->assertStatus(201);

        $this->assertCount(1, $response->json('invoices'));
        $this->assertSame(500.0, (float) $response->json('invoices.0.monto'));

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertSame(1, (int) DB::table("{$slug}_admin_contab.invoices")->where('order_id', $root->id)->where('tipo', 'completo')->count());
        // Ninguna entrega hija tiene una factura propia.
        foreach ($children as $child) {
            $this->assertSame(0, (int) DB::table("{$slug}_admin_contab.invoices")->where('order_id', $child->id)->count());
        }

        // Con el pedido completo ya emitido, cualquier otra factura — pedida desde una entrega
        // hermana, en cualquiera de los dos modos — se rechaza.
        foreach (['completo', 'entrega'] as $modo) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/admin-contab/invoices', ['order_ids' => [$children[0]->id], 'modo' => $modo])
                ->assertStatus(422)
                ->assertJsonPath('errors.0.missing.0', $modo === 'completo'
                    ? 'Este pedido ya tiene una factura generada.'
                    : 'Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.');
        }
        $this->assertSame(1, (int) DB::table("{$slug}_admin_contab.invoices")->where('family_root_id', $root->id)->whereNull('anulada_at')->count());
    }

    /**
     * SCRUM-929 (reescrito; antes `test_876_lista_pendientes_facturar_muestra_una_linea_por_pedido`).
     * Un pedido sin vínculo a la cotización conserva la forma de SCRUM-876: UNA fila anclada a la
     * raíz (aunque la raíz siga en picking), con `puede_facturar_completo` y sin opción por entrega.
     * La fila por entrega de un pedido vinculado se cubre en `AdminContPartialInvoicingTest`.
     */
    public function test_929_lista_pedido_sin_vinculo_muestra_una_fila_anclada_a_la_raiz(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        // Raíz todavía en Packing; solo las hijas llegaron a Por Despachar → el pedido igual aparece.
        ['root' => $root] = $this->makeFacturableFamily($ownerId, 3, ['stage' => Order::STAGE_PACKING]);
        $token = $this->loginAsFelix();

        $rows = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana')
            ->assertStatus(200)
            ->json('rows');

        $paraEstePedido = collect($rows)->where('order_number', $root->order_number);
        $this->assertCount(1, $paraEstePedido);
        $fila = $paraEstePedido->first();
        $this->assertSame($root->id, $fila['order_id']);
        $this->assertSame('pendiente-facturar', $fila['estado']);
        $this->assertCount(3, $fila['entregas_del_pedido']);
        $this->assertFalse($fila['facturacion_por_entrega']);
        $this->assertTrue($fila['puede_facturar_completo']);
        $this->assertFalse($fila['puede_facturar_entrega']);
        $this->assertEquals(500.0, $fila['restante']);
    }

    /** SCRUM-929 (ajustado; antes `test_876_summary_cuenta_la_familia_como_un_solo_pedido`): un pedido que solo admite completo cuenta 1. */
    public function test_929_summary_cuenta_como_un_solo_pendiente_a_la_familia_que_solo_admite_completo(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $this->makeFacturableFamily($ownerId, 4, ['stage' => Order::STAGE_PACKING]);
        $token = $this->loginAsFelix();

        $summary = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices/summary')
            ->assertStatus(200)
            ->json();

        $this->assertSame(1, $summary['pendientes_facturar']);
        // La raíz en Packing no cuenta además como "pendiente de entrega" (el pedido ya está en
        // pendiente-facturar porque una entrega llegó a Por Despachar).
        $this->assertSame(0, $summary['pendientes_entrega']);
    }

    public function test_summary_cuenta_las_4_tarjetas_sin_ver_afectadas_por_filtros(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        // cotización+monto+cliente completos, pero la entrega sigue en Bodega antes de por_despachar.
        ['order' => $order] = $this->makeFacturableOrder($ownerId, orderOverrides: ['stage' => Order::STAGE_PACKING]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $this->assertSame(false, $response->json('results.0.ok'));
        // SCRUM-876 — el mensaje pasa a hablar del PEDIDO (familia), no de "la entrega".
        $this->assertContains('Ninguna entrega del pedido llegó a la etapa "Por despachar" de Bodega', $response->json('results.0.missing'));
    }

    public function test_store_confirma_factura_asigna_folio_y_bodega_ve_la_factura(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        $this->assertSame('F-0001', $response->json('invoices.0.numero'));
        $this->assertSame([], $response->json('errors'));

        // SCRUM-876 — `orders.invoice_ready` dejó de usarse; la factura se asocia a la tarjeta y
        // el estado se calcula en vivo (ver OrderInvoiceControllerTest para el lado de Bodega).
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.invoices", ['order_id' => $order->id, 'numero' => 'F-0001']);
    }

    public function test_store_rechaza_facturar_dos_veces_la_misma_entrega(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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

    /**
     * SCRUM-902 §4.3 — retirado el saldo a favor de Facturación por completo (antes Batch 3,
     * SCRUM-519→523, REQ-442): el preview ya no incluye ningún campo de saldo, ninguna entrega se
     * ve afectada por saldo a favor en este paso.
     */
    public function test_preview_ya_no_incluye_ningun_campo_de_saldo_a_favor(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices/preview', ['order_ids' => [$order->id]])
            ->assertStatus(200);

        $response->assertJsonMissingPath('results.0.saldo_disponible');
        $response->assertJsonMissingPath('results.0.saldo_aplicable');
        $response->assertJsonMissingPath('results.0.total_a_pagar');
        $response->assertJsonMissingPath('aviso_saldo_solo_primera');
        $this->assertEquals(500.0, $response->json('results.0.monto'));
    }

    /**
     * SCRUM-902 §4.3 — una factura SIEMPRE se genera por su monto completo, sin ninguna resta,
     * incluso cuando el proyecto tiene un abono de nivel Proyecto confirmado esperando factura:
     * el abono se re-asocia a la factura recién creada (queda a nivel Factura desde ahí), pero la
     * factura en sí no cambia.
     */
    public function test_store_genera_la_factura_completa_y_reasocia_abono_de_proyecto(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $masterClientId = $order->pipelineCard->master_client_id;
        $salesProjectId = $order->pipelineCard->sales_project_id;
        $bankAccount = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $abono = AdminContAbono::create([
            'master_client_id' => $masterClientId,
            'sales_project_id' => $salesProjectId,
            'monto_original' => 200,
            'monto_usado' => 0,
            'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $bankAccount->id,
            'referencia' => 'TRF-ABONO-1',
            'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL),
            'fecha_registro' => now(),
            'fecha_confirmacion' => now(),
        ]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        // La factura se genera por su monto completo, sin ninguna resta.
        $this->assertEquals(500.0, $response->json('invoices.0.monto'));
        $this->assertEquals(0.0, $response->json('invoices.0.saldo_aplicado'));
        $this->assertEquals(500.0, $response->json('invoices.0.total_a_pagar'));

        $invoiceId = $response->json('invoices.0.id');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $abono = AdminContAbono::findOrFail($abono->id);
        $this->assertEquals($invoiceId, $abono->invoice_id);
        $this->assertSame('factura', $abono->nivelAsociacion());
    }

    /** REQ-445 — passthrough completo de filtros + RN4 (`total_en_tab` sin otros filtros). */
    public function test_index_filtra_por_cliente_y_calcula_total_en_tab_sin_otros_filtros(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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

    // ── SCRUM-939 — formato de moneda (Monto como celda numérica, no texto) ────────────────

    public function test_939_excel_escribe_monto_como_celda_numerica_con_formato_de_moneda(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $expectedMonto = (float) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana')
            ->assertStatus(200)
            ->json('rows.0.monto');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/invoices/export?format=excel')
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();

        $this->assertSame(
            ['Entrega / Hito', 'Cliente', 'Vendedor', 'N° Factura', 'N° Cotización', 'Monto', 'Estado'],
            $sheet->toArray()[0],
        );
        $this->assertMoneyCell($sheet, 'F2', $expectedMonto);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4321', 'numero_cuenta_completo' => '03-01-01-074321-4', 'moneda' => 'USD', 'activa' => true]);
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
        // exponía "Pago a", faltaban cuenta número y responsable). "responsable" es un único valor
        // consistente tomado de `responsable_administracion_user_id` (Batch 9, REQ-458 RN3), no
        // "quien generó la factura" — desde SCRUM-857 ese campo viene sembrado (Contabilidad Test) por
        // AdminContFiscalIdentitySeeder; antes era null porque nadie llenaba Configuración Fiscal.
        $this->assertSame('Contabilidad Test', $response->json('responsable'));
        $this->assertNotNull($response->json('cuenta_pago'));
    }

    /** SCRUM-943 — el detalle expone el estado real de Facturación Electrónica, nunca oculto. */
    public function test_detail_expone_el_estado_de_facturacion_electronica(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $invoice = AdminContInvoice::forOrder($order)->whereNull('anulada_at')->firstOrFail();
        $invoice->forceFill([
            'fe_estado' => 'certificado', 'cufe' => 'FE0120000...test', 'qr_content' => 'https://qr.test',
            'pac_provider_usado' => 'msl',
        ])->save();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);

        $this->assertSame('certificado', $response->json('fe_estado'));
        $this->assertSame('FE0120000...test', $response->json('cufe'));
        $this->assertSame('https://qr.test', $response->json('qr_content'));
        $this->assertSame('msl', $response->json('pac_provider_usado'));
    }

    /** SCRUM-858 — `puede_registrar_cobro` solo miraba `anulada_at`/`es_incobrable`, nunca si la
     *  factura ya estaba cobrada (`saldo_aplicado`, que solo se incrementa con pagos confirmados,
     *  ver `ClientCollectionReportService`) — el botón "Registrar cobro" quedaba visible para
     *  siempre. Un saldo parcial (no cubre el 100%) debe seguir permitiendo registrar otro cobro. */
    public function test_puede_registrar_cobro_pasa_a_falso_solo_cuando_el_saldo_queda_cubierto(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $invoice = AdminContInvoice::where('order_id', $order->id)->firstOrFail();

        // Pago parcial (simula un cobro confirmado que no cubre el total) — sigue pudiendo cobrarse.
        $invoice->update(['saldo_aplicado' => round($invoice->monto / 2, 2)]);
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('puede_registrar_cobro', true);

        // Saldo completo (cobro confirmado que cubre el 100%) — ya no debe poder registrarse otro.
        // El GET anterior es otro round-trip HTTP real — reafirmar el tenant "actual" (mismo gotcha).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $invoice->update(['saldo_aplicado' => $invoice->monto]);
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('puede_registrar_cobro', false);
    }

    /** SCRUM-864 — "Marcar como incobrable" no tiene sentido de negocio sobre una factura ya
     *  cobrada. Mismo criterio de "no pagada" que `puede_registrar_cobro` (saldo_aplicado < monto). */
    public function test_puede_marcar_incobrable_pasa_a_falso_cuando_la_factura_ya_esta_pagada(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $invoice = AdminContInvoice::where('order_id', $order->id)->firstOrFail();

        // Sin cobro todavía — la opción debe estar disponible.
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('puede_marcar_incobrable', true);

        // Saldo completo (cobro confirmado que cubre el 100%) — ya no puede marcarse como incobrable.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $invoice->update(['saldo_aplicado' => $invoice->monto]);
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('puede_marcar_incobrable', false);
    }

    /** REQ-447 Escenario 3 — la guía nunca puede completarse antes que la factura, ni aunque el
     *  `OrderDocument` ya exista (no debería poder pasar por el gate de Bodega, se defiende igual). */
    public function test_detail_guia_de_entrega_nunca_completa_si_no_hay_factura_aunque_exista_el_documento(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
     *  REQ-451 RN3) y `puede_decidir_incobrable` (dependiente del actor, solo true para Gerencia y
     *  solo mientras hay una propuesta pendiente — gap real detectado en Senior Review, Frontend
     *  Dev ya construyó contra estos 2 campos sin coordinación en vivo con Backend Dev). */
    public function test_detail_expone_master_client_id_y_puede_decidir_incobrable_solo_para_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        $asFelixBeforePropuesta = $this->withHeaders($this->authHeaders($contabilidad))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertNotNull($asFelixBeforePropuesta->json('master_client_id'));
        $this->assertFalse($asFelixBeforePropuesta->json('puede_decidir_incobrable'));

        $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $asFelixPendiente = $this->withHeaders($this->authHeaders($contabilidad))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertFalse($asFelixPendiente->json('puede_decidir_incobrable'));

        $approver = $this->loginAsMark();
        $asMarkPendiente = $this->withHeaders($this->authHeaders($approver))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertTrue($asMarkPendiente->json('puede_decidir_incobrable'));
    }

    /** Batch endurecimiento FE/MSL — el motivo textual del rechazo del PAC solo lo ve Gerencia/superadmin. */
    public function test_detail_expone_fe_detalle_solo_a_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContInvoice::forOrder($order)->firstOrFail()
            ->forceFill(['fe_estado' => 'rechazado', 'fe_detalle' => '9026 - Falló la validación'])->save();

        $asFelix = $this->withHeaders($this->authHeaders($contabilidad))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertNull($asFelix->json('fe_detalle'));
        $this->assertFalse($asFelix->json('puede_ver_fe_transmisiones'));

        $asMark = $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")
            ->assertStatus(200);
        $this->assertSame('9026 - Falló la validación', $asMark->json('fe_detalle'));
        $this->assertTrue($asMark->json('puede_ver_fe_transmisiones'));
    }

    /** Blocker de la Senior Review: `markUncollectible()` devuelve el modelo Eloquent; `fe_detalle`
     *  (`$hidden`) no debe filtrarse a Contabilidad por esa vía. */
    public function test_mark_uncollectible_no_filtra_fe_detalle_al_actor(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContInvoice::forOrder($order)->firstOrFail()
            ->forceFill(['fe_estado' => 'rechazado', 'fe_detalle' => '9026 - secreto'])->save();

        $response = $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta'])
            ->assertStatus(200);

        $this->assertArrayNotHasKey('fe_detalle', $response->json() ?? []);
        $this->assertStringNotContainsString('9026 - secreto', $response->getContent() ?: '');
    }

    /** REQ-448 Escenario 1 — propone, queda pendiente pero sigue en cartera cobrable. */
    public function test_mark_uncollectible_propone_y_queda_pendiente_en_cartera_cobrable(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'x'])
            ->assertStatus(422);
    }

    /** RN5 — no se puede proponer un segundo cambio mientras el primero sigue sin resolver. */
    public function test_mark_uncollectible_rechaza_propuesta_duplicada(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'primer intento']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'segundo intento'])
            ->assertStatus(422);
    }

    /** REQ-448 Escenario 2 — Gerencia aprueba: pasa a cartera incobrable y sale de la antigüedad. */
    public function test_uncollectible_decision_aprobar_pasa_a_incobrable_y_sale_del_aging(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $approver = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($approver))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(200);

        $this->assertTrue($response->json('es_incobrable'));
        $this->assertFalse($response->json('incobrable_pendiente'));

        $aging = $this->withHeaders($this->authHeaders($approver))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(200);
        $totalFacturas = array_sum(array_column($aging->json('ranges'), 'cantidad'));
        $this->assertSame(0, $totalFacturas);
    }

    /** REQ-448 Escenario 3 — Gerencia rechaza: vuelve exactamente al estado normal. */
    public function test_uncollectible_decision_rechazar_regresa_al_estado_normal(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $approver = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($approver))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => false])
            ->assertStatus(200);

        $this->assertFalse($response->json('es_incobrable'));
        $this->assertFalse($response->json('incobrable_pendiente'));
    }

    /** `primary_approver_only` — un rol distinto de Gerencia no puede decidir, aunque sea Gerencia genérica. */
    public function test_uncollectible_decision_rechaza_a_un_no_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$order->id}/mark-uncollectible", ['motivo' => 'sin respuesta']);

        $this->withHeaders($this->authHeaders($contabilidad))
            ->putJson("/api/admin-contab/invoices/{$order->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(403);
    }

    /** superadmin.all bypassa el gate exclusivo de Gerencia (decisión de Luis, 2026-08-22) — tanto la
     *  acción real (primary_approver_only) como el flag `puede_decidir_incobrable` que ve el frontend. */
    public function test_uncollectible_decision_permite_a_superadmin_aunque_no_sea_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        $this->withHeaders($this->authHeaders($contabilidad))
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);

        // vencida hace 45 días (dentro del plazo de crédito + 45) — debe caer en el rango 31-60.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('order_id', $order->id)
            ->update(['created_at' => now()->subDays($diasCredito + 45)]);

        $response = $this->withHeaders($this->authHeaders($contabilidad))
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
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        // vigente (0-30): factura recién creada, todavía no vence.
        ['order' => $orderVigente] = $this->makeFacturableOrder($ownerId);
        // vencida +90: retrocedemos `created_at` para simular una factura vieja.
        ['order' => $orderVieja] = $this->makeFacturableOrder($ownerId);

        $contabilidad = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$orderVigente->id]]);
        $this->withHeaders($this->authHeaders($contabilidad))->postJson('/api/admin-contab/invoices', ['order_ids' => [$orderVieja->id]]);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('order_id', $orderVieja->id)
            ->update(['created_at' => now()->subDays($diasCredito + 95)]);

        // aprobamos como incobrable la vieja — no debe contar en ningún rango.
        $this->withHeaders($this->authHeaders($contabilidad))
            ->postJson("/api/admin-contab/invoices/{$orderVieja->id}/mark-uncollectible", ['motivo' => 'x']);
        $approver = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($approver))
            ->putJson("/api/admin-contab/invoices/{$orderVieja->id}/uncollectible-decision", ['approve' => true]);

        $response = $this->withHeaders($this->authHeaders($contabilidad))
            ->getJson('/api/admin-contab/invoices/aging')
            ->assertStatus(200);

        $ranges = $response->json('ranges');
        $this->assertCount(4, $ranges);
        $this->assertSame(1, $ranges[0]['cantidad']); // 0-30: la vigente
        $this->assertSame(0, $ranges[3]['cantidad']); // +90: la vieja quedó excluida por incobrable aprobada
    }

    /** SCRUM-857/858 — con `dv` vacío (el valor real), la línea de RUC no debe quedar con un
     *  guion colgante ("RUC 1838853-1-711641-"), y el encabezado no debe repetir la marca. */
    public function test_pdf_de_factura_no_deja_guion_colgante_en_el_ruc_sin_dv(): void
    {
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'ATLANTIC USA CORP', 'ruc' => '1838853-1-711641', 'dv' => '',
        ]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $html = app(InvoicePdfService::class)
            ->viewData(['es_anulada' => false, 'cliente' => 'Cliente Test', 'monto' => 100]);
        $html = view('admin-cont.invoice-pdf', $html)->render();

        $this->assertStringContainsString('RUC 1838853-1-711641', $html);
        $this->assertStringNotContainsString('RUC 1838853-1-711641-<', $html);
        $this->assertStringNotContainsString('RUC 1838853-1-711641-<br', $html);
        // SCRUM-893 — la marca ahora viene del encabezado real compartido (`pdf.partials.header`,
        // mismo markup que el resto de los descargables). Una sola vez "ATLANTIC" a secas
        // (marca) + el nombre legal completo aparte ("ATLANTIC USA CORP") — sin dos líneas de
        // marca idénticas.
        $this->assertStringContainsString('brand-name">ATLANTIC</div>', $html);
    }

    /** SCRUM-928 — con DV cargado, el PDF de la Factura imprime `RUC {ruc}-{dv}`; el resto no cambia. */
    public function test_928_pdf_de_factura_muestra_el_dv_junto_al_ruc(): void
    {
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'ATLANTIC USA CORP', 'ruc' => '1838853-1-711641', 'dv' => '81',
        ]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $data = app(InvoicePdfService::class)
            ->viewData(['es_anulada' => false, 'cliente' => 'Cliente Test', 'monto' => 100]);
        $html = view('admin-cont.invoice-pdf', $data)->render();

        $this->assertStringContainsString('RUC 1838853-1-711641-81', $html);
    }

    /** SCRUM-928 (D-6) — sin número completo, "Cuenta número" muestra "—", nunca `****XXXX`. */
    public function test_928_pdf_de_factura_no_enmascara_la_cuenta_sin_numero_completo(): void
    {
        AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4554', 'activa' => true,
        ]);
        $pago = InvoiceService::paymentBlock();
        $this->assertNull($pago['cuenta_pago']);

        $data = app(InvoicePdfService::class)
            ->viewData(['es_anulada' => false, 'cliente' => 'Cliente Test', 'monto' => 100, 'cuenta_pago' => $pago['cuenta_pago']]);
        $html = view('admin-cont.invoice-pdf', $data)->render();

        $this->assertStringContainsString('Cuenta número: —', $html);
        $this->assertStringNotContainsString('****', $html);
    }

    /** SCRUM-928 — con número completo, la factura lo muestra íntegro junto al banco. */
    public function test_928_pdf_de_factura_muestra_la_cuenta_completa_cuando_esta_cargada(): void
    {
        AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4554',
            'numero_cuenta_completo' => '03-01-01-074554-4', 'activa' => true,
        ]);
        $pago = InvoiceService::paymentBlock();

        $data = app(InvoicePdfService::class)
            ->viewData(['es_anulada' => false, 'cliente' => 'Cliente Test', 'monto' => 100, 'cuenta_pago' => $pago['cuenta_pago']]);
        $html = view('admin-cont.invoice-pdf', $data)->render();

        $this->assertStringContainsString('Cuenta número: Banco Demo A 03-01-01-074554-4', $html);
    }

    /** REQ-449 — documento fiscal real, 200 + content-type PDF tanto vigente como anulada. */
    public function test_pdf_genera_documento_fiscal_para_factura_vigente_y_anulada(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
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

    /** SCRUM-952 — RUC, vendedor y fecha de vencimiento nuevos en el PDF, con la fuente real de cada uno. */
    public function test_952_pdf_de_factura_incluye_ruc_vendedor_y_fecha_de_vencimiento(): void
    {
        AdminContFiscalSettings::current()->update(['dias_credito_factura' => 30]);
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        // Mismo patrón que los tests hermanos de arriba (ver test_928_*): el PDF de dompdf es
        // binario comprimido, no texto buscable — se verifica el HTML fuente vía InvoicePdfService,
        // no el stream() del endpoint.
        $detail = app(InvoiceService::class)->detail($order->id);
        $data = app(InvoicePdfService::class)->viewData($detail);
        $html = view('admin-cont.invoice-pdf', $data)->render();

        $subClientTax = Order::findOrFail($order->id)->pipelineCard?->subClient?->tax_id;
        $vencimiento = Carbon::parse($detail['fecha_factura'])->addDays(30)->toDateString();

        $this->assertStringContainsString("RUC {$subClientTax}", $html);
        $this->assertStringContainsString('Vendedor:', $html);
        $this->assertStringContainsString("Fecha de vencimiento: {$vencimiento}", $html);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/invoices/{$order->id}/pdf")
            ->assertStatus(200);
    }

    /** SCRUM-952 — sin RUC de cliente, la factura NO se genera (bloqueo explícito, no PDF a medias). */
    public function test_952_pdf_bloquea_generacion_si_el_cliente_no_tiene_ruc(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $subClient = Order::findOrFail($order->id)->pipelineCard?->subClient;
        if ($subClient === null) {
            $this->fail('Fixture inconsistente: subCliente no encontrado.');
        }
        // SCRUM-1140 — una factura con receptor congelado ya no depende del cliente actual; este test cubre la factura
        // anterior al cambio (sin receptor congelado), que sigue leyendo el cliente en vivo.
        AdminContInvoice::forOrder($order->id)->update(['receptor_tipo' => null, 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => null, 'receptor_validado_at' => null]);
        $subClient->update(['tax_id' => '']);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/invoices/{$order->id}/pdf")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede generar la factura: falta el RUC del cliente.']);
    }

    /**
     * SCRUM-1003 (D2) — Consumidor Final: el PDF interno NO exige RUC, conserva el NOMBRE REAL del cliente y lleva una
     * leyenda para que no se presente como el documento fiscal (el CAFE lo emite el PAC).
     */
    public function test_1003_pdf_de_consumidor_final_se_genera_sin_ruc_con_nombre_real_y_leyenda(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        // SCRUM-1140 — el receptor se congela al facturar: el cliente ya es Consumidor Final cuando se crea la factura.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $subClient = Order::findOrFail($order->id)->pipelineCard?->subClient;
        $this->assertNotNull($subClient);
        $subClient->update(['receptor_tipo' => '02', 'tax_id' => '', 'tax_id_dv' => null]);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->withHeaders($this->authHeaders($token))->get("/api/admin-contab/invoices/{$order->id}/pdf")->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $detail = app(InvoiceService::class)->detail($order->id);
        $this->assertSame('02', $detail['cliente_receptor_tipo']);
        $html = view('admin-cont.invoice-pdf', app(InvoicePdfService::class)->viewData($detail))->render();
        $this->assertStringContainsString('Consumidor final — sin RUC', $html);
        $this->assertStringContainsString($subClient->business_name, $html, 'el PDF interno conserva el nombre real');
        $this->assertStringNotContainsString('CONSUMIDOR FINAL', $html);
    }

    /** SCRUM-1003 — regresión: un Contribuyente sin RUC sigue bloqueando el PDF (SCRUM-952) y el detalle trae su tipo. */
    public function test_1003_el_detalle_trae_el_tipo_y_un_contribuyente_sin_ruc_sigue_bloqueado(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['order' => $order] = $this->makeFacturableOrder($ownerId);
        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // SCRUM-1140 — una factura con receptor congelado ya no depende del cliente actual; este test cubre la factura
        // anterior al cambio (sin receptor congelado), que sigue leyendo el cliente en vivo.
        AdminContInvoice::forOrder($order->id)->update(['receptor_tipo' => null, 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => null, 'receptor_validado_at' => null]);
        Order::findOrFail($order->id)->pipelineCard?->subClient?->update(['receptor_tipo' => '01', 'tax_id' => '']);

        $this->assertSame('01', app(InvoiceService::class)->detail($order->id)['cliente_receptor_tipo']);
        $this->withHeaders($this->authHeaders($token))->get("/api/admin-contab/invoices/{$order->id}/pdf")
            ->assertStatus(422)->assertJsonFragment(['message' => 'No se puede generar la factura: falta el RUC del cliente.']);
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

    // ── SCRUM-947 — paginación estándar (view=plana, la única que consume FacturacionPage.tsx) ──

    public function test_index_plana_pagina_20_por_defecto_sin_afectar_total_en_tab(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        for ($i = 0; $i < 25; $i++) {
            $this->makeFacturableOrder($ownerId);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/invoices?view=plana&tab=cobrable')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['rows']);
        $this->assertSame(25, $response['total_en_tab']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);

        $page2 = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/invoices?view=plana&tab=cobrable&page=2')
            ->assertStatus(200)->json();
        $this->assertCount(5, $page2['rows']);
    }

    public function test_index_agrupado_no_pagina_sin_consumidor_real_hoy(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        for ($i = 0; $i < 25; $i++) {
            $this->makeFacturableOrder($ownerId);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/invoices?view=agrupado&tab=cobrable')
            ->assertStatus(200)->json();

        $this->assertArrayHasKey('groups', $response);
        $this->assertArrayNotHasKey('meta', $response);
        $this->assertCount(25, $response['groups']);
    }

    // ── SCRUM-1147: filtro Cobradas/Por cobrar, vendedores responsables y saldo a favor ─────────

    /** @return array{order: Order, invoice: AdminContInvoice} */
    private function facturar(string $token, int $ownerId, array $cardOverrides = []): array
    {
        // Cada round-trip HTTP anterior pierde el tenant "actual" (mismo gotcha de los tests de arriba).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        ['order' => $order] = $this->makeFacturableOrder($ownerId, [], $cardOverrides);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/invoices', ['order_ids' => [$order->id]])
            ->assertStatus(201);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return ['order' => $order, 'invoice' => AdminContInvoice::where('order_id', $order->id)->firstOrFail()];
    }

    public function test_1147_filtro_cobro_separa_cobradas_de_por_cobrar_y_combina_con_otros_filtros(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        $a = $this->facturar($token, $ownerId);   // cobrada
        $b = $this->facturar($token, $ownerId);   // cobro parcial
        $c = $this->facturar($token, $ownerId);   // sin cobro
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $pendiente = $this->makeFacturableOrder($ownerId)['order']; // nunca facturada
        $a['invoice']->update(['saldo_aplicado' => $a['invoice']->monto]);
        $b['invoice']->update(['saldo_aplicado' => round($b['invoice']->monto / 2, 2)]);

        $ids = fn (string $qs): array => collect($this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices?view=plana{$qs}")->assertStatus(200)->json('rows'))
            ->pluck('order_id')->all();

        $cobradas = $ids('&cobro=cobradas');
        $this->assertContains($a['order']->id, $cobradas);
        $this->assertNotContains($b['order']->id, $cobradas);
        $this->assertNotContains($c['order']->id, $cobradas);

        $porCobrar = $ids('&cobro=por_cobrar');
        $this->assertEqualsCanonicalizing([$b['order']->id, $c['order']->id], array_values(array_intersect($porCobrar, [$a['order']->id, $b['order']->id, $c['order']->id])));
        $this->assertNotContains($pendiente->id, $porCobrar); // sin factura: no es "por cobrar"
        $this->assertNotContains($pendiente->id, $cobradas);

        // Sin filtro (o valor inválido): todas, como antes.
        $todas = $ids('');
        $this->assertContains($a['order']->id, $todas);
        $this->assertContains($pendiente->id, $todas);
        $this->assertContains($c['order']->id, $ids('&cobro=cualquiera'));

        // Combinado con otro filtro existente (cliente).
        $clienteB = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/invoices?view=plana')->json('rows');
        $nombreB = collect($clienteB)->firstWhere('order_id', $b['order']->id)['cliente'];
        $this->assertSame([$b['order']->id], $ids('&cobro=por_cobrar&cliente='.urlencode($nombreB)));
    }

    public function test_1147_filtro_cobro_tambien_funciona_en_la_vista_agrupada(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        $a = $this->facturar($token, $ownerId);
        $b = $this->facturar($token, $ownerId);
        $a['invoice']->update(['saldo_aplicado' => $a['invoice']->monto]);

        $groups = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=agrupado&cobro=cobradas')->assertStatus(200)->json('groups');
        $orderIds = collect($groups)->pluck('entregas')->flatten(1)->pluck('order_id')->all();

        $this->assertContains($a['order']->id, $orderIds);
        $this->assertNotContains($b['order']->id, $orderIds);
    }

    public function test_1147_detalle_lista_todos_los_vendedores_responsables_del_pedido(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $otroId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAsFelix();
        ['order' => $order] = $this->facturar($token, $ownerId, ['compartido_con' => [$otroId, $ownerId]]);

        $nombres = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")->assertStatus(200)->json('vendedores_responsables');

        $this->assertCount(2, $nombres);              // el owner no se repite aunque esté en compartido_con
        $this->assertNotEmpty($nombres[0]);
    }

    public function test_1175_el_listado_trae_los_vendedores_y_se_filtra_por_vendedor_combinable_con_por_cobrar(): void
    {
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAsFelix();
        $a = $this->facturar($token, $felixId, ['compartido_con' => [$designerId]]); // Contabilidad + diseñador, por cobrar
        $b = $this->facturar($token, $designerId);                                   // solo el diseñador
        $c = $this->facturar($token, $felixId);                                      // solo Contabilidad, cobrada
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $c['invoice']->update(['saldo_aplicado' => $c['invoice']->monto]);

        $filas = collect($this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana')->assertStatus(200)->json('rows'));
        $filaA = $filas->firstWhere('order_id', $a['order']->id);
        $this->assertCount(2, $filaA['vendedores']);
        $nombreFelix = $filas->firstWhere('order_id', $c['order']->id)['vendedores'][0];
        $nombreDesigner = $filas->firstWhere('order_id', $b['order']->id)['vendedores'][0];
        $this->assertNotSame($nombreFelix, $nombreDesigner);

        // Opciones del filtro: solo responsables de pedidos facturables, con id y nombre.
        $opciones = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/invoices/sellers')->assertStatus(200)->json('data'));
        $this->assertEqualsCanonicalizing([$felixId, $designerId], $opciones->pluck('id')->intersect([$felixId, $designerId])->all());
        $this->assertSame($nombreFelix, $opciones->firstWhere('id', $felixId)['name']);

        $ids = fn (string $qs): array => collect($this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices?view=plana{$qs}")->assertStatus(200)->json('rows'))
            ->pluck('order_id')->all();
        $propios = [$a['order']->id, $b['order']->id, $c['order']->id];
        $soloMios = fn (array $ids): array => array_values(array_intersect($ids, $propios));

        // Coincide si CUALQUIER responsable contiene el texto (Contabilidad está en A y en C; el diseñador en A y en B).
        $this->assertEqualsCanonicalizing([$a['order']->id, $c['order']->id], $soloMios($ids("&vendedor_ids[]={$felixId}")));
        $this->assertEqualsCanonicalizing([$a['order']->id, $b['order']->id], $soloMios($ids("&vendedor_ids[]={$designerId}")));
        // Varios a la vez: OR entre responsables.
        $this->assertEqualsCanonicalizing($propios, $soloMios($ids("&vendedor_ids[]={$felixId}&vendedor_ids[]={$designerId}")));
        // Combinado con «por cobrar»: la de Contabilidad que ya está cobrada (C) sale.
        $this->assertSame([$a['order']->id], $soloMios($ids("&cobro=por_cobrar&vendedor_ids[]={$felixId}")));
        // Un vendedor que no existe no devuelve nada.
        $this->assertSame([], $soloMios($ids('&vendedor_ids[]=999999')));
        // También en la vista agrupada.
        $grupos = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=agrupado&vendedor_ids[]='.$designerId)->assertStatus(200)->json('groups');
        $enGrupos = collect($grupos)->pluck('entregas')->flatten(1)->pluck('order_id')->all();
        $this->assertContains($b['order']->id, $enGrupos);
        $this->assertNotContains($c['order']->id, $enGrupos);
    }

    public function test_1175_el_export_incluye_cliente_y_vendedor_y_marca_sin_vendedor(): void
    {
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        ['order' => $order] = $this->facturar($token, $felixId);
        $fila = collect($this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana')->json('rows'))->firstWhere('order_id', $order->id);

        $sheet = $this->loadWorkbook($this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/invoices/export?format=excel&vendedor_ids[]='.$felixId)->assertStatus(200))->getActiveSheet();
        $datos = $sheet->toArray();
        $this->assertSame($fila['cliente'], collect($datos)->firstWhere(3, $fila['numero_factura'])[1]);
        $this->assertSame($fila['vendedores'][0], collect($datos)->firstWhere(3, $fila['numero_factura'])[2]);

        $this->withHeaders($this->authHeaders($token))->get('/api/admin-contab/invoices/export?format=pdf&vendedor_ids[]='.$felixId)->assertStatus(200);

        // Sin responsables (factura migrada o de venta chica) la celda dice «—», no queda vacía.
        $bytes = app(InvoiceExportService::class)->toExcel([[
            'order_number' => 'PED-X', 'cliente' => 'Cliente X', 'vendedores' => [], 'numero_factura' => 'F-X', 'cotizacion_folio' => null, 'monto' => 10.0, 'estado' => 'facturada',
        ]]);
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $bytes);
        $this->assertSame('—', IOFactory::load($tmp)->getActiveSheet()->getCell('C2')->getValue());
        @unlink($tmp);
    }

    public function test_1147_detalle_con_un_solo_responsable_lo_lista(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        ['order' => $order] = $this->facturar($token, $ownerId);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/invoices/{$order->id}/detail")->assertStatus(200)
            ->assertJsonCount(1, 'vendedores_responsables');
    }

    private function makeAbono(array $attrs): AdminContAbono
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $bank = AdminContBankAccount::first() ?? AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        return AdminContAbono::create([
            'monto_usado' => 0, 'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $bank->id,
            'referencia' => 'TRF-'.uniqid(), 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'fecha_registro' => now(), 'fecha_confirmacion' => now(),
            ...$attrs,
        ]);
    }

    public function test_1147_saldo_a_favor_muestra_solo_el_nivel_mas_especifico(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        $conFactura = $this->facturar($token, $ownerId);   // abono nivel Factura (120) + cliente (300) -> 120
        $soloCliente = $this->facturar($token, $ownerId);  // solo abono nivel Cliente (300) -> 300
        $sinSaldo = $this->facturar($token, $ownerId);     // ninguno -> null
        $cobrada = $this->facturar($token, $ownerId);      // cobrada: aunque tenga saldo no se muestra
        $cobrada['invoice']->update(['saldo_aplicado' => $cobrada['invoice']->monto]);

        $masterOf = fn (array $f): int => (int) $f['order']->pipelineCard->master_client_id;
        $this->makeAbono(['master_client_id' => $masterOf($conFactura), 'invoice_id' => $conFactura['invoice']->id, 'monto_original' => 120]);
        $this->makeAbono(['master_client_id' => $masterOf($conFactura), 'monto_original' => 300]);
        $this->makeAbono(['master_client_id' => $masterOf($soloCliente), 'monto_original' => 300]);
        $this->makeAbono(['master_client_id' => $masterOf($cobrada), 'monto_original' => 50]);

        $rows = collect($this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=plana')->assertStatus(200)->json('rows'))->keyBy('order_id');

        $this->assertEquals(120.0, $rows[$conFactura['order']->id]['saldo_a_favor']);
        $this->assertEquals(300.0, $rows[$soloCliente['order']->id]['saldo_a_favor']);
        $this->assertNull($rows[$sinSaldo['order']->id]['saldo_a_favor']);
        $this->assertNull($rows[$cobrada['order']->id]['saldo_a_favor']);
    }

    public function test_1147_saldo_a_favor_tambien_llega_en_la_vista_agrupada(): void
    {
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsFelix();
        $f = $this->facturar($token, $ownerId);
        $this->makeAbono(['master_client_id' => (int) $f['order']->pipelineCard->master_client_id, 'monto_original' => 80]);

        $groups = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/invoices?view=agrupado')->assertStatus(200)->json('groups');
        $row = collect($groups)->pluck('entregas')->flatten(1)->firstWhere('order_id', $f['order']->id);

        $this->assertEquals(80.0, $row['saldo_a_favor']);
    }
}
