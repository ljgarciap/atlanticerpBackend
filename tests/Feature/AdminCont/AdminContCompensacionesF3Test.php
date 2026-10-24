<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\AccountStatementService;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerBackfill;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F3 — compensaciones (cruce): el dinero disponible de un cliente (cobros a cuenta, abonos históricos, saldo de notas de crédito) se
 * aplica a sus facturas pendientes, total o parcial, con vista previa, folio CMP y traza en el libro.
 */
class AdminContCompensacionesF3Test extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function remakeTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function headers(bool $v2 = true): array
    {
        $token = $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
        $this->remakeTenant();
        app(CobrosConfigService::class)->setV2Activo($v2);

        return $this->authHeaders($token);
    }

    private function cuenta(): AdminContBankAccount
    {
        return AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999)]);
    }

    private function factura(MasterClient $master, float $monto, float $saldoAplicado = 0.0, ?int $diasAtras = null): AdminContInvoice
    {
        $f = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        if ($diasAtras !== null) {
            \Illuminate\Support\Facades\DB::table($f->getTable())->where('id', $f->id)->update(['created_at' => now()->subDays($diasAtras)]);
        }

        return $f;
    }

    /** Un cobro del concepto nuevo, por HTTP, sin aplicar nada (queda todo como saldo a favor). */
    private function cobroACuenta(array $h, MasterClient $master, float $monto): int
    {
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', [
            'master_client_id' => $master->id, 'monto_recibido' => $monto, 'metodo_pago' => 'efectivo', 'bank_account_id' => $this->cuenta()->id,
        ])->assertStatus(201);
        $this->remakeTenant();

        return (int) $r->json('id');
    }

    private function abonoConfirmado(int $masterId, float $monto, ?int $invoiceId = null): AdminContAbono
    {
        return AdminContAbono::create([
            'numero_recibo' => 'ABN-'.static::uniqueTestNumber(), 'master_client_id' => $masterId, 'invoice_id' => $invoiceId, 'monto_original' => $monto, 'monto_usado' => 0,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $this->cuenta()->id, 'referencia' => 'TRF-F3', 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por' => User::where('email', self::FELIX_EMAIL)->value('id'), 'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);
    }

    private function notaConSaldo(MasterClient $master, AdminContInvoice $origen, float $monto): AdminContCreditNote
    {
        return AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $origen->id, 'monto' => $monto, 'subtotal' => $monto, 'itbms' => 0,
            'monto_aplicado_saldo' => 0, 'monto_usado' => 0, 'motivo' => 'Saldo de nota', 'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => User::where('email', self::FELIX_EMAIL)->value('id'), 'fecha' => now(),
        ]);
    }

    private function sinDiferencias(): void
    {
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], $r['facturas'], 'Facturas con diferencia: '.json_encode($r['facturas']));
        $this->assertSame([], $r['clientes'], 'Clientes con diferencia: '.json_encode($r['clientes']));
    }

    /** @return array<string, mixed> */
    private function cruce(int $masterId, array $lines): array
    {
        return ['master_client_id' => $masterId, 'lines' => $lines];
    }

    // ── bandera ─────────────────────────────────────────────────────────────────────────────────

    public function test_con_la_bandera_apagada_las_compensaciones_no_estan_disponibles(): void
    {
        $h = $this->headers(false);
        $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes')->assertStatus(422);
    }

    // ── lista de clientes con saldo activo ──────────────────────────────────────────────────────

    public function test_la_lista_trae_clientes_con_saldo_a_favor_con_sus_montos_y_signo(): void
    {
        $conSaldo  = MasterClient::create(['name' => 'Cliente ConSaldo '.uniqid()]);
        $soloDeuda = MasterClient::create(['name' => 'Cliente SoloDeuda '.uniqid()]);
        $this->factura($conSaldo, 300);
        $this->factura($soloDeuda, 500);   // debe pero sin saldo ni movimiento: no entra
        $h = $this->headers();
        $this->cobroACuenta($h, $conSaldo, 700);

        $r = $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes?search='.urlencode('ConSaldo'))->assertStatus(200);
        $fila = $r->json('rows.0');
        $this->assertSame($conSaldo->id, $fila['master_client_id']);
        $this->assertSame(700.0, (float) $fila['saldo_a_favor']);
        $this->assertSame(300.0, (float) $fila['pendiente']);
        $this->assertSame(400.0, (float) $fila['neto']);
        $this->assertSame('positivo', $fila['signo']);
        $this->assertSame(1, $fila['facturas_pendientes']);

        $todos = $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes?per_page=100')->assertStatus(200)->json('rows');
        $this->assertNotContains($soloDeuda->id, array_column($todos, 'master_client_id'), 'Quien solo debe, sin saldo ni movimiento, sigue en Estado de Cuenta.');
    }

    public function test_el_filtro_de_signo_separa_a_favor_de_debe(): void
    {
        $aFavor = MasterClient::create(['name' => 'Cliente AFavor '.uniqid()]);
        $debe   = MasterClient::create(['name' => 'Cliente Debe '.uniqid()]);
        $this->factura($aFavor, 100);
        $this->factura($debe, 900);
        $h = $this->headers();
        $this->cobroACuenta($h, $aFavor, 300);   // neto +200
        $this->cobroACuenta($h, $debe, 100);     // neto -800

        $positivos = array_column($this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes?signo=positivo&per_page=100')->json('rows'), 'master_client_id');
        $negativos = array_column($this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes?signo=negativo&per_page=100')->json('rows'), 'master_client_id');

        $this->assertContains($aFavor->id, $positivos);
        $this->assertNotContains($debe->id, $positivos);
        $this->assertContains($debe->id, $negativos);
        $this->assertNotContains($aFavor->id, $negativos);
    }

    public function test_un_cliente_sin_saldo_pero_con_movimiento_reciente_aparece_y_uno_viejo_no(): void
    {
        $reciente = MasterClient::create(['name' => 'Cliente Reciente '.uniqid()]);
        $viejo    = MasterClient::create(['name' => 'Cliente Viejo '.uniqid()]);
        $h = $this->headers();
        $cuenta = $this->cuenta();
        // Cobro aplicado por completo (no queda saldo) — el movimiento de hoy lo mantiene en la lista.
        $f1 = $this->factura($reciente, 100);
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', [
            'master_client_id' => $reciente->id, 'monto_recibido' => 100, 'metodo_pago' => 'efectivo', 'bank_account_id' => $cuenta->id,
            'aplicaciones' => [['invoice_id' => $f1->id, 'monto' => 100]],
        ])->assertStatus(201);
        $this->remakeTenant();
        // El otro tuvo movimiento hace 60 días: fuera de la ventana de 30.
        $f2 = $this->factura($viejo, 100);
        $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', [
            'master_client_id' => $viejo->id, 'monto_recibido' => 100, 'metodo_pago' => 'efectivo', 'bank_account_id' => $cuenta->id,
            'aplicaciones' => [['invoice_id' => $f2->id, 'monto' => 100]],
        ])->assertStatus(201);
        $this->remakeTenant();
        \Illuminate\Support\Facades\DB::table((new AdminContPaymentMovement())->getTable())->where('master_client_id', $viejo->id)->update(['fecha_movimiento' => now()->subDays(60)]);

        $ids = array_column($this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes?per_page=100')->json('rows'), 'master_client_id');

        $this->assertContains($reciente->id, $ids);
        $this->assertNotContains($viejo->id, $ids);
    }

    // ── detalle y sugerencia ────────────────────────────────────────────────────────────────────

    public function test_el_detalle_trae_fuentes_facturas_sugerencia_y_traza_y_la_sugerencia_va_a_las_mas_antiguas(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Detalle '.uniqid()]);
        $vieja  = $this->factura($master, 100, 0, 40);
        $nueva  = $this->factura($master, 300, 0, 5);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 250);

        $d = $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes/'.$master->id)->assertStatus(200);

        $this->assertSame(250.0, (float) $d->json('saldo_a_favor'));
        $this->assertSame(400.0, (float) $d->json('pendiente'));
        $this->assertSame($cobro, $d->json('fuentes.0.source_id'));
        $this->assertSame('cobro', $d->json('fuentes.0.source_type'));
        $this->assertSame([$vieja->id, $nueva->id], array_column($d->json('facturas'), 'id'), 'Facturas de la más antigua a la más reciente.');
        // Sugerencia: $100 a la vieja y los $150 restantes a la nueva.
        $this->assertEquals([['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $vieja->id, 'monto' => 100], ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $nueva->id, 'monto' => 150]], $d->json('sugerencia'));
        $this->assertSame('ingreso', $d->json('movimientos.0.tipo'));
        // El detalle no aplicó nada.
        $this->remakeTenant();
        $this->assertSame(0.0, (float) $vieja->refresh()->saldo_aplicado);
    }

    public function test_la_sugerencia_prefiere_la_factura_a_la_que_venia_atado_un_abono(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Atado '.uniqid()]);
        $vieja  = $this->factura($master, 500, 0, 40);
        $atada  = $this->factura($master, 500, 0, 5);
        $this->abonoConfirmado($master->id, 200, $atada->id);
        $h = $this->headers();

        $d = $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes/'.$master->id)->assertStatus(200);

        $this->assertSame($atada->id, $d->json('sugerencia.0.invoice_id'), 'La factura original del abono va primero, aunque no sea la más antigua.');
        $this->assertSame($atada->id, $d->json('fuentes.0.factura_sugerida_id'));
    }

    // ── vista previa ────────────────────────────────────────────────────────────────────────────

    public function test_la_vista_previa_dice_como_quedaria_todo_sin_cambiar_nada(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Preview '.uniqid()]);
        $factura = $this->factura($master, 300);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 500);

        $p = $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones/preview', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $factura->id, 'monto' => 200],
        ]))->assertStatus(200);

        $this->assertSame(200.0, (float) $p->json('total_aplicado'));
        $this->assertSame(500.0, (float) $p->json('saldo_a_favor_antes'));
        $this->assertSame(300.0, (float) $p->json('saldo_a_favor_despues'));
        $this->assertSame(300.0, (float) $p->json('facturas.0.pendiente_antes'));
        $this->assertSame(100.0, (float) $p->json('facturas.0.pendiente_despues'));
        $this->remakeTenant();
        $this->assertSame(0.0, (float) $factura->refresh()->saldo_aplicado);
        $this->assertSame(0, AdminContCompensation::count());
    }

    public function test_la_vista_previa_y_la_confirmacion_rechazan_lo_que_no_cabe(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Rechazo '.uniqid()]);
        $otro    = MasterClient::create(['name' => 'Cliente Otro '.uniqid()]);
        $factura = $this->factura($master, 300);
        $ajena   = $this->factura($otro, 300);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 100);
        $linea = fn (int $invoiceId, float $monto) => ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $invoiceId, 'monto' => $monto];

        foreach (['preview', ''] as $ruta) {
            $url = '/api/admin-contab/compensaciones'.($ruta === '' ? '' : "/{$ruta}");
            // Más que lo disponible de la fuente.
            $this->withHeaders($h)->postJson($url, $this->cruce($master->id, [$linea($factura->id, 150)]))->assertStatus(422);
            $this->remakeTenant();
            // Más que lo pendiente de la factura (la fuente alcanza para 100, la factura está en 300: se prueba con dos líneas que la superan).
            $this->withHeaders($h)->postJson($url, $this->cruce($master->id, [$linea($factura->id, 60), $linea($factura->id, 60)]))->assertStatus(422);
            $this->remakeTenant();
            // Factura de otro cliente.
            $this->withHeaders($h)->postJson($url, $this->cruce($master->id, [$linea($ajena->id, 50)]))->assertStatus(422);
            $this->remakeTenant();
            // Fuente inexistente.
            $this->withHeaders($h)->postJson($url, $this->cruce($master->id, [['source_type' => 'cobro', 'source_id' => 999999, 'invoice_id' => $factura->id, 'monto' => 10]]))->assertStatus(422);
            $this->remakeTenant();
        }

        $this->assertSame(0, AdminContCompensation::count(), 'Ningún intento inválido deja un cruce a medias.');
        $this->assertEqualsWithDelta(0.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
    }

    // ── confirmar ───────────────────────────────────────────────────────────────────────────────

    public function test_confirmar_desde_un_cobro_aplica_parcial_a_dos_facturas_deja_folio_proyeccion_y_traza(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Cruce '.uniqid()]);
        $f1 = $this->factura($master, 100, 0, 30);
        $f2 = $this->factura($master, 50, 0, 20);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 70);

        // El ejemplo de Luis: pagó 70, Felix decide que son solo para la de 50 (aplica 50) y conserva 20 a favor.
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f2->id, 'monto' => 50],
        ]))->assertStatus(201);

        $this->assertMatchesRegularExpression('/^CMP-\d{3,}$/', $r->json('folio'));
        $this->assertSame(50.0, (float) $r->json('monto_total'));
        $this->assertSame('confirmado', $r->json('estado'));
        $this->assertStringContainsString('Cobro', $r->json('lineas.0.fuente'));

        $this->remakeTenant();
        $this->assertEqualsWithDelta(50.0, (float) $f2->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $f1->refresh()->saldo_aplicado, 0.001, 'La que Felix no tocó sigue pendiente.');
        $this->assertEqualsWithDelta(20.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001, 'Los $20 siguen como saldo a favor del cobro.');
        $linea = AdminContPaymentInvoice::where('payment_id', $cobro)->where('invoice_id', $f2->id)->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float) $linea->monto_aplicado, 0.001);
        $this->assertNotNull($linea->fecha_aplicacion);
        $mov = AdminContPaymentMovement::where('compensation_id', $r->json('id'))->firstOrFail();
        $this->assertSame('aplicacion', $mov->tipo);
        $this->assertSame('cruce', $mov->origen);
        $this->sinDiferencias();

        // Al día siguiente: otros 200 (disponible 220) → paga la de 100 y deja el resto a favor; sigue cuadrando.
        $cobro2 = $this->cobroACuenta($h, $master, 200);
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f1->id, 'monto' => 20],
            ['source_type' => 'cobro', 'source_id' => $cobro2, 'invoice_id' => $f1->id, 'monto' => 80],
        ]))->assertStatus(201);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(100.0, (float) $f1->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(120.0, app(PaymentLedger::class)->remanenteDeCobro($cobro2), 0.001);
        $this->sinDiferencias();
    }

    public function test_confirmar_desde_un_abono_historico_descuenta_el_abono_y_aparece_en_el_estado_de_cuenta(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $factura = $this->factura($master, 300);
        $abono   = $this->abonoConfirmado($master->id, 200);
        app(PaymentLedgerBackfill::class)->run();   // el historial ya está en el libro, como tras el despliegue
        $h = $this->headers();

        $r = $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'abono', 'source_id' => $abono->id, 'invoice_id' => $factura->id, 'monto' => 150],
        ]))->assertStatus(201);

        $this->remakeTenant();
        $this->assertEqualsWithDelta(150.0, (float) $abono->refresh()->monto_usado, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $this->sinDiferencias();

        $movs = app(AccountStatementService::class)->detail($master->id, null, null, null)['movimientos'];
        // SCRUM-1168: la compensación no es una fila; figura el abono (cobro) con su recibo, por lo aplicado y por lo que sigue a favor.
        $this->assertNull(collect($movs)->firstWhere('tipo', 'compensacion'), 'Una compensación ya no es fila del Estado de Cuenta.');
        $cobros = collect($movs)->where('tipo', 'cobro')->values();
        $this->assertSame(200.0, round((float) $cobros->sum('credito'), 2), 'El abono figura completo, aplicado o no.');
        $aplicado = $cobros->firstWhere('numero_factura', $factura->numero);
        $this->assertSame(150.0, (float) $aplicado['credito']);
        $this->assertSame($abono->numero_recibo, $aplicado['recibo']['numero_recibo']);
        $this->assertSame('abono', $aplicado['recibo']['tipo']);
        $this->assertSame(50.0, (float) $cobros->firstWhere('numero_factura', null)['credito'], 'Lo no aplicado es saldo a favor.');
        $this->assertSame(100.0, (float) end($movs)['saldo'], '300 de factura menos 200 recibidos.');
    }

    public function test_el_estado_de_cuenta_muestra_el_cobro_completo_y_su_saldo_a_favor_sin_filas_de_compensacion(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Estado '.uniqid()]);
        $f1      = $this->factura($master, 100, 0, 30);
        $f2      = $this->factura($master, 50, 0, 20);
        $h       = $this->headers();
        $cobro   = $this->cobroACuenta($h, $master, 500);

        $antes = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $this->assertSame(-350.0, $antes['saldo'], '150 de facturas menos 500 recibidos: todo el cobro es saldo a favor.');
        $this->assertTrue($antes['saldo_a_favor']);
        $this->assertSame(1, collect($antes['movimientos'])->where('tipo', 'cobro')->count());

        // Se asigna a las dos facturas en un solo cruce: sigue siendo UN cobro, no tres filas.
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f1->id, 'monto' => 100],
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f2->id, 'monto' => 50],
        ]))->assertStatus(201);
        $this->remakeTenant();

        $d = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $this->assertSame([], collect($d['movimientos'])->where('tipo', 'compensacion')->values()->all());
        $cobros = collect($d['movimientos'])->where('tipo', 'cobro')->values();
        $this->assertSame(500.0, round((float) $cobros->sum('credito'), 2), 'El cobro figura por su monto completo, sin importar cómo se asignó.');
        $this->assertEqualsCanonicalizing([150.0, 350.0], $cobros->pluck('credito')->map(fn ($c) => (float) $c)->all(), 'Lo aplicado a las dos facturas y, aparte, lo que sigue a favor.');
        $this->assertSame(-350.0, $d['saldo'], 'Compensar no cambia el saldo del cliente.');
        $this->assertNotNull($cobros->first()['recibo']);
        $this->sinDiferencias();
    }

    /** Pre-QA SCRUM-1168, bloqueante 1: anular una factura neutraliza su débito, no el dinero que el cliente ya pagó. */
    public function test_un_cobro_aplicado_a_una_factura_anulada_sigue_figurando_por_su_monto_completo(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Anulada '.uniqid()]);
        $factura = $this->factura($master, 300, 0, 10);
        $h       = $this->headers();
        $cobro   = $this->cobroACuenta($h, $master, 500);
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $factura->id, 'monto' => 300],
        ]))->assertStatus(201);
        $this->remakeTenant();
        \Illuminate\Support\Facades\DB::table($factura->getTable())->where('id', $factura->id)->update(['anulada_at' => now()]);

        $d = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $cobros = collect($d['movimientos'])->where('tipo', 'cobro');
        $this->assertSame(500.0, round((float) $cobros->sum('credito'), 2), 'El cobro figura completo aunque la factura se anuló.');
        $this->assertSame(-500.0, $d['saldo'], 'La anulación deja la factura en cero y los 500 pagados quedan a favor del cliente.');
    }

    /** Pre-QA SCRUM-1168 ronda 2, bloqueante 3: la nota de anulación que devuelve en efectivo lo pagado es una salida de dinero. */
    public function test_la_anulacion_de_una_factura_pagada_con_el_excedente_devuelto_en_efectivo_cuadra_con_lo_que_le_queda_al_cliente(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Anulada Devuelta '.uniqid()]);
        $factura = $this->factura($master, 300, 0, 10);
        $h       = $this->headers();
        $cobro   = $this->cobroACuenta($h, $master, 500);
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $factura->id, 'monto' => 300],
        ]))->assertStatus(201);
        $this->remakeTenant();
        \Illuminate\Support\Facades\DB::table($factura->getTable())->where('id', $factura->id)->update(['anulada_at' => now()]);
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'factura_origen_id' => $factura->id, 'monto' => 300, 'subtotal' => 300, 'itbms' => 0,
            'monto_aplicado_saldo' => 0, 'monto_usado' => 0, 'motivo' => 'Anulación', 'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => User::where('email', self::FELIX_EMAIL)->value('id'), 'fecha' => now(),
        ]);

        $d     = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $movs  = collect($d['movimientos']);
        $this->assertSame(500.0, round((float) $movs->where('tipo', 'cobro')->sum('credito'), 2));
        $dev = $movs->firstWhere('tipo', 'devolucion_saldo');
        $this->assertNotNull($dev, 'Los 300 devueltos en efectivo salen como débito.');
        $this->assertSame(300.0, (float) $dev['debito']);
        $this->assertSame($nota->id, $dev['nota_id']);
        $this->assertSame(-200.0, $d['saldo'], 'Pagó 500, se le devolvieron 300: le quedan 200 a favor.');
    }

    /** Un cobro anterior (no v2) aplicado a una factura que después se anuló también sigue figurando: el cliente pagó. */
    public function test_un_cobro_anterior_aplicado_a_una_factura_anulada_sigue_figurando(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Legacy Anulada '.uniqid()]);
        $factura = $this->factura($master, 300, 300, 10);
        $cobro   = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_recibido' => 300, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => 'efectivo', 'estado' => AdminContPayment::ESTADO_CONFIRMADO, 'cobros_v2' => false,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $cobro->id, 'invoice_id' => $factura->id, 'monto_aplicado' => 300]);
        \Illuminate\Support\Facades\DB::table($factura->getTable())->where('id', $factura->id)->update(['anulada_at' => now()]);

        $d = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $this->assertSame(300.0, round((float) collect($d['movimientos'])->where('tipo', 'cobro')->sum('credito'), 2));
        $this->assertSame(-300.0, $d['saldo'], 'Factura 300, anulación -300, cobro -300: queda a favor lo pagado.');
    }

    // ── SCRUM-1183: el pago de una factura corregida pasa al reemplazo ──────────────────────────────

    /** Factura de 300 pagada con un cobro v2 de 500 (300 aplicados), anulada por una corrección y con su reemplazo del monto dado. */
    private function facturaCorregida(MasterClient $master, array $h, float $montoReemplazo): array
    {
        $original = $this->factura($master, 300, 0, 10);
        $cobro    = $this->cobroACuenta($h, $master, 500);
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $original->id, 'monto' => 300],
        ]))->assertStatus(201);
        $this->remakeTenant();
        \Illuminate\Support\Facades\DB::table($original->getTable())->where('id', $original->id)->update(['anulada_at' => now()]);
        $reemplazo = $this->factura($master, $montoReemplazo);

        return [$original->refresh(), $reemplazo, $cobro];
    }

    private function trasladar(AdminContInvoice $original, AdminContInvoice $reemplazo): float
    {
        return \Illuminate\Support\Facades\DB::transaction(
            fn (): float => app(\App\Modules\AdminCont\Services\InvoiceReplacementPaymentTransfer::class)->transfer($original, $reemplazo, 'Corrección de datos NC-T: el pago pasa al reemplazo', null),
        );
    }

    public function test_el_pago_de_una_factura_corregida_pasa_al_reemplazo_con_el_mismo_monto(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Traslado '.uniqid()]);
        [$original, $reemplazo, $cobro] = $this->facturaCorregida($master, $this->headers(), 300);

        $this->assertSame(300.0, $this->trasladar($original, $reemplazo));

        $this->assertEqualsWithDelta(0.0, (float) $original->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $reemplazo->refresh()->saldo_aplicado, 0.001, 'El reemplazo queda pagado.');
        $this->assertEqualsWithDelta(200.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001, 'Lo no aplicado del cobro sigue a favor.');
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->aplicadoAFactura($original->id), 0.001);
        $this->assertEqualsWithDelta(300.0, app(PaymentLedger::class)->aplicadoAFactura($reemplazo->id), 0.001);
        $linea = AdminContPaymentInvoice::where('payment_id', $cobro)->where('invoice_id', $reemplazo->id)->firstOrFail();
        $this->assertEqualsWithDelta(300.0, (float) $linea->monto_aplicado, 0.001);
        $this->assertNull(AdminContPaymentInvoice::where('payment_id', $cobro)->where('invoice_id', $original->id)->first());
        $this->assertSame(0, AdminContCompensation::count() - 1, 'Un traslado no crea compensación: solo está la del cruce original.');
        $this->sinDiferencias();
        $this->assertSame([], app(PaymentLedgerVerifier::class)->verify()['anuladas'], 'Ya no hay anulada con dinero sin respaldo.');

        $d = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $this->assertSame(-200.0, $d['saldo'], 'El saldo del cliente no cambia: pagó 500, debe 300 del reemplazo y 0 de la anulada.');
    }

    public function test_si_el_reemplazo_vale_mas_el_resto_queda_pendiente_y_si_vale_menos_sobra_a_favor(): void
    {
        $h = $this->headers();
        $m1 = MasterClient::create(['name' => 'Cliente Mayor '.uniqid()]);
        [$o1, $r1, $c1] = $this->facturaCorregida($m1, $h, 350);
        $this->assertSame(300.0, $this->trasladar($o1, $r1));
        $this->assertEqualsWithDelta(300.0, (float) $r1->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $r1->monto - (float) $r1->saldo_aplicado, 0.001, 'Quedan 50 pendientes en el reemplazo.');
        $this->sinDiferencias();

        $m2 = MasterClient::create(['name' => 'Cliente Menor '.uniqid()]);
        [$o2, $r2, $c2] = $this->facturaCorregida($m2, $h, 250);
        $this->assertSame(250.0, $this->trasladar($o2, $r2));
        $this->assertEqualsWithDelta(250.0, (float) $r2->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(250.0, app(PaymentLedger::class)->remanenteDeCobro($c2), 0.001, 'Los 50 que sobran vuelven a ser saldo a favor del cobro (200 + 50).');
        $this->sinDiferencias();
    }

    public function test_el_traslado_de_un_abono_ajusta_lo_usado_del_abono(): void
    {
        $master   = MasterClient::create(['name' => 'Cliente Traslado Abono '.uniqid()]);
        $original = $this->factura($master, 300, 0, 10);
        $abono    = $this->abonoConfirmado($master->id, 500);
        app(PaymentLedgerBackfill::class)->run();
        $h = $this->headers();
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'abono', 'source_id' => $abono->id, 'invoice_id' => $original->id, 'monto' => 300],
        ]))->assertStatus(201);
        $this->remakeTenant();
        \Illuminate\Support\Facades\DB::table($original->getTable())->where('id', $original->id)->update(['anulada_at' => now()]);
        $reemplazo = $this->factura($master, 250);

        $this->assertSame(250.0, $this->trasladar($original->refresh(), $reemplazo));

        $this->assertEqualsWithDelta(250.0, (float) $abono->refresh()->monto_usado, 0.001, 'Del abono quedan usados solo los 250 que cupieron.');
        $this->assertEqualsWithDelta(250.0, (float) $reemplazo->refresh()->saldo_aplicado, 0.001);
        $this->sinDiferencias();
    }

    public function test_el_comando_traslada_los_pagos_de_correcciones_anteriores_y_en_simulacion_no_escribe_nada(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Comando '.uniqid()]);
        [$original, $reemplazo, $cobro] = $this->facturaCorregida($master, $this->headers(), 300);
        AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
            'factura_origen_id' => $original->id, 'factura_nueva_id' => $reemplazo->id, 'monto' => 300, 'subtotal' => 300, 'itbms' => 0,
            'monto_aplicado_saldo' => 0, 'monto_usado' => 0, 'motivo' => 'Corrección', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => User::where('email', self::FELIX_EMAIL)->value('id'), 'fecha' => now(),
        ]);
        $remake = fn () => Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        \Illuminate\Support\Facades\Artisan::call('cobros:trasladar-pagos-correcciones');
        $remake();
        $this->assertEqualsWithDelta(0.0, (float) $reemplazo->refresh()->saldo_aplicado, 0.001, 'En simulación no se escribe nada.');

        \Illuminate\Support\Facades\Artisan::call('cobros:trasladar-pagos-correcciones', ['--force' => true]);
        $remake();
        $this->assertEqualsWithDelta(300.0, (float) $reemplazo->refresh()->saldo_aplicado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $original->refresh()->saldo_aplicado, 0.001);
        $this->sinDiferencias();
        $this->assertSame([], app(PaymentLedgerVerifier::class)->verify()['anuladas']);

        \Illuminate\Support\Facades\Artisan::call('cobros:trasladar-pagos-correcciones', ['--force' => true]);
        $remake();
        $this->assertEqualsWithDelta(300.0, (float) $reemplazo->refresh()->saldo_aplicado, 0.001, 'Idempotente: correrlo otra vez no mueve nada más.');
    }

    public function test_el_verificador_senala_una_factura_anulada_con_dinero_aplicado_sin_respaldo(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Verificador '.uniqid()]);
        [$original] = $this->facturaCorregida($master, $this->headers(), 300);

        $anuladas = app(PaymentLedgerVerifier::class)->verify()['anuladas'];
        $this->assertSame([$original->id], array_column($anuladas, 'invoice_id'));
        $this->assertSame(300.0, $anuladas[0]['libro']);
    }

    /** Pre-QA SCRUM-1168, bloqueante 2: un cobro anterior que gastó saldo de un abono no debe contarse además del abono completo. */
    public function test_un_cobro_anterior_que_gasto_saldo_de_un_abono_no_duplica_el_credito(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono Gastado '.uniqid()]);
        $factura = $this->factura($master, 700, 700, 10);
        $abono   = $this->abonoConfirmado($master->id, 1000);
        app(PaymentLedgerBackfill::class)->run();   // el abono ya está en el libro, como tras el despliegue

        // Cobro del flujo anterior: no recibió dinero propio, aplicó 700 del saldo del abono (el libro lo deja en el cobro, no en el abono).
        $cobroViejo = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_recibido' => 0, 'saldo_favor_aplicado' => 700,
            'metodo_pago' => 'efectivo', 'estado' => AdminContPayment::ESTADO_CONFIRMADO, 'cobros_v2' => false,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $cobroViejo->id, 'invoice_id' => $factura->id, 'monto_aplicado' => 700, 'fecha_aplicacion' => now()]);
        app(PaymentLedger::class)->aplicacion($cobroViejo, $factura, 700, AdminContPaymentMovement::ORIGEN_DIRECTA);

        $d = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $this->assertSame(1000.0, round((float) collect($d['movimientos'])->where('tipo', 'cobro')->sum('credito'), 2), 'Se recibieron 1000: 700 gastados en la factura y 300 a favor, sin contar el gasto dos veces.');
        $this->assertSame(-300.0, $d['saldo']);
        $this->assertEqualsWithDelta(300.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001, 'Coincide con el dinero disponible del libro.');
    }

    public function test_el_dinero_devuelto_en_efectivo_sale_como_debito_y_reduce_el_saldo_a_favor(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Devuelto '.uniqid()]);
        $h      = $this->headers();
        $cobro  = $this->cobroACuenta($h, $master, 200);

        app(PaymentLedger::class)->record([
            'master_client_id' => $master->id, 'tipo' => AdminContPaymentMovement::TIPO_DEVOLUCION, 'monto' => 80,
            'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'payment_id' => $cobro, 'delta_disponible' => -80, 'motivo' => 'Devolución al cliente',
        ]);

        $d      = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $movs   = collect($d['movimientos']);
        $this->assertSame(120.0 + 80.0, round((float) $movs->where('tipo', 'cobro')->sum('credito'), 2), 'El cobro figura completo: lo que sigue a favor más lo devuelto.');
        $this->assertSame(80.0, (float) $movs->firstWhere('tipo', 'devolucion_saldo')['debito']);
        $this->assertSame(-120.0, $d['saldo'], 'Quedan 120 a favor del cliente.');
    }

    public function test_un_cruce_desde_el_saldo_de_una_nota_de_credito_no_agrega_fila_al_estado_de_cuenta(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Nota Estado '.uniqid()]);
        $origen  = $this->factura($master, 300, 300);
        $factura = $this->factura($master, 400);
        $nota    = $this->notaConSaldo($master, $origen, 120);
        $h       = $this->headers();

        $antes = collect(app(AccountStatementService::class)->detail($master->id, null, null, null)['movimientos'])->count();
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'nota_credito', 'source_id' => $nota->id, 'invoice_id' => $factura->id, 'monto' => 120],
        ]))->assertStatus(201);
        $this->remakeTenant();

        $movs = collect(app(AccountStatementService::class)->detail($master->id, null, null, null)['movimientos']);
        $this->assertSame($antes, $movs->count(), 'La nota ya acredita su monto en su propia fila; el cruce no lo repite.');
        $this->assertNull($movs->firstWhere('tipo', 'compensacion'));
    }

    public function test_un_cobro_repartido_entre_proyectos_muestra_cada_porcion_en_su_proyecto_y_el_filtro_deja_solo_lo_aplicado_a_ese(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Proyectos '.uniqid()]);
        $sub    = \App\Modules\VentasDiseno\Models\SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '8-'.random_int(100, 999)]);
        $p1     = \App\Modules\VentasDiseno\Models\SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Uno '.uniqid()]);
        $p2     = \App\Modules\VentasDiseno\Models\SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Dos '.uniqid()]);
        $f1     = $this->factura($master, 100, 0, 30);
        $f2     = $this->factura($master, 60, 0, 20);
        \Illuminate\Support\Facades\DB::table($f1->getTable())->where('id', $f1->id)->update(['sales_project_id' => $p1->id]);
        \Illuminate\Support\Facades\DB::table($f2->getTable())->where('id', $f2->id)->update(['sales_project_id' => $p2->id]);
        $h     = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 400);
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f1->id, 'monto' => 100],
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $f2->id, 'monto' => 60],
        ]))->assertStatus(201);
        $this->remakeTenant();

        $svc   = app(AccountStatementService::class);
        $todo  = collect($svc->detail($master->id, null, null, null)['movimientos'])->where('tipo', 'cobro');
        $this->assertSame(400.0, round((float) $todo->sum('credito'), 2));
        $this->assertSame(100.0, (float) $todo->firstWhere('sales_project_id', $p1->id)['credito']);
        $this->assertSame(60.0, (float) $todo->firstWhere('sales_project_id', $p2->id)['credito']);
        $this->assertSame(240.0, (float) $todo->firstWhere('sales_project_id', null)['credito'], 'El saldo a favor no pertenece a ningún proyecto.');

        $uno = $svc->detail($master->id, $p1->id, null, null);
        $this->assertSame(100.0, round((float) collect($uno['movimientos'])->where('tipo', 'cobro')->sum('credito'), 2));
        $this->assertSame(0.0, $uno['saldo'], 'El proyecto queda saldado y no arrastra el saldo a favor del cliente.');
    }

    public function test_confirmar_desde_el_saldo_de_una_nota_de_credito_descuenta_la_nota_sin_tocar_su_logica(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Nota '.uniqid()]);
        $origen  = $this->factura($master, 300, 300);
        $factura = $this->factura($master, 400);
        $nota    = $this->notaConSaldo($master, $origen, 120);
        $h = $this->headers();

        $d = $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes/'.$master->id)->assertStatus(200);
        $this->assertSame('nota_credito', $d->json('fuentes.0.source_type'));
        $this->assertSame(120.0, (float) $d->json('fuentes.0.disponible'));

        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'nota_credito', 'source_id' => $nota->id, 'invoice_id' => $factura->id, 'monto' => 120],
        ]))->assertStatus(201);

        $this->remakeTenant();
        $this->assertEqualsWithDelta(120.0, (float) $nota->refresh()->monto_usado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $nota->monto_aplicado_saldo, 0.001, 'La nota conserva su propio registro de lo que redujo en su factura.');
        $this->assertEqualsWithDelta(120.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertNull(collect($r['facturas'])->firstWhere('invoice_id', $factura->id), 'La factura compensada cuadra con el libro.');
    }

    public function test_si_la_fuente_se_consume_entre_la_vista_previa_y_la_confirmacion_no_se_aplica(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Carrera '.uniqid()]);
        $f1 = $this->factura($master, 300);
        $f2 = $this->factura($master, 300);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 100);
        $cruce = fn (int $invoiceId) => $this->cruce($master->id, [['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $invoiceId, 'monto' => 100]]);

        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones/preview', $cruce($f1->id))->assertStatus(200);
        $this->remakeTenant();
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $cruce($f2->id))->assertStatus(201);   // otro cruce se llevó los $100
        $this->remakeTenant();
        $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $cruce($f1->id))->assertStatus(422);   // el primero ya no tiene de dónde

        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, (float) $f1->refresh()->saldo_aplicado, 0.001);
        $this->assertSame(1, AdminContCompensation::count());
        $this->sinDiferencias();
    }

    // ── detalle y documento ─────────────────────────────────────────────────────────────────────

    public function test_el_cruce_confirmado_se_consulta_y_se_descarga_con_su_folio(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Doc '.uniqid()]);
        $factura = $this->factura($master, 100);
        $h = $this->headers();
        $cobro = $this->cobroACuenta($h, $master, 100);
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/compensaciones', $this->cruce($master->id, [
            ['source_type' => 'cobro', 'source_id' => $cobro, 'invoice_id' => $factura->id, 'monto' => 100],
        ]))->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/'.$r->json('id'))->assertStatus(200)->assertJson(['folio' => $r->json('folio')]);
        $pdf = $this->withHeaders($h)->get('/api/admin-contab/compensaciones/'.$r->json('id').'/pdf')->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/999999')->assertStatus(404);
    }

    public function test_el_resumen_y_las_compensaciones_estan_cerradas_a_roles_sin_acceso_al_modulo(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/compensaciones/clientes')->assertStatus(403);
    }
}
