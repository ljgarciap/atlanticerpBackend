<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\CreditTerms;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 28 (grupo 3) — días de crédito por cliente y cartera sin facturas pagadas. */
class CreditTermsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function invoice(?int $masterClientId, float $monto, float $saldoAplicado, int $diasAtras): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-CT-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente CT', 'master_client_id' => $masterClientId, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        $invoice->forceFill(['created_at' => now()->subDays($diasAtras)])->save();

        return $invoice;
    }

    public function test_sin_perfil_rige_el_global_y_con_dias_propios_rige_el_del_cliente(): void
    {
        $global  = (int) AdminContFiscalSettings::current()->dias_credito_factura;
        $propio  = MasterClient::create(['name' => 'Cliente 90 dias']);
        $heredado = MasterClient::create(['name' => 'Cliente sin perfil']);
        AdminContClientBillingProfile::create(['master_client_id' => $propio->id, 'dias_credito' => 90]);

        $terms = new CreditTerms();
        $this->assertSame(90, $terms->diasPara($propio->id));
        $this->assertSame($global, $terms->diasPara($heredado->id));
        $this->assertSame($global, $terms->diasPara(null));

        $factura = $this->invoice($propio->id, 100, 0, 100);
        $this->assertSame(now()->subDays(100)->addDays(90)->toDateString(), $terms->vencimiento($factura)->toDateString());
    }

    public function test_la_cartera_cuenta_desde_el_vencimiento_del_cliente_y_excluye_lo_pagado(): void
    {
        $service = app(InvoiceService::class);
        $base    = $service->agingBuckets()['ranges'];

        $propio    = MasterClient::create(['name' => 'Cliente 90 dias']);
        $heredado  = MasterClient::create(['name' => 'Cliente sin perfil']);
        AdminContClientBillingProfile::create(['master_client_id' => $propio->id, 'dias_credito' => 90]);

        $this->invoice($heredado->id, 500, 0, 100);   // global (30): vencida hace ~70 días → tramo 61-90
        $this->invoice($propio->id, 300, 0, 100);     // 90 días de crédito: vencida hace 10 días → tramo 0-30
        $this->invoice($heredado->id, 900, 900, 400); // pagada hace mucho: no es cartera
        $this->invoice($heredado->id, 700, 650, 400); // abono parcial: cuenta solo su saldo (50) en 91+

        $despues = $service->agingBuckets()['ranges'];

        $this->assertSame($base[0]['cantidad'] + 1, $despues[0]['cantidad']);
        $this->assertEqualsWithDelta($base[0]['monto'] + 300, $despues[0]['monto'], 0.001);
        $this->assertSame($base[2]['cantidad'] + 1, $despues[2]['cantidad']);
        $this->assertEqualsWithDelta($base[2]['monto'] + 500, $despues[2]['monto'], 0.001);
        $this->assertSame($base[3]['cantidad'] + 1, $despues[3]['cantidad'], 'la pagada no cuenta en 91+, el abono parcial sí');
        $this->assertEqualsWithDelta($base[3]['monto'] + 50, $despues[3]['monto'], 0.001);
    }

    public function test_el_endpoint_fija_y_quita_los_dias_de_credito_de_un_cliente(): void
    {
        $cliente = MasterClient::create(['name' => 'Cliente endpoint']);
        $global  = (int) AdminContFiscalSettings::current()->dias_credito_factura;
        $token   = $this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab');
        $url     = "/api/admin-contab/clientes/{$cliente->id}/dias-credito";

        $this->withHeaders($this->authHeaders($token))->putJson($url, ['dias_credito' => 60])
            ->assertStatus(200)->assertJsonPath('dias_credito', 60)->assertJsonPath('dias_credito_efectivo', 60);
        $this->withHeaders($this->authHeaders($token))->putJson($url, ['dias_credito' => 0])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->putJson($url, ['dias_credito' => null])
            ->assertStatus(200)->assertJsonPath('dias_credito_efectivo', $global);

        $vendedor = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($vendedor))->putJson($url, ['dias_credito' => 10])->assertStatus(403);
    }
}
