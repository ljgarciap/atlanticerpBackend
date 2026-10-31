<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;
use Tests\Support\RegistraCobrosConCargaAnterior;

/**
 * SCRUM-1168 F0 — integridad previa del cobro de facturas, sin cambiar el concepto:
 *  - confirmar un cobro «esperando confirmación» revalida la factura (antes sumaba sin mirar y la dejaba con más cobrado que su valor);
 *  - el saldo a favor / abono que otro cobro ya consumió hace fallar la confirmación en vez de aplicarse en silencio;
 *  - un abono solo puede asociarse a la factura, proyecto o subcliente de SU cliente.
 */
class AdminContCobrosIntegridadF0Test extends FeatureTestCase
{
    use RegistraCobrosConCargaAnterior;

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsContabilidad(): string
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

    /** Disponible del libro del cliente: el excedente de un cobro (lo que no cupo en las facturas) vive aquí, ya no como un abono aparte. */
    private function disponibleDe(MasterClient $master): float
    {
        return round((float) AdminContPaymentMovement::where('master_client_id', $master->id)->sum('delta_disponible'), 2);
    }

    public function test_el_preview_avisa_de_la_implicacion_antes_de_confirmar_y_no_cambia_nada(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsContabilidad());

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
        // Solo el cobro confirmado (a) entró al libro y se aplicó completo: nada sin aplicar.
        $this->assertSame(0.0, $this->disponibleDe($master));

        // Un cobro ya confirmado no tiene preview.
        $this->withHeaders($h)->getJson('/api/admin-contab/payments/'.$a->json('id').'/confirm-preview')->assertStatus(422);
    }

    public function test_confirmar_un_cobro_con_lugar_en_la_factura_sigue_funcionando_sin_ajustes(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente F0 '.uniqid()]);
        $factura = $this->facturaDe($master, 500);
        $cuenta  = $this->cuenta();
        $h       = $this->authHeaders($this->loginAsContabilidad());

        $cobro = $this->withHeaders($h)->postJson('/api/admin-contab/payments', $this->cobro($master->id, [$factura->id], 'transferencia', $cuenta->id, 500))->assertStatus(201);
        $this->remakeTenant();
        $r = $this->withHeaders($h)->putJson('/api/admin-contab/payments/'.$cobro->json('id').'/confirm')->assertStatus(200)->assertJson(['estado' => 'confirmado']);
        $this->assertNull($r->json('ajustes_confirmacion'));

        $this->remakeTenant();
        $this->assertSame(500.0, (float) $factura->refresh()->saldo_aplicado);
        $this->assertSame(0.0, $this->disponibleDe($master));
    }

}
