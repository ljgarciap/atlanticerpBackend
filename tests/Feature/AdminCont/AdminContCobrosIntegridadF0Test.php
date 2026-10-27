<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F0 — integridad previa del cobro de facturas, sin cambiar el concepto:
 *  - confirmar un cobro «esperando confirmación» revalida la factura (antes sumaba sin mirar y la dejaba con más cobrado que su valor);
 *  - el saldo a favor / abono que otro cobro ya consumió hace fallar la confirmación en vez de aplicarse en silencio;
 *  - un abono solo puede asociarse a la factura, proyecto o subcliente de SU cliente.
 */
class AdminContCobrosIntegridadF0Test extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function remakeTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function cuenta(): AdminContBankAccount
    {
        return AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999),
        ]);
    }

    private function facturaDe(MasterClient $master, float $monto, float $saldoAplicado = 0.0): AdminContInvoice
    {
        return AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
    }

    private function abonoCliente(int $masterId, float $monto): AdminContAbono
    {
        return AdminContAbono::create([
            'master_client_id' => $masterId, 'monto_original' => $monto, 'monto_usado' => 0, 'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $this->cuenta()->id, 'referencia' => 'TRF-F0', 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function cobro(int $masterId, array $invoiceIds, string $metodo, int $accountId, float $estimado, float $saldoCliente = 0.0): array
    {
        return array_filter([
            'master_client_id' => $masterId, 'invoice_ids' => $invoiceIds, 'metodo_pago' => $metodo,
            'bank_account_id' => $accountId, 'monto_recibido_estimado' => $estimado,
            'monto_saldo_cliente_aplicado' => $saldoCliente > 0 ? $saldoCliente : null,
        ], fn ($v) => $v !== null);
    }

    // ── confirmar nunca se rechaza: lo que no cabe en la factura pasa a saldo a favor ──────────

    /** @return list<AdminContAbono> */
    private function excedentesDe(MasterClient $master): array
    {
        return AdminContAbono::where('master_client_id', $master->id)->where('referencia', 'like', 'Excedente del cobro%')->get()->all();
    }

    public function test_confirmar_un_cobro_cuando_la_factura_ya_no_tiene_lugar_lo_confirma_y_el_dinero_pasa_a_saldo_a_favor(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        // Dos cobros por transferencia sobre la misma factura: ambos pasan el registro (ninguno movió el saldo todavía).
        $a = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $b = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();

        $primero = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$a->json('id').'/confirm')->assertStatus(200);
        $this->assertNull($primero->json('ajustes_confirmacion'), 'El primero se aplica tal cual: sin ajustes.');
        $this->remakeTenant();
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado);

        // El segundo: el dinero ya entró; la factura ya está cubierta → se confirma y los $500 quedan a favor del cliente.
        $segundo = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$b->json('id').'/confirm')->assertStatus(200)->assertJson(['estado' => 'confirmado']);
        $ajustes = $segundo->json('ajustes_confirmacion');
        $this->assertSame(500.0, (float) $ajustes['excedente_a_saldo_favor']);
        $this->assertSame($factura->numero, $ajustes['facturas'][0]['numero']);
        $this->assertSame(0.0, (float) $ajustes['facturas'][0]['aplicado']);

        $this->remakeTenant();
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado, 'La factura no puede quedar con más cobrado que su valor.');
        $excedentes = $this->excedentesDe($master);
        $this->assertCount(1, $excedentes);
        $this->assertSame(500.0, (float) $excedentes[0]->monto_original);
        $this->assertSame(AdminContAbono::ESTADO_CONFIRMADO, $excedentes[0]->estado);
        $this->assertNull($excedentes[0]->invoice_id, 'Saldo a favor del cliente, no atado a ninguna factura.');
        // El dinero sí entró al banco: ambos cobros generaron su movimiento.
        $this->assertSame(2, \App\Modules\AdminCont\Models\AdminContBankMovement::where('source_type', 'payment')->whereIn('source_id', [$a->json('id'), $b->json('id')])->count());
    }

    public function test_confirmar_cuando_una_nota_de_credito_dejo_la_factura_con_poco_lugar_aplica_lo_que_cabe_y_el_resto_a_saldo_a_favor(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $cobro = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'cheque', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();

        // Una nota de crédito aplicada entre el registro y la confirmación reduce lo pendiente (mismo acumulador `saldo_aplicado`).
        $factura->refresh()->update(['saldo_aplicado' => 200]);

        $respuesta = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$cobro->json('id').'/confirm')->assertStatus(200);
        $this->assertSame(200.0, (float) $respuesta->json('ajustes_confirmacion.excedente_a_saldo_favor'));
        $this->assertSame(300.0, (float) $respuesta->json('ajustes_confirmacion.facturas.0.aplicado'));

        $this->remakeTenant();
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado);
        $this->assertSame(200.0, (float) $this->excedentesDe($master)[0]->monto_original);
        // El detalle del cobro refleja lo que de verdad se aplicó a la factura.
        $detalle = $this->withHeaders($h)->getJson('/api/admin-contab/payments/'.$cobro->json('id'))->assertStatus(200);
        $this->assertSame(300.0, (float) $detalle->json('facturas.0.monto_aplicado'));
    }

    public function test_el_preview_avisa_de_la_implicacion_antes_de_confirmar_y_no_cambia_nada(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $a = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $b = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();

        // Sin carrera: nada que avisar.
        $this->withHeaders($h)->getJson('/api/admin-contab/payments/'.$b->json('id').'/confirm-preview')->assertStatus(200)->assertJson(['ajustes_confirmacion' => null]);

        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$a->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();

        $preview = $this->withHeaders($h)->getJson('/api/admin-contab/payments/'.$b->json('id').'/confirm-preview')->assertStatus(200);
        $this->assertSame(500.0, (float) $preview->json('ajustes_confirmacion.excedente_a_saldo_favor'));

        // El preview no confirma ni mueve nada.
        $this->remakeTenant();
        $this->assertSame(AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION, AdminContPayment::findOrFail($b->json('id'))->estado);
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado);
        $this->assertCount(0, $this->excedentesDe($master));

        // Un cobro ya confirmado no tiene preview.
        $this->withHeaders($h)->getJson('/api/admin-contab/payments/'.$a->json('id').'/confirm-preview')->assertStatus(422);
    }

    public function test_confirmar_un_cobro_con_lugar_en_la_factura_sigue_funcionando_sin_ajustes(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $cobro = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $r = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$cobro->json('id').'/confirm')->assertStatus(200)->assertJson(['estado' => 'confirmado']);
        $this->assertNull($r->json('ajustes_confirmacion'));

        $this->remakeTenant();
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado);
        $this->assertCount(0, $this->excedentesDe($master));
    }

    // ── el saldo a favor consumido por otro cobro no se aplica dos veces ───────────────────────

    public function test_dos_cobros_que_reservan_el_mismo_saldo_el_segundo_aplica_solo_lo_que_realmente_pago_y_la_factura_queda_con_la_diferencia(): void
    {
        $master   = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $facturaA = $this->facturaDe($master, 300);
        $facturaB = $this->facturaDe($master, 300);
        $abono    = $this->abonoCliente($master->id, 100);
        $cuenta   = $this->cuenta();
        $h        = $this->authHeaders($this->loginAsFelix());

        // Ambos cobros por transferencia aplican los mismos $100 de saldo (el saldo recién se consume al confirmar).
        $a = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$facturaA->id], 'transferencia', $cuenta->id, 200, 100))->assertStatus(201);
        $this->remakeTenant();
        $b = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$facturaB->id], 'transferencia', $cuenta->id, 200, 100))->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$a->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();
        $this->assertSame(100.0, (float) $abono->refresh()->monto_usado);
        $this->assertSame(300.0, (float) $facturaA->refresh()->saldo_aplicado);

        // El segundo: el saldo ya no existe. Entró el efectivo ($200): se aplica eso, la factura B queda con $100 pendientes y se avisa.
        $segundo = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$b->json('id').'/confirm')->assertStatus(200)->assertJson(['estado' => 'confirmado']);
        $this->assertSame(100.0, (float) $segundo->json('ajustes_confirmacion.saldo_insuficiente'));
        $this->assertSame(200.0, (float) $segundo->json('ajustes_confirmacion.facturas.0.aplicado'));

        $this->remakeTenant();
        $this->assertSame(100.0, (float) $abono->refresh()->monto_usado, 'El saldo no se descuenta dos veces.');
        $this->assertSame(200.0, (float) $facturaB->refresh()->saldo_aplicado, 'Solo se aplica el dinero que de verdad existe.');
        $this->assertCount(0, $this->excedentesDe($master));
        $this->assertSame(0.0, (float) AdminContPayment::findOrFail($b->json('id'))->saldo_favor_aplicado);
    }

    public function test_registrar_un_cobro_que_pide_mas_saldo_del_disponible_sigue_rechazandose(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 300);
        $this->abonoCliente($master->id, 50);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'efectivo', $cuenta->id, 100, 200))->assertStatus(422);
    }

    // ── el abono solo se asocia a lo del cliente ────────────────────────────────────────────────

    public function test_un_abono_no_puede_asociarse_a_la_factura_de_otro_cliente(): void
    {
        $dueno  = MasterClient::create(['name' => 'Cliente Dueño '.uniqid()]);
        $otro   = MasterClient::create(['name' => 'Cliente Otro '.uniqid()]);
        $ajena  = $this->facturaDe($otro, 500);
        $cuenta = $this->cuenta();
        $h      = $this->authHeaders($this->loginAsFelix());

        $this->withHeaders($h)->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $dueno->id, 'invoice_id' => $ajena->id, 'monto_original' => 100,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-AJENA',
        ])->assertStatus(422);
    }

    public function test_un_abono_no_puede_asociarse_al_proyecto_ni_al_subcliente_de_otro_cliente(): void
    {
        $dueno   = MasterClient::create(['name' => 'Cliente Dueño '.uniqid()]);
        $otro    = MasterClient::create(['name' => 'Cliente Otro '.uniqid()]);
        $subAjeno = SubClient::create(['master_client_id' => $otro->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => 'TAX-'.uniqid()]);
        $proyectoAjeno = SalesProject::create(['sub_client_id' => $subAjeno->id, 'name' => 'Proyecto ajeno '.uniqid()]);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $base = ['master_client_id' => $dueno->id, 'monto_original' => 100, 'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-AJENO'];

        $this->withHeaders($h)->postJson('/api/admin-contab/abonos', $base + ['sub_client_id' => $subAjeno->id, 'sales_project_id' => $proyectoAjeno->id])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin-contab/abonos', $base + ['sales_project_id' => $proyectoAjeno->id])->assertStatus(422);
    }

    public function test_un_abono_si_puede_asociarse_a_lo_de_su_propio_cliente(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Propio '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => 'TAX-'.uniqid()]);
        $proyecto = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto propio '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsFelix());

        $this->withHeaders($h)->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'sales_project_id' => $proyecto->id, 'invoice_id' => $factura->id,
            'monto_original' => 100, 'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-PROPIO',
        ])->assertStatus(201);
    }

    public function test_editar_un_abono_por_confirmar_no_permite_moverlo_a_la_factura_de_otro_cliente(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Dueño '.uniqid()]);
        $otro   = MasterClient::create(['name' => 'Cliente Otro '.uniqid()]);
        $ajena  = $this->facturaDe($otro, 500);
        $cuenta = $this->cuenta();
        $h      = $this->authHeaders($this->loginAsFelix());

        $creado = $this->withHeaders($h)->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'monto_original' => 100, 'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-EDIT',
        ])->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($h)->putJson('/api/admin-contab/abonos/'.$creado->json('id'), ['invoice_id' => $ajena->id])->assertStatus(422);
    }

    public function test_confirmar_un_abono_dos_veces_responde_422_la_segunda(): void
    {
        $master = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $cuenta = $this->cuenta();
        $h      = $this->authHeaders($this->loginAsFelix());

        $creado = $this->withHeaders($h)->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'monto_original' => 100, 'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-CONF',
        ])->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($h)->postJson('/api/admin-contab/abonos/'.$creado->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();
        $this->withHeaders($h)->postJson('/api/admin-contab/abonos/'.$creado->json('id').'/confirm')->assertStatus(422);
    }
}
