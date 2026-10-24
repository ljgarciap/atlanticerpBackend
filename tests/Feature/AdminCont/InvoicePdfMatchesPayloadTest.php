<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Services\InvoiceService;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1156 (T6, RN7) — lo que el PDF imprime como neto, ITBMS y total tiene que ser lo que viajó al PAC. Se arma una factura REAL
 * (cotización con descuentos, facturación por el flujo normal), se construye el documento fiscal con `FiscalDocumentBuilder` (el mismo
 * que arma el payload de MSL) y se compara contra el detalle y el HTML del PDF certificado. Sin números del sandbox: la transmisión se
 * fabrica con el payload ya armado.
 */
final class InvoicePdfMatchesPayloadTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    private const CUFE = 'FE0120000155704849-2-2021-261179609251609600073000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    /**
     * @param array<string, mixed> $quoteOverrides
     * @return array{0: AdminContInvoice, 1: array<string, mixed>}
     */
    private function facturaCertificadaConDescuentos(array $quoteOverrides = []): array
    {
        ['root' => $root] = $this->makePedido([
            ['desc' => 'Panel LED con 10 % de línea', 'qty' => 9, 'price' => 100.00, 'discount' => 10.0],
            ['desc' => 'Luminaria sin descuento', 'qty' => 2, 'price' => 55.55],
            ['desc' => 'Riel con 25 % de línea', 'qty' => 3, 'price' => 33.33, 'discount' => 25.0],
        ], [[9, 2, 3]], $quoteOverrides);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);

        $invoice = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $qr      = 'https://dgi-fep-test.mef.gob.pa:40001/Consultas/FacturasPorCUFE?chFE='.self::CUFE.'&iAmb=2&digestValue=abc';
        $invoice->forceFill(['serie' => 'ILU', 'fe_estado' => 'certificado', 'cufe' => self::CUFE, 'qr_content' => $qr, 'fe_emision_at' => now()])->save();

        $documento = app(FiscalDocumentBuilder::class)->paraFactura($invoice);
        AdminContFeTransmision::create([
            'documento_tipo' => 'factura', 'documento_id' => $invoice->id, 'provider' => 'msl', 'ambiente' => 'pruebas',
            'operacion' => 'certificar', 'origen' => 'certificacion', 'serie' => 'ILU', 'numero_fiscal' => '0001', 'resultado' => 'certificado',
            'request_payload' => ['items' => array_map(static fn ($i): array => [
                'codigo' => $i->codigo, 'cantidad' => $i->cantidad, 'precioUnitario' => $i->precioUnitario, 'descuento' => $i->descuento, 'tasaITBMS' => $i->tasaItbmsCode,
            ], $documento->items)],
        ]);

        $detail = app(InvoiceService::class)->detail($invoice->order_id, $invoice->id);

        return [$invoice->refresh(), $detail];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function escenariosDeDescuento(): array
    {
        return [
            'descuentos por línea' => [[]],
            'descuento global'     => [['discount_mode' => 'global', 'global_discount_percent' => 12.0]],
        ];
    }

    /** @param array<string, mixed> $quoteOverrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('escenariosDeDescuento')]
    public function test_el_neto_el_itbms_y_el_total_del_pdf_son_los_del_documento_enviado_al_pac(array $quoteOverrides): void
    {
        [$invoice, $detail] = $this->facturaCertificadaConDescuentos($quoteOverrides);
        $documento = app(FiscalDocumentBuilder::class)->paraFactura($invoice);

        $netoPac = round(array_sum(array_map(static fn ($i): float => $i->cantidad * $i->precioUnitario - $i->descuento, $documento->items)), 2);
        $itbmsPac = round(array_sum(array_map(static fn ($i): float => ($i->cantidad * $i->precioUnitario - $i->descuento) * ($i->tasaItbmsCode === '01' ? 0.07 : 0.0), $documento->items)), 2);

        // Subtotal − descuentos del PDF = neto que viaja al PAC.
        $this->assertEqualsWithDelta($netoPac, $detail['subtotal'] - $detail['descuentos'], 0.011, 'neto del PDF vs neto del PAC');
        $this->assertEqualsWithDelta($itbmsPac, $detail['itbms'], 0.011, 'ITBMS del PDF vs ITBMS del PAC');
        $this->assertEqualsWithDelta($netoPac + $itbmsPac, $detail['total'], 0.011, 'total del PDF vs total del PAC');

        // Y eso es lo que sale impreso en la variante certificada.
        $data = app(InvoicePdfService::class)->viewData($detail);
        $this->assertTrue($data['variante']->isFiscal());
        $html = view('admin-cont.invoice-pdf-fiscal', $data)->render();
        $this->assertStringContainsString(number_format($detail['total'], 2), $html);
        $this->assertStringContainsString(number_format($detail['itbms'], 2), $html);
        // Los descuentos (de línea o globales) ya vienen dentro del precio unitario que se envía al PAC: el PDF no inventa una fila aparte.
        $this->assertEqualsWithDelta(0.0, $detail['descuentos'], 0.001);
        $this->assertStringNotContainsString('>Descuentos<', $html);
    }

    public function test_el_pac_nunca_recibe_descuento_por_linea_y_por_eso_el_pdf_no_imprime_la_columna_dto(): void
    {
        [$invoice, $detail] = $this->facturaCertificadaConDescuentos();

        foreach (app(FiscalDocumentBuilder::class)->paraFactura($invoice)->items as $item) {
            $this->assertSame(0.0, $item->descuento, 'los descuentos ya vienen dentro del precio que se envía (FiscalDocumentItemsBuilder)');
        }

        $html = view('admin-cont.invoice-pdf-fiscal', app(InvoicePdfService::class)->viewData($detail))->render();
        // El logo va incrustado en base64 (data URI): la cadena «Dto» puede aparecer por azar dentro de la imagen.
        $this->assertStringNotContainsString('Dto', (string) preg_replace('/data:[^"\'\s)]+/', '', $html));
    }
}
