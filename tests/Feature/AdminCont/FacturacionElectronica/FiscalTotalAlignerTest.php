<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalTotalAligner;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceLine;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1185 (hotfix) — el total fiscal es la única fuente de verdad del monto: antes del primer envío se alinean líneas y `monto`.
 */
final class FiscalTotalAlignerTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    /** Netos reales de ILU-0116 (14 líneas, todas al 7 %) en centavos. */
    private const F0116 = [25872, 25872, 30800, 6684, 5880, 14627, 17500, 7560, 7997, 11900, 21700, 3998, 5950, 10850];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        // Sin envío al PAC: la factura se crea y queda sin tocar (no_aplica).
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
    }

    private function aligner(): FiscalTotalAligner
    {
        return app(FiscalTotalAligner::class);
    }

    /** @param list<int> $netos */
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

    public function test_alinea_lineas_y_monto_de_una_factura_sin_enviar_y_registra_la_diferencia(): void
    {
        $factura = $this->facturaConLineas();
        $netoAntes = (float) $factura->lines()->sum('subtotal');
        $montoAntes = (float) $factura->monto;

        $r = $this->aligner()->alinear($factura);

        $this->assertSame(['alineada' => true, 'motivo' => 'alineada'], $r);
        $fresca = $factura->fresh();
        $this->assertEqualsWithDelta(2109.94, (float) $fresca->monto, 0.0001);
        $this->assertEqualsWithDelta($montoAntes - 0.01, (float) $fresca->monto, 0.0001);
        $this->assertEqualsWithDelta(-0.01, (float) $fresca->ajuste_itbms_delta, 0.0001);
        $this->assertEqualsWithDelta($netoAntes, (float) $fresca->lines()->sum('subtotal'), 0.0001, 'el neto no cambia');
        $this->assertEqualsWithDelta((float) $fresca->monto, (float) $fresca->lines()->sum('total'), 0.0001, 'monto = Σ totales de línea');
    }

    public function test_es_idempotente(): void
    {
        $factura = $this->facturaConLineas();
        $this->aligner()->alinear($factura);
        $montoTras = (float) $factura->fresh()->monto;

        $r = $this->aligner()->alinear($factura->fresh());

        $this->assertFalse($r['alineada']);
        $this->assertSame('no_requiere_balanceo', $r['motivo']);
        $this->assertEqualsWithDelta($montoTras, (float) $factura->fresh()->monto, 0.0001);
        $this->assertEqualsWithDelta(-0.01, (float) $factura->fresh()->ajuste_itbms_delta, 0.0001, 'no suma el ajuste dos veces');
    }

    public function test_no_toca_una_factura_que_no_necesita_balanceo(): void
    {
        $factura = $this->facturaConLineas([10000, 20000]);
        $antes = (float) $factura->monto;

        $r = $this->aligner()->alinear($factura);

        $this->assertSame('no_requiere_balanceo', $r['motivo']);
        $this->assertEqualsWithDelta($antes, (float) $factura->fresh()->monto, 0.0001);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function estadosQueBloquean(): array
    {
        return [
            'ya certificada (cufe)'   => [['cufe' => 'FE01-x', 'fe_estado' => 'certificado'], 'ya_certificada_o_pendiente'],
            'pendiente ante el PAC'   => [['fe_estado' => 'pendiente'], 'ya_certificada_o_pendiente'],
            'anulada'                 => [['anulada_at' => '2026-10-20 10:00:00'], 'anulada_o_incobrable'],
            'incobrable'              => [['es_incobrable' => true], 'anulada_o_incobrable'],
            'con saldo aplicado'      => [['saldo_aplicado' => 5.00], 'tiene_saldo_aplicado'],
        ];
    }

    /** @param array<string, mixed> $cambios */
    #[\PHPUnit\Framework\Attributes\DataProvider('estadosQueBloquean')]
    public function test_no_alinea_si_el_estado_o_el_saldo_lo_impiden(array $cambios, string $motivo): void
    {
        $factura = $this->facturaConLineas();
        $factura->forceFill($cambios)->save();
        $antes = (float) $factura->monto;

        $r = $this->aligner()->alinear($factura->fresh());

        $this->assertFalse($r['alineada']);
        $this->assertSame($motivo, $r['motivo']);
        $this->assertEqualsWithDelta($antes, (float) $factura->fresh()->monto, 0.0001);
    }

    public function test_no_alinea_si_hay_un_cobro_aplicado(): void
    {
        $factura = $this->facturaConLineas();
        $cobro = \App\Modules\AdminCont\Models\AdminContPayment::create([
            'numero_recibo' => 'REC-AL-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'C '.self::uniqueTestNumber()])->id,
            'monto_recibido' => 10, 'saldo_favor_aplicado' => 0, 'metodo_pago' => 'efectivo',
            'estado' => \App\Modules\AdminCont\Models\AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $cobro->id, 'invoice_id' => $factura->id, 'monto_aplicado' => 10]);
        $antes = (float) $factura->monto;

        $r = $this->aligner()->alinear($factura->fresh());

        $this->assertSame('tiene_cobros_o_notas', $r['motivo']);
        $this->assertEqualsWithDelta($antes, (float) $factura->fresh()->monto, 0.0001);
    }

    public function test_no_alinea_si_el_monto_guardado_no_coincide_con_sus_lineas(): void
    {
        $factura = $this->facturaConLineas();
        $factura->forceFill(['monto' => (float) $factura->monto + 3.00])->save();
        $antes = (float) $factura->fresh()->monto;

        $r = $this->aligner()->alinear($factura->fresh());

        $this->assertSame('delta_fuera_de_rango', $r['motivo']);
        $this->assertEqualsWithDelta($antes, (float) $factura->fresh()->monto, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $factura->fresh()->ajuste_itbms_delta, 0.0001);
    }

    public function test_una_factura_sin_lineas_no_se_alinea(): void
    {
        $factura = $this->facturaConLineas();
        $factura->lines()->delete();

        $this->assertSame('sin_lineas', $this->aligner()->alinear($factura->fresh())['motivo']);
    }

    public function test_el_documento_enviado_al_pac_ya_coincide_con_el_monto_alineado(): void
    {
        $factura = $this->facturaConLineas();
        $this->aligner()->alinear($factura);

        $documento = app(\App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder::class)->paraFactura($factura->fresh());
        $this->assertCount(14, $documento->items);
        $this->assertEqualsWithDelta(2109.94, array_sum(array_map(static fn ($p) => $p->monto, $documento->pagos)), 0.0001);
        $this->assertSame(AuditService::ADMIN_CONT_FE_TOTAL_ALINEADO, 'admin_cont.fe_documento.total_alineado');
    }
}
