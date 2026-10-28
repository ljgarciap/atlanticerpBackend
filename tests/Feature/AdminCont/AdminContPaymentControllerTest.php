<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Services\PaymentReceiptPdfService;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — Cobros. */
class AdminContPaymentControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    /**
     * Fixtures cross-schema (ventas_diseno) ANTES de loguear — mismo gotcha ya documentado en
     * AdminContInvoiceControllerTest (spatie/laravel-multitenancy olvida el tenant tras cada
     * round-trip HTTP de loginAs()).
     *
     * @return array{master: MasterClient, invoice: AdminContInvoice}
     */
    private function makeClientWithInvoice(float $monto, float $saldoAplicado = 0.0): array
    {
        $master = MasterClient::create(['name' => 'Cliente Cobros '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name,
            'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);

        return ['master' => $master, 'invoice' => $invoice];
    }

    // ── SCRUM-865 — filtro only_with_invoice, reusado por "Registrar nueva nota" ────────────────

    public function test_clients_only_with_invoice_excluye_clientes_sin_ninguna_factura(): void
    {
        ['master' => $conFactura] = $this->makeClientWithInvoice(500);
        $sinFactura = MasterClient::create(['name' => 'Cliente Sin Factura '.uniqid()]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/clients?only_with_invoice=1')
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($conFactura->id, $ids);
        $this->assertNotContains($sinFactura->id, $ids);
    }

    public function test_clients_sin_only_with_invoice_incluye_todos_los_clientes(): void
    {
        ['master' => $conFactura] = $this->makeClientWithInvoice(500);
        $sinFactura = MasterClient::create(['name' => 'Cliente Sin Factura '.uniqid()]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/clients')
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($conFactura->id, $ids);
        $this->assertContains($sinFactura->id, $ids);
    }

    // ── SCRUM-905 — only_with_pending_invoice, más angosto que only_with_invoice, usado por
    //    "Registrar nuevo cobro" (independiente de SCRUM-865, nunca reusar uno para el otro) ────

    public function test_clients_only_with_pending_invoice_excluye_clientes_sin_nada_pendiente(): void
    {
        ['master' => $conPendiente] = $this->makeClientWithInvoice(500, saldoAplicado: 0);
        ['master' => $yaSaldado] = $this->makeClientWithInvoice(500, saldoAplicado: 500);
        $sinFactura = MasterClient::create(['name' => 'Cliente Sin Factura '.uniqid()]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/clients?only_with_pending_invoice=1')
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($conPendiente->id, $ids);
        $this->assertNotContains($yaSaldado->id, $ids, 'Cliente con factura 100% cobrada no tiene nada pendiente.');
        $this->assertNotContains($sinFactura->id, $ids);
    }

    public function test_clients_only_with_invoice_no_excluye_cliente_ya_saldado(): void
    {
        // SCRUM-905 — confirma que los 2 flags son independientes: only_with_invoice (SCRUM-865)
        // sigue incluyendo un cliente sin nada pendiente, a diferencia de only_with_pending_invoice.
        ['master' => $yaSaldado] = $this->makeClientWithInvoice(500, saldoAplicado: 500);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/clients?only_with_invoice=1')
            ->assertStatus(200);

        $this->assertContains($yaSaldado->id, array_column($response->json('data'), 'id'));
    }

    // ── Gate de permisos — REQ-463 es más angosto (sin Asistente Administrativa) que el resto del batch ──────────

    /**
     * Corrección SCRUM-881 (Gerencia, 2026-09-09) — REQ-463 originalmente excluía a Asistente Administrativa de las
     * tarjetas de indicadores de Cobros ("Visible para Contabilidad, Gerencia y Gerencia"), inconsistente con
     * el resto del módulo donde Asistente Administrativa sí tiene el mismo alcance que Contabilidad (ella veía el Historial
     * de cobros completo pero las 4 tarjetas en guion). Se corrige el gate de ruta para incluirla.
     */
    public function test_summary_visible_para_contabilidad_aprobador_y_asistente(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->withHeaders($this->authHeaders($this->loginAsYaneth()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
    }

    public function test_open_invoices_si_visible_para_asistente(): void
    {
        ['master' => $master] = $this->makeClientWithInvoice(500);

        $this->withHeaders($this->authHeaders($this->loginAsYaneth()))
            ->getJson("/api/admin-contab/payments/open-invoices?master_client_id={$master->id}")
            ->assertStatus(200);
    }

    // ── REQ-464 RN1 — facturas abiertas ──────────────────────────────────────────────────────

    public function test_open_invoices_excluye_facturas_pagadas_incobrables_y_anuladas(): void
    {
        ['master' => $master] = $this->makeClientWithInvoice(500);
        $this->makeClientWithInvoice(1); // otro cliente, no debe aparecer

        $paid = AdminContInvoice::create([
            'numero' => 'F-PAID', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 300, 'saldo_aplicado' => 300,
        ]);
        $uncollectible = AdminContInvoice::create([
            'numero' => 'F-UNCOL', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 200, 'es_incobrable' => true,
        ]);
        $voided = AdminContInvoice::create([
            'numero' => 'F-VOID', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 150, 'anulada_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/payments/open-invoices?master_client_id={$master->id}")
            ->assertStatus(200);

        /** @var list<array<string, mixed>> $invoicesJson */
        $invoicesJson = $response->json('invoices');
        $ids = array_column($invoicesJson, 'id');
        $this->assertNotContains($paid->id, $ids);
        $this->assertNotContains($uncollectible->id, $ids);
        $this->assertNotContains($voided->id, $ids);
    }

    // ── REQ-466 — auto-selección de cuenta bancaria ──────────────────────────────────────────

    public function test_default_bank_account_tarjeta_usa_banco_demo_b_tarjeta_credito(): void
    {
        AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $tarjeta = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'tipo_cuenta' => AdminContBankAccount::TIPO_TARJETA_CREDITO, 'ultimos_4_digitos' => '2222']);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/default-bank-account?metodo_pago=tarjeta')
            ->assertStatus(200);

        $this->assertSame($tarjeta->id, $response->json('data.id'));
    }

    public function test_default_bank_account_efectivo_y_transferencia_usan_banco_general_corriente(): void
    {
        $corriente = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        foreach (['efectivo', 'transferencia', 'yappy'] as $metodo) {
            $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
                ->getJson("/api/admin-contab/payments/default-bank-account?metodo_pago={$metodo}")
                ->assertStatus(200);
            $this->assertSame($corriente->id, $response->json('data.id'), "metodo={$metodo}");
        }
    }

    public function test_default_bank_account_retencion_y_ajuste_devuelven_null(): void
    {
        AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        foreach (['retencion_impuestos', 'ajuste_cuenta'] as $metodo) {
            $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
                ->getJson("/api/admin-contab/payments/default-bank-account?metodo_pago={$metodo}")
                ->assertStatus(200);
            $this->assertNull($response->json('data'), "metodo={$metodo}");
        }
    }

    // ── REQ-464/465/467 — registrar cobro ────────────────────────────────────────────────────

    public function test_register_cobro_simple_cubre_factura_y_crea_movimiento_bancario(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        // Efectivo (REQ-469 RN3) — confirma de inmediato, así que este caso "simple" sigue
        // verificando el efecto inmediato de saldo/ledger. `transferencia` ahora arranca
        // `esperando_confirmacion` (RN2) — ver bloque REQ-469 más abajo para ese caso.
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 6200,
        ])->assertStatus(201);

        $this->assertSame(6200.0, (float) $response->json('monto_recibido'));
        $this->assertSame('confirmado', $response->json('estado'));

        // El postJson anterior es un round-trip HTTP real — spatie/laravel-multitenancy olvida el
        // tenant "actual" al terminar cada request (mismo gotcha ya documentado en
        // AdminContInvoiceControllerTest) — re-establecer antes de cualquier Eloquent directo.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(6200.0, (float) $invoice->refresh()->saldo_aplicado);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.bank_movements", [
            'bank_account_id' => $account->id, 'tipo' => 'cobro', 'monto' => 6200,
        ]);
    }

    /** SCRUM-902 §4.4 — reemplaza `AdminContCustomerCreditBalance` (retirado) por un Abono nivel Cliente confirmado. */
    private function makeAbonoNivelCliente(int $masterClientId, float $monto): AdminContAbono
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '9999']);

        return AdminContAbono::create([
            'master_client_id' => $masterClientId,
            'monto_original' => $monto,
            'monto_usado' => 0,
            'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $account->id,
            'referencia' => 'TRF-SALDO-TEST',
            'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha_registro' => now(),
            'fecha_confirmacion' => now(),
        ]);
    }

    public function test_register_aplica_saldo_a_favor_y_reduce_monto_recibido(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $this->makeAbonoNivelCliente($master->id, 500);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        // Efectivo (confirma de inmediato, REQ-469 RN3) — `transferencia` ya no consume el saldo a
        // favor al registrar, ver bloque REQ-469 (RN5 diferido hasta la confirmación, Batch 7).
        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'monto_saldo_cliente_aplicado' => 500, 'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])->assertStatus(201);

        $this->assertSame(5700.0, (float) $response->json('monto_recibido'));
        $this->assertSame(500.0, (float) $response->json('saldo_favor_aplicado'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $abono = AdminContAbono::where('master_client_id', $master->id)->first();
        $this->assertSame(0.0, $abono->montoDisponible());
    }

    public function test_register_saldo_favor_cubre_100_por_ciento_no_exige_cuenta_bancaria(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $this->makeAbonoNivelCliente($master->id, 6200);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'monto_saldo_cliente_aplicado' => 6200, 'metodo_pago' => 'transferencia',
            'monto_recibido_estimado' => 0,
        ])->assertStatus(201);

        $this->assertSame(0.0, (float) $response->json('monto_recibido'));
        $this->assertNull($response->json('bank_account_id'));
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseCount("{$slug}_admin_contab.bank_movements", 0);
    }

    /** SCRUM-902 §4.4 Escenario 8 — el backend nunca confía en el monto que mande el HTTP: si
     *  supera el disponible real, 422 con el mensaje exacto del ticket. */
    public function test_register_rechaza_monto_de_saldo_cliente_mayor_al_disponible(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $this->makeAbonoNivelCliente($master->id, 400);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '2222']);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'monto_saldo_cliente_aplicado' => 500, 'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])
            ->assertStatus(422)
            ->assertJson(['message' => 'El monto ingresado supera el saldo a favor disponible de $400.00.']);
    }

    public function test_register_sin_cuenta_bancaria_cuando_monto_es_mayor_a_cero_falla(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'transferencia', 'monto_recibido_estimado' => 500,
        ])->assertStatus(422);
    }

    public function test_register_ajuste_de_cuenta_requiere_comentario(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'ajuste_cuenta', 'monto_recibido_estimado' => 500,
        ])->assertStatus(422);
    }

    public function test_register_retencion_impuestos_no_pide_cuenta_bancaria(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'retencion_impuestos', 'numero_documento_retencion' => 'DGI-12345',
            'monto_recibido_estimado' => 500,
        ])->assertStatus(201);

        $this->assertNull($response->json('bank_account_id'));
        $this->assertSame('DGI-12345', $response->json('numero_documento_retencion'));
    }

    public function test_register_link_pago_arranca_esperando_confirmacion_y_no_cuenta_en_summary(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'link_pago', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 500,
        ])->assertStatus(201);

        $this->assertSame('esperando_confirmacion', $response->json('estado'));

        $summary = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->assertSame(0, $summary->json('cantidad'));
        $this->assertSame(0.0, (float) $summary->json('total_cobrado'));

        // REQ-469 RN5 — mientras espera confirmación, no reduce el saldo pendiente de la factura
        // ni deja movimiento en el ledger bancario (ambos se aplican recién al confirmar, Batch 7).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0.0, (float) $invoice->refresh()->saldo_aplicado);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseCount("{$slug}_admin_contab.bank_movements", 0);
    }

    // ── REQ-469 — folio REC-XXX + estado real por método ─────────────────────────────────────

    public function test_register_asigna_folio_rec_consecutivo(): void
    {
        ['master' => $master, 'invoice' => $invoiceA] = $this->makeClientWithInvoice(100);
        $invoiceB = AdminContInvoice::create([
            'numero' => 'F-SEG', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 100,
        ]);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $r1 = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceA->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
        $r2 = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceB->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $this->assertMatchesRegularExpression('/^REC-\d{3}$/', (string) $r1->json('numero_recibo'));
        $this->assertNotSame($r1->json('numero_recibo'), $r2->json('numero_recibo'));
    }

    public function test_register_metodos_bancarios_arrancan_esperando_confirmacion(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $metodos = ['transferencia', 'cheque', 'deposito', 'tarjeta', 'yappy', 'link_pago'];

        // Fixtures ANTES de loginAs()/cualquier round-trip HTTP — spatie/laravel-multitenancy
        // pierde el tenant "actual" en cuanto se hace la primera llamada HTTP de la sesión de
        // test (login incluido, no solo el postJson del cobro), así que ningún Eloquent::create()
        // puede quedar después de loginAs() (mismo gotcha ya documentado en makeClientWithInvoice()).
        $fixtures = [];
        foreach ($metodos as $metodo) {
            $fixtures[$metodo] = $this->makeClientWithInvoice(100);
        }

        $token = $this->loginAsFelix();

        foreach ($metodos as $metodo) {
            ['master' => $master, 'invoice' => $invoice] = $fixtures[$metodo];

            $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
                'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
                'metodo_pago' => $metodo, 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
            ])->assertStatus(201);

            $this->assertSame('esperando_confirmacion', $response->json('estado'), "metodo={$metodo}");
        }
    }

    public function test_register_efectivo_retencion_y_ajuste_confirman_de_inmediato(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $metodos = ['efectivo', 'retencion_impuestos', 'ajuste_cuenta'];

        $fixtures = [];
        foreach ($metodos as $metodo) {
            $fixtures[$metodo] = $this->makeClientWithInvoice(100);
        }

        $token = $this->loginAsFelix();

        foreach ($metodos as $metodo) {
            ['master' => $master, 'invoice' => $invoice] = $fixtures[$metodo];

            $payload = [
                'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
                'metodo_pago' => $metodo, 'monto_recibido_estimado' => 100,
            ];
            if ($metodo === 'efectivo') {
                $payload['bank_account_id'] = $account->id;
            }
            if ($metodo === 'ajuste_cuenta') {
                $payload['comentario_ajuste'] = 'Ajuste de prueba';
            }

            $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', $payload)
                ->assertStatus(201);

            $this->assertSame('confirmado', $response->json('estado'), "metodo={$metodo}");
        }
    }

    // ── REQ-468 — comprobante de pago (carga de archivo) ─────────────────────────────────────

    public function test_register_con_comprobante_lo_deja_adjunto(): void
    {
        Storage::fake('s3-private');
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->post('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
            'comprobante' => $file,
        ])->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.payment_attachments", [
            'payment_id' => $response->json('id'), 'nombre_archivo' => 'comprobante.pdf',
        ]);
    }

    public function test_register_sin_comprobante_lo_permite_igual(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
    }

    // ── REQ-470 — historial: búsqueda + filtros combinados ───────────────────────────────────

    public function test_historial_filtra_por_metodo_y_estado_combinados(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        // Ambos fixtures ANTES de loginAs() — ver nota en el bloque de arriba.
        ['master' => $m1, 'invoice' => $i1] = $this->makeClientWithInvoice(100);
        ['master' => $m2, 'invoice' => $i2] = $this->makeClientWithInvoice(100);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $m1->id, 'invoice_ids' => [$i1->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $m2->id, 'invoice_ids' => [$i2->id],
            'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments?metodo=efectivo&estado=confirmado')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('total'));
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('efectivo', $rows[0]['metodo_pago']);
    }

    public function test_historial_busqueda_de_texto_libre_por_recibo(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
        $recibo = (string) $created->json('numero_recibo');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments?search='.$recibo)
            ->assertStatus(200);

        $this->assertCount(1, $response->json('rows'));
        $this->assertSame($recibo, $response->json('rows.0.numero_recibo'));

        $miss = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments?search=REC-NO-EXISTE')
            ->assertStatus(200);
        $this->assertCount(0, $miss->json('rows'));
    }

    // ── REQ-471 — detalle de un cobro ─────────────────────────────────────────────────────────

    public function test_detalle_de_cobro_incluye_facturas_cubiertas_con_su_monto(): void
    {
        ['master' => $master, 'invoice' => $invoiceA] = $this->makeClientWithInvoice(4000);
        $invoiceB = AdminContInvoice::create([
            'numero' => 'F-SEG', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 2200,
        ]);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceA->id, $invoiceB->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 6200,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200);

        $facturas = $response->json('facturas');
        $this->assertCount(2, $facturas);
        $montos = array_column($facturas, 'monto_aplicado');
        $this->assertContains(4000.0, array_map('floatval', $montos));
        $this->assertContains(2200.0, array_map('floatval', $montos));
        $this->assertSame('Contabilidad Test', $response->json('registrado_por'));
    }

    public function test_detalle_de_cobro_inexistente_devuelve_404(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/999999')
            ->assertStatus(404);
    }

    public function test_register_multiples_facturas_reduce_el_saldo_de_cada_una(): void
    {
        ['master' => $master, 'invoice' => $invoiceA] = $this->makeClientWithInvoice(4000);
        $invoiceB = AdminContInvoice::create([
            'numero' => 'F-B', 'order_id' => random_int(1, 999999), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 2200,
        ]);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceA->id, $invoiceB->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 6200,
        ])->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(4000.0, (float) $invoiceA->refresh()->saldo_aplicado);
        $this->assertSame(2200.0, (float) $invoiceB->refresh()->saldo_aplicado);
    }

    // ── Rebote Pre-QA (SCRUM-858) — factura ya saldada rechaza el cobro ─────────────────────────

    public function test_register_sobre_factura_ya_saldada_falla_422(): void
    {
        // saldo_aplicado === monto → factura 100% cubierta, mismo criterio de `puede_registrar_cobro`.
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500, 500);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 0,
        ])->assertStatus(422);

        $this->assertNotEmpty($response->json('message'));

        // No debe haber consumido folio ni creado el pago de $0.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseCount("{$slug}_admin_contab.payments", 0);
        $this->assertSame(500.0, (float) $invoice->refresh()->saldo_aplicado);
    }

    public function test_register_sobre_factura_con_saldo_parcial_sigue_permitido(): void
    {
        // saldo_aplicado (200) < monto (500) → todavía pendiente, no debe verse afectado por el
        // guard nuevo (no regresión del camino ya cubierto por otros tests de este bloque).
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(500, 200);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 300,
        ])->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(500.0, (float) $invoice->refresh()->saldo_aplicado);
    }

    public function test_register_con_una_factura_saldada_entre_varias_rechaza_todo_el_lote(): void
    {
        // Una sola factura ya saldada dentro de un lote de varias debe rechazar el cobro completo
        // (nunca registrar parcialmente sobre las demás).
        ['master' => $master, 'invoice' => $invoiceOpen] = $this->makeClientWithInvoice(500);
        $invoicePaid = AdminContInvoice::create([
            'numero' => 'F-PAID2', 'order_id' => self::uniqueTestNumber(), 'master_client_id' => $master->id,
            'cliente' => $master->name, 'monto' => 300, 'saldo_aplicado' => 300,
        ]);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceOpen->id, $invoicePaid->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 500,
        ])->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0.0, (float) $invoiceOpen->refresh()->saldo_aplicado);
    }

    // ── REQ-463 — 4 tarjetas del mes ─────────────────────────────────────────────────────────

    public function test_summary_calcula_total_cantidad_promedio_y_metodo_principal(): void
    {
        ['master' => $m1, 'invoice' => $i1] = $this->makeClientWithInvoice(4000);
        ['master' => $m2, 'invoice' => $i2] = $this->makeClientWithInvoice(4000);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        // `retencion_impuestos` confirma de inmediato igual que `efectivo` (REQ-469 RN3) — usado
        // acá en vez de `transferencia` para que ambos cobros SÍ cuenten en el resumen del mes.
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $m1->id, 'invoice_ids' => [$i1->id],
            'metodo_pago' => 'retencion_impuestos', 'monto_recibido_estimado' => 4000,
        ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $m2->id, 'invoice_ids' => [$i2->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 4000,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);

        $this->assertSame(2, $response->json('cantidad'));
        $this->assertSame(8000.0, (float) $response->json('total_cobrado'));
        $this->assertSame(4000.0, (float) $response->json('promedio'));
        $this->assertNotNull($response->json('metodo_principal.metodo'));
        $this->assertSame(50.0, (float) $response->json('metodo_principal.porcentaje'));
    }

    // ── Batch 7 (SCRUM-549→552, REQ-472→475) ─────────────────────────────────────────────────

    // ── REQ-472 — ver comprobante ─────────────────────────────────────────────────────────────

    public function test_attachment_devuelve_null_cuando_el_cobro_no_tiene_comprobante(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id').'/attachment')
            ->assertStatus(200);

        $this->assertNull($response->json('data'));
    }

    public function test_attachment_devuelve_url_firmada_y_metadata_cuando_si_tiene_comprobante(): void
    {
        Storage::fake('s3-private');
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->post('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
            'comprobante' => $file,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id').'/attachment')
            ->assertStatus(200);

        $this->assertSame('comprobante.pdf', $response->json('data.nombre_archivo'));
        $this->assertSame('Contabilidad Test', $response->json('data.uploaded_by'));
        $this->assertNotEmpty($response->json('data.url'));
    }

    // ── REQ-473 — recibo formal (PDF) ────────────────────────────────────────────────────────

    public function test_pdf_del_recibo_devuelve_un_pdf_valido(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/payments/'.$created->json('id').'/pdf')
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_de_cobro_inexistente_devuelve_404(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->get('/api/admin-contab/payments/999999/pdf')
            ->assertStatus(404);
    }

    // ── SCRUM-936 (PQ-928-2, hallazgo de Pre-QA de SCRUM-928 fuera de su alcance original) ──────
    // El Recibo de pago es un documento que recibe el cliente — nunca debe mostrar la cuenta
    // enmascarada (****XXXX), mismo criterio D-6 ya aplicado en Factura/Estado de Cuenta por
    // SCRUM-928. Smoke test contra el endpoint real (wiring de pdf()) + tests de contenido
    // bypasseando dompdf, mismo patrón que test_pdf_del_recibo_no_duplica_el_escape_de_comillas_...
    // arriba — dompdf comprime el stream de texto, no es buscable como string plano en el binario.

    public function test_pdf_del_recibo_no_se_rompe_con_cuenta_sin_numero_completo_cargado(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/payments/'.$created->json('id').'/pdf')
            ->assertStatus(200);
    }

    public function test_pdf_del_recibo_muestra_numero_de_cuenta_completo_y_nunca_asteriscos(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE,
            'ultimos_4_digitos' => '1111', 'numero_cuenta_completo' => '04-72-99-123456-1',
        ]);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        // La misma línea que ejecuta AdminContPaymentController::pdf() antes de generar el PDF —
        // presentDetail() (show(), arriba) se mantiene enmascarado a propósito, solo el PDF pisa
        // esta clave puntual.
        $detail['bank_account'] = $account->fresh()->receiptLabel();

        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringContainsString('Cuenta destino: Banco Demo A — corriente 04-72-99-123456-1', $html);
        $this->assertStringNotContainsString('****', $html);
    }

    public function test_pdf_del_recibo_muestra_guion_cuando_no_hay_numero_de_cuenta_completo(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $detail['bank_account'] = $account->fresh()->receiptLabel();

        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringContainsString('Cuenta destino: Banco Demo A — corriente -', $html);
        $this->assertStringNotContainsString('****', $html);
    }

    public function test_label_sigue_enmascarado_para_selectores_internos(): void
    {
        $account = AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE,
            'ultimos_4_digitos' => '4554', 'numero_cuenta_completo' => '04-72-99-123456-1',
        ]);

        $this->assertSame('Banco Demo A — corriente ****4554', $account->label());
        $this->assertSame('Banco Demo A — corriente 04-72-99-123456-1', $account->receiptLabel());
    }

    // Rebote de QA (SCRUM-858, qa.qa 2026-09-08) — sin RUC cargado en Configuración Fiscal,
    // el PDF no debe romperse (antes mostraba "RUC -" en el header; el guard nuevo simplemente
    // oculta la línea, ver payment-receipt-pdf.blade.php).
    public function test_pdf_del_recibo_no_se_rompe_sin_ruc_cargado(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        AdminContFiscalSettings::current()->update(['ruc' => '', 'dv' => '']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/payments/'.$created->json('id').'/pdf')
            ->assertStatus(200);
    }

    // ── SCRUM-550 (rebote de Gerencia, 2026-09-10) — RUC/contacto de empresa, saldo a favor, y
    // eliminación de la línea de confirmación en el recibo de cobro ────────────────────────────

    public function test_pdf_del_recibo_muestra_ruc_y_contacto_de_la_empresa(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        AdminContFiscalSettings::current()->update([
            'ruc' => '155646789-2-2019', 'dv' => '44',
            'telefono_contacto' => '+507 6000-9999', 'correo_contacto' => 'facturacion@atlantic.com.pa',
        ]);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringContainsString('RUC 155646789-2-2019-44', $html);
        $this->assertStringContainsString('+507 6000-9999', $html);
        $this->assertStringContainsString('facturacion@atlantic.com.pa', $html);
    }

    public function test_pdf_del_recibo_omite_bloque_de_saldo_a_favor_cuando_no_aplica(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringNotContainsString('Saldo a favor aplicado', $html);
        $this->assertStringNotContainsString('Total facturas', $html);
    }

    public function test_pdf_del_recibo_muestra_saldo_a_favor_aplicado_y_total_facturas_antes(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $this->makeAbonoNivelCliente($master->id, 500);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        // Efectivo confirma de inmediato (RN3) — no hace falta el paso de confirm() para este caso.
        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'monto_saldo_cliente_aplicado' => 500, 'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])->assertStatus(201);
        $this->assertSame(500.0, (float) $created->json('saldo_favor_aplicado'));

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();
        $this->assertSame(6200.0, (float) $detail['total_facturas'], 'Total de facturas ANTES de aplicar el saldo a favor.');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringContainsString('Saldo a favor aplicado', $html);
        $this->assertStringContainsString('Total facturas', $html);
        $this->assertStringContainsString('6,200.00', $html, 'Total de facturas antes de aplicar el saldo a favor.');
        $this->assertStringContainsString('500.00', $html, 'Monto de saldo a favor aplicado.');
        $this->assertStringContainsString('5,700.00', $html, 'Total recibido final, ya con el saldo a favor aplicado.');
    }

    public function test_pdf_del_recibo_nunca_muestra_la_linea_de_confirmacion(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
        $paymentId = (int) $created->json('id');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/payments/{$paymentId}/confirm")
            ->assertStatus(200);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/payments/{$paymentId}")
            ->assertStatus(200)->json();
        $this->assertNotNull($detail['confirmacion'], 'El detalle sigue trayendo la confirmación — solo el PDF la elimina.');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringNotContainsString('Confirmación CONF-', $html);
        $this->assertStringNotContainsString((string) $detail['confirmacion']['numero_confirmacion'], $html);
    }

    /**
     * RN2 — el recibo de un cobro "Esperando confirmación" debe mostrar ese estado de forma
     * visible, nunca "Confirmado". Gerencia no pudo validarlo con la evidencia que tenía a mano
     * (su cobro de prueba ya estaba confirmado) — este test lo cubre en vivo con un cobro real en
     * ese estado (transferencia, arranca `esperando_confirmacion` por RN2 de REQ-469).
     */
    public function test_pdf_del_recibo_esperando_confirmacion_muestra_estado_visible_no_confirmado(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
        $this->assertSame('esperando_confirmacion', $created->json('estado'));

        // También debe seguir sirviendo un PDF válido vía el endpoint real, sin romperse.
        $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/payments/'.$created->json('id').'/pdf')
            ->assertStatus(200);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)->json();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view(
            'admin-cont.payment-receipt-pdf',
            app(PaymentReceiptPdfService::class)->viewData($detail),
        )->render();

        $this->assertStringContainsString('estado-pill esperando_confirmacion', $html);
        $this->assertStringContainsString('Esperando confirmación', $html);
        $this->assertStringNotContainsString('Confirmado', $html, 'Nunca debe mostrar "Confirmado" mientras espera confirmación.');
    }

    // Pre-QA (2026-09-09) — encontrado en vivo contra dev.atlanticerp.ai al poblar Configuración Fiscal
    // con una comilla (justamente para probar el escaping): el PDF mostraba literalmente
    // "Corp\&#039;s" en el pie de página en vez de "Corp's". PdfFooterText::forFiscal() ya hacía
    // addslashes() para el contexto de <script type="text/php"> de dompdf, pero
    // payment-receipt-pdf.blade.php interpolaba el resultado con {{ }} (htmlspecialchars),
    // aplicando un segundo escape sobre uno que ya estaba hecho. El test anterior
    // (test_pdf_del_recibo_no_se_rompe_sin_ruc_cargado) solo afirmaba HTTP 200 — nunca hubiera
    // agarrado esto (dompdf degrada en silencio, nunca tira 500 ahí). Este test renderiza la vista
    // Blade directamente (sin pasar por dompdf/PDF binario) para verificar el HTML/PHP que
    // realmente se genera, mismo criterio que QuotePdfLayoutEstimatorTest (tests/Unit).
    public function test_pdf_del_recibo_no_duplica_el_escape_de_comillas_en_el_pie_de_pagina(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => "Atlantic USA Corp's",
            'direccion_fiscal' => 'Calle 57 Este, Panama',
        ]);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200)
            ->json();

        // Las llamadas HTTP simuladas de arriba (withHeaders()->postJson()/getJson()) resetean el
        // search_path del tenant al terminar cada request-cycle simulado — sin esto, la llamada
        // directa a AdminContFiscalSettings::current() (vía viewData()) de abajo falla con
        // "relation fiscal_settings does not exist" (busca en el schema public).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $viewData = app(PaymentReceiptPdfService::class)->viewData($detail);
        $html = view('admin-cont.payment-receipt-pdf', $viewData)->render();

        // Scope al bloque <script type="text/php"> del pie de página — la línea "empresa-nombre"
        // del header SÍ debe seguir usando {{ }} normal (HTML-escapado correctamente ahí, un
        // "&#039;" legítimo en ese contexto no es el bug: dompdf lo decodifica de vuelta a un
        // apóstrofe visible como cualquier otro texto HTML).
        $this->assertStringContainsString('<script type="text/php">', $html);
        $footerScript = substr($html, (int) strpos($html, '<script type="text/php">'));
        $this->assertStringContainsString('$companyText = "Atlantic USA Corp\'s - Calle 57 Este, Panama";', $footerScript);
        $this->assertStringNotContainsString('&#039;', $footerScript);
    }

    // Hallazgo real al re-verificar SCRUM-858 en dev.atlanticerp.ai (2026-09-08) — la pantalla de
    // Configuración Fiscal (exclusiva de Gerencia) nunca se llenó en NINGÚN entorno (dev/test/prod,
    // confirmado por tinker en vivo); `nombre_comercial`/`direccion_fiscal` quedan en '' por
    // default de la migración. Sin el fallback de PdfFooterText, el encabezado/pie del recibo
    // salían en blanco (nunca "AtlanticERP", pero tampoco "Atlantic") — que es lo que qa vio y
    // reportó como "el pie de página real no se ve". Fix es a nivel de dato (Gerencia debe cargar la
    // config real, ticket de seguimiento pendiente), pero el código nunca debe mostrar un
    // encabezado/pie vacío mientras tanto. Prueba pura sobre PdfFooterText — sin tocar DB/tenant,
    // no necesita el modelo persistido, solo su forma.
    // Pre-QA (2026-09-09) — el pie de página con AdminContFiscalSettings vacío (el estado real
    // hoy en dev/test/prod) debe mostrar el mismo texto estándar completo ya hardcodeado en
    // quote-pdf.blade.php/service-quote-pdf.blade.php ("<razón social> - <dirección>
    // Este..."), no solo "Atlantic" a secas — eso incumplía el criterio literal del ticket
    // (SCRUM-858 AC #4) y la consistencia visual pedida contra cotizaciones (AC #6). El nombre de
    // marca en el encabezado (companyName(), usado en la línea "empresa-nombre" del header, no en
    // el pie) sigue cayendo a "Atlantic" a secas — ver PdfFooterTextTest para el detalle
    // completo del fallback.
    public function test_pdf_footer_text_usa_el_pie_de_pagina_estandar_cuando_configuracion_fiscal_esta_vacia(): void
    {
        $fiscal = new AdminContFiscalSettings(['nombre_comercial' => '', 'direccion_fiscal' => '']);

        $this->assertSame('Atlantic', PdfFooterText::companyName($fiscal));
        $this->assertSame(
            'Atlantic', // Fase 4a: respaldo neutro (solo la marca)
            PdfFooterText::forFiscal($fiscal)
        );
    }

    // ── REQ-475 — confirmación manual ────────────────────────────────────────────────────────

    public function test_confirm_aplica_saldo_de_factura_saldo_favor_y_ledger_bancario(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        $abono = $this->makeAbonoNivelCliente($master->id, 500);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'monto_saldo_cliente_aplicado' => 500, 'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])->assertStatus(201);
        $paymentId = (int) $created->json('id');
        $this->assertSame('esperando_confirmacion', $created->json('estado'));

        // El abono todavía NO se consumió — diferido hasta confirm() (RN5 de REQ-469, mismo
        // criterio ya establecido para saldo_aplicado/bank_movements).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(500.0, $abono->refresh()->montoDisponible());

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/payments/{$paymentId}/confirm")
            ->assertStatus(200);

        $this->assertSame('confirmado', $response->json('estado'));
        $this->assertMatchesRegularExpression('/^CONF-\d{3}$/', (string) $response->json('confirmacion.numero_confirmacion'));
        $this->assertSame('Contabilidad Test', $response->json('confirmacion.confirmado_por'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(6200.0, (float) $invoice->refresh()->saldo_aplicado);
        $this->assertSame(0.0, $abono->refresh()->montoDisponible());
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.bank_movements", [
            'bank_account_id' => $account->id, 'tipo' => 'cobro', 'monto' => 5700,
        ]);
    }

    public function test_confirm_sobre_cobro_ya_confirmado_falla_422(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        // Efectivo confirma de inmediato (RN3) — nunca pasa por "esperando_confirmacion".
        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/payments/'.$created->json('id').'/confirm')
            ->assertStatus(422);
    }

    public function test_confirm_no_puede_correrse_dos_veces(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
        $paymentId = (int) $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/payments/{$paymentId}/confirm")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/payments/{$paymentId}/confirm")->assertStatus(422);
    }

    public function test_confirm_de_cobro_inexistente_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->putJson('/api/admin-contab/payments/999999/confirm')
            ->assertStatus(422);
    }

    public function test_detalle_incluye_cliente_y_metodo_pago_label(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/payments/'.$created->json('id'))
            ->assertStatus(200);

        $this->assertSame($master->name, $response->json('cliente'));
        $this->assertSame('Efectivo', $response->json('metodo_pago_label'));
        $this->assertNull($response->json('confirmacion'));
    }

    // ── Batch 19 (SCRUM-602, REQ-525) — constancia de retención ──────────────────────────────

    public function test_retention_attachment_sube_y_permite_reemplazar(): void
    {
        Storage::fake('s3-private');

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-9110', 'master_client_id' => 1, 'monto_recibido' => 3450, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_RETENCION_IMPUESTOS,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $token = $this->loginAsFelix();

        $first = $this->withHeaders($this->authHeaders($token))
            ->post("/api/admin-contab/payments/{$payment->id}/retention-attachment", [
                'file' => UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $this->assertSame('constancia.pdf', $first->json('nombre_archivo'));
        $this->assertDatabaseCount('atlantic_admin_contab.payment_retention_attachments', 1);

        // reemplazo real — sigue habiendo 1 sola fila, con el archivo nuevo.
        $second = $this->withHeaders($this->authHeaders($token))
            ->post("/api/admin-contab/payments/{$payment->id}/retention-attachment", [
                'file' => UploadedFile::fake()->create('constancia-v2.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $this->assertSame('constancia-v2.pdf', $second->json('nombre_archivo'));
        $this->assertDatabaseCount('atlantic_admin_contab.payment_retention_attachments', 1);
    }

    public function test_retention_attachment_rechaza_cobro_con_otro_metodo(): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-9111', 'master_client_id' => 1, 'monto_recibido' => 100, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/admin-contab/payments/{$payment->id}/retention-attachment", [
                'file' => UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    public function test_historial_pagina_20_por_defecto_sin_afectar_total_global(): void
    {
        for ($i = 0; $i < 25; $i++) {
            AdminContPayment::create([
                'numero_recibo' => 'REC-PAG-'.$i, 'master_client_id' => 1, 'monto_recibido' => 100, 'saldo_favor_aplicado' => 0,
                'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
            ]);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['rows']);
        $this->assertSame(25, $response['total']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);

        $page2 = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments?page=2')
            ->assertStatus(200)->json();
        $this->assertCount(5, $page2['rows']);
    }
}
