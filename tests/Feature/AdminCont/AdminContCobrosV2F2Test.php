<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F2 — concepto de cobros (desde la Fase 15a el único): el cobro es dinero que entra (con o sin factura); lo que
 * se aplica a facturas lo decide el usuario (parcial permitido) y el resto queda como saldo a favor del cliente, que solo vive en el libro.
 */
class AdminContCobrosV2F2Test extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function remakeTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Inicia sesión como Contabilidad y deja la bandera del concepto nuevo como se pide. */
    private function headers(): array
    {
        $token = $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
        $this->remakeTenant();

        return $this->authHeaders($token);
    }

    private function cuenta(): AdminContBankAccount
    {
        return AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999)]);
    }

    private function factura(MasterClient $master, float $monto, float $saldoAplicado = 0.0): AdminContInvoice
    {
        return AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
    }

    /** @return array<string, mixed> */
    private function cobro(int $masterId, float $monto, string $metodo, ?int $accountId, array $aplicaciones = []): array
    {
        return array_filter([
            'master_client_id' => $masterId, 'monto_recibido' => $monto, 'metodo_pago' => $metodo, 'bank_account_id' => $accountId,
            'aplicaciones' => $aplicaciones === [] ? null : $aplicaciones,
        ], fn ($v) => $v !== null);
    }

    private function sinDiferencias(): void
    {
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], $r['facturas'], 'Facturas con diferencia: '.json_encode($r['facturas']));
        $this->assertSame([], $r['clientes'], 'Clientes con diferencia: '.json_encode($r['clientes']));
    }

    // ── configuración ──────────────────────────────────────────────────────────────────────

    public function test_la_configuracion_dice_la_ventana_de_movimiento_reciente(): void
    {
        $h = $this->headers();
        $this->withHeaders($h)->getJson('/api/admin-contab/payments/config')->assertStatus(200)->assertExactJson(['compensaciones_dias_movimiento' => 30]);

        $this->remakeTenant();
        app(CobrosConfigService::class)->setDiasMovimientoReciente(45);
        $this->withHeaders($h)->getJson('/api/admin-contab/payments/config')->assertStatus(200)->assertExactJson(['compensaciones_dias_movimiento' => 45]);
    }

    // ── cobro sin factura y con aplicación directa parcial ─────────────────────────────────────

    public function test_un_cobro_sin_factura_queda_como_saldo_a_favor_del_cliente_y_entra_a_caja(): void
    {
        $master = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $cuenta = $this->cuenta();
        $h = $this->headers();

        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 700, 'efectivo', $cuenta->id))->assertStatus(201);
        $this->assertSame('confirmado', $r->json('estado'));
        $this->assertSame([], $r->json('facturas'));
        $this->assertSame(700.0, (float) $r->json('saldo_sin_aplicar'));

        $this->remakeTenant();
        $this->assertEqualsWithDelta(700.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertSame(1, AdminContBankMovement::where('source_type', 'payment')->where('source_id', $r->json('id'))->count(), 'La caja se cuenta por el cobro, aplicado o no.');
        $this->sinDiferencias();
    }

    public function test_aplicacion_directa_parcial_aplica_lo_indicado_y_el_resto_es_saldo_a_favor(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $factura = $this->factura($master, 300);
        $cuenta  = $this->cuenta();
        $h = $this->headers();

        // El cliente paga 500 y dice que $200 son para esta factura (que debe 300): la factura queda con $100 pendientes y $300 a favor.
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'efectivo', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 200]]))->assertStatus(201);
        $this->assertSame(300.0, (float) $r->json('saldo_sin_aplicar'));
        $this->assertSame(200.0, (float) $r->json('facturas.0.monto_aplicado'));

        $this->remakeTenant();
        $this->assertEqualsWithDelta(200.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(300.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertNotNull(DB::table((new \App\Modules\AdminCont\Models\AdminContPaymentInvoice())->getTable())->where('payment_id', $r->json('id'))->value('fecha_aplicacion'));
        $this->sinDiferencias();
    }

    public function test_la_aplicacion_directa_valida_contra_lo_pendiente_y_contra_el_cobro(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $otro    = MasterClient::create(['name' => 'Cliente Otro '.uniqid()]);
        $factura = $this->factura($master, 300, 100);   // pendiente 200
        $ajena   = $this->factura($otro, 300);
        $cuenta  = $this->cuenta();
        $h = $this->headers();

        // Más que lo pendiente de la factura.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'efectivo', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 250]]))->assertStatus(422);
        $this->remakeTenant();
        // Más que el cobro.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 100, 'efectivo', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 150]]))->assertStatus(422);
        $this->remakeTenant();
        // Factura de otro cliente.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'efectivo', $cuenta->id, [['invoice_id' => $ajena->id, 'monto' => 100]]))->assertStatus(422);
        $this->remakeTenant();
        // La misma factura dos veces.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'efectivo', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 50], ['invoice_id' => $factura->id, 'monto' => 50]]))->assertStatus(422);
        $this->remakeTenant();

        $this->assertSame(0, AdminContPayment::where('master_client_id', $master->id)->count(), 'Ningún intento inválido deja un cobro a medias.');
        $this->assertEqualsWithDelta(100.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
    }

    public function test_retencion_y_ajuste_deben_aplicarse_completos_a_facturas(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $factura = $this->factura($master, 300);
        $h = $this->headers();

        // Sin aplicar nada: no hay dinero real que deje saldo.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 100, 'retencion_impuestos', null))->assertStatus(422);
        $this->remakeTenant();
        // Aplicado completo: se acepta, sin cuenta y sin movimiento bancario.
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 100, 'retencion_impuestos', null, [['invoice_id' => $factura->id, 'monto' => 100]]))->assertStatus(201);
        $this->remakeTenant();
        $this->assertSame(0, AdminContBankMovement::where('source_type', 'payment')->where('source_id', $r->json('id'))->count());
        $this->assertEqualsWithDelta(100.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
    }

    // ── cobros diferidos ───────────────────────────────────────────────────────────────────────

    public function test_un_cobro_sin_factura_por_transferencia_entra_a_caja_y_al_disponible_recien_al_confirmarse(): void
    {
        $master = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $cuenta = $this->cuenta();
        $h = $this->headers();

        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 400, 'transferencia', $cuenta->id))->assertStatus(201);
        $this->assertSame('esperando_confirmacion', $r->json('estado'));
        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertSame(0, AdminContBankMovement::where('source_type', 'payment')->where('source_id', $r->json('id'))->count());

        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$r->json('id').'/confirm')->assertStatus(200)->assertJson(['estado' => 'confirmado']);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(400.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertSame(1, AdminContBankMovement::where('source_type', 'payment')->where('source_id', $r->json('id'))->count());
        $this->sinDiferencias();
    }

    public function test_confirmar_un_cobro_nuevo_cuya_factura_ya_no_tiene_lugar_deja_el_excedente_en_el_libro(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $factura = $this->factura($master, 500);
        $cuenta  = $this->cuenta();
        $h = $this->headers();

        $a = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'transferencia', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 500]]))->assertStatus(201);
        $this->remakeTenant();
        $b = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $this->cobro($master->id, 500, 'transferencia', $cuenta->id, [['invoice_id' => $factura->id, 'monto' => 500]]))->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$a->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();
        $segundo = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$b->json('id').'/confirm')->assertStatus(200);
        $this->assertSame(500.0, (float) $segundo->json('ajustes_confirmacion.excedente_a_saldo_favor'));

        $this->remakeTenant();
        $this->assertEqualsWithDelta(500.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->remanenteDeCobro((int) $b->json('id')), 0.001);
        $this->sinDiferencias();
    }

    // ── la fecha de aplicación manda ───────────────────────────────────────────────────────────

    public function test_la_comision_de_cartera_de_mas_de_90_dias_cuenta_por_la_fecha_de_aplicacion(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente V2 '.uniqid()]);
        $factura = $this->factura($master, 500, 500);
        DB::table($factura->getTable())->where('id', $factura->id)->update(['created_at' => now()->subDays(200)]);
        $pago = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_recibido' => 500, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => 'efectivo', 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        DB::table($pago->getTable())->where('id', $pago->id)->update(['created_at' => now()->subDays(60)]);   // el dinero entró hace 60 días…
        $pago->invoiceLines()->create(['invoice_id' => $factura->id, 'monto_aplicado' => 500, 'fecha_aplicacion' => now()]); // …y se aplicó hoy

        $r = app(InvoiceService::class)->over90DaysCollectedForCurrentMonth();

        $this->assertGreaterThanOrEqual(500.0, $r['cobrado_mes'], 'Manda la fecha de aplicación (este mes), no la del ingreso del dinero.');
    }
}
