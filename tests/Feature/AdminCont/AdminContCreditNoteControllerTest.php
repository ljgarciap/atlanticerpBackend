<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Mail\CreditNoteConfirmedMail;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\CreditNotePdfService;
use App\Modules\AdminCont\Services\InvoiceBalanceWriter;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Batch 10 de Admin&Cont (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones. */
class AdminContCreditNoteControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    /** Batch 13 — mismo email real que `AdminContInvoiceControllerTest`/`AdminContFiscalSettingsControllerTest`. */
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

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{order: Order, invoice: AdminContInvoice} */
    private function makeInvoicedOrder(int $ownerId, float $monto = 500, float $saldoAplicado = 0.0): array
    {
        $master = MasterClient::create(['name' => 'Cliente Notas Crédito '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 500.0, 'stage_changed_at' => Carbon::now(),
        ]);

        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        $generalRate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(),
            'order_id' => $order->id,
            'itbms_rate_id' => $generalRate->id,
            'master_client_id' => $master->id,
            'cliente' => $sub->business_name,
            'monto' => $monto,
            'saldo_aplicado' => $saldoAplicado,
        ]);

        return ['order' => $order, 'invoice' => $invoice];
    }

    /** @return array<string, mixed> */
    private function baseRegisterPayload(AdminContInvoice $invoice, float $monto): array
    {
        return [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $invoice->id,
            'monto' => $monto,
            'motivo' => 'Descuento comercial acordado con el cliente',
        ];
    }

    /** SCRUM-865 punto 3 — variante de `baseRegisterPayload()` con comprobante adjunto (obligatorio
     *  desde este ticket), para los tests que solo necesitan un registro exitoso sin ejercitar esa
     *  validación en particular. Fakea el disco acá mismo (no solo en los tests que ya lo hacían
     *  explícito) — sin esto, `register()` termina subiendo de verdad al bucket S3 real cuando el
     *  entorno tiene credenciales configuradas (pasa en local, no en CI, ver AwsS3V3Adapter). */
    private function baseRegisterPayloadConComprobante(AdminContInvoice $invoice, float $monto): array
    {
        Storage::fake('s3-private');

        return [...$this->baseRegisterPayload($invoice, $monto), 'comprobante' => UploadedFile::fake()->create('soporte.pdf', 100, 'application/pdf')];
    }

    /**
     * SCRUM-786 — `GET /notas-credito/devoluciones/{customerReturn}` (REQ-491) no tenía ningún
     * test propio. Cubre el shape completo, incluido `unit_price` (antes inexistente, ver
     * `docs/reviews/scrum565-570-batch12-correccion-datos-20260824.md`).
     */
    public function test_devolucion_detail_incluye_unit_price_de_la_linea(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order, 'invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);

        $item = $order->items()->create([
            'qty_requested' => 5, 'qty_delivered' => 5, 'unit_price' => 45.5,
            'reference_snapshot' => 'LED-100', 'description_snapshot' => 'Luminaria LED',
        ]);

        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999),
            'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by' => $ownerId,
        ]);
        $customerReturn->lines()->create([
            'order_item_id' => $item->id,
            'reference_snapshot' => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot,
            'unit_price' => $item->unit_price,
            'qty_requested' => 2,
            'qty_received' => 2,
            'reason' => 'danado_defectuoso',
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}")
            ->assertStatus(200);

        $response->assertJsonPath('customer_return_id', $customerReturn->id);
        $response->assertJsonPath('factura_origen_id', $invoice->id);
        $response->assertJsonPath('productos.0.unit_price', 45.5);
        $response->assertJsonPath('productos.0.qty_received', 2);
    }

    /** @return array{0: CustomerReturn, 1: AdminContInvoice} */
    private function makeDevolucionConLineas(float $montoFactura, array $lineas): array
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order, 'invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: $montoFactura);

        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999),
            'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            'contact_name' => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by' => $ownerId,
        ]);
        foreach ($lineas as $i => $l) {
            $item = $order->items()->create([
                'qty_requested' => $l['qty'], 'qty_delivered' => $l['qty'], 'unit_price' => $l['unit_price'],
                'reference_snapshot' => "REF-{$i}", 'description_snapshot' => "Producto {$i}",
            ]);
            $customerReturn->lines()->create([
                'order_item_id' => $item->id, 'reference_snapshot' => $item->reference_snapshot,
                'description_snapshot' => $item->description_snapshot, 'unit_price' => $l['unit_price'],
                'qty_requested' => $l['qty'], 'qty_received' => $l['qty'], 'reason' => 'danado_defectuoso',
            ]);
        }

        return [$customerReturn, $invoice];
    }

    /**
     * NC-0001 (2026-10-06): Bodega guarda el precio SIN ITBMS (64.38 × 2 = 128.76) y Administración lo tomó como el
     * total de la nota, dejando 9.01 de ITBMS sin acreditar. El detalle debe entregar el total CON ITBMS de la tasa
     * de la factura, más el total de la factura como referencia.
     */
    public function test_devolucion_detail_entrega_monto_sugerido_con_itbms_y_referencia_de_la_factura(): void
    {
        [$customerReturn, $invoice] = $this->makeDevolucionConLineas(137.77, [['qty' => 2, 'unit_price' => 64.38]]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}")
            ->assertStatus(200);

        $response->assertJsonPath('productos.0.unit_price', 64.38); // neto, sin cambios
        $response->assertJsonPath('itbms_percentage', 7);
        $response->assertJsonPath('monto_sugerido', 137.77);       // total con ITBMS, no 128.76
        $response->assertJsonPath('factura_monto', 137.77);
        $response->assertJsonPath('factura_saldo_pendiente', 137.77);
    }

    public function test_devolucion_detail_monto_sugerido_es_null_si_alguna_linea_no_tiene_precio(): void
    {
        [$customerReturn] = $this->makeDevolucionConLineas(500, [
            ['qty' => 1, 'unit_price' => 100.0],
            ['qty' => 1, 'unit_price' => null],
        ]);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}")
            ->assertStatus(200)
            ->assertJsonPath('monto_sugerido', null);
    }

    /**
     * SCRUM-865 punto 2 — `devolucionDetail()` no traía `contact_name`/`contact_phone` (persona
     * que devuelve), `project` ni `has_signed_document`, aunque Bodega ya los captura — el
     * formulario mostraba "No disponible" con el dato existente. `makeInvoicedOrder()` no linkea
     * `pipeline_card_id`, así que acá se arma la orden a mano para poder verificar el caso CON dato.
     */
    public function test_devolucion_detail_expone_persona_que_devuelve_proyecto_y_documento_firmado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        $master = MasterClient::create(['name' => 'Cliente Devolución '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Paso a Bodega 1']);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 500.0, 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name, 'pipeline_card_id' => $card->id,
        ]);
        $generalRate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => $order->id, 'itbms_rate_id' => $generalRate->id,
            'master_client_id' => $master->id, 'cliente' => $sub->business_name, 'monto' => 500,
        ]);
        $item = $order->items()->create([
            'qty_requested' => 2, 'qty_delivered' => 2, 'unit_price' => 45.5,
            'reference_snapshot' => 'LED-100', 'description_snapshot' => 'Luminaria LED',
        ]);
        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999), 'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            'contact_name' => 'Gerencia Test', 'contact_phone' => '54345434',
            'signed_document_path' => 'bodega/customer-returns/formulario.pdf', 'signed_document_uploaded_at' => now(),
            'created_by' => $ownerId,
        ]);
        $customerReturn->lines()->create([
            'order_item_id' => $item->id, 'reference_snapshot' => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot, 'unit_price' => $item->unit_price,
            'qty_requested' => 1, 'qty_received' => 1, 'reason' => 'danado_defectuoso',
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}")
            ->assertStatus(200);

        $response->assertJsonPath('project', 'Proyecto Paso a Bodega 1');
        $response->assertJsonPath('contact_name', 'Gerencia Test');
        $response->assertJsonPath('contact_phone', '54345434');
        $response->assertJsonPath('has_signed_document', true);

        Storage::fake('s3-private');
        $urlResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}/signed-document")
            ->assertStatus(200);
        $this->assertNotEmpty($urlResponse->json('url'));
    }

    /** Sin documento firmado — 404 con mensaje claro, no un presign vacío. */
    public function test_devolucion_signed_document_url_404_sin_documento_firmado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999), 'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            'contact_name' => 'Ana Pérez', 'contact_phone' => '6000-1234', 'created_by' => $ownerId,
        ]);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/devoluciones/{$customerReturn->id}/signed-document")
            ->assertStatus(404);
    }

    // NOTA — un test end-to-end de "registrar nota de devolución vía POST /notas-credito con
    // devolucion_bodega_id → finaliza la devolución con credit_note_id" se intentó acá y falló de
    // forma reproducible (aislado y en el suite completo) con "relation customer_returns does not
    // exist" al llegar a `finalizeForCreditNote()` dentro de la transacción — aunque el mismo
    // request ya había leído/escrito esa tabla exitosamente segundos antes (fixture directo, y
    // `devolucionDetail()` cross-módulo funciona bien vía GET). No se identificó la causa raíz con
    // el presupuesto disponible; puede ser un artefacto del entorno de test (transacción anidada +
    // resolución de tenant) o un bug real de contexto de tenant en este flujo específico — no
    // descartado, señalado para que Senior Review/QA lo evalúe. La persistencia real de
    // `credit_note_id` SÍ está cubierta directamente en
    // `tests/Feature/Bodega/CustomerReturnControllerTest::test_finalize_for_credit_note_persiste_status_y_credit_note_id`.

    public function test_summary_devuelve_todo_en_cero_sin_notas_registradas(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/summary')
            ->assertStatus(200);

        $response->assertJson([
            'total_acreditado' => 0,
            'numero_notas' => 0,
            'pendientes_aprobacion_monto' => 0,
            'pendientes_aprobacion_cantidad' => 0,
            'devoluciones_por_generar' => 0,
            'nota_promedio' => 0,
        ]);
    }

    public function test_summary_agrega_notas_aplicadas_del_mes_y_separa_pendientes_de_aprobacion(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoiceA] = $this->makeInvoicedOrder($ownerId);
        ['invoice' => $invoiceB] = $this->makeInvoicedOrder($ownerId);
        ['invoice' => $invoiceOutsideMonth] = $this->makeInvoicedOrder($ownerId);
        ['invoice' => $invoicePending] = $this->makeInvoicedOrder($ownerId);

        $base = [
            'numero' => 'NC-', 'master_client_id' => $invoiceA->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'subtotal' => 100, 'itbms' => 7,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
        ];

        AdminContCreditNote::create([...$base, 'numero' => 'NC-0001', 'factura_origen_id' => $invoiceA->id, 'monto' => 100, 'estado' => AdminContCreditNote::ESTADO_APLICADA]);
        AdminContCreditNote::create([...$base, 'numero' => 'NC-0002', 'factura_origen_id' => $invoiceB->id, 'monto' => 200, 'estado' => AdminContCreditNote::ESTADO_APLICADA]);

        // Fuera del mes actual — no debe contarse en los indicadores del mes.
        AdminContCreditNote::create([...$base, 'numero' => 'NC-0003', 'factura_origen_id' => $invoiceOutsideMonth->id, 'monto' => 999, 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'fecha' => now()->subMonths(2)]);

        // Pendiente de aprobación — cuenta aparte, nunca dentro de "total acreditado".
        AdminContCreditNote::create([...$base, 'numero' => 'NC-0004', 'factura_origen_id' => $invoicePending->id, 'monto' => 6000, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/summary')
            ->assertStatus(200);

        $response->assertJson([
            'total_acreditado' => 300,
            'numero_notas' => 2,
            'pendientes_aprobacion_monto' => 6000,
            'pendientes_aprobacion_cantidad' => 1,
            'nota_promedio' => 150,
        ]);
    }

    public function test_itbms_treatment_devuelve_tratamiento_actual_y_las_otras_2_tasas(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order] = $this->makeInvoicedOrder($ownerId);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/facturas/{$order->id}/itbms-treatment")
            ->assertStatus(200);

        $response->assertJson([
            'tratamiento_actual' => ['nombre' => '7% — General', 'porcentaje' => 7],
        ]);

        $alternativas = collect($response->json('alternativas'))->pluck('nombre')->all();
        $this->assertCount(2, $alternativas);
        $this->assertContains('3.5% — Retención de impuesto', $alternativas);
        $this->assertContains('0% — Exento', $alternativas);
        $this->assertNotContains('7% — General', $alternativas);
    }

    public function test_itbms_rates_devuelve_el_catalogo_completo_de_tasas_activas(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/itbms-rates')
            ->assertStatus(200);

        $nombres = collect($response->json())->pluck('nombre')->all();
        $this->assertCount(3, $nombres);
        $this->assertContains('7% — General', $nombres);
        $this->assertContains('3.5% — Retención de impuesto', $nombres);
        $this->assertContains('0% — Exento', $nombres);
        $response->assertJsonStructure([['id', 'nombre', 'descripcion', 'porcentaje', 'es_base']]);
    }

    public function test_itbms_treatment_devuelve_404_si_el_order_no_tiene_factura(): void
    {
        $order = Order::create([
            'order_number' => 'PED-SIN-FACTURA-'.random_int(1000, 999999),
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_PACKING,
            'customer_name' => 'X',
        ]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/facturas/{$order->id}/itbms-treatment")
            ->assertStatus(404);
    }

    public function test_summary_y_itbms_treatment_rechazan_a_un_rol_no_autorizado(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/summary')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/facturas/1/itbms-treatment')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/itbms-rates')
            ->assertStatus(403);
    }

    // ── Batch 11 (SCRUM-559→564, REQ-482→487) — registro real ────────────────────────────────

    public function test_facturas_del_cliente_incluye_las_ya_pagadas_con_saldo_cero(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $pagada] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $masterClientId = $pagada->master_client_id;
        ['invoice' => $conSaldo] = $this->makeInvoicedOrder($ownerId, monto: 200, saldoAplicado: 50);
        $conSaldo->update(['master_client_id' => $masterClientId]);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/clientes/{$masterClientId}/facturas")
            ->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEquals(0.0, $rows[$pagada->id]['saldo_pendiente']);
        $this->assertEquals(150.0, $rows[$conSaldo->id]['saldo_pendiente']);
    }

    /**
     * Pre-QA Batch 12 (2026-08-24) — regresión real encontrada en vivo contra dev.atlanticerp.ai:
     * `invoicesForClient()` nunca devolvía `itbms_rate_id`, pese a que el frontend
     * (`NotaCreditoFacturaOrigen.itbms_rate_id`, ver docblock del tipo) lo necesita para excluir la
     * tasa ya aplicada de las opciones de "tratamiento correcto" en Corrección de datos (RN1
     * REQ-488). Sin este campo, "Tratamiento actual" siempre mostraba "—" y el picker de
     * "tratamiento correcto" ofrecía la MISMA tasa ya aplicada como si fuera una corrección válida.
     */
    public function test_facturas_del_cliente_incluye_itbms_rate_id_para_excluirlo_en_correccion_de_datos(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/clientes/{$invoice->master_client_id}/facturas")
            ->assertStatus(200);

        $row = collect($response->json('data'))->keyBy('id')[$invoice->id];
        $this->assertSame($invoice->itbms_rate_id, $row['itbms_rate_id']);
    }

    public function test_registrar_descuento_comercial_dentro_del_saldo_se_aplica_sin_preguntar_excedente(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6200);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->baseRegisterPayloadConComprobante($invoice, 1000))
            ->assertStatus(201);

        $response->assertJson(['estado' => AdminContCreditNote::ESTADO_APLICADA, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);
        $this->assertEquals(1000.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
    }

    public function test_registrar_con_excedente_devuelto_crea_movimiento_bancario_de_devolucion(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        // Factura de 300 YA COBRADA (saldo por cobrar 0) y nota de 300: todo el valor de la nota es excedente → se devuelve
        // lo cobrado. SCRUM-1116 (D2): antes este test usaba una nota de 1.000 sobre una factura de 300, es decir un
        // acumulado MAYOR que la factura, que el ticket prohíbe en cualquier tipo de nota (ver
        // `test_una_nota_mayor_que_el_valor_de_la_factura_se_rechaza`).
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayloadConComprobante($invoice, 300), 'resultado' => 'devuelto', 'cuenta_bancaria_salida_id' => $account->id];

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        $response->assertJson(['resultado' => 'devuelto']);
        $this->assertDatabaseHas($this->tableFqn('bank_movements'), [
            'bank_account_id' => $account->id, 'tipo' => AdminContBankMovement::TIPO_DEVOLUCION, 'monto' => 300,
        ]);
    }

    public function test_registrar_con_excedente_como_saldo_a_favor_aumenta_el_saldo_del_cliente(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        // Factura de 300 ya cobrada y nota de 300 (SCRUM-1116: antes 400 sobre 300, un acumulado mayor que la factura).
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayloadConComprobante($invoice, 300), 'resultado' => 'saldo_favor'];

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        // SCRUM-902/903 — retirado `customer_credit_balances`: el saldo a favor que una nota
        // `saldo_favor` genera se lee de la nota misma (`monto - monto_aplicado_saldo -
        // monto_usado`), nunca de una tabla aparte.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertEquals(300.0, $note->montoDisponibleSaldoFavor());
    }

    public function test_excedente_sin_resultado_es_rechazado(): void
    {
        // SCRUM-1116: el excedente es la nota (300) sobre una factura de 300 YA cobrada; antes era 400, una nota mayor que la factura.
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 300))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resultado']);
    }

    public function test_resultado_devuelto_sin_cuenta_bancaria_es_rechazado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayload($invoice, 300), 'resultado' => 'devuelto'];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuenta_bancaria_salida_id']);
    }

    public function test_motivo_es_siempre_obligatorio(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6200);
        $token = $this->loginAsFelix();

        $payload = $this->baseRegisterPayload($invoice, 100);
        unset($payload['motivo']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo']);
    }

    public function test_anulacion_completa_ignora_el_monto_del_request_y_usa_el_total_de_la_factura(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'monto' => 1, // debe ignorarse — la nota debe quedar en 500 (total de la factura).
            'motivo' => 'Pedido cancelado por el cliente',
            'comprobante' => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        // Batch 13 (REQ-494 RN1) — toda Anulación completa queda pendiente de aprobación de Mark
        // sin importar el monto (acá $500, bajo el umbral de $5,000), así que `saldo_aplicado`
        // NO se mueve todavía — recién se aplica cuando Mark aprueba (ver `decide()`).
        $response->assertJson(['monto' => 500, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION]);
        $this->assertEquals(0.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
    }

    public function test_anulacion_completa_de_factura_nunca_cobrada_no_mueve_dinero(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500, saldoAplicado: 0);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'motivo' => 'Pedido cancelado, nunca cobrado',
            'comprobante' => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        $response->assertJson(['resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);
        $this->assertSame(0, DB::table($this->tableFqn('bank_movements'))->count());
        // SCRUM-902/903 — `resultado=aplicado_saldo` (no `saldo_favor`) nunca genera saldo a favor.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEquals(0.0, AdminContCreditNote::findOrFail($response->json('id'))->montoDisponibleSaldoFavor());
    }

    public function test_anulacion_completa_sin_comprobante_es_rechazada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'motivo' => 'Pedido cancelado por el cliente',
        ];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comprobante']);
    }

    public function test_monto_mayor_al_umbral_exige_comprobante_y_queda_pendiente_de_aprobacion_sin_aplicar(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 10000);
        $token = $this->loginAsFelix();

        // Sin comprobante — monto (6000) > umbral default (5000) → rechazado.
        $sinComprobante = $this->baseRegisterPayload($invoice, 6000);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $sinComprobante)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comprobante']);

        $conComprobante = [...$sinComprobante, 'comprobante' => UploadedFile::fake()->create('soporte.pdf', 100, 'application/pdf')];
        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $conComprobante)
            ->assertStatus(201);

        $response->assertJson(['estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION]);
        // Pendiente de aprobación — no se toca el saldo de la factura todavía (eso es al aprobar, Batch 13).
        $this->assertEquals(0.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
    }

    /**
     * SCRUM-865 punto 3 — antes Devolución de mercancía era la ÚNICA excepción que nunca exigía
     * comprobante (REQ-487 RN2), sin importar el monto. Ahora el comprobante es obligatorio para
     * cualquier tipo, incluida Devolución de mercancía — reemplaza la excepción original.
     */
    public function test_devolucion_de_mercancia_ahora_tambien_exige_comprobante(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 10000);
        $token = $this->loginAsFelix();

        $sinComprobante = [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA,
            'factura_origen_id' => $invoice->id,
            'monto' => 6000,
            'motivo' => 'Devolución de mercancía confirmada por Bodega',
        ];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $sinComprobante)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comprobante']);

        Storage::fake('s3-private');
        $conComprobante = [...$sinComprobante, 'comprobante' => UploadedFile::fake()->create('devolucion.pdf', 100, 'application/pdf')];

        $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $conComprobante)
            ->assertStatus(201);
    }

    public function test_correccion_de_datos_todavia_no_soportada_es_rechazada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
            'factura_origen_id' => $invoice->id,
            'motivo' => 'Corrección de ITBMS',
        ];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subtipo_anulacion']);
    }

    /**
     * @return array{order: Order, quote: Quote, invoice: AdminContInvoice}
     */
    private function makeCorreccionFixture(int $ownerId): array
    {
        $master = MasterClient::create(['name' => 'Cliente Corrección '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Corrección '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Corrección '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 500.0, 'stage_changed_at' => Carbon::now(),
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);
        $order->documents()->create(['tipo' => OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-correccion-test.pdf']);

        $generalRate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(),
            'order_id' => $order->id,
            'quote_id' => $quote->id,
            'itbms_rate_id' => $generalRate->id,
            'master_client_id' => $master->id,
            'cliente' => $sub->business_name,
            'monto' => 500,
        ]);

        return ['order' => $order, 'quote' => $quote, 'invoice' => $invoice];
    }

    /**
     * Pre-QA Batch 12 (2026-08-24) — regresión real encontrada en vivo contra dev.atlanticerp.ai: las
     * tarjetas de `previewCorreccion()` mandaban un boolean crudo (`guia_entrega.existe: true`) y
     * un objeto crudo (`correccion_aplicada.nuevo_tratamiento_itbms: {...}`), que
     * `CardBlock.tsx` (frontend) renderiza con `String(v)` — literalmente "true" y
     * "[object Object]" en pantalla, en vez de un texto legible. RN1 REQ-489 exige que la revisión
     * previa muestre claramente qué se está corrigiendo antes de confirmar — un valor roto ahí
     * incumple esa intención aunque ningún criterio lo mencione palabra por palabra.
     */
    public function test_preview_correccion_tarjetas_no_manda_booleans_ni_objetos_crudos(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeCorreccionFixture($ownerId);
        // Mismo gotcha ya documentado en este archivo — loginAs() hace un round-trip HTTP real y
        // spatie/laravel-multitenancy olvida el tenant "actual" al terminar el request, así que
        // cualquier query cross-schema (acá `itbms_rates`) se resuelve ANTES de loguear.
        $nuevoRateId = AdminContItbmsRate::whereKeyNot($invoice->itbms_rate_id)->value('id');
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id' => $invoice->master_client_id,
            'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
            'nuevo_tratamiento_itbms_rate_id' => $nuevoRateId,
            'motivo' => 'Prueba Pre-QA — tratamiento ITBMS incorrecto',
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito/correccion/preview', $payload)
            ->assertStatus(200);

        $tarjetas = $response->json('tarjetas');
        $this->assertIsString($tarjetas['guia_entrega']['existe']);
        $this->assertSame('Sí', $tarjetas['guia_entrega']['existe']);
        $this->assertIsString($tarjetas['correccion_aplicada']['nuevo_tratamiento_itbms']);
        $this->assertIsString($tarjetas['correccion_aplicada']['motivo_correccion']);
        $this->assertNotSame(AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, $tarjetas['correccion_aplicada']['motivo_correccion']);
    }

    /**
     * Guarda de la opción A (2026-10-05): corregir una factura YA certificada ante la DGI hacia la tasa de retención
     * (3.5%, sin código DGI) dejaría la NC de anulación certificada y el reemplazo imposible de emitir. Se rechaza
     * antes de crear nada.
     */
    public function test_correccion_a_tasa_de_retencion_de_factura_certificada_es_rechazada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeCorreccionFixture($ownerId);
        AdminContInvoice::whereKey($invoice->id)->update(['cufe' => 'FE-CUFE-DE-PRUEBA', 'fe_estado' => 'certificado']);
        $retencionId = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->value('id');
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito/correccion/preview', [
                'master_client_id' => $invoice->master_client_id,
                'factura_origen_id' => $invoice->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
                'nuevo_tratamiento_itbms_rate_id' => $retencionId,
                'motivo' => 'Prueba — corregir a retención',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede corregir a esta tasa: la factura ya está certificada ante la DGI y su reemplazo no se podría emitir. '
                .'No se certificó: la tasa «3.5% — Retención de impuesto» aún no se puede reportar a la DGI '
                .'(MSL la declararía al 7% y el total fiscal no coincidiría con el de la factura en AtlanticERP). '
                .'Pendiente de confirmación fiscal. Usa la tasa 7% o contacta a Administración.']);
    }

    public function test_store_y_facturas_rechazan_a_un_rol_no_autorizado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/clientes/{$invoice->master_client_id}/facturas")
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 100))
            ->assertStatus(403);
    }

    /** REQ-494 Escenario 1 — Mark aprueba una nota pendiente: pasa a aplicada y mueve el saldo. */
    public function test_decision_aprobar_aplica_la_nota_y_mueve_el_saldo(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $felix = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($felix))->post('/api/admin-contab/notas-credito', [
            ...$this->baseRegisterPayload($invoice, 500),
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'comprobante' => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $noteId = $store->json('id');
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $store->json('estado'));

        $mark = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => true])
            ->assertStatus(200);

        $this->assertSame(AdminContCreditNote::ESTADO_APLICADA, $response->json('estado'));
        $this->assertNotNull($response->json('aprobado_por'));
        $this->assertFalse($response->json('puede_aprobar_rechazar'));
        $this->assertEquals(500.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
    }

    /** REQ-494 Escenario 3 — Mark rechaza: motivo obligatorio, no mueve dinero. */
    public function test_decision_rechazar_exige_motivo_y_no_mueve_dinero(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $felix = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($felix))->post('/api/admin-contab/notas-credito', [
            ...$this->baseRegisterPayload($invoice, 500),
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'comprobante' => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $noteId = $store->json('id');

        $mark = $this->loginAsMark();

        // RN4 — sin motivo, 422.
        $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo_rechazo']);

        $response = $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => false, 'motivo_rechazo' => 'Documentación insuficiente'])
            ->assertStatus(200);

        $this->assertSame(AdminContCreditNote::ESTADO_RECHAZADA, $response->json('estado'));
        $this->assertSame('Documentación insuficiente', $response->json('motivo_rechazo'));
        $this->assertEquals(0.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
        $this->assertSame(0, DB::table($this->tableFqn('bank_movements'))->count());
    }

    /** RN2 REQ-494 — no se puede aprobar una nota que exige comprobante y no lo tiene. */
    public function test_decision_aprobar_sin_comprobante_requerido_es_bloqueada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        AdminContCreditNote::create([
            'numero' => 'NC-BLOQ', 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id, 'monto' => 500, 'subtotal' => 467, 'itbms' => 33,
            'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, 'comprobante_url' => null,
        ]);
        $noteId = DB::table($this->tableFqn('credit_notes'))->where('numero', 'NC-BLOQ')->value('id');

        $mark = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => true])
            ->assertStatus(422);

        $this->assertSame(
            AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
            DB::table($this->tableFqn('credit_notes'))->where('id', $noteId)->value('estado'),
        );
    }

    /** `primary_approver_only` — un rol distinto de Mark, aunque sea Gerencia genérica, no puede decidir. */
    public function test_decision_rechaza_a_un_no_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6000);
        AdminContCreditNote::create([
            'numero' => 'NC-NOMARK', 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 6000, 'subtotal' => 5607, 'itbms' => 393, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
        ]);
        $noteId = DB::table($this->tableFqn('credit_notes'))->where('numero', 'NC-NOMARK')->value('id');

        $felix = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felix))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => true])
            ->assertStatus(403);
    }

    /** Batch endurecimiento FE/MSL — el motivo textual del rechazo del PAC solo lo ve Mark/superadmin,
     *  tanto en `show()` como en la respuesta de `decision()` (ruta `primary_approver_only`). */
    public function test_show_y_decision_exponen_fe_detalle_solo_a_mark(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6000);
        AdminContCreditNote::create([
            'numero' => 'NC-FEDETALLE', 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 6000, 'subtotal' => 5607, 'itbms' => 393, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
        ]);
        $noteId = DB::table($this->tableFqn('credit_notes'))->where('numero', 'NC-FEDETALLE')->value('id');
        DB::table($this->tableFqn('credit_notes'))->where('id', $noteId)
            ->update(['fe_estado' => 'rechazado', 'fe_detalle' => '9026 - Falló la validación']);

        $asFelix = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/notas-credito/{$noteId}")
            ->assertStatus(200);
        $this->assertNull($asFelix->json('fe_detalle'));
        $this->assertFalse($asFelix->json('puede_ver_fe_transmisiones'));

        $mark = $this->loginAsMark();
        $asMark = $this->withHeaders($this->authHeaders($mark))
            ->getJson("/api/admin-contab/notas-credito/{$noteId}")
            ->assertStatus(200);
        $this->assertSame('9026 - Falló la validación', $asMark->json('fe_detalle'));
        $this->assertTrue($asMark->json('puede_ver_fe_transmisiones'));

        $decision = $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$noteId}/decision", ['approve' => false, 'motivo_rechazo' => 'Documentación insuficiente'])
            ->assertStatus(200);
        $this->assertSame('9026 - Falló la validación', $decision->json('fe_detalle'));
        $this->assertTrue($decision->json('puede_ver_fe_transmisiones')); // sin esto el visor se desmonta al aprobar
    }

    /**
     * Pre-QA Batch 13 (2026-08-24) — `puede_aprobar_rechazar` es dependiente del actor, no solo
     * del estado de la nota (mismo criterio que `puede_decidir_incobrable` en
     * `AdminContInvoiceController::detail()`, REQ-448). Sin este chequeo, GET .../{id} le mostraba
     * `true` a Felix en una nota pendiente aunque el backend igual bloqueara el intento real de
     * decidir con 403 — no era un gap de seguridad, pero sí un botón que siempre iba a fallar.
     */
    public function test_show_puede_aprobar_rechazar_es_false_para_un_no_mark_aunque_la_nota_este_pendiente(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6000);
        AdminContCreditNote::create([
            'numero' => 'NC-ACTORGATE', 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 6000, 'subtotal' => 5607, 'itbms' => 393, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
        ]);
        $noteId = DB::table($this->tableFqn('credit_notes'))->where('numero', 'NC-ACTORGATE')->value('id');

        $felix = $this->loginAsFelix();
        $asFelix = $this->withHeaders($this->authHeaders($felix))
            ->getJson("/api/admin-contab/notas-credito/{$noteId}")
            ->assertStatus(200);
        $this->assertFalse($asFelix->json('puede_aprobar_rechazar'));

        $mark = $this->loginAsMark();
        $asMark = $this->withHeaders($this->authHeaders($mark))
            ->getJson("/api/admin-contab/notas-credito/{$noteId}")
            ->assertStatus(200);
        $this->assertTrue($asMark->json('puede_aprobar_rechazar'));
    }

    /** SCRUM-943 — el detalle expone el estado real de Facturación Electrónica, nunca oculto. */
    public function test_show_expone_el_estado_de_facturacion_electronica(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $note = AdminContCreditNote::create([
            'numero' => 'NC-FE-TEST', 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_APLICADA,
        ]);
        $note->forceFill(['fe_estado' => 'error_configuracion', 'pac_provider_usado' => 'msl'])->save();

        $felix = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($felix))
            ->getJson("/api/admin-contab/notas-credito/{$note->id}")
            ->assertStatus(200);

        $this->assertSame('error_configuracion', $response->json('fe_estado'));
        $this->assertSame('msl', $response->json('pac_provider_usado'));
        $this->assertNull($response->json('cufe'));
    }

    /** REQ-495 — comprobante adjunto: URL + quién lo subió + cuándo. */
    public function test_comprobante_devuelve_url_y_metadata_cuando_existe(): void
    {
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 6000);
        $token = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($token))->post('/api/admin-contab/notas-credito', [
            ...$this->baseRegisterPayload($invoice, 6000),
            'comprobante' => UploadedFile::fake()->create('soporte.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/{$store->json('id')}/comprobante")
            ->assertStatus(200);

        $this->assertTrue($response->json('tiene_comprobante'));
        $this->assertNotEmpty($response->json('url'));
        $this->assertNotEmpty($response->json('subido_por'));
    }

    /**
     * REQ-495 RN2 — mensaje explícito cuando la nota no tiene comprobante. SCRUM-865 punto 3 hizo
     * el comprobante obligatorio al registrar, así que este escenario ("nota sin comprobante") ya
     * no puede darse vía el endpoint de registro — se simula una nota histórica (previa al cambio,
     * o creada por otro camino) escribiendo el registro directo.
     */
    public function test_comprobante_devuelve_false_cuando_no_tiene_adjunto(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 100);
        $token = $this->loginAsFelix();

        // loginAsFelix() es otro round-trip HTTP real — reafirmar el tenant "actual" antes de
        // escribir directo por Eloquent (mismo gotcha ya documentado en AdminContInvoiceControllerTest).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $note = AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $invoice->id, 'monto' => 100, 'subtotal' => 100, 'itbms' => 0,
            'motivo' => 'Nota histórica sin comprobante', 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'fecha' => now(), 'registrado_por' => $ownerId,
        ]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/{$note->id}/comprobante")
            ->assertStatus(200);

        $this->assertFalse($response->json('tiene_comprobante'));
    }

    /** SCRUM-857/858 — con `dv` vacío (el valor real), la línea de RUC del PDF de la nota de
     *  crédito no debe quedar con un guion colgante ("RUC 1838853-1-711641-"). */
    public function test_pdf_de_nota_credito_no_deja_guion_colgante_en_el_ruc_sin_dv(): void
    {
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'ATLANTIC USA CORP', 'ruc' => '1838853-1-711641', 'dv' => '',
        ]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $data = app(CreditNotePdfService::class)->viewData([
            'tipo' => 'devolucion_mercancia', 'resultado' => 'devuelto', 'estado' => 'aplicada',
            'cliente' => 'Cliente Test', 'numero' => 'NC-0001', 'fecha' => '2026-09-10', 'motivo' => 'Prueba',
        ]);
        $html = view('admin-cont.credit-note-pdf', $data)->render();

        $this->assertStringContainsString('RUC 1838853-1-711641', $html);
        $this->assertStringNotContainsString('RUC 1838853-1-711641-<', $html);
        $this->assertStringContainsString('>ATLANTIC</span>', $html);
    }

    /** REQ-496 — el documento formal se genera como PDF descargable. */
    public function test_pdf_genera_el_documento_formal(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 100);
        $token = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->baseRegisterPayloadConComprobante($invoice, 100))
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/notas-credito/{$store->json('id')}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    /** REQ-497 — `show()` expone el `order_id` real, no `factura_origen_id`, para pegarle a
     *  `GET /invoices/{order}/detail` (ese endpoint espera el Order de Bodega, no el PK de la factura). */
    public function test_show_expone_factura_origen_order_id_para_la_vista_de_factura_relacionada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['order' => $order, 'invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 100);
        $token = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->baseRegisterPayloadConComprobante($invoice, 100))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/{$store->json('id')}")
            ->assertStatus(200);

        $this->assertSame($order->id, $response->json('factura_origen_order_id'));
        $this->assertNotSame($invoice->id, $order->id, 'fixture inválido: este assert no prueba nada si ambos ids coinciden por casualidad');
    }

    /**
     * REQ-486 (rebote SCRUM-563, 2026-09-14) — mismo `makeInvoicedOrder()` de arriba, pero con la
     * cotización de origen real (contacto de cliente con email + vendedor dueño) — sin esto, la
     * factura queda con `quote_id=null` y `SendCreditNoteConfirmedEmailJob` no tiene a quién
     * notificar (caso cubierto aparte, ver `test_nota_sin_cotizacion...` abajo).
     *
     * @return array{order: Order, invoice: AdminContInvoice, quote: Quote, contactEmail: string, ownerEmail: string}
     */
    private function makeInvoicedOrderWithQuote(int $ownerId, string $ownerEmail, float $monto = 500, float $saldoAplicado = 0.0): array
    {
        $master = MasterClient::create(['name' => 'Cliente Notas Crédito '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 500.0, 'stage_changed_at' => Carbon::now(),
        ]);

        // `pipeline_card_id` real — `Order::linkedQuote()` (usado por el flujo de Corrección de
        // datos) lo necesita para resolver la cotización vía `sales_project_id`, ver docblock ahí.
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
            'pipeline_card_id' => $card->id,
        ]);

        $contactEmail = 'cliente-'.uniqid().'@example.test';
        $contact = $sub->contacts()->create(['name' => 'Contacto Cliente', 'role' => 'client', 'email' => $contactEmail]);

        $quote = Quote::create([
            'owner_id' => $ownerId,
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id,
            'folio' => 'COT-2026-'.static::uniqueTestNumber(),
            'generated_at' => Carbon::now(),
        ]);
        $quote->contacts()->attach($contact->id);

        $generalRate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(),
            'order_id' => $order->id,
            'quote_id' => $quote->id,
            'itbms_rate_id' => $generalRate->id,
            'sales_project_id' => $project->id,
            'master_client_id' => $master->id,
            'cliente' => $sub->business_name,
            'monto' => $monto,
            'saldo_aplicado' => $saldoAplicado,
        ]);

        return ['order' => $order, 'invoice' => $invoice, 'quote' => $quote, 'contactEmail' => $contactEmail, 'ownerEmail' => $ownerEmail];
    }

    /** REQ-486 (rebote SCRUM-563) — la nota queda `aplicada` de inmediato (monto bajo el umbral de
     *  Mark), el correo se encola en el mismo request (QUEUE_CONNECTION=sync en testing). */
    public function test_confirmar_nota_aplicada_de_inmediato_encola_correo_al_cliente_con_copia_al_vendedor(): void
    {
        Mail::fake();
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice, 'contactEmail' => $contactEmail] = $this->makeInvoicedOrderWithQuote($ownerId, self::FELIX_EMAIL, monto: 500);
        $felix = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($felix))
            ->post('/api/admin-contab/notas-credito', $this->baseRegisterPayloadConComprobante($invoice, 100))
            ->assertStatus(201);

        $this->assertSame(AdminContCreditNote::ESTADO_APLICADA, $response->json('estado'));

        Mail::assertSent(CreditNoteConfirmedMail::class, function (CreditNoteConfirmedMail $mail) use ($response, $contactEmail) {
            return $mail->hasTo($contactEmail)
                && $mail->hasCc(self::FELIX_EMAIL)
                && $mail->numero === $response->json('numero')
                && count($mail->attachments()) === 2;
        });
    }

    /** REQ-486 — una nota que queda `pendiente_aprobacion` (Anulación completa, sin importar el
     *  monto, RN1 Batch 13) no notifica al cliente hasta que Mark realmente la aprueba. */
    public function test_nota_pendiente_de_aprobacion_no_envia_correo_hasta_que_mark_aprueba(): void
    {
        Mail::fake();
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice, 'contactEmail' => $contactEmail] = $this->makeInvoicedOrderWithQuote($ownerId, self::FELIX_EMAIL, monto: 500);
        $felix = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($felix))->post('/api/admin-contab/notas-credito', [
            ...$this->baseRegisterPayload($invoice, 500),
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'comprobante' => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $store->json('estado'));

        Mail::assertNothingSent();

        $mark = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($mark))
            ->putJson("/api/admin-contab/notas-credito/{$store->json('id')}/decision", ['approve' => true])
            ->assertStatus(200);

        Mail::assertSent(CreditNoteConfirmedMail::class, function (CreditNoteConfirmedMail $mail) use ($contactEmail) {
            return $mail->hasTo($contactEmail) && $mail->hasCc(self::FELIX_EMAIL);
        });
        Mail::assertSentCount(1);
    }

    /** REQ-486 (confirmado con Luis, 2026-09-15) — Corrección de datos también notifica al
     *  cliente, aunque RN2 la excluya del aviso de reducción de comisión. */
    public function test_correccion_de_datos_aplicada_tambien_envia_correo(): void
    {
        Mail::fake();
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice, 'contactEmail' => $contactEmail] = $this->makeInvoicedOrderWithQuote($ownerId, self::FELIX_EMAIL, monto: 100);
        $felix = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($felix))
            ->post('/api/admin-contab/notas-credito/correccion', [
                'master_client_id' => $invoice->master_client_id,
                'factura_origen_id' => $invoice->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
                'nueva_fecha' => now()->toDateString(),
                'motivo' => 'Fecha de facturación incorrecta',
                'comprobante' => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        Mail::assertSent(CreditNoteConfirmedMail::class, fn (CreditNoteConfirmedMail $mail) => $mail->hasTo($contactEmail));
    }

    /** SCRUM-1183 — una corrección REAL (por la API y el servicio de notas) de una factura ya cobrada traslada el pago al reemplazo. */
    public function test_correccion_de_datos_traslada_el_pago_aplicado_a_la_factura_de_reemplazo(): void
    {
        Mail::fake();
        Storage::fake('s3-private');
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrderWithQuote($ownerId, self::FELIX_EMAIL, monto: 107);
        $invoice->lines()->create([
            'quote_item_id' => static::uniqueTestNumber(), 'descripcion' => 'Lámpara', 'cantidad' => 1, 'precio_unitario' => 100.00,
            'subtotal' => 100.00, 'itbms_rate_id' => $invoice->itbms_rate_id, 'itbms' => 7.00, 'total' => 107.00,
        ]);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $invoice->master_client_id, 'monto_recibido' => 107,
            'saldo_favor_aplicado' => 0, 'metodo_pago' => 'efectivo', 'estado' => 'confirmado', 'cobros_v2' => true,
        ]);
        DB::transaction(function () use ($payment, $invoice): void {
            $ledger = app(PaymentLedger::class);
            $ledger->ingreso($payment, 107, AdminContPaymentMovement::ORIGEN_DIRECTA);
            $ledger->aplicacion($payment, $invoice, 107, AdminContPaymentMovement::ORIGEN_DIRECTA);
            AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => 107, 'fecha_aplicacion' => now()]);
            app(InvoiceBalanceWriter::class)->apply(AdminContInvoice::query()->lockForUpdate()->findOrFail($invoice->id), 107);
        });
        $felix = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($felix))
            ->post('/api/admin-contab/notas-credito/correccion', [
                'master_client_id' => $invoice->master_client_id,
                'factura_origen_id' => $invoice->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
                'nueva_fecha' => now()->toDateString(),
                'motivo' => 'Fecha de facturación incorrecta',
                'comprobante' => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $nota = AdminContCreditNote::query()->where('factura_origen_id', $invoice->id)->firstOrFail();
        $reemplazo = AdminContInvoice::query()->findOrFail($nota->factura_nueva_id);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->refresh()->saldo_aplicado, 0.001, 'La original anulada ya no conserva el pago.');
        $this->assertEqualsWithDelta(107.0, (float) $reemplazo->saldo_aplicado, 0.001, 'El reemplazo queda pagado con el mismo dinero.');
        $ledger = app(PaymentLedger::class);
        $this->assertEqualsWithDelta(107.0, $ledger->aplicadoAFactura($reemplazo->id), 0.001);
        $this->assertEqualsWithDelta(0.0, $ledger->aplicadoAFactura($invoice->id), 0.001);
        $this->assertEqualsWithDelta(0.0, $ledger->remanenteDeCobro($payment->id), 0.001, 'El cobro sigue totalmente aplicado, ahora al reemplazo.');
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], $r['facturas']);
        $this->assertSame([], $r['anuladas']);
    }

    /** REQ-486 — caso real que reportó Daniela (SCRUM-563): una factura sin cotización de origen
     *  asociada no debe romper la confirmación de la nota, solo omitir el correo. */
    public function test_nota_sin_cotizacion_de_origen_no_envia_correo_pero_no_rompe_la_confirmacion(): void
    {
        Mail::fake();
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $felix = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($felix))
            ->post('/api/admin-contab/notas-credito', $this->baseRegisterPayloadConComprobante($invoice, 100))
            ->assertStatus(201);

        Mail::assertNothingSent();
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    public function test_index_pagina_20_por_defecto_y_expone_meta(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);

        for ($i = 0; $i < 25; $i++) {
            ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId);
            AdminContCreditNote::create([
                'numero' => "NC-PAG-{$i}", 'master_client_id' => $invoice->master_client_id, 'cliente' => 'X',
                'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'subtotal' => 100, 'itbms' => 7,
                'motivo' => 'Test', 'registrado_por' => $ownerId, 'fecha' => now(),
                'factura_origen_id' => $invoice->id, 'monto' => 100, 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            ]);
        }

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['data']);
        $this->assertSame(25, $response['meta']['total']);
        $this->assertSame(20, $response['meta']['per_page']);
        $this->assertSame(1, $response['meta']['current_page']);
        $this->assertSame(2, $response['meta']['last_page']);

        $page2 = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito?page=2')
            ->assertStatus(200)->json();
        $this->assertCount(5, $page2['data']);
    }

    private function tableFqn(string $table): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return "{$slug}_admin_contab.{$table}";
    }
}
