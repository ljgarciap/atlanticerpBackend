<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\VentasDiseno\Models\SubClient;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — ensamblado completo de `FiscalDocument` para facturas: emisor (`fiscal_settings`),
 * receptor (`sub_clients` vía `Order->pipelineCard->subClient`) y pagos (reales ya cobrados +
 * saldo a crédito). Ítems ya cubiertos por `FiscalDocumentItemsBuilderTest` (SCRUM-929), no se
 * repiten acá.
 */
final class FiscalDocumentBuilderTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    private function builder(): FiscalDocumentBuilder
    {
        return app(FiscalDocumentBuilder::class);
    }

    private function facturaSimple(): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);

        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
    }

    public function test_emisor_viene_de_fiscal_settings(): void
    {
        AdminContFiscalSettings::current()->forceFill([
            'razon_social' => 'ILUMINATIONS S.A.', 'ruc' => '155704849-2-2021', 'dv' => '32',
            'correo_contacto' => 'facturacion@atlantic.com.pa', 'direccion_fiscal' => 'Calle 50, Panamá',
        ])->save();

        $documento = $this->builder()->paraFactura($this->facturaSimple());

        $this->assertSame('01', $documento->emisor->tipo);
        $this->assertSame('155704849-2-2021', $documento->emisor->ruc);
        $this->assertSame('32', $documento->emisor->dv);
        $this->assertSame('ILUMINATIONS S.A.', $documento->emisor->nombre);
        $this->assertSame('facturacion@atlantic.com.pa', $documento->emisor->email);
    }

    public function test_receptor_usa_datos_reales_del_subclient(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $subClient = $root->pipelineCard->subClient;
        $subClient->update(['tax_id_dv' => '7', 'receptor_tipo' => '01']);

        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $documento = $this->builder()->paraFactura($factura);

        $this->assertSame('01', $documento->receptor->tipo);
        $this->assertSame($subClient->tax_id, $documento->receptor->ruc);
        $this->assertSame('7', $documento->receptor->dv);
        $this->assertSame($subClient->business_name, $documento->receptor->nombre);
    }

    public function test_receptor_sin_tax_id_cae_a_consumidor_final(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $root->pipelineCard->subClient->update(['tax_id' => '']);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $documento = $this->builder()->paraFactura($factura);

        $this->assertSame('02', $documento->receptor->tipo);
        $this->assertSame('CONSUMIDOR FINAL', $documento->receptor->nombre);
    }

    /** SCRUM-1003 — selector explícito: `02` es Consumidor Final aunque el subcliente conserve un RUC guardado. */
    public function test_receptor_tipo_02_sale_como_consumidor_final_sin_ruc_ni_dv_aunque_haya_tax_id(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $root->pipelineCard->subClient->update(['receptor_tipo' => '02', 'tax_id_dv' => '81']); // el tax_id del fixture sigue guardado
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $documento = $this->builder()->paraFactura($factura);

        $this->assertSame('02', $documento->receptor->tipo);
        $this->assertSame('CONSUMIDOR FINAL', $documento->receptor->nombre);
        $this->assertNull($documento->receptor->ruc);
        $this->assertNull($documento->receptor->dv);
    }

    /** SCRUM-1003 — regresión: una fila anterior a la captura (NULL) y una `01` explícita salen igual que siempre. */
    public function test_receptor_tipo_null_y_01_no_cambian_el_payload_de_contribuyente(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $sub = $root->pipelineCard->subClient;
        $sub->update(['tax_id_dv' => '7']);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $conNull = $this->builder()->paraFactura($factura)->receptor;
        $sub->update(['receptor_tipo' => '01']);
        $con01 = $this->builder()->paraFactura($factura->fresh())->receptor;

        $this->assertEquals($conNull, $con01);
        $this->assertSame('01', $con01->tipo);
        $this->assertSame($sub->tax_id, $con01->ruc);
        $this->assertSame('7', $con01->dv);
        $this->assertSame($sub->business_name, $con01->nombre);
    }

    public function test_receptor_sin_receptor_tipo_capturado_asume_contribuyente(): void
    {
        // Hoy `receptor_tipo` siempre es NULL (sin flujo de captura, ver docblock del builder).
        $factura = $this->facturaSimple();

        $documento = $this->builder()->paraFactura($factura);

        $this->assertSame('01', $documento->receptor->tipo);
    }

    public function test_factura_sin_ningun_pago_declara_el_total_como_credito(): void
    {
        $factura = $this->facturaSimple();

        $documento = $this->builder()->paraFactura($factura);

        $this->assertCount(1, $documento->pagos);
        $this->assertSame(MslPaymentMethodMapper::METODO_INTERNO_CREDITO, $documento->pagos[0]->metodoPagoInterno);
        $this->assertEqualsWithDelta($factura->monto, $documento->pagos[0]->monto, 0.001);
    }

    public function test_factura_con_pago_parcial_real_declara_ese_pago_mas_el_saldo_a_credito(): void
    {
        $factura = $this->facturaSimple();
        $pago    = AdminContPayment::create([
            'master_client_id' => $factura->master_client_id ?? 1,
            'monto_recibido'   => 400.0,
            'metodo_pago'      => AdminContPayment::METODO_EFECTIVO,
            'estado'           => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $pago->id, 'invoice_id' => $factura->id, 'monto_aplicado' => 400.0]);

        $documento = $this->builder()->paraFactura($factura->fresh());

        $this->assertCount(2, $documento->pagos);
        $this->assertSame('efectivo', $documento->pagos[0]->metodoPagoInterno);
        $this->assertEqualsWithDelta(400.0, $documento->pagos[0]->monto, 0.001);
        $this->assertSame(MslPaymentMethodMapper::METODO_INTERNO_CREDITO, $documento->pagos[1]->metodoPagoInterno);
        $this->assertEqualsWithDelta($factura->monto - 400.0, $documento->pagos[1]->monto, 0.001);
    }

    public function test_factura_totalmente_cobrada_no_agrega_linea_de_credito(): void
    {
        $factura = $this->facturaSimple();
        $pago    = AdminContPayment::create([
            'master_client_id' => $factura->master_client_id ?? 1,
            'monto_recibido'   => $factura->monto,
            'metodo_pago'      => AdminContPayment::METODO_EFECTIVO,
            'estado'           => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $pago->id, 'invoice_id' => $factura->id, 'monto_aplicado' => $factura->monto]);

        $documento = $this->builder()->paraFactura($factura->fresh());

        $this->assertCount(1, $documento->pagos);
        $this->assertSame('efectivo', $documento->pagos[0]->metodoPagoInterno);
    }

    // ─── SCRUM-1140 — receptor congelado en la factura ───────────────────────────────────────

    /** El documento fiscal lleva lo que se congeló al facturar, no lo que el cliente tenga después. */
    public function test_receptor_congelado_gana_sobre_el_cliente_actual(): void
    {
        $factura = $this->facturaSimple();
        $factura->forceFill([
            'receptor_tipo' => '01', 'receptor_ruc' => '155704849-2-2021', 'receptor_dv' => '32',
            'receptor_nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', 'receptor_validado_at' => now(),
        ])->save();
        $order = \App\Modules\Bodega\Models\Order::find($factura->family_root_id);
        $order?->pipelineCard?->subClient?->update(['business_name' => 'NOMBRE CAMBIADO DESPUES', 'tax_id' => '999-9-9999', 'tax_id_dv' => '11']);

        $receptor = $this->builder()->paraFactura($factura->fresh())->receptor;

        $this->assertSame('01', $receptor->tipo);
        $this->assertSame('155704849-2-2021', $receptor->ruc);
        $this->assertSame('32', $receptor->dv);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $receptor->nombre);
    }

    public function test_receptor_congelado_de_consumidor_final_sale_02_aunque_el_cliente_tenga_ruc(): void
    {
        $factura = $this->facturaSimple();
        $factura->forceFill(['receptor_tipo' => '02', 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => 'CONSUMIDOR FINAL'])->save();

        $receptor = $this->builder()->paraFactura($factura->fresh())->receptor;

        $this->assertSame('02', $receptor->tipo);
        $this->assertSame('CONSUMIDOR FINAL', $receptor->nombre);
        $this->assertNull($receptor->ruc);
        $this->assertNull($receptor->dv);
    }

    public function test_cedula_sin_dv_congelada_no_lleva_dv(): void
    {
        $factura = $this->facturaSimple();
        $factura->forceFill(['receptor_tipo' => '01', 'receptor_ruc' => '8-123-456', 'receptor_dv' => null, 'receptor_nombre' => 'JUAN PEREZ'])->save();

        $payload = (new \App\Modules\AdminCont\FacturacionElectronica\Mappers\MslJsonMapper())->map($this->builder()->paraFactura($factura->fresh()), 'pruebas');

        $this->assertSame('01', $payload['receptor']['tipo']);
        $this->assertSame('8-123-456', $payload['receptor']['ruc']);
        $this->assertArrayNotHasKey('dv', $payload['receptor'], 'El DV solo viaja si la DGI lo trajo.');
    }

    public function test_factura_sin_receptor_congelado_sigue_leyendo_el_cliente_en_vivo(): void
    {
        $factura = $this->facturaSimple();
        $factura->forceFill(['receptor_tipo' => null, 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => null, 'receptor_validado_at' => null])->save();
        $order = \App\Modules\Bodega\Models\Order::find($factura->family_root_id);
        $order?->pipelineCard?->subClient?->update(['business_name' => 'NOMBRE VIGENTE', 'tax_id_dv' => '55']);

        $receptor = $this->builder()->paraFactura($factura->fresh())->receptor;

        $this->assertSame('NOMBRE VIGENTE', $receptor->nombre);
        $this->assertSame('55', $receptor->dv);
    }

    public function test_el_pdf_muestra_la_razon_social_validada_y_el_tipo_congelado(): void
    {
        $factura = $this->facturaSimple();
        $factura->forceFill(['receptor_tipo' => '01', 'receptor_ruc' => '155704849-2-2021', 'receptor_dv' => '32', 'receptor_nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', 'receptor_validado_at' => now()])->save();

        $detail = app(\App\Modules\AdminCont\Services\InvoiceService::class)->detail($factura->order_id, $factura->id);

        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $detail['receptor_nombre']);
        $this->assertSame('155704849-2-2021', $detail['cliente_ruc']);
        $this->assertSame('01', $detail['cliente_receptor_tipo']);
        $html = view('admin-cont.invoice-pdf', app(\App\Modules\AdminCont\Services\InvoicePdfService::class)->viewData($detail))->render();
        $this->assertStringContainsString('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $html);
    }
}
