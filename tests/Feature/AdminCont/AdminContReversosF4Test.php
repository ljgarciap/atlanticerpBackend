<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCommissionAdjustment;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Models\AdminContPaymentReversalRequest;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\AdminCont\Services\DailyCashCountService;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerBackfill;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F4 — reversos: desaplicar (una aplicación o un cruce completo, sin aprobación) y anular un cobro (lo solicita Contabilidad o Asistente Administrativa, lo decide Gerencia).
 * Todo con contra-movimientos: nada se borra; la fecha del reverso es la del día; la comisión ya liquidada no se toca, se descuenta en la siguiente.
 */
class AdminContReversosF4Test extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';
    private const APPROVER_EMAIL  = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function remakeTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} [felix, mark] */
    private function sesiones(bool $v2 = true): array
    {
        $contabilidad = $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
        $approver  = $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
        $this->remakeTenant();
        app(CobrosConfigService::class)->setV2Activo($v2);

        return [$this->authHeaders($contabilidad), $this->authHeaders($approver)];
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

    /** Cobro del concepto nuevo por HTTP. Devuelve el id. */
    private function cobro(array $h, MasterClient $master, float $monto, array $aplicaciones = [], string $metodo = 'efectivo'): int
    {
        $payload = ['master_client_id' => $master->id, 'monto_recibido' => $monto, 'metodo_pago' => $metodo, 'bank_account_id' => $this->cuenta()->id];
        if ($aplicaciones !== []) {
            $payload['aplicaciones'] = $aplicaciones;
        }
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', $payload)->assertStatus(201);
        $this->remakeTenant();

        return (int) $r->json('id');
    }

    private function movimientoAplicacion(int $paymentId, int $invoiceId): AdminContPaymentMovement
    {
        return AdminContPaymentMovement::where('payment_id', $paymentId)->where('invoice_id', $invoiceId)->where('tipo', 'aplicacion')->firstOrFail();
    }

    private function sinDiferencias(): void
    {
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], $r['facturas'], 'Facturas con diferencia: '.json_encode($r['facturas']));
        $this->assertSame([], $r['clientes'], 'Clientes con diferencia: '.json_encode($r['clientes']));
    }

    // ── desaplicar ──────────────────────────────────────────────────────────────────────────────

    public function test_desaplicar_una_aplicacion_directa_devuelve_el_dinero_al_cobro_y_la_factura_recupera_su_saldo(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Rev '.uniqid()]);
        $factura = $this->factura($master, 300);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 500, [['invoice_id' => $factura->id, 'monto' => 200]]);
        $mov   = $this->movimientoAplicacion($cobro, $factura->id);
        $this->assertEqualsWithDelta(200.0, (float) $factura->refresh()->saldo_aplicado, 0.001);

        $r = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$mov->id}/desaplicar", ['motivo' => 'Se aplicó a la factura equivocada'])->assertStatus(201);

        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001, 'Los $200 vuelven al saldo del cobro.');
        $this->assertNull(AdminContPaymentInvoice::where('payment_id', $cobro)->where('invoice_id', $factura->id)->first(), 'La proyección ya no muestra esa aplicación.');
        $rev = AdminContPaymentMovement::findOrFail($r->json('id'));
        $this->assertSame('desaplicacion', $rev->tipo);
        $this->assertSame($mov->id, $rev->reverses_movement_id);
        $this->assertSame('Se aplicó a la factura equivocada', $rev->motivo);
        // El original sigue ahí, intacto (el libro no se reescribe).
        $this->assertSame('aplicacion', $mov->fresh()->tipo);
        $this->sinDiferencias();

        // Se puede reaplicar a otra factura con un cruce.
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$mov->id}/desaplicar", ['motivo' => 'otra vez'])->assertStatus(422);
    }

    public function test_la_traza_del_cliente_marca_que_aplicaciones_se_pueden_desaplicar_y_cuales_ya_se_reversaron(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Traza '.uniqid()]);
        $factura = $this->factura($master, 300);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 300, [['invoice_id' => $factura->id, 'monto' => 300]]);
        $mov   = $this->movimientoAplicacion($cobro, $factura->id);

        $fila = fn () => collect($this->withHeaders($contabilidad)->getJson('/api/admin-contab/compensaciones/clientes/'.$master->id)->json('movimientos'))->firstWhere('id', $mov->id);
        $this->assertTrue($fila()['reversible']);
        $this->assertFalse($fila()['reversado']);

        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$mov->id}/desaplicar", ['motivo' => 'x'])->assertStatus(201);
        $this->remakeTenant();

        $this->assertFalse($fila()['reversible']);
        $this->assertTrue($fila()['reversado']);
    }

    public function test_desaplicar_exige_motivo_y_rechaza_historial_y_movimientos_que_no_son_aplicaciones(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Rev '.uniqid()]);
        $factura = $this->factura($master, 300);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 300, [['invoice_id' => $factura->id, 'monto' => 300]]);
        $mov   = $this->movimientoAplicacion($cobro, $factura->id);
        $ingreso = AdminContPaymentMovement::where('payment_id', $cobro)->where('tipo', 'ingreso')->firstOrFail();

        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$mov->id}/desaplicar", [])->assertStatus(422);   // sin motivo
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$ingreso->id}/desaplicar", ['motivo' => 'x'])->assertStatus(422);
        $this->withHeaders($contabilidad)->postJson('/api/admin-contab/movimientos/999999/desaplicar', ['motivo' => 'x'])->assertStatus(422);

        // Un movimiento histórico (copiado del pasado) no se reversa por componente.
        $this->remakeTenant();
        $historico = AdminContPaymentMovement::create([
            'master_client_id' => $master->id, 'tipo' => 'aplicacion', 'monto' => 10, 'origen' => 'migracion', 'invoice_id' => $factura->id,
            'delta_factura' => 10, 'fecha_movimiento' => now(),
        ]);
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/movimientos/{$historico->id}/desaplicar", ['motivo' => 'x'])->assertStatus(422);
    }

    public function test_reversar_un_cruce_completo_con_las_tres_fuentes_las_restituye_y_lo_marca_reversado(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Cruce '.uniqid()]);
        $f1 = $this->factura($master, 300);
        $f2 = $this->factura($master, 300);
        // Factura de origen de la nota (no se cobra más: incobrable, fuera de las pendientes).
        $origen = $this->factura($master, 100);
        $origen->update(['es_incobrable' => true]);
        $abono = AdminContAbono::create([
            'numero_recibo' => 'ABN-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_original' => 100, 'monto_usado' => 0, 'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $this->cuenta()->id, 'referencia' => 'TRF', 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'), 'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $origen->id, 'monto' => 80, 'subtotal' => 80, 'itbms' => 0, 'monto_aplicado_saldo' => 0, 'monto_usado' => 0, 'motivo' => 'saldo',
            'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR, 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'), 'fecha' => now(),
        ]);
        app(PaymentLedgerBackfill::class)->run();
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 150);

        $cruce = $this->withHeaders($contabilidad)->postJson('/api/admin-contab/compensaciones', ['master_client_id' => $master->id, 'lines' => [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f1->id, 'monto' => 150],
            ['source_type' => 'abono', 'source_id' => $abono->id, 'invoice_id' => $f1->id, 'monto' => 100],
            ['source_type' => 'nota_credito', 'source_id' => $nota->id, 'invoice_id' => $f2->id, 'monto' => 80],
        ]])->assertStatus(201);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(250.0, (float) $f1->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $f2->refresh()->saldo_aplicado, 0.001);
        $this->sinDiferencias();

        $this->withHeaders($contabilidad)->postJson('/api/admin-contab/compensaciones/'.$cruce->json('id').'/reverse', ['motivo' => 'Cruce hecho con el cliente equivocado'])->assertStatus(200)->assertJson(['estado' => 'reversado']);

        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, (float) $f1->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $f2->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $abono->refresh()->monto_usado, 0.001, 'El abono recupera su saldo.');
        $this->assertEqualsWithDelta(0.0, (float) $nota->refresh()->monto_usado, 0.001, 'La nota recupera su saldo.');
        $this->assertEqualsWithDelta(150.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001);
        $comp = AdminContCompensation::findOrFail($cruce->json('id'));
        $this->assertSame('reversado', $comp->estado);
        $this->assertNotNull($comp->reversed_at);
        $this->sinDiferencias();

        $this->withHeaders($contabilidad)->postJson('/api/admin-contab/compensaciones/'.$cruce->json('id').'/reverse', ['motivo' => 'otra vez'])->assertStatus(422);
    }

    // ── anular un cobro (solicita Contabilidad, decide Gerencia) ───────────────────────────────────────────

    public function test_anular_un_cobro_del_concepto_nuevo_lo_solicita_contabilidad_y_al_aprobar_aprobador_se_reversa_todo(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Anular '.uniqid()]);
        $factura = $this->factura($master, 300);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 500, [['invoice_id' => $factura->id, 'monto' => 300]]);   // 300 aplicados, 200 a favor

        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Monto mal digitado'])->assertStatus(201);
        $this->assertSame('solicitada', $sol->json('estado'));
        $this->remakeTenant();
        $this->assertSame('confirmado', AdminContPayment::findOrFail($cobro)->estado, 'Solicitar no cambia nada todavía.');
        $this->assertEqualsWithDelta(300.0, (float) $factura->refresh()->saldo_aplicado, 0.001);

        // Solo Gerencia decide.
        $this->withHeaders($contabilidad)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(403);

        $r = $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->assertSame('ejecutada', $r->json('estado'));

        $this->remakeTenant();
        $p = AdminContPayment::findOrFail($cobro);
        $this->assertSame('anulado', $p->estado);
        $this->assertSame('Monto mal digitado', $p->motivo_anulacion);
        $this->assertNotNull($p->anulado_at);
        $this->assertEqualsWithDelta(0.0, (float) $factura->refresh()->saldo_aplicado, 0.001, 'La factura vuelve a estar pendiente.');
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001, 'El saldo a favor de ese cobro deja de existir.');
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertSame(1, AdminContPaymentMovement::where('payment_id', $cobro)->where('tipo', 'anulacion')->count());
        $this->sinDiferencias();

        // La caja: el dinero sale el día de la anulación.
        $reverso = AdminContBankMovement::where('source_type', 'payment_reversal')->where('source_id', $cobro)->firstOrFail();
        $this->assertSame('devolucion', $reverso->tipo);
        $this->assertEqualsWithDelta(500.0, (float) $reverso->monto, 0.001);
        $this->assertSame(now()->toDateString(), $reverso->fecha->toDateString());

        // Ya anulado: no admite otra solicitud ni otra anulación; el detalle lo dice.
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'otra'])->assertStatus(422);
        $detalle = $this->withHeaders($contabilidad)->getJson("/api/admin-contab/payments/{$cobro}")->assertStatus(200);
        $this->assertSame('anulado', $detalle->json('estado'));
        $this->assertSame('ejecutada', $detalle->json('solicitud_anulacion.estado'));
        $this->assertSame('Monto mal digitado', $detalle->json('motivo_anulacion'));
    }

    public function test_el_arqueo_conserva_el_cobro_en_su_dia_y_resta_la_anulacion_del_dia_en_que_ocurre(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Arqueo '.uniqid()]);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 400);   // efectivo: entra hoy

        $antes = app(DailyCashCountService::class)->forDate(now()->toDateString());
        $this->assertEqualsWithDelta(400.0, $antes['total_cobrado'], 0.001);

        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Duplicado'])->assertStatus(201);
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->remakeTenant();

        $despues = app(DailyCashCountService::class)->forDate(now()->toDateString());
        $this->assertEqualsWithDelta(400.0, $despues['total_cobrado'], 0.001, 'El cobro anulado sigue contando en el día en que entró el dinero.');
        $this->assertEqualsWithDelta(400.0, $despues['total_reversos'], 0.001);
        $this->assertCount(1, $despues['reversos']);
        $this->assertStringContainsString('Anulación', $despues['reversos'][0]['concepto']);
        $this->assertEqualsWithDelta($antes['total_neto'] - 400.0, $despues['total_neto'], 0.001, 'Neto del día: el reverso se resta.');
    }

    public function test_1175_el_arqueo_y_su_documento_muestran_el_cliente_de_cada_linea_e_incluyen_las_anulaciones(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Documento '.uniqid()]);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 400);
        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Duplicado'])->assertStatus(201);
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->remakeTenant();

        $dia = app(DailyCashCountService::class)->forDate(now()->toDateString());
        $this->assertSame($master->name, $dia['cobros'][0]['cliente']);
        $this->assertSame($master->name, $dia['reversos'][0]['cliente'], 'La anulación se asocia al cliente de su cobro.');

        // El documento imprimible: columna Cliente, el nombre en la línea del cobro y la de la anulación, y la anulación en los totales.
        $html = view('admin-cont.daily-cash-count-summary-pdf', app(\App\Modules\AdminCont\Services\DailyCashCountSummaryPdfService::class)->viewData([
            ...$dia, 'numero' => 1, 'cerrado_por' => null, 'cerrado_at' => null, 'aprobado_por' => null, 'aprobado_at' => null,
        ]))->render();
        $this->assertStringContainsString('<th>Cliente</th>', $html);
        $this->assertSame(2, substr_count($html, $master->name), 'Cobro y anulación llevan el nombre del cliente.');
        $this->assertStringContainsString('Anulaciones y devoluciones al cliente', $html);
        $this->assertStringContainsString('Anulación del cobro', $html);

        // Un arqueo cerrado ANTES del cambio no trae `cliente` en su snapshot: se completa al leerlo.
        $viejo = $dia;
        foreach (['cobros', 'notas_credito', 'reversos'] as $seccion) {
            $viejo[$seccion] = array_map(fn (array $r): array => array_diff_key($r, ['cliente' => 1]), $viejo[$seccion]);
        }
        $registro = \App\Modules\AdminCont\Models\AdminContDailyCashCount::create([
            'fecha' => now()->toDateString(), 'estado' => \App\Modules\AdminCont\Models\AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION, 'snapshot' => $viejo,
        ]);
        $leido = app(DailyCashCountService::class)->detail($registro->id);
        $this->assertSame($master->name, $leido['cobros'][0]['cliente']);
        $this->assertSame($master->name, $leido['reversos'][0]['cliente']);
    }

    public function test_aprobador_puede_rechazar_la_solicitud_con_motivo_y_no_cambia_nada(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Rechazo '.uniqid()]);
        $factura = $this->factura($master, 200);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 200, [['invoice_id' => $factura->id, 'monto' => 200]]);
        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Creo que está mal'])->assertStatus(201);

        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => false])->assertStatus(422);   // sin motivo del rechazo
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => false, 'motivo_rechazo' => 'El cobro está bien'])->assertStatus(200)->assertJson(['estado' => 'rechazada']);

        $this->remakeTenant();
        $this->assertSame('confirmado', AdminContPayment::findOrFail($cobro)->estado);
        $this->assertEqualsWithDelta(200.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertSame(0, AdminContBankMovement::where('source_type', 'payment_reversal')->count());
        // Resuelta: no se decide dos veces; y se puede volver a solicitar.
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(422);
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Ahora con comprobante'])->assertStatus(201);
    }

    public function test_no_se_puede_pedir_dos_veces_la_anulacion_mientras_la_primera_sigue_pendiente_y_la_lista_se_filtra(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Doble '.uniqid()]);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 100);

        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'a'])->assertStatus(201);
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'b'])->assertStatus(422);
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", [])->assertStatus(422);   // sin motivo

        $pendientes = $this->withHeaders($contabilidad)->getJson('/api/admin-contab/reversal-requests?estado=solicitada&tipo=anulacion')->assertStatus(200);
        $ids = array_column($pendientes->json('rows'), 'payment_id');
        $this->assertContains($cobro, $ids);
        $this->assertSame($master->name, collect($pendientes->json('rows'))->firstWhere('payment_id', $cobro)['cliente']);
    }

    public function test_anular_un_cobro_que_espera_confirmacion_solo_lo_marca_sin_tocar_caja_ni_libro(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Espera '.uniqid()]);
        $factura = $this->factura($master, 200);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobro($contabilidad, $master, 200, [['invoice_id' => $factura->id, 'monto' => 200]], 'transferencia');
        $this->assertSame('esperando_confirmacion', AdminContPayment::findOrFail($cobro)->estado);

        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Nunca llegó'])->assertStatus(201);
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);

        $this->remakeTenant();
        $this->assertSame('anulado', AdminContPayment::findOrFail($cobro)->estado);
        $this->assertEqualsWithDelta(0.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertSame(0, AdminContPaymentMovement::where('payment_id', $cobro)->count());
        $this->assertSame(0, AdminContBankMovement::where('source_id', $cobro)->count());
        // Ya no se puede confirmar.
        $this->withHeaders($contabilidad)->putJson("/api/admin-contab/payments/{$cobro}/confirm")->assertStatus(422);
    }

    public function test_anular_un_cobro_del_flujo_anterior_reversa_las_lineas_restituye_abono_y_saldo_y_el_libro_cuadra(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Legado '.uniqid()]);
        $factura = $this->factura($master, 600);
        $cuenta  = $this->cuenta();
        $abono = AdminContAbono::create([
            'numero_recibo' => 'ABN-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_original' => 100, 'monto_usado' => 0, 'metodo_pago' => 'transferencia',
            'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF', 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'), 'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);
        app(PaymentLedgerBackfill::class)->run();
        [$contabilidad, $approver] = $this->sesiones(false);   // concepto anterior

        // Cobro del flujo anterior que usa $100 de saldo a favor del cliente y $500 en efectivo.
        $r = $this->withHeaders($contabilidad)->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$factura->id], 'metodo_pago' => 'efectivo', 'bank_account_id' => $cuenta->id,
            'monto_recibido_estimado' => 500, 'monto_saldo_cliente_aplicado' => 100,
        ])->assertStatus(201);
        $this->remakeTenant();
        $cobro = (int) $r->json('id');
        $this->assertEqualsWithDelta(600.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $abono->refresh()->monto_usado, 0.001);
        $this->sinDiferencias();

        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'Cliente equivocado'])->assertStatus(201);
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);

        $this->remakeTenant();
        $this->assertSame('anulado', AdminContPayment::findOrFail($cobro)->estado);
        $this->assertEqualsWithDelta(0.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        // El saldo a favor consumido vuelve al cliente (como un abono nuevo de restitución); el efectivo sale de caja.
        $restitucion = AdminContAbono::where('master_client_id', $master->id)->where('referencia', 'like', 'Restitución por anulación%')->get();
        $this->assertCount(1, $restitucion);
        $this->assertEqualsWithDelta(100.0, (float) $restitucion[0]->monto_original, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) AdminContBankMovement::where('source_type', 'payment_reversal')->where('source_id', $cobro)->value('monto'), 0.001);
        $this->sinDiferencias();
    }

    // ── comisión ya liquidada ──────────────────────────────────────────────────────────────────

    /** Pedido aprobado hace `$mesesAtras` meses, con su factura sin cobrar. */
    private function pedidoComisionable(int $ownerId, float $amount, int $mesesAtras): AdminContInvoice
    {
        $master  = MasterClient::create(['name' => 'Cliente Comisión '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Comisión '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Comisión '.uniqid()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'amount' => $amount, 'stage_changed_at' => Carbon::now()->subMonthsNoOverflow($mesesAtras)->startOfMonth()->addDays(2),
        ]);
        $order = Order::create([
            'order_number' => 'PED-COM-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name,
        ]);

        return AdminContInvoice::create([
            'numero' => 'F-COM-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => 0,
        ]);
    }

    public function test_reversar_el_cobro_de_un_pedido_ya_liquidado_deja_la_comision_pagada_y_registra_un_descuento_para_la_siguiente_liquidacion(): void
    {
        $vendedor = (int) User::where('email', 'designer@atlantic.test')->value('id');
        $facturaA = $this->pedidoComisionable($vendedor, 15000, 2);   // cerrado hace 2 meses
        [$contabilidad, $approver] = $this->sesiones();
        $masterA = MasterClient::findOrFail($facturaA->master_client_id);

        $cobroA = $this->cobro($contabilidad, $masterA, 15000, [['invoice_id' => $facturaA->id, 'monto' => 15000]]);
        $this->assertSame(1, app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger(), 'La comisión del mes de A se liquida (queda pagada).');

        // Se anula el cobro de A: el pedido vuelve a estar sin cobrar, la comisión ya pagada NO se toca, se registra un descuento.
        $sol = $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobroA}/anular", ['motivo' => 'Cobro duplicado'])->assertStatus(201);
        $r = $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->remakeTenant();

        $this->assertCount(1, $r->json('ajustes_comision'));
        $ajuste = AdminContCommissionAdjustment::firstOrFail();
        $this->assertSame($vendedor, $ajuste->vendedor_id);
        $this->assertEqualsWithDelta(225.0, $ajuste->monto, 0.001, '1.5 % de $15,000, lo que se había pagado de ese pedido.');
        $this->assertSame(1, AdminContBankMovement::where('source_type', 'internal_commission')->count(), 'La comisión pagada sigue pagada: no se anuló ni se recalculó.');
        $this->assertSame(0, app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger(), 'Un descuento de hoy no afecta liquidaciones de meses ya cerrados.');

        // El descuento se resta de la siguiente liquidación: lo dejamos registrado el mes pasado y aparece una liquidación de ese mes ($225 de otro pedido).
        DB::table($ajuste->getTable())->where('id', $ajuste->id)->update(['created_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(14)]);
        $this->pedidoComisionable($vendedor, 15000, 1);

        $creados = app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger();
        $ajuste->refresh();
        $this->assertEqualsWithDelta(225.0, $ajuste->monto_aplicado, 0.001);
        $this->assertNotNull($ajuste->applied_snapshot_id);
        $this->assertSame(0, $creados, 'La liquidación del mes pasado ($225) quedó en cero por el descuento: no hay movimiento de caja.');
        $this->assertSame(1, AdminContBankMovement::where('source_type', 'internal_commission')->count());
        $this->assertSame(0, app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger(), 'Idempotente: correrla otra vez no paga lo ya descontado.');
    }

    public function test_un_descuento_mayor_a_la_liquidacion_deja_el_resto_para_la_siguiente(): void
    {
        $vendedor = (int) User::where('email', 'designer@atlantic.test')->value('id');
        $facturaB = $this->pedidoComisionable($vendedor, 15000, 1);   // comisión del mes pasado: $225
        [$contabilidad] = $this->sesiones();
        $masterB = MasterClient::findOrFail($facturaB->master_client_id);
        $ajuste = AdminContCommissionAdjustment::create([
            'vendedor_id' => $vendedor, 'pedido_id' => 1, 'monto' => 500, 'motivo' => 'prueba',
            'created_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(10),
        ]);
        $this->cobro($contabilidad, $masterB, 15000, [['invoice_id' => $facturaB->id, 'monto' => 15000]]);

        app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger();

        $ajuste->refresh();
        $this->assertEqualsWithDelta(225.0, $ajuste->monto_aplicado, 0.001, 'Solo se descuenta hasta lo que da la liquidación.');
        $this->assertEqualsWithDelta(275.0, $ajuste->pendiente(), 0.001, 'El resto queda para la siguiente.');
        $this->assertSame(0, AdminContBankMovement::where('source_type', 'internal_commission')->count());
    }
}
