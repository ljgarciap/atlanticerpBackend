<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — extremo a extremo: confirmar una factura vía el endpoint real ya certifica sola
 * (listener síncrono sobre `InvoiceCreated`), sin que el controller/`InvoiceService` sepan nada de
 * facturación electrónica.
 */
final class CertifyInvoiceOnCreatedTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config([
            'services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
        ]);
    }

    public function test_confirmar_factura_la_certifica_automaticamente(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        Storage::fake('s3-private');
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0120000...auto',
            'qr' => 'https://dgi-fep-test.mef.gob.pa/qr', 'xmlFirmado' => base64_encode('<xml/>'),
        ], 200)]);

        ['root' => $root] = $this->makePedido([['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $this->assertSame('certificado', $factura->fe_estado);
        $this->assertSame('FE0120000...auto', $factura->cufe);
    }

    /** El bug/config rota de facturación electrónica nunca revierte ni bloquea la creación de la factura. */
    public function test_un_fallo_de_certificacion_nunca_bloquea_la_creacion_de_la_factura(): void
    {
        // Un pac_provider que no existe en el registro — la certificación falla internamente,
        // la factura debe seguir creándose con 201 igual.
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'proveedor-inexistente'])->save();
        Http::fake();

        ['root' => $root] = $this->makePedido([['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $this->assertSame('error_configuracion', $factura->fe_estado);
        $this->assertNull($factura->cufe);
    }
}
