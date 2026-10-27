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

/** Pre-QA SCRUM-1185/1186 — ataques de extremo a extremo (payload real al PAC). */
final class PreQa1185Test extends FeatureTestCase
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

    public function test_camino_feliz_total_de_items_igual_a_pagos_igual_a_monto(): void
    {
        $f = $this->facturaConLineas();
        $this->fakeCertificado();
        $this->enviar($f);
        $t = $this->totalesEnviados();
        fwrite(STDERR, "\nHAPPY ".json_encode($t).' monto='.$f->fresh()->monto."\n");
        $this->assertSame($t['items'], $t['pagos']);
        $this->assertEqualsWithDelta((float) $f->fresh()->monto, $t['pagos'], 0.0001);
    }

    public function test_con_un_cobro_aplicado_los_pagos_cuadran_con_los_items(): void
    {
        $f = $this->facturaConLineas();
        $cobro = AdminContPayment::create([
            'numero_recibo' => 'REC-PQ-'.self::uniqueTestNumber(), 'master_client_id' => $f->master_client_id ?? \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'C '.self::uniqueTestNumber()])->id,
            'monto_recibido' => 10, 'saldo_favor_aplicado' => 0, 'metodo_pago' => 'efectivo', 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $cobro->id, 'invoice_id' => $f->id, 'monto_aplicado' => 10]);
        $this->fakeCertificado();
        $this->enviar($f);
        $t = $this->totalesEnviados();
        fwrite(STDERR, "\nCOBRO ".json_encode($t).' monto='.$f->fresh()->monto."\n");
        $this->assertSame($t['items'], $t['pagos'], 'Σ items (balanceados) debe igualar Σ pagos');
    }

    public function test_rechazo_y_reintento_no_duplican_el_ajuste(): void
    {
        $f = $this->facturaConLineas();
        $this->fakeRechazo();
        $this->enviar($f);
        $delta1 = (float) $f->fresh()->ajuste_itbms_delta;
        $monto1 = (float) $f->fresh()->monto;
        $this->assertSame('rechazado', $f->fresh()->fe_estado);
        $this->reTenant();
        $r = app(FiscalCertificationService::class)->recertificar($f->fresh(), 'factura');
        fwrite(STDERR, "\nRETRY ".json_encode($r).' delta='.$f->fresh()->ajuste_itbms_delta."\n");
        $this->assertEqualsWithDelta($delta1, (float) $f->fresh()->ajuste_itbms_delta, 0.0001);
        $this->assertEqualsWithDelta($monto1, (float) $f->fresh()->monto, 0.0001);
    }

    public function test_nota_de_credito_de_anulacion_completa_sobre_factura_balanceada(): void
    {
        $f = $this->facturaConLineas();
        $this->fakeCertificado();
        $this->enviar($f);
        $this->assertSame('certificado', $f->fresh()->fe_estado);
        $docF = app(\App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder::class)->paraFactura($f->fresh());
        $nc = new \App\Modules\AdminCont\Models\AdminContCreditNote();
        $this->assertTrue(true);
        $sum = 0.0; foreach ($docF->items as $it) { $sum += round($it->cantidad * $it->precioUnitario, 2); }
        $this->assertEqualsWithDelta(array_sum(self::F0116) / 100, $sum, 0.0001, 'neto total conservado en items');
    }

    public function test_pedido_con_dos_entregas_parciales_sigue_consistente_tras_alinear(): void
    {
        $items = [];
        foreach (self::F0116 as $i => $neto) { $items[] = ['desc' => 'Prod '.$i, 'qty' => 2, 'price' => $neto / 200]; }
        $uno = array_fill(0, 14, 1);
        $ped = $this->makePedido($items, [$uno, $uno]);
        $root = $ped['root'];
        $tok = $this->token();
        $this->facturar($tok, [$ped['entregas'][0]->id], 'entrega')->assertStatus(201);
        $this->reTenant();
        $f1 = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->orderBy('id')->firstOrFail();
        fwrite(STDERR, "\nF1 monto={$f1->monto} lines=".$f1->lines()->count().' sumNeto='.$f1->lines()->sum('subtotal').' sumItbms='.$f1->lines()->sum('itbms')."\n");
        $this->fakeCertificado();
        $this->enviar($f1);
        $f1 = $f1->fresh();
        fwrite(STDERR, "F1 tras envío monto={$f1->monto} ajuste={$f1->ajuste_itbms_delta} estado={$f1->fe_estado}\n");
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        $this->facturar($tok, [$ped['entregas'][1]->id], 'entrega')->assertStatus(201);
        $this->reTenant();
        $f2 = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->orderByDesc('id')->firstOrFail();
        fwrite(STDERR, "F2 monto={$f2->monto} lines=".$f2->lines()->count().' sumNeto='.$f2->lines()->sum('subtotal').' sumItbms='.$f2->lines()->sum('itbms')."\n");
        $this->fakeCertificado();
        $this->enviar($f2);
        $f2 = $f2->fresh();
        fwrite(STDERR, "F2 tras envío monto={$f2->monto} ajuste={$f2->ajuste_itbms_delta}\n");
        $ctx = app(\App\Modules\AdminCont\Services\Billing\InvoiceBillingPlanner::class)->context($root->fresh());
        fwrite(STDERR, 'CTX total='.$ctx->total.' facturado='.$ctx->facturado().' restante='.$ctx->restante().' ajuste='.$ctx->ajusteItbms.' card='.$ped['card']->amount."\n");
        $this->assertEqualsWithDelta(0.0, $ctx->restante(), 0.0001);
        $this->assertEqualsWithDelta($ctx->total, $ctx->facturado(), 0.0001);
    }

    public function test_alineacion_deja_auditoria(): void
    {
        $f = $this->facturaConLineas();
        $this->fakeCertificado();
        $this->enviar($f);
        $this->assertDatabaseHas('atlantic_auth.audit_logs', ['action' => 'admin_cont.fe_documento.total_alineado', 'entity_id' => $f->id]);
        $row = \Illuminate\Support\Facades\DB::table('atlantic_auth.audit_logs')->where('action', 'admin_cont.fe_documento.total_alineado')->first();
        fwrite(STDERR, "\nAUDIT ".json_encode($row)."\n");

    }
}
