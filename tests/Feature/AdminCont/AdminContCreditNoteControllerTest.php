<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContCustomerCreditBalance;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
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
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Batch 10 de Admin&Cont (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones. */
class AdminContCreditNoteControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'conta@illuminations.com.pa';

    /** Batch 13 — mismo email real que `AdminContInvoiceControllerTest`/`AdminContFiscalSettingsControllerTest`. */
    private const MARK_EMAIL = 'mbekhar@illuminations.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{order: Order, invoice: AdminContInvoice} */
    private function makeInvoicedOrder(int $ownerId, float $monto = 500, float $saldoAplicado = 0.0): array
    {
        $master  = MasterClient::create(['name' => 'Cliente Notas Crédito '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
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
            'numero'       => 'F-'.static::uniqueTestNumber(),
            'order_id'     => $order->id,
            'itbms_rate_id' => $generalRate->id,
            'master_client_id' => $master->id,
            'cliente'      => $sub->business_name,
            'monto'        => $monto,
            'saldo_aplicado' => $saldoAplicado,
        ]);

        return ['order' => $order, 'invoice' => $invoice];
    }

    /** @return array<string, mixed> */
    private function baseRegisterPayload(AdminContInvoice $invoice, float $monto): array
    {
        return [
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $invoice->id,
            'monto'              => $monto,
            'motivo'             => 'Descuento comercial acordado con el cliente',
        ];
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
            'order_id'      => $order->id,
            'status'        => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO,
            'contact_name'  => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by'    => $ownerId,
        ]);
        $customerReturn->lines()->create([
            'order_item_id'        => $item->id,
            'reference_snapshot'   => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot,
            'unit_price'           => $item->unit_price,
            'qty_requested'        => 2,
            'qty_received'         => 2,
            'reason'               => 'danado_defectuoso',
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

    public function test_summary_devuelve_todo_en_cero_sin_notas_registradas(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/notas-credito/summary')
            ->assertStatus(200);

        $response->assertJson([
            'total_acreditado'             => 0,
            'numero_notas'                  => 0,
            'pendientes_aprobacion_monto'    => 0,
            'pendientes_aprobacion_cantidad' => 0,
            'devoluciones_por_generar'       => 0,
            'nota_promedio'                  => 0,
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
            'total_acreditado'               => 300,
            'numero_notas'                     => 2,
            'pendientes_aprobacion_monto'      => 6000,
            'pendientes_aprobacion_cantidad'   => 1,
            'nota_promedio'                    => 150,
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
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

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
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 1000))
            ->assertStatus(201);

        $response->assertJson(['estado' => AdminContCreditNote::ESTADO_APLICADA, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);
        $this->assertEquals(1000.0, DB::table($this->tableFqn('invoices'))->where('id', $invoice->id)->value('saldo_aplicado'));
    }

    public function test_registrar_con_excedente_devuelto_crea_movimiento_bancario_de_devolucion(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        // saldo pendiente real = 300 - 300 = 0, monto de la nota 1000 → excedente 1000.
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayload($invoice, 1000), 'resultado' => 'devuelto', 'cuenta_bancaria_salida_id' => $account->id];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        $response->assertJson(['resultado' => 'devuelto']);
        $this->assertDatabaseHas($this->tableFqn('bank_movements'), [
            'bank_account_id' => $account->id, 'tipo' => AdminContBankMovement::TIPO_DEVOLUCION, 'monto' => 1000,
        ]);
    }

    public function test_registrar_con_excedente_como_saldo_a_favor_aumenta_el_saldo_del_cliente(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayload($invoice, 400), 'resultado' => 'saldo_favor'];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        $balance = DB::table($this->tableFqn('customer_credit_balances'))->where('master_client_id', $invoice->master_client_id)->value('saldo');
        $this->assertEquals(400.0, $balance);
    }

    public function test_excedente_sin_resultado_es_rechazado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 400))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resultado']);
    }

    public function test_resultado_devuelto_sin_cuenta_bancaria_es_rechazado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 300, saldoAplicado: 300);
        $token = $this->loginAsFelix();

        $payload = [...$this->baseRegisterPayload($invoice, 400), 'resultado' => 'devuelto'];

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
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'monto'              => 1, // debe ignorarse — la nota debe quedar en 500 (total de la factura).
            'motivo'             => 'Pedido cancelado por el cliente',
            'comprobante'        => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
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
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'motivo'             => 'Pedido cancelado, nunca cobrado',
            'comprobante'        => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);

        $response->assertJson(['resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);
        $this->assertSame(0, DB::table($this->tableFqn('bank_movements'))->count());
        $this->assertNull(DB::table($this->tableFqn('customer_credit_balances'))->where('master_client_id', $invoice->master_client_id)->first());
    }

    public function test_anulacion_completa_sin_comprobante_es_rechazada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $invoice->id,
            'motivo'             => 'Pedido cancelado por el cliente',
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

    public function test_devolucion_de_mercancia_nunca_exige_comprobante_aunque_supere_el_umbral(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 10000);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA,
            'factura_origen_id' => $invoice->id,
            'monto'              => 6000,
            'motivo'             => 'Devolución de mercancía confirmada por Bodega',
        ];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $payload)
            ->assertStatus(201);
    }

    public function test_correccion_de_datos_todavia_no_soportada_es_rechazada(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAsFelix();

        $payload = [
            'master_client_id'  => $invoice->master_client_id,
            'cliente'            => $invoice->cliente,
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
            'factura_origen_id' => $invoice->id,
            'motivo'             => 'Corrección de ITBMS',
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
        $master  = MasterClient::create(['name' => 'Cliente Corrección '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Corrección '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Corrección '.uniqid()]);
        $card    = PipelineCard::create([
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
            'numero'           => 'F-'.static::uniqueTestNumber(),
            'order_id'         => $order->id,
            'quote_id'         => $quote->id,
            'itbms_rate_id'    => $generalRate->id,
            'master_client_id' => $master->id,
            'cliente'          => $sub->business_name,
            'monto'            => 500,
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
            'master_client_id'                => $invoice->master_client_id,
            'factura_origen_id'                => $invoice->id,
            'motivo_correccion'                => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
            'nuevo_tratamiento_itbms_rate_id'  => $nuevoRateId,
            'motivo'                            => 'Prueba Pre-QA — tratamiento ITBMS incorrecto',
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

    public function test_store_y_facturas_rechazan_a_un_rol_no_autorizado(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 500);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

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
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'comprobante'        => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
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
            'tipo'               => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'comprobante'        => UploadedFile::fake()->create('anulacion.pdf', 100, 'application/pdf'),
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

    /** `mark_only` — un rol distinto de Mark, aunque sea Gerencia genérica, no puede decidir. */
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

    /** REQ-495 RN2 — mensaje explícito cuando la nota no tiene comprobante. */
    public function test_comprobante_devuelve_false_cuando_no_tiene_adjunto(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 100);
        $token = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 100))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/{$store->json('id')}/comprobante")
            ->assertStatus(200);

        $this->assertFalse($response->json('tiene_comprobante'));
    }

    /** REQ-496 — el documento formal se genera como PDF descargable. */
    public function test_pdf_genera_el_documento_formal(): void
    {
        $ownerId = $this->userIdByEmail(self::FELIX_EMAIL);
        ['invoice' => $invoice] = $this->makeInvoicedOrder($ownerId, monto: 100);
        $token = $this->loginAsFelix();

        $store = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 100))
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
            ->postJson('/api/admin-contab/notas-credito', $this->baseRegisterPayload($invoice, 100))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/{$store->json('id')}")
            ->assertStatus(200);

        $this->assertSame($order->id, $response->json('factura_origen_order_id'));
        $this->assertNotSame($invoice->id, $order->id, 'fixture inválido: este assert no prueba nada si ambos ids coinciden por casualidad');
    }

    private function tableFqn(string $table): string
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        return "{$slug}_admin_contab.{$table}";
    }
}
