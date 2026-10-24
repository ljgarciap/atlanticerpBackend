<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F5 — devolución de dinero al cliente desde su saldo a favor: la solicitan Contabilidad/Yaneth (cuenta de salida, referencia y comprobante
 * obligatorios) y la aprueba solo Gerencia; al aprobar sale de la fuente elegida, queda en el libro y en la caja del día.
 */
class AdminContDevolucionF5Test extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';
    private const APPROVER_EMAIL  = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3-private');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function remakeTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function sesiones(): array
    {
        $contabilidad = $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
        $approver  = $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
        $this->remakeTenant();
        app(CobrosConfigService::class)->setV2Activo(true);

        return [$this->authHeaders($contabilidad), $this->authHeaders($approver)];
    }

    private function cuenta(): AdminContBankAccount
    {
        return AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999)]);
    }

    private function cobroSinAplicar(array $h, MasterClient $master, float $monto): int
    {
        $r = $this->withHeaders($h)->postJson('/api/admin-contab/payments/cobro', [
            'master_client_id' => $master->id, 'monto_recibido' => $monto, 'metodo_pago' => 'efectivo', 'bank_account_id' => $this->cuenta()->id,
        ])->assertStatus(201);
        $this->remakeTenant();

        return (int) $r->json('id');
    }

    /** @param array<string, mixed> $over */
    private function solicitar(array $h, MasterClient $master, int $paymentId, float $monto, array $over = [])
    {
        $r = $this->withHeaders($h)->post('/api/admin-contab/clientes/'.$master->id.'/devolucion', $over + [
            'source_type' => 'cobro', 'source_id' => $paymentId, 'monto' => $monto, 'bank_account_id' => $this->cuenta()->id,
            'referencia' => 'TRF-998', 'motivo' => 'Pago de más', 'comprobante' => UploadedFile::fake()->create('trf.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json']);
        $this->remakeTenant();

        return $r;
    }

    public function test_devolver_dinero_requiere_aprobacion_de_aprobador_y_al_aprobar_sale_del_cobro_y_de_la_caja_de_hoy(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Dev '.uniqid()]);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobroSinAplicar($contabilidad, $master, 500);

        $sol = $this->solicitar($contabilidad, $master, $cobro, 200)->assertStatus(201);
        $this->assertSame('solicitada', $sol->json('estado'));
        $this->assertEqualsWithDelta(500.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001, 'Solicitar no mueve nada.');
        $this->assertSame(0, AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_CLIENT_REFUND)->count());

        $this->withHeaders($contabilidad)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(403);
        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->remakeTenant();

        $this->assertEqualsWithDelta(300.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001);
        $salida = AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_CLIENT_REFUND)->firstOrFail();
        $this->assertSame(AdminContBankMovement::TIPO_DEVOLUCION, $salida->tipo);
        $this->assertEqualsWithDelta(200.0, (float) $salida->monto, 0.001);
        $this->assertSame(now()->toDateString(), $salida->fecha->toDateString());
        $this->assertSame([], app(PaymentLedgerVerifier::class)->verify()['clientes']);

        // Un cobro que ya tuvo una devolución de dinero no se puede anular después.
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/anular", ['motivo' => 'x'])->assertStatus(422);
    }

    public function test_pide_comprobante_cuenta_y_referencia_y_no_acepta_mas_de_lo_disponible(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Dev '.uniqid()]);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobroSinAplicar($contabilidad, $master, 100);

        $this->solicitar($contabilidad, $master, $cobro, 50, ['comprobante' => null])->assertStatus(422);
        $this->solicitar($contabilidad, $master, $cobro, 50, ['referencia' => ''])->assertStatus(422);
        $this->solicitar($contabilidad, $master, $cobro, 50, ['bank_account_id' => 99999])->assertStatus(422);
        $this->solicitar($contabilidad, $master, $cobro, 150)->assertStatus(422);

        // Dos solicitudes pendientes sobre la misma fuente no pueden sumar más de lo disponible.
        $this->solicitar($contabilidad, $master, $cobro, 70)->assertStatus(201);
        $this->solicitar($contabilidad, $master, $cobro, 40)->assertStatus(422);
    }

    public function test_devolver_desde_un_abono_lo_consume(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Dev '.uniqid()]);
        [$contabilidad, $approver] = $this->sesiones();
        $abono = AdminContAbono::create([
            'master_client_id' => $master->id, 'monto_original' => 300, 'monto_usado' => 0, 'metodo_pago' => 'efectivo', 'referencia' => 'REF-A', 'registrado_por' => User::query()->value('id'), 'estado' => AdminContAbono::ESTADO_CONFIRMADO,
            'fecha_registro' => now(), 'fecha_confirmacion' => now(),
        ]);

        $sol = $this->withHeaders($contabilidad)->post('/api/admin-contab/clientes/'.$master->id.'/devolucion', [
            'source_type' => 'abono', 'source_id' => $abono->id, 'monto' => 120, 'bank_account_id' => $this->cuenta()->id,
            'referencia' => 'CHK-1', 'motivo' => 'Cliente lo pidió', 'comprobante' => UploadedFile::fake()->image('c.png'),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->remakeTenant();

        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => true])->assertStatus(200);
        $this->remakeTenant();

        $this->assertEqualsWithDelta(180.0, (float) $abono->fresh()->montoDisponible(), 0.001);
        $this->assertSame(1, AdminContPaymentMovement::where('abono_id', $abono->id)->where('tipo', 'devolucion')->count());
    }

    public function test_tras_confirmar_solo_se_corrigen_referencia_y_comprobante(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Edit '.uniqid()]);
        [$contabilidad] = $this->sesiones();
        $cobro = $this->cobroSinAplicar($contabilidad, $master, 100);

        $ok = $this->withHeaders($contabilidad)->post("/api/admin-contab/payments/{$cobro}/datos", [
            'referencia' => 'TRF-CORREGIDA', 'comprobante' => UploadedFile::fake()->create('nuevo.pdf', 30, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(200);
        $this->remakeTenant();
        $this->assertSame('TRF-CORREGIDA', $ok->json('referencia'));
        $this->assertSame(1, \App\Modules\AdminCont\Models\AdminContPaymentAttachment::where('payment_id', $cobro)->count());

        // Cualquier campo contable se rechaza.
        $this->withHeaders($contabilidad)->postJson("/api/admin-contab/payments/{$cobro}/datos", ['monto_recibido' => 999])->assertStatus(422);
        $this->remakeTenant();
        $this->assertEqualsWithDelta(100.0, (float) \App\Modules\AdminCont\Models\AdminContPayment::findOrFail($cobro)->monto_recibido, 0.001);
    }

    public function test_rechazar_la_devolucion_no_mueve_dinero(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Dev '.uniqid()]);
        [$contabilidad, $approver] = $this->sesiones();
        $cobro = $this->cobroSinAplicar($contabilidad, $master, 100);
        $sol = $this->solicitar($contabilidad, $master, $cobro, 100)->assertStatus(201);

        $this->withHeaders($approver)->putJson('/api/admin-contab/reversal-requests/'.$sol->json('id').'/decision', ['aprobar' => false, 'motivo_rechazo' => 'No procede'])->assertStatus(200);
        $this->remakeTenant();

        $this->assertEqualsWithDelta(100.0, app(PaymentLedger::class)->remanenteDeCobro($cobro), 0.001);
        $this->assertSame(0, AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_CLIENT_REFUND)->count());
    }
}
