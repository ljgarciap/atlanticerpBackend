<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCustomerCreditBalance;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — Cobros. */
class AdminContPaymentControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL  = 'conta@illuminations.com.pa';
    private const MARK_EMAIL   = 'mbekhar@illuminations.com.pa';
    private const YANETH_EMAIL = 'asistente@illuminations.com.pa';

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

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::YANETH_EMAIL, self::YANETH_EMAIL, 'asistente_administrativa');
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
        $master  = MasterClient::create(['name' => 'Cliente Cobros '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name,
            'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);

        return ['master' => $master, 'invoice' => $invoice];
    }

    // ── Gate de permisos — REQ-463 es más angosto (sin Yaneth) que el resto del batch ──────────

    public function test_summary_visible_para_felix_mark_pero_no_yaneth(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(200);
        $this->withHeaders($this->authHeaders($this->loginAsYaneth()))
            ->getJson('/api/admin-contab/payments/summary')->assertStatus(403);
    }

    public function test_open_invoices_si_visible_para_yaneth(): void
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

    public function test_default_bank_account_tarjeta_usa_banistmo_tarjeta_credito(): void
    {
        AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $tarjeta = AdminContBankAccount::create(['banco' => 'Banistmo', 'tipo_cuenta' => AdminContBankAccount::TIPO_TARJETA_CREDITO, 'ultimos_4_digitos' => '2222']);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/payments/default-bank-account?metodo_pago=tarjeta')
            ->assertStatus(200);

        $this->assertSame($tarjeta->id, $response->json('data.id'));
    }

    public function test_default_bank_account_efectivo_y_transferencia_usan_banco_general_corriente(): void
    {
        $corriente = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        foreach (['efectivo', 'transferencia', 'yappy'] as $metodo) {
            $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
                ->getJson("/api/admin-contab/payments/default-bank-account?metodo_pago={$metodo}")
                ->assertStatus(200);
            $this->assertSame($corriente->id, $response->json('data.id'), "metodo={$metodo}");
        }
    }

    public function test_default_bank_account_retencion_y_ajuste_devuelven_null(): void
    {
        AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

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
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(6200.0, (float) $invoice->refresh()->saldo_aplicado);
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.bank_movements", [
            'bank_account_id' => $account->id, 'tipo' => 'cobro', 'monto' => 6200,
        ]);
    }

    public function test_register_aplica_saldo_a_favor_y_reduce_monto_recibido(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        AdminContCustomerCreditBalance::create(['master_client_id' => $master->id, 'saldo' => 500]);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        // Efectivo (confirma de inmediato, REQ-469 RN3) — `transferencia` ya no consume el saldo a
        // favor al registrar, ver bloque REQ-469 (RN5 diferido hasta la confirmación, Batch 7).
        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'aplicar_saldo_favor' => true, 'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])->assertStatus(201);

        $this->assertSame(5700.0, (float) $response->json('monto_recibido'));
        $this->assertSame(500.0, (float) $response->json('saldo_favor_aplicado'));

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(0.0, (float) AdminContCustomerCreditBalance::where('master_client_id', $master->id)->value('saldo'));
    }

    public function test_register_saldo_favor_cubre_100_por_ciento_no_exige_cuenta_bancaria(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        AdminContCustomerCreditBalance::create(['master_client_id' => $master->id, 'saldo' => 6200]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'aplicar_saldo_favor' => true, 'metodo_pago' => 'transferencia',
            'monto_recibido_estimado' => 0,
        ])->assertStatus(201);

        $this->assertSame(0.0, (float) $response->json('monto_recibido'));
        $this->assertNull($response->json('bank_account_id'));
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseCount("{$slug}_admin_contab.bank_movements", 0);
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

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
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(0.0, (float) $invoice->refresh()->saldo_aplicado);
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->post('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
            'comprobante' => $file,
        ])->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.payment_attachments", [
            'payment_id' => $response->json('id'), 'nombre_archivo' => 'comprobante.pdf',
        ]);
    }

    public function test_register_sin_comprobante_lo_permite_igual(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id, 'monto_recibido_estimado' => 100,
        ])->assertStatus(201);
    }

    // ── REQ-470 — historial: búsqueda + filtros combinados ───────────────────────────────────

    public function test_historial_filtra_por_metodo_y_estado_combinados(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $this->assertSame('Felix Campos', $response->json('registrado_por'));
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);

        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoiceA->id, $invoiceB->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 6200,
        ])->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(4000.0, (float) $invoiceA->refresh()->saldo_aplicado);
        $this->assertSame(2200.0, (float) $invoiceB->refresh()->saldo_aplicado);
    }

    // ── REQ-463 — 4 tarjetas del mes ─────────────────────────────────────────────────────────

    public function test_summary_calcula_total_cantidad_promedio_y_metodo_principal(): void
    {
        ['master' => $m1, 'invoice' => $i1] = $this->makeClientWithInvoice(4000);
        ['master' => $m2, 'invoice' => $i2] = $this->makeClientWithInvoice(4000);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
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
        $this->assertSame('Felix Campos', $response->json('data.uploaded_by'));
        $this->assertNotEmpty($response->json('data.url'));
    }

    // ── REQ-473 — recibo formal (PDF) ────────────────────────────────────────────────────────

    public function test_pdf_del_recibo_devuelve_un_pdf_valido(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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

    // ── REQ-475 — confirmación manual ────────────────────────────────────────────────────────

    public function test_confirm_aplica_saldo_de_factura_saldo_favor_y_ledger_bancario(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(6200);
        AdminContCustomerCreditBalance::create(['master_client_id' => $master->id, 'saldo' => 500]);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'aplicar_saldo_favor' => true, 'metodo_pago' => 'transferencia', 'bank_account_id' => $account->id,
            'monto_recibido_estimado' => 5700,
        ])->assertStatus(201);
        $paymentId = (int) $created->json('id');
        $this->assertSame('esperando_confirmacion', $created->json('estado'));

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/payments/{$paymentId}/confirm")
            ->assertStatus(200);

        $this->assertSame('confirmado', $response->json('estado'));
        $this->assertMatchesRegularExpression('/^CONF-\d{3}$/', (string) $response->json('confirmacion.numero_confirmacion'));
        $this->assertSame('Felix Campos', $response->json('confirmacion.confirmado_por'));

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(6200.0, (float) $invoice->refresh()->saldo_aplicado);
        $this->assertSame(0.0, (float) AdminContCustomerCreditBalance::where('master_client_id', $master->id)->value('saldo'));
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseHas("{$slug}_admin_contab.bank_movements", [
            'bank_account_id' => $account->id, 'tipo' => 'cobro', 'monto' => 5700,
        ]);
    }

    public function test_confirm_sobre_cobro_ya_confirmado_falla_422(): void
    {
        ['master' => $master, 'invoice' => $invoice] = $this->makeClientWithInvoice(100);
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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
        $account = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '1111']);
        $token   = $this->loginAsFelix();

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
        $this->assertDatabaseCount('illuminations_admin_contab.payment_retention_attachments', 1);

        // reemplazo real — sigue habiendo 1 sola fila, con el archivo nuevo.
        $second = $this->withHeaders($this->authHeaders($token))
            ->post("/api/admin-contab/payments/{$payment->id}/retention-attachment", [
                'file' => UploadedFile::fake()->create('constancia-v2.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $this->assertSame('constancia-v2.pdf', $second->json('nombre_archivo'));
        $this->assertDatabaseCount('illuminations_admin_contab.payment_retention_attachments', 1);
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
}
