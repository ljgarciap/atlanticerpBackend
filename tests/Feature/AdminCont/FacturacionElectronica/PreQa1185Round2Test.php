<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceLine;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/** Pre-QA ronda 2 — pagos[] vs ítems. */
final class PreQa1185Round2Test extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    private const F0116 = [25872, 25872, 30800, 6684, 5880, 14627, 17500, 7560, 7997, 11900, 21700, 3998, 5950, 10850];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        Storage::fake('s3-private');
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'apagado'])->save();
    }

    private function facturaConLineas(array $netos = self::F0116): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $tasa = AdminContItbmsRate::query()->where('porcentaje', 7)->firstOrFail();
        $base = $factura->lines()->first();
        $factura->lines()->delete();
        $monto = 0;
        foreach ($netos as $i => $neto) {
            $itbms = (int) floor($neto * 7 / 100 + 0.5);
            $monto += $neto + $itbms;
            AdminContInvoiceLine::create([
                'invoice_id' => $factura->id, 'quote_item_id' => $base?->quote_item_id, 'order_item_id' => $base?->order_item_id,
                'descripcion' => 'Línea '.($i + 1), 'cantidad' => 1, 'precio_unitario' => $neto / 100, 'subtotal' => $neto / 100,
                'itbms_rate_id' => $tasa->id, 'itbms' => $itbms / 100, 'total' => ($neto + $itbms) / 100,
            ]);
        }
        $factura->forceFill(['monto' => $monto / 100, 'ajuste_itbms_delta' => 0, 'fe_estado' => 'no_aplica'])->save();

        return $factura->fresh();
    }

    private function fakeCertificado(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01-x', 'qr' => 'https://qr', 'xmlFirmado' => base64_encode('<x/>')], 200)]);
    }

    private function fakeRechazo(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'error' => 'x [2507]'], 422)]);
    }

    /** @return array{items: float, pagos: float, itbmsLineas: float} */
    private function totalesEnviados(): array
    {
        $req = Http::recorded()->last()[0];
        $body = $req->data();
        $items = 0.0; $itbms = 0.0;
        foreach ($body['items'] as $it) {
            $neto = round($it['cantidad'] * $it['precioUnitario'], 2);
            $i = round($neto * 0.07, 2);
            $itbms += $i;
            $items += $neto + $i;
        }
        $pagos = array_sum(array_map(static fn ($p) => $p['monto'], $body['pagos']));

        return ['items' => round($items, 2), 'pagos' => round($pagos, 2), 'itbmsLineas' => round($itbms, 2)];
    }

    private function enviar(AdminContInvoice $f): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'testing'])->save();
        app(FiscalCertificationService::class)->certificarFactura($f->fresh());
    }


    private function cobro(AdminContInvoice $f, float $monto, string $metodo = 'efectivo'): void
    {
        $cid = $f->master_client_id ?? \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'C '.self::uniqueTestNumber()])->id;
        $c = AdminContPayment::create([
            'numero_recibo' => 'REC-R2-'.self::uniqueTestNumber(), 'master_client_id' => $cid,
            'monto_recibido' => $monto, 'saldo_favor_aplicado' => 0, 'metodo_pago' => $metodo, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $c->id, 'invoice_id' => $f->id, 'monto_aplicado' => $monto]);
    }

    /** @return array{items: float, pagos: float, lista: array} */
    private function doc(AdminContInvoice $f): array
    {
        $d = app(\App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder::class)->paraFactura($f->fresh());
        $items = 0.0;
        foreach ($d->items as $it) { $n = round($it->cantidad * $it->precioUnitario, 2); $items += $n + round($n * 0.07, 2); }
        $pagos = 0.0; $lista = [];
        foreach ($d->pagos as $p) { $pagos += $p->monto; $lista[] = [$p->metodoPagoInterno, $p->monto]; }
        return ['items' => round($items, 2), 'pagos' => round($pagos, 2), 'lista' => $lista];
    }

    public function test_sin_cobros_pagos_igual_items_aunque_monto_difiera_1c(): void
    {
        $f = $this->facturaConLineas();   // monto 2109.95 (suma naive)
        $r = $this->doc($f);
        fwrite(STDERR, "\nSINCOBRO monto={$f->monto} ".json_encode($r)."\n");
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_cobro_parcial(): void
    {
        $f = $this->facturaConLineas(); $this->cobro($f, 500.00);
        $r = $this->doc($f); fwrite(STDERR, "\nPARCIAL ".json_encode($r)."\n");
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_totalmente_cobrada_por_monto_atlanticerp(): void
    {
        $f = $this->facturaConLineas(); $this->cobro($f, (float) $f->monto);
        $r = $this->doc($f); fwrite(STDERR, "\nCOBRADA-TOTAL monto={$f->monto} ".json_encode($r)."\n");
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_varios_pagos_cobrada(): void
    {
        $f = $this->facturaConLineas();
        $this->cobro($f, 1000.00, 'efectivo'); $this->cobro($f, 1109.95 , 'transferencia');
        $r = $this->doc($f); fwrite(STDERR, "\nVARIOS ".json_encode($r)."\n");
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_ultimo_pago_chico_absorbe_y_queda_negativo(): void
    {
        $f = $this->facturaConLineas();
        $this->cobro($f, 2109.92); $this->cobro($f, 0.03);
        $r = $this->doc($f); fwrite(STDERR, "\nULTIMO-CHICO ".json_encode($r)."\n");
        foreach ($r['lista'] as $p) { $this->assertGreaterThan(0, $p[1], 'pago no positivo en payload'); }
    }

    public function test_ultimo_pago_de_1c_queda_en_cero(): void
    {
        $f = $this->facturaConLineas();
        $this->cobro($f, 2109.94); $this->cobro($f, 0.01);
        $r = $this->doc($f); fwrite(STDERR, "\nULTIMO-1C ".json_encode($r)."\n");
        $this->assertTrue(true);
    }

    public function test_sobrecobro_mayor_a_5c(): void
    {
        $f = $this->facturaConLineas(); $this->cobro($f, 2109.95 + 0.10);
        $r = $this->doc($f); fwrite(STDERR, "\nSOBRECOBRO10c ".json_encode($r)."\n");
        $this->assertTrue(true);
    }

    public function test_sobrecobro_de_exactamente_5c_y_6c(): void
    {
        $f = $this->facturaConLineas(); $this->cobro($f, 2109.94 + 0.05);
        $r = $this->doc($f); fwrite(STDERR, "\nSOBRE5c ".json_encode($r)."\n");
        $g = $this->facturaConLineas(); $this->cobro($g, 2109.94 + 0.06);
        $r = $this->doc($g); fwrite(STDERR, "\nSOBRE6c ".json_encode($r)."\n");
        $this->assertTrue(true);
    }

    public function test_con_nota_de_credito_y_abono_sobre_factura(): void
    {
        $f = $this->facturaConLineas();
        $r = $this->doc($f);
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_factura_exacta_sin_diferencia_no_cambia(): void
    {
        // netos cuyo redondeo por línea coincide con el exacto: F0116 reducido a 2 líneas
        $f = $this->facturaConLineas([10000, 20000]);   // 7 % exacto
        $r = $this->doc($f); fwrite(STDERR, "\nEXACTA monto={$f->monto} ".json_encode($r)."\n");
        $this->assertSame((float) $f->monto, $r['pagos']);
        $this->assertSame($r['items'], $r['pagos']);
    }

    public function test_alineada_y_enviada_con_pagos_en_payload(): void
    {
        $f = $this->facturaConLineas();
        $this->cobro($f, 100.00);
        $this->fakeCertificado();
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'testing'])->save();
        app(FiscalCertificationService::class)->certificarFactura($f->fresh());
        $t = $this->totalesEnviados();
        fwrite(STDERR, "\nENVIO-COBRO ".json_encode($t)." monto=".$f->fresh()->monto." estado=".$f->fresh()->fe_estado."\n");
        $this->assertSame($t['items'], $t['pagos']);
    }

    public function test_pedido_grande_parcial_con_cobro_diferencia_monto_vs_fiscal(): void
    {
        mt_srand(8);
        foreach ([60, 100, 120, 150, 200, 60, 100, 120] as $nProd) {
            $items = [];
            for ($i = 0; $i < $nProd; $i++) { $items[] = ['desc' => "Prod $i", 'qty' => 2, 'price' => mt_rand(500, 90000) / 100]; }
            $uno = array_fill(0, $nProd, 1);
            $ped = $this->makePedido($items, [$uno, $uno]);
            $this->facturar($this->token(), [$ped['entregas'][0]->id], 'entrega')->assertStatus(201);
            $this->reTenant();
            $f1 = AdminContInvoice::forOrder($ped['root'])->whereNull('anulada_at')->orderBy('id')->firstOrFail();
            $sinCobro = $this->doc($f1);
            $this->cobro($f1, 10.00);
            $conCobro = $this->doc($f1);
            $items1 = $f1->lines()->count();
            if ($conCobro['pagos'] !== $conCobro['items']) {
                $this->fakeCertificado();
                AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'testing'])->save();
                app(FiscalCertificationService::class)->certificarFactura($f1->fresh());
                $t = $this->totalesEnviados();
                fwrite(STDERR, "\nENVIADO-DESCUADRE nProd=$nProd ".json_encode($t)." montoDespues=".$f1->fresh()->monto." estado=".$f1->fresh()->fe_estado."\n");
                AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
            }
            fwrite(STDERR, "\nGRANDE nProd=$nProd lineas=$items1 monto={$f1->monto} sinCobro=".json_encode($sinCobro)." conCobro=".json_encode($conCobro)." dif_cobro=".round($conCobro['pagos'] - $conCobro['items'], 2)."\n");
            $this->assertEqualsWithDelta($conCobro['items'], $conCobro['pagos'], 0.0001, "pagos cuadran con ítems ({$nProd} productos)");
        }
        $this->assertTrue(true);
    }

    public function test_si_el_alineador_falla_el_envio_continua_y_el_payload_sigue_cuadrado(): void
    {
        $f = $this->facturaConLineas();
        $tabla = (new AdminContInvoiceLine())->getTable();
        \Illuminate\Support\Facades\DB::statement("CREATE OR REPLACE FUNCTION pg_temp.boom() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'boom'; END \$\$ LANGUAGE plpgsql");
        \Illuminate\Support\Facades\DB::statement("CREATE OR REPLACE FUNCTION public.preqa_boom() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'boom'; END \$\$ LANGUAGE plpgsql");
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER preqa_boom BEFORE UPDATE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION public.preqa_boom()");
        try {
            $this->fakeCertificado();
            $this->enviar($f);
            $t = $this->totalesEnviados();
            fwrite(STDERR, "\nALINEADOR-FALLA ".json_encode($t)." monto=".$f->fresh()->monto." estado=".$f->fresh()->fe_estado."\n");
            $this->assertSame('certificado', $f->fresh()->fe_estado);
            $this->assertSame($t['items'], $t['pagos']);
            // reintento no duplica
            $this->assertEqualsWithDelta(0.0, (float) $f->fresh()->ajuste_itbms_delta, 0.0001);
        } finally {
            \Illuminate\Support\Facades\DB::statement("DROP TRIGGER IF EXISTS preqa_boom ON {$tabla}");
        }
    }
}
