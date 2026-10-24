<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\ClientLock;
use App\Modules\AdminCont\Services\InvoiceBalanceWriter;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerBackfill;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F1 — libro de movimientos de cobros en modo sombra: se escribe en paralelo al flujo actual (que no cambia) y el verificador
 * debe dar CERO diferencias contra `saldo_aplicado` y contra el saldo de los abonos; el historial se copia sin tocar el pasado.
 */
class AdminContCobrosLibroF1Test extends FeatureTestCase
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

    private function headers(): array
    {
        $token = $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');

        return $this->authHeaders($token);
    }

    private function cuenta(): AdminContBankAccount
    {
        return AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999)]);
    }

    private function factura(MasterClient $master, float $monto, float $saldoAplicado = 0.0): AdminContInvoice
    {
        return AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
    }

    private function abonoConfirmado(int $masterId, float $monto, float $usado = 0.0): AdminContAbono
    {
        return AdminContAbono::create([
            'numero_recibo' => 'ABN-'.static::uniqueTestNumber(), 'master_client_id' => $masterId, 'monto_original' => $monto, 'monto_usado' => $usado,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $this->cuenta()->id, 'referencia' => 'TRF-F1',
            'estado' => AdminContAbono::ESTADO_CONFIRMADO, 'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function cobro(int $masterId, array $invoiceIds, string $metodo, int $accountId, float $estimado, float $saldoCliente = 0.0): array
    {
        return array_filter([
            'master_client_id' => $masterId, 'invoice_ids' => $invoiceIds, 'metodo_pago' => $metodo, 'bank_account_id' => $accountId,
            'monto_recibido_estimado' => $estimado, 'monto_saldo_cliente_aplicado' => $saldoCliente > 0 ? $saldoCliente : null,
        ], fn ($v) => $v !== null);
    }

    private function sinDiferencias(): void
    {
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], $r['facturas'], 'Facturas con diferencia entre saldo_aplicado y el libro: '.json_encode($r['facturas']));
        $this->assertSame([], $r['clientes'], 'Clientes con diferencia de disponible: '.json_encode($r['clientes']));
    }

    // ── el libro es append-only ────────────────────────────────────────────────────────────────

    public function test_un_movimiento_del_libro_no_se_edita_ni_se_borra(): void
    {
        $master = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $m = app(PaymentLedger::class)->record([
            'master_client_id' => $master->id, 'tipo' => AdminContPaymentMovement::TIPO_INGRESO, 'monto' => 10,
            'origen' => AdminContPaymentMovement::ORIGEN_DIRECTA, 'delta_disponible' => 10,
        ]);

        try {
            $m->update(['monto' => 99]);
            $this->fail('Editar un movimiento debía fallar.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $m->delete();
            $this->fail('Borrar un movimiento debía fallar.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_el_libro_rechaza_un_monto_cero_o_negativo(): void
    {
        $master = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => app(PaymentLedger::class)->record([
            'master_client_id' => $master->id, 'tipo' => AdminContPaymentMovement::TIPO_INGRESO, 'monto' => 0, 'origen' => 'directa',
        ]));
    }

    // ── única puerta de escritura de saldo_aplicado y bloqueo por cliente ──────────────────────

    public function test_el_escritor_de_saldo_respeta_el_rango_de_la_factura(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 100, 40);
        $writer  = app(InvoiceBalanceWriter::class);

        DB::transaction(function () use ($writer, $factura): void {
            $writer->apply($factura, 60);
            $this->assertEqualsWithDelta(100.0, (float) $factura->refresh()->saldo_aplicado, 0.001);

            try {
                $writer->apply($factura, 0.5);
                $this->fail('Pasarse del valor de la factura debía fallar.');
            } catch (InvoiceStateException) {
            }
            try {
                $writer->apply($factura, -150);
                $this->fail('Dejar el saldo bajo cero debía fallar.');
            } catch (InvoiceStateException) {
            }

            // Las notas de crédito conservan su regla propia (sin tope), exactamente como antes.
            $writer->apply($factura, 10, permitirExceso: true);
            $this->assertEqualsWithDelta(110.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        });
    }

    public function test_el_escritor_y_el_bloqueo_exigen_una_transaccion(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 100);

        // `RefreshDatabase` envuelve cada test en una transacción: salimos de ella para comprobar el guardia.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $rechazos = 0;
        try {
            app(InvoiceBalanceWriter::class)->apply($factura, 10);
        } catch (\LogicException) {
            $rechazos++;
        }
        try {
            app(ClientLock::class)->lock($master->id);
        } catch (\LogicException) {
            $rechazos++;
        }
        DB::beginTransaction();
        $this->assertSame(2, $rechazos, 'Ambos deben negarse a correr fuera de una transacción.');
    }

    // ── modo sombra: el flujo actual escribe el libro y el verificador da cero diferencias ──────

    public function test_un_cobro_en_efectivo_asienta_ingreso_y_aplicacion_y_el_libro_coincide(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->headers();

        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'efectivo', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();

        $movs = AdminContPaymentMovement::where('payment_id', $r->json('id'))->orderBy('id')->get();
        $this->assertSame([AdminContPaymentMovement::TIPO_INGRESO, AdminContPaymentMovement::TIPO_APLICACION], $movs->pluck('tipo')->all());
        $this->assertEqualsWithDelta(0.0, (float) $movs->sum('delta_disponible'), 0.001, 'Todo el efectivo se aplicó: no queda disponible.');
        $this->assertEqualsWithDelta(500.0, (float) $movs->sum('delta_factura'), 0.001);
        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->aplicadoAFactura($factura->id), 0.001);
        $this->assertNotNull(DB::table((new \App\Modules\AdminCont\Models\AdminContPaymentInvoice())->getTable())->where('payment_id', $r->json('id'))->value('fecha_aplicacion'));

        $this->sinDiferencias();
    }

    public function test_un_cobro_diferido_asienta_el_libro_recien_al_confirmarse(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->headers();

        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $this->assertSame(0, AdminContPaymentMovement::where('payment_id', $r->json('id'))->count(), 'Esperando confirmación: todavía no aplicó nada.');
        $this->assertNull(DB::table((new \App\Modules\AdminCont\Models\AdminContPaymentInvoice())->getTable())->where('payment_id', $r->json('id'))->value('fecha_aplicacion'));

        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$r->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();

        $this->assertSame(2, AdminContPaymentMovement::where('payment_id', $r->json('id'))->count());
        $this->sinDiferencias();
    }

    public function test_el_cobro_que_no_cabe_manda_el_excedente_al_disponible_y_el_libro_coincide(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->headers();

        $a = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $b = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$a->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();
        $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$b->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();

        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001, 'Los $500 que no cupieron quedan como saldo a favor.');
        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->aplicadoAFactura($factura->id), 0.001);
        $this->sinDiferencias();
    }

    public function test_un_abono_confirmado_entra_al_disponible_y_su_consumo_por_un_cobro_lo_baja(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 300);
        $cuenta  = $this->cuenta();
        $h       = $this->headers();

        $abono = $this->withHeaders($h)->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'monto_original' => 100, 'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $cuenta->id, 'referencia' => 'TRF-F1',
        ])->assertStatus(201);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001, 'Por confirmar: aún no es saldo.');

        $this->withHeaders($h)->postJson('/api/admin-contab/abonos/'.$abono->json('id').'/confirm')->assertStatus(200);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(100.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->sinDiferencias();

        // Un cobro en efectivo que usa los $100 de saldo: entra $200 y se aplican $300 → el disponible queda en 0.
        $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'efectivo', $cuenta->id, 200, 100))->assertStatus(201);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) AdminContAbono::findOrFail($abono->json('id'))->monto_usado, 0.001);
        $this->sinDiferencias();
    }

    public function test_el_saldo_que_sale_de_notas_de_credito_no_descuadra_el_disponible_del_libro(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $origen  = $this->factura($master, 300, 300);
        $factura = $this->factura($master, 300);
        $cuenta  = $this->cuenta();
        // Saldo a favor del cliente que proviene de una nota de crédito (fuera del libro) — $100.
        AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $origen->id, 'monto' => 100, 'subtotal' => 100, 'itbms' => 0,
            'monto_aplicado_saldo' => 0, 'monto_usado' => 0, 'motivo' => 'Saldo de nota', 'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'), 'fecha' => now(),
        ]);
        $h = $this->headers();

        $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'efectivo', $cuenta->id, 200, 100))->assertStatus(201);
        $this->remakeTenant();

        $this->assertEqualsWithDelta(0.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) AdminContCreditNote::where('factura_origen_id', $origen->id)->value('monto_usado'), 0.001);
        // La factura de origen se sembró a mano con saldo_aplicado = monto (no vino de un cobro ni de una nota aplicada): esa sí se excluye de la comparación.
        $r = app(PaymentLedgerVerifier::class)->verify();
        $this->assertSame([], array_values(array_filter($r['facturas'], fn (array $f) => $f['invoice_id'] === $factura->id)));
        $this->assertSame([], array_values(array_filter($r['clientes'], fn (array $c) => $c['master_client_id'] === $master->id)));
    }

    public function test_una_falla_al_escribir_el_libro_nunca_rompe_la_operacion_real(): void
    {
        $ledger = app(PaymentLedger::class);
        $master = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);

        DB::transaction(function () use ($ledger, $master): void {
            $factura = $this->factura($master, 100);
            $ledger->shadow(function (): void {
                throw new \RuntimeException('falla simulada del libro');
            });
            // La transacción externa sigue viva y puede seguir trabajando.
            $factura->update(['saldo_aplicado' => 10]);
            $this->assertEqualsWithDelta(10.0, (float) $factura->refresh()->saldo_aplicado, 0.001);
        });
    }

    // ── historial: backfill idempotente y verificador ───────────────────────────────────────────

    public function test_el_backfill_copia_el_historial_sin_duplicar_y_el_verificador_da_cero(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500, 300);       // 300 ya cobrado por un cobro histórico
        $this->abonoConfirmado($master->id, 200, 50);       // disponible 150

        $pago = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_recibido' => 300, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => 'efectivo', 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        $pago->invoiceLines()->create(['invoice_id' => $factura->id, 'monto_aplicado' => 300, 'monto_abono_aplicado' => 0]);
        // Un cobro «esperando confirmación»: todavía no aplicó nada, no se copia.
        $pendiente = AdminContPayment::create([
            'numero_recibo' => 'REC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'monto_recibido' => 200, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => 'transferencia', 'estado' => AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION,
        ]);
        $pendiente->invoiceLines()->create(['invoice_id' => $factura->id, 'monto_aplicado' => 200, 'monto_abono_aplicado' => 0]);

        $this->assertNotSame([], app(PaymentLedgerVerifier::class)->verify()['facturas'], 'Antes del backfill el libro está vacío: debe haber diferencias.');

        $primera = app(PaymentLedgerBackfill::class)->run();
        $this->assertSame(1, $primera['abonos']);
        $this->assertSame(1, $primera['consumos']);
        $this->assertSame(1, $primera['aplicaciones']);

        $segunda = app(PaymentLedgerBackfill::class)->run();
        $this->assertSame(['abonos' => 0, 'consumos' => 0, 'aplicaciones' => 0], $segunda, 'Idempotente: no repite nada.');

        $this->assertEqualsWithDelta(150.0, app(PaymentLedger::class)->disponibleDelCliente($master->id), 0.001);
        $this->assertEqualsWithDelta(300.0, app(PaymentLedger::class)->aplicadoAFactura($factura->id), 0.001);
        $this->assertSame(0, AdminContPaymentMovement::where('payment_id', $pendiente->id)->count());
        $this->sinDiferencias();
    }

    public function test_el_verificador_senala_una_factura_cuyo_saldo_no_coincide_con_el_libro(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500, 120);   // saldo aplicado sin ningún movimiento ni nota que lo respalde

        $facturas = app(PaymentLedgerVerifier::class)->verify()['facturas'];
        $fila = collect($facturas)->firstWhere('invoice_id', $factura->id);

        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(120.0, $fila['diferencia'], 0.001);
    }

    public function test_el_aporte_de_las_notas_de_credito_aplicadas_cuenta_en_la_verificacion(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F1 '.uniqid()]);
        $factura = $this->factura($master, 500, 80);
        AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $factura->id, 'monto' => 80, 'subtotal' => 80, 'itbms' => 0,
            'monto_aplicado_saldo' => 80, 'monto_usado' => 0, 'motivo' => 'Devolución', 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => User::where('email', self::CONTABILIDAD_EMAIL)->value('id'), 'fecha' => now(),
        ]);

        $facturas = app(PaymentLedgerVerifier::class)->verify()['facturas'];
        $this->assertNull(collect($facturas)->firstWhere('invoice_id', $factura->id), 'Lo que aportó la nota explica el saldo: sin diferencia.');
    }
}
